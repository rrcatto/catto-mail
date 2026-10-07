<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Audit\AuditActor;
use App\Client\ClientLifecycle;
use App\Client\ClientLimitAdministration;
use App\Client\ClientStatusTransitions;
use App\Dashboard\ClientAccess;
use App\Dashboard\ClientAccountReadModel;
use App\Dashboard\ClientReadModel;
use App\Dashboard\OperatorReadModel;
use App\Domain\DomainRuleViolation;
use App\Entity\ApiKey;
use App\Entity\Client;
use App\Entity\ClientLimits;
use App\Enum\ClientStatus;
use App\Enum\PolicyAcceptanceSource;
use App\Security\ApiKeyManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The operator's client account page and its actions (Phase 9). Each action names the
 * permission key it needs; lifecycle transitions need the key of the transition
 * (PLATFORM.CLIENT.APPROVE or PLATFORM.CLIENT.RESTRICT, ClientStatusTransitions) and a
 * reason. Every change goes through an audited service; this controller parses the
 * form, checks CSRF and reports the outcome.
 */
#[Route('/dashboard/operator/clients')]
final class OperatorClientController extends AbstractController
{
    public function __construct(
        private readonly OperatorReadModel $read,
        private readonly ClientAccountReadModel $account,
        private readonly ClientAccess $access,
        private readonly ClientLifecycle $lifecycle,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'dashboard_operator_client_create', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT.APPROVE')]
    public function create(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $r = $request->request;
        try {
            $client = $this->lifecycle->create($r->getString('company_name'), $r->getString('contact_email'), $r->getString('plan', 'standard'),
                ClientStatus::PendingApproval, $this->actor(), 'exempt' !== $r->getString('policy_acceptance'),
                $r->getString('billing_contact_email'), $r->getString('abuse_contact_email'));
            if ('' !== trim($r->getString('note'))) {
                $this->lifecycle->addNote($client, $r->getString('note'), $this->actor(), $this->access->user());
            }
            $this->addFlash('success', 'Client record created (pending approval).');

            return $this->redirectToRoute('dashboard_operator_client', ['id' => $client->getId()->toRfc4122()]);
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_operator_clients');
        }
    }

    #[Route('/{id}', name: 'dashboard_operator_client', methods: ['GET'])]
    #[IsGranted('PLATFORM.CLIENT.VIEW')]
    public function client(string $id): Response
    {
        $c = $this->read->client($id) ?? throw new NotFoundHttpException('Not found.');
        $client = $this->loadClient($id);
        $user = $this->access->user();
        $transitions = [];
        foreach (ClientStatusTransitions::targets($client->getStatus()) as $to) {
            $permission = (string) ClientStatusTransitions::permission($client->getStatus(), $to);
            $transitions[] = ['to' => $to->value, 'verb' => ClientStatusTransitions::verb($client->getStatus(), $to),
                'permission' => $permission, 'allowed' => $user->hasPermission($permission)];
        }

        return $this->render('dashboard/operator/client.html.twig', [
            'section' => 'clients', 'c' => $c, 'transitions' => $transitions,
            'limits' => $this->account->limits($client), 'limit_columns' => ClientLimits::FIELDS,
            'api_keys' => $this->account->apiKeys($id), 'endpoints' => $this->account->webhookEndpoints($id),
            'usage' => $this->account->usagePeriods($id), 'policy' => $this->account->policy($client),
            'statements' => $this->account->statements($id, true), 'notes' => $this->account->notes($id),
            'reputation' => $this->account->reputation($id), 'audit' => $this->account->audit($id),
        ]);
    }

    #[Route('/{id}/status', name: 'dashboard_operator_client_status', methods: ['POST'])]
    public function setStatus(string $id, Request $request): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        $to = ClientStatus::tryFrom($request->request->getString('status'));
        $permission = null === $to ? null : ClientStatusTransitions::permission($client->getStatus(), $to);
        if (null === $permission) {
            $this->addFlash('error', 'That status change is not possible from the current status.');
        } elseif (!$this->isGranted($permission)) {
            throw $this->createAccessDeniedException("Needs $permission.");
        } else {
            try {
                $this->lifecycle->changeStatus($client, $to, $this->actor(), $request->request->getString('note'), $this->access->user());
                $this->addFlash('success', \sprintf('Client status set to %s.', $to->value));
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->back($id);
    }

    #[Route('/{id}/account', name: 'dashboard_operator_client_account', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT.MANAGE')]
    public function account(string $id, Request $request): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        $r = $request->request;
        try {
            $changed = $this->lifecycle->updateAccount($client, $r->getString('company_name'), $r->getString('contact_email'),
                $r->getString('billing_contact_email'), $r->getString('abuse_contact_email'), $r->getString('plan'), $this->actor());
            $this->addFlash($changed ? 'success' : 'info', $changed ? 'Account details saved.' : 'Nothing changed.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($id);
    }

    #[Route('/{id}/notes', name: 'dashboard_operator_client_note', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT.MANAGE')]
    public function note(string $id, Request $request): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        try {
            $this->lifecycle->addNote($client, $request->request->getString('note'), $this->actor(), $this->access->user());
            $this->addFlash('success', 'Note added.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($id);
    }

    #[Route('/{id}/policy', name: 'dashboard_operator_client_policy', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT.MANAGE')]
    public function policy(string $id, Request $request): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        $r = $request->request;
        try {
            match ($r->getString('action')) {
                'record' => $this->lifecycle->recordPolicyAcceptance($client, $r->getString('policy_version'), PolicyAcceptanceSource::OperatorRecorded,
                    $this->actor(), null, $r->getString('reference')),
                'require', 'exempt' => $this->lifecycle->setPolicyAcceptanceRequired($client, 'require' === $r->getString('action'), $this->actor(), $r->getString('note')),
                default => throw new DomainRuleViolation('Unknown policy action.'),
            };
            $this->addFlash('success', 'Policy settings saved.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($id);
    }

    #[Route('/{id}/limits', name: 'dashboard_operator_client_limits', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT_LIMIT.MANAGE')]
    public function limits(string $id, Request $request, ClientLimitAdministration $limits): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        $values = [];
        foreach (ClientLimits::FIELDS as $column) {
            $raw = trim($request->request->getString($column));
            if ('' !== $raw && 1 !== preg_match('/^\d{1,12}$/', $raw)) {
                $this->addFlash('error', "$column must be a whole number, or empty for no client limit.");

                return $this->back($id);
            }
            $values[$column] = '' === $raw ? null : (int) $raw;
        }
        try {
            $changes = $limits->set($client, $values, $this->actor(), $request->request->getString('note'));
            $this->addFlash([] === $changes ? 'info' : 'success', [] === $changes ? 'Nothing changed.' : 'Limits saved: '.implode(', ', array_keys($changes)).'.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($id);
    }

    #[Route('/{id}/api-keys', name: 'dashboard_operator_client_key_create', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT_KEY.MANAGE')]
    public function createKey(string $id, Request $request, ApiKeyManager $keys): Response
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        try {
            [$key, $raw] = $keys->create($client, $request->request->getString('name'), $this->actor(), self::expiry($request));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->back($id);
        }

        $response = $this->render('dashboard/api_key_secret.html.twig', ['section' => 'clients', 'key' => $key, 'raw' => $raw,
            'back' => $this->generateUrl('dashboard_operator_client', ['id' => $id]), 'client_name' => $client->getCompanyName()]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/{id}/api-keys/{keyId}/revoke', name: 'dashboard_operator_client_key_revoke', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT_KEY.MANAGE')]
    public function revokeKey(string $id, string $keyId, Request $request, ApiKeyManager $keys): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        $key = ClientReadModel::isUuid($keyId) ? $this->em->getRepository(ApiKey::class)->findOneBy(['id' => Uuid::fromString($keyId), 'client' => $client]) : null;
        if (null === $key) {
            throw new NotFoundHttpException('Not found.');
        }
        $keys->revoke($key, $this->actor());
        $this->addFlash('success', \sprintf('API key %s… revoked.', $key->getKeyPrefix()));

        return $this->back($id);
    }

    /** Optional expiry date (YYYY-MM-DD, end of that UTC day) from a key form. */
    public static function expiry(Request $request): ?\DateTimeImmutable
    {
        $raw = trim($request->request->getString('expires_on'));
        if ('' === $raw) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            throw new DomainRuleViolation('The expiry date must be YYYY-MM-DD.');
        }

        return $date->modify('+1 day');
    }

    private function loadClient(string $id): Client
    {
        $client = ClientReadModel::isUuid($id) ? $this->em->find(Client::class, Uuid::fromString($id)) : null;

        return $client ?? throw new NotFoundHttpException('Not found.');
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute('dashboard_operator_client', ['id' => $id]);
    }

    private function actor(): AuditActor
    {
        return AuditActor::user($this->access->user());
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
