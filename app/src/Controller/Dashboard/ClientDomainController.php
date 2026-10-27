<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Audit\AuditActor;
use App\Client\ClientLimitPolicy;
use App\Dashboard\ClientAccess;
use App\Dashboard\ClientReadModel;
use App\Domain\DkimSetup;
use App\Domain\DomainRuleViolation;
use App\Domain\SendingDomainService;
use App\Entity\Client;
use App\Entity\SendingDomain;
use App\Entity\User;
use App\Enum\ClientStatus;
use App\Enum\DkimStatus;
use App\Security\ClientVoter;
use App\Util\Clock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * An account's sending domains (owner decisions 2026-10-11 and 2026-10-27), set up without the
 * operator, one step at a time:
 *
 *   list / new   the account's domains; adding one (a client admin, within the account's limit:
 *                ClientLimitPolicy) is its own page and leads to the domain's steps;
 *   the steps    /sending-domains/{domain}: prove it is yours (the TXT record), the signing key is
 *                made (the host agent, DkimSetup::requestKey, asked for as soon as the domain is
 *                verified), publish the signing key (its DKIM record), signing switched on, ready.
 *                Each record has copy buttons and one line on what it is for; an existing DMARC
 *                record is recognised. An application may link here with ?back=<its address>:
 *                the page then offers the way back, when the address is on the origin of one of
 *                the account's connected applications;
 *   check        the page asks every 30 seconds (POST, JSON) and moves on by itself: the TXT
 *                challenge, or the DKIM record, whichever is next (quietly: a failed automatic
 *                check is recorded on the domain, not in the audit log; at most every 20 seconds);
 *   verify       the same check from a button, for a page without JavaScript (audited);
 *   dkim         a client admin asks for the key again (after a failed generation).
 *
 * ClientAccess answers 404 for an account the user may not see, and a domain is loaded only with
 * the account's own id. Every action is POST with CSRF and audited by the services it calls.
 */
#[Route('/dashboard/c/{clientId}', requirements: ['clientId' => '[0-9a-fA-F-]{36}'])]
final class ClientDomainController extends AbstractController
{
    /** The page's automatic check, and the least time between two of them for one domain. */
    public const CHECK_SECONDS = 30;
    private const MIN_CHECK_SECONDS = 20;
    private const DOMAIN = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientReadModel $read,
        private readonly ClientLimitPolicy $limits,
        private readonly SendingDomainService $domains,
        private readonly DkimSetup $dkim,
        private readonly EntityManagerInterface $em,
        private readonly string $smarthostEnv,
    ) {
    }

    #[Route('/sending-domains', name: 'dashboard_client_domains', methods: ['GET'])]
    public function index(string $clientId): Response
    {
        $client = $this->access->client($clientId);
        $domains = array_map(static fn (array $d): array => $d + ['stage' => DkimSetup::stage($d)], $this->read->sendingDomains($clientId));

        return $this->render('dashboard/client/domains.html.twig', ['client' => $client, 'section' => 'domains', 'domains' => $domains,
            'max_domains' => $this->limits->maxSendingDomains($client), 'may_admin' => $this->access->may(ClientVoter::ADMIN, $client),
            'may_add' => !\in_array($client->getStatus(), [ClientStatus::Suspended, ClientStatus::Closed], true)]);
    }

    #[Route('/sending-domains/new', name: 'dashboard_client_domain_new', methods: ['GET'])]
    public function new(string $clientId, Request $request): Response
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);

        return $this->newForm($client, '', null, $this->back($client, $request->query->getString('back')));
    }

    #[Route('/sending-domains/new', name: 'dashboard_client_domain_add', methods: ['POST'])]
    public function add(string $clientId, Request $request): Response
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $this->assertCsrf($request);
        $name = $request->request->getString('domain');
        $back = $this->back($client, $request->request->getString('back'));
        try {
            if (\in_array($client->getStatus(), [ClientStatus::Suspended, ClientStatus::Closed], true)) {
                throw new DomainRuleViolation('A suspended or closed account cannot add sending domains.');
            }
            $domain = $this->domains->register($client, $name, AuditActor::user($this->access->user()));
        } catch (DomainRuleViolation $e) {
            return $this->newForm($client, $name, $e->getMessage(), $back);
        }

        return $this->redirectToRoute('dashboard_client_domain', ['clientId' => $clientId, 'domainId' => $domain->getId()->toRfc4122()]
            + (null === $back ? [] : ['back' => $back['url']]));
    }

    #[Route('/sending-domains/{domainId}', name: 'dashboard_client_domain', methods: ['GET'], requirements: ['domainId' => self::DOMAIN])]
    public function show(string $clientId, string $domainId, Request $request): Response
    {
        $client = $this->access->client($clientId);
        $row = $this->read->sendingDomains($clientId, $this->domain($client, $domainId)->getId()->toRfc4122())[0]
            ?? throw new NotFoundHttpException('Not found.');
        $stage = DkimSetup::stage($row);

        return $this->render('dashboard/client/domain.html.twig', ['client' => $client, 'section' => 'domains', 'd' => $row, 'stage' => $stage,
            'records' => $this->dkim->records($row['domain'], $row['verification_token'], $row['dkim_selector'], $row['dkim_public_key']),
            'dmarc' => 'active' === $stage ? $this->dkim->existingDmarc($row['domain']) : null,
            'may_operate' => $this->access->may(ClientVoter::OPERATE, $client), 'may_admin' => $this->access->may(ClientVoter::ADMIN, $client),
            'back' => $this->back($client, $request->query->getString('back')), 'check_seconds' => self::CHECK_SECONDS,
            'production' => 'production' === $this->smarthostEnv]);
    }

    /** The page's automatic check: the next DNS lookup the domain needs, and where it stands afterwards. */
    #[Route('/sending-domains/{domainId}/check', name: 'dashboard_client_domain_check', methods: ['POST'], requirements: ['domainId' => self::DOMAIN])]
    public function check(string $clientId, string $domainId, Request $request): JsonResponse
    {
        $client = $this->access->client($clientId, ClientVoter::OPERATE);
        $this->assertCsrf($request);
        $domain = $this->domain($client, $domainId);
        $checked = $domain->getLastCheckedAt();
        $message = null;
        if (null === $checked || $checked < Clock::now()->modify('-'.self::MIN_CHECK_SECONDS.' seconds')) {
            try {
                [, $message] = $this->next($domain, null);
            } catch (DomainRuleViolation $e) {
                $message = $e->getMessage();
            }
        }
        $this->em->refresh($domain);
        $row = $this->read->sendingDomains($clientId, $domainId)[0] ?? [];

        return new JsonResponse(['stage' => [] === $row ? null : DkimSetup::stage($row), 'message' => $message,
            'checked_at' => null === $domain->getLastCheckedAt() ? null : $domain->getLastCheckedAt()->format('H:i:s')],
            Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    /** The same check from a button (a page without JavaScript, or someone who does not want to wait). */
    #[Route('/sending-domains/{domainId}/verify', name: 'dashboard_client_domain_verify', methods: ['POST'], requirements: ['domainId' => self::DOMAIN])]
    public function verify(string $clientId, string $domainId, Request $request): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::OPERATE);
        $this->assertCsrf($request);
        $domain = $this->domain($client, $domainId);
        try {
            [$ok, $message] = $this->next($domain, $this->access->user());
            $this->addFlash($ok ? 'success' : 'error', $message);
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->toSteps($client, $domain, $request);
    }

    #[Route('/sending-domains/{domainId}/dkim', name: 'dashboard_client_domain_dkim', methods: ['POST'], requirements: ['domainId' => self::DOMAIN])]
    public function dkim(string $clientId, string $domainId, Request $request): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $this->assertCsrf($request);
        $domain = $this->domain($client, $domainId);
        try {
            $this->dkim->requestKey($domain, $this->access->user());
            $this->addFlash('success', \sprintf('A new signing key for %s is being made; this page shows its record in a minute or two.', $domain->getDomain()));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', 'The signing key was not asked for: '.$e->getMessage());
        }

        return $this->toSteps($client, $domain, $request);
    }

    /**
     * The next check the domain needs: its DKIM record once a key waits for it, otherwise the TXT
     * challenge (and, when that passes for the first time, the request for its signing key).
     * $user null: the page's automatic check, which records failures on the domain only.
     *
     * @return array{0: bool, 1: string} whether it moved on, and what to tell the person
     */
    private function next(SendingDomain $domain, ?User $user): array
    {
        if ($domain->isVerified() && DkimStatus::PendingDns === $domain->getDkimStatus() && null !== $domain->getDkimPublicKey()) {
            return $this->dkim->checkDns($domain, $user);
        }
        if ($domain->isVerified()) {
            return [true, \sprintf('%s is verified; nothing to look up now.', $domain->getDomain())];
        }
        $actor = null === $user ? AuditActor::system('domain-page') : AuditActor::user($user);
        $ok = $this->domains->verify($domain, $actor, null === $user);
        if (!$ok) {
            return [false, \sprintf('The TXT record of %s is not there yet: %s', $domain->getDomain(), $domain->getLastCheckError() ?? 'not found.')];
        }
        if (DkimStatus::NotConfigured === $domain->getDkimStatus()) {
            try {
                $this->dkim->requestKey($domain, $user);
            } catch (DomainRuleViolation $e) {
                return [true, \sprintf('%s is verified, but its signing key was not asked for: %s', $domain->getDomain(), $e->getMessage())];
            }
        }

        return [true, \sprintf('%s is verified: it is yours. Its signing key is being made.', $domain->getDomain())];
    }

    /**
     * The way back to the application that sent the person here (?back=): only an absolute address
     * on the origin of one of the account's connected applications, named after it.
     *
     * @return array{url: string, name: string}|null
     */
    private function back(Client $client, string $url): ?array
    {
        $parts = '' === $url || \strlen($url) > 2000 || 1 === preg_match('/[^\x21-\x7E]/', $url) ? false : parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !\in_array(strtolower($parts['scheme']), 'production' === $this->smarthostEnv ? ['https'] : ['https', 'http'], true)) {
            return null;
        }
        $origin = self::origin($url);
        foreach ($this->em->getConnection()->fetchAllAssociative(
            "SELECT application_name, redirect_uri FROM application_connections WHERE client_id = ? AND status = 'active' ORDER BY created_at DESC",
            [$client->getId()->toRfc4122()]) as $c) {
            if (self::origin((string) $c['redirect_uri']) === $origin) {
                return ['url' => $url, 'name' => (string) $c['application_name']];
            }
        }

        return null;
    }

    private static function origin(string $url): string
    {
        $p = (array) parse_url($url);

        return strtolower((string) ($p['scheme'] ?? '')).'://'.strtolower((string) ($p['host'] ?? '')).(isset($p['port']) ? ':'.$p['port'] : '');
    }

    /** @param array{url: string, name: string}|null $back */
    private function newForm(Client $client, string $name, ?string $error, ?array $back): Response
    {
        $count = (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM sending_domains WHERE client_id = ?', [$client->getId()->toRfc4122()]);

        return $this->render('dashboard/client/domain_new.html.twig', ['client' => $client, 'section' => 'domains', 'name' => $name,
            'error' => $error, 'back' => $back, 'room' => $count < $this->limits->maxSendingDomains($client), 'max_domains' => $this->limits->maxSendingDomains($client)],
            new Response('', null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function toSteps(Client $client, SendingDomain $domain, Request $request): RedirectResponse
    {
        $back = $this->back($client, $request->request->getString('back'));

        return $this->redirectToRoute('dashboard_client_domain', ['clientId' => $client->getId()->toRfc4122(), 'domainId' => $domain->getId()->toRfc4122()]
            + (null === $back ? [] : ['back' => $back['url']]));
    }

    private function domain(Client $client, string $domainId): SendingDomain
    {
        $domain = ClientReadModel::isUuid($domainId)
            ? $this->em->getRepository(SendingDomain::class)->findOneBy(['id' => Uuid::fromString($domainId), 'client' => $client]) : null;

        return $domain ?? throw new NotFoundHttpException('Not found.');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
