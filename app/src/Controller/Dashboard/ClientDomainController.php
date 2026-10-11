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
use App\Enum\ClientStatus;
use App\Enum\DkimStatus;
use App\Security\ClientVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * A client's sending domains in its own dashboard (owner decision 2026-10-11), set up without
 * the operator:
 *
 *   add          a client admin adds a domain, within the client's limit (ClientLimitPolicy:
 *                min(own limit, installation ceiling)), and sees its TXT challenge;
 *   verify       "Check DNS now" (members and admins): the TXT challenge (SendingDomainService::verify);
 *                once a domain is verified, its DKIM key is requested from the host agent at once
 *                (DkimSetup::requestKey). For a domain waiting for its DKIM record the same button
 *                looks the record up and, when it matches, asks the host agent to activate it
 *                (DkimSetup::checkDns); the host agent also does this by itself every few minutes;
 *   dkim         a client admin requests the key again (after a failed generation, or for a domain
 *                verified before this page could do it).
 *
 * ClientAccess answers 404 for a client the user may not see, and a domain is loaded only with
 * the client's own id, so another client's domain id is "not found" too. Every action is POST
 * with CSRF and audited by the services it calls.
 */
#[Route('/dashboard/c/{clientId}', requirements: ['clientId' => '[0-9a-fA-F-]{36}'])]
final class ClientDomainController extends AbstractController
{
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
        $domains = [];
        foreach ($this->read->sendingDomains($clientId) as $d) {
            $domains[] = $d + ['stage' => DkimSetup::stage($d),
                'records' => $this->dkim->records($d['domain'], $d['verification_token'], $d['dkim_selector'], $d['dkim_public_key'])];
        }

        return $this->render('dashboard/client/domains.html.twig', ['client' => $client, 'section' => 'domains', 'domains' => $domains,
            'steps' => DkimSetup::STEPS, 'max_domains' => $this->limits->maxSendingDomains($client),
            'may_operate' => $this->access->may(ClientVoter::OPERATE, $client), 'may_admin' => $this->access->may(ClientVoter::ADMIN, $client),
            'may_add' => !\in_array($client->getStatus(), [ClientStatus::Suspended, ClientStatus::Closed], true),
            // In production the delivery daemon also needs active DKIM (sending_domains.live_sending_rule).
            'production' => 'production' === $this->smarthostEnv]);
    }

    #[Route('/sending-domains', name: 'dashboard_client_domain_add', methods: ['POST'])]
    public function add(string $clientId, Request $request): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $this->assertCsrf($request);
        try {
            if (\in_array($client->getStatus(), [ClientStatus::Suspended, ClientStatus::Closed], true)) {
                throw new DomainRuleViolation('A suspended or closed client cannot add sending domains.');
            }
            $domain = $this->domains->register($client, $request->request->getString('domain'), AuditActor::user($this->access->user()));
            $this->addFlash('success', \sprintf('%s is added. Publish its TXT record, then press Check DNS now.', $domain->getDomain()));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_client_domains', ['clientId' => $clientId]);
    }

    /** The next DNS check the domain needs: the TXT challenge, or its DKIM record. */
    #[Route('/sending-domains/{domainId}/verify', name: 'dashboard_client_domain_verify', methods: ['POST'])]
    public function verify(string $clientId, string $domainId, Request $request): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::OPERATE);
        $this->assertCsrf($request);
        $domain = $this->domain($client, $domainId);
        $user = $this->access->user();
        try {
            if ($domain->isVerified() && DkimStatus::PendingDns === $domain->getDkimStatus() && null !== $domain->getDkimPublicKey()) {
                [$ok, $message] = $this->dkim->checkDns($domain, $user);
                $this->addFlash($ok ? 'success' : 'error', $message);
            } else {
                $wasVerified = $domain->isVerified();
                $ok = $this->domains->verify($domain, AuditActor::user($user));
                $this->addFlash($ok ? 'success' : 'error', $ok ? \sprintf('%s is verified.', $domain->getDomain())
                    : \sprintf('%s could not be verified: %s', $domain->getDomain(), $domain->getLastCheckError() ?? 'the TXT record was not found.'));
                if ($ok && !$wasVerified && DkimStatus::NotConfigured === $domain->getDkimStatus()) {
                    $this->requestKey($domain);
                }
            }
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_client_domains', ['clientId' => $clientId]);
    }

    #[Route('/sending-domains/{domainId}/dkim', name: 'dashboard_client_domain_dkim', methods: ['POST'])]
    public function dkim(string $clientId, string $domainId, Request $request): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $this->assertCsrf($request);
        $this->requestKey($this->domain($client, $domainId));

        return $this->redirectToRoute('dashboard_client_domains', ['clientId' => $clientId]);
    }

    private function requestKey(SendingDomain $domain): void
    {
        try {
            $this->dkim->requestKey($domain, $this->access->user());
            $this->addFlash('success', \sprintf('A DKIM key for %s is requested. The host agent generates it within a minute or two; then its DNS records appear here.', $domain->getDomain()));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', 'DKIM key not requested: '.$e->getMessage());
        }
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
