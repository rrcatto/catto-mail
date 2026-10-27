<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Audit\AuditActor;
use App\Client\AccountOpening;
use App\Client\ClientLifecycle;
use App\Client\ClientLimitAdministration;
use App\Client\ClientLimitPolicy;
use App\Client\ClientStatusTransitions;
use App\Client\Invitations;
use App\Client\PlanCatalog;
use App\Dashboard\ClientAccess;
use App\Dashboard\ClientAccountReadModel;
use App\Dashboard\ClientReadModel;
use App\Dashboard\Listing;
use App\Dashboard\OperatorReadModel;
use App\Domain\DomainRuleViolation;
use App\Entity\ApiKey;
use App\Entity\Client;
use App\Entity\ClientLimits;
use App\Enum\ClientStatus;
use App\Enum\PolicyAcceptanceSource;
use App\Security\ApiKeyManager;
use App\Util\InstallationTime;
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
 * The operator's Clients area (Phase 9; owner decisions 2026-10-27): the list and its views at
 * plain addresses, New client on its own page (AccountOpening), the plans (PlanCatalog), the
 * invitations (Invitations), and a client's page with its actions. Each action names the
 * permission key it needs; lifecycle transitions need the key of the transition
 * (PLATFORM.CLIENT.APPROVE or PLATFORM.CLIENT.RESTRICT, ClientStatusTransitions) and a reason.
 * Every change goes through an audited service; this controller parses the form, checks CSRF and
 * reports the outcome.
 */
#[Route('/dashboard/operator/clients')]
final class OperatorClientController extends AbstractController
{
    private const UUID = '[0-9a-fA-F-]{36}';
    private const PLAN_ID = '(?!new$)[a-z][a-z0-9-]{1,39}';
    /** The list's views: address => [label, filters, default sort, direction]. */
    public const VIEWS = [
        '' => ['All clients', [], 'created', 'desc'],
        'waiting' => ['Waiting for approval', ['status' => 'pending_approval'], 'status_changed', 'asc'],
        'active' => ['Active', ['status' => 'active'], 'created', 'desc'],
        'throttled' => ['Throttled', ['status' => 'throttled'], 'created', 'desc'],
        'suspended' => ['Suspended', ['status' => 'suspended'], 'created', 'desc'],
        'closed' => ['Closed', ['status' => 'closed'], 'created', 'desc'],
        'invited' => ['Opened by invitation', ['origin' => 'invitation'], 'created', 'desc'],
        'sign-ups' => ['Public sign-ups', ['origin' => 'public_application'], 'created', 'desc'],
    ];

    public function __construct(
        private readonly OperatorReadModel $read,
        private readonly ClientAccountReadModel $account,
        private readonly ClientAccess $access,
        private readonly ClientLifecycle $lifecycle,
        private readonly PlanCatalog $plans,
        private readonly ClientLimitPolicy $limitPolicy,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'dashboard_operator_clients', methods: ['GET'])]
    #[Route('/{view}', name: 'dashboard_operator_clients_view', methods: ['GET'], requirements: ['view' => 'waiting|active|throttled|suspended|closed|invited|sign-ups'])]
    #[IsGranted('PLATFORM.CLIENT.VIEW')]
    public function clients(Request $request, string $view = ''): Response
    {
        [$label, $filters, $sort, $dir] = self::VIEWS[$view];
        $filters += ['status' => '', 'origin' => '', 'q' => mb_substr(trim($request->query->getString('q')), 0, 200)];
        $listing = Listing::fromRequest($request, array_keys(OperatorReadModel::CLIENT_SORTS), $sort, $dir);

        return $this->render('dashboard/operator/clients.html.twig', ['section' => 'clients', 'view' => $view, 'view_label' => $label,
            'views' => self::VIEWS, 'page' => $this->read->clients($filters, $listing), 'filters' => $filters, 'listing' => $listing]);
    }

    #[Route('/new', name: 'dashboard_operator_client_new', methods: ['GET'])]
    #[IsGranted('PLATFORM.CLIENT.APPROVE')]
    public function newClient(): Response
    {
        return $this->clientForm([], null);
    }

    #[Route('/new', name: 'dashboard_operator_client_create', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT.APPROVE')]
    public function create(Request $request, AccountOpening $opening): Response
    {
        $this->assertCsrf($request);
        $r = $request->request;
        $values = ['company_name' => $r->getString('company_name'), 'manager_email' => $r->getString('manager_email'),
            'plan' => $r->getString('plan'), 'policy' => $r->getString('policy'), 'note' => $r->getString('note')];
        try {
            $opened = $opening->forManager($values['company_name'], $values['manager_email'], $values['plan'], $values['policy'], $values['note'],
                $this->access->user());
        } catch (DomainRuleViolation $e) {
            return $this->clientForm($values, $e->getMessage());
        }
        $client = $opened['client'];
        $this->addFlash($opened['mailed'] ? 'success' : 'error', $opened['mailed']
            ? \sprintf('%s is open%s. %s was emailed a link to sign in to it.', $client->getCompanyName(),
                ClientStatus::PendingApproval === $client->getStatus() ? ' as soon as its manager accepts the service policy' : '', $client->getContactEmail())
            : \sprintf('%s is open, but the email to %s could not be sent: they can sign in at the dashboard with that address.', $client->getCompanyName(), $client->getContactEmail()));

        return $this->redirectToRoute('dashboard_operator_client', ['id' => $client->getId()->toRfc4122()]);
    }

    #[Route('/plans', name: 'dashboard_operator_plans', methods: ['GET'])]
    #[IsGranted('PLATFORM.CLIENT.VIEW')]
    public function plans(): Response
    {
        return $this->render('dashboard/operator/plans.html.twig', ['section' => 'plans', 'plans' => $this->plans->all(),
            'columns' => ClientLimits::FIELDS]);
    }

    #[Route('/plans/new', name: 'dashboard_operator_plan_new', methods: ['GET'])]
    #[IsGranted('PLATFORM.CLIENT_LIMIT.MANAGE')]
    public function newPlan(): Response
    {
        return $this->planForm(null, '', array_fill_keys(ClientLimits::FIELDS, null), null);
    }

    #[Route('/plans/new', name: 'dashboard_operator_plan_create', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT_LIMIT.MANAGE')]
    public function createPlan(Request $request): Response
    {
        $this->assertCsrf($request);
        $name = $request->request->getString('name');
        $raw = $request->request->all('limits');
        try {
            $this->plans->create($name, PlanCatalog::limitsFromInput($raw), $this->actor());
        } catch (DomainRuleViolation $e) {
            return $this->planForm(null, $name, $raw, $e->getMessage());
        }
        $this->addFlash('success', \sprintf('Plan %s added. Choose it for a client on New client, an invitation or the client\'s page.', trim($name)));

        return $this->redirectToRoute('dashboard_operator_plans');
    }

    #[Route('/plans/{plan}', name: 'dashboard_operator_plan', methods: ['GET'], requirements: ['plan' => self::PLAN_ID])]
    #[IsGranted('PLATFORM.CLIENT_LIMIT.MANAGE')]
    public function editPlan(string $plan): Response
    {
        $p = $this->plans->find($plan) ?? throw new NotFoundHttpException('Not found.');

        return $this->planForm($p, $p['name'], $p['limits'], null);
    }

    #[Route('/plans/{plan}', name: 'dashboard_operator_plan_update', methods: ['POST'], requirements: ['plan' => self::PLAN_ID])]
    #[IsGranted('PLATFORM.CLIENT_LIMIT.MANAGE')]
    public function updatePlan(string $plan, Request $request): Response
    {
        $p = $this->plans->find($plan) ?? throw new NotFoundHttpException('Not found.');
        $this->assertCsrf($request);
        $name = $request->request->getString('name');
        $raw = $request->request->all('limits');
        try {
            $moved = $this->plans->update($plan, $name, PlanCatalog::limitsFromInput($raw), $this->actor());
        } catch (DomainRuleViolation $e) {
            return $this->planForm($p, $name, $raw, $e->getMessage());
        }
        $this->addFlash('success', \sprintf('Plan %s saved.%s', trim($name), $moved > 0 ? \sprintf(' The limits of %d client%s on it changed with it.', $moved, 1 === $moved ? '' : 's') : ''));

        return $this->redirectToRoute('dashboard_operator_plans');
    }

    #[Route('/plans/{plan}/delete', name: 'dashboard_operator_plan_delete', methods: ['POST'], requirements: ['plan' => self::PLAN_ID])]
    #[IsGranted('PLATFORM.CLIENT_LIMIT.MANAGE')]
    public function deletePlan(string $plan, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        try {
            $name = $this->plans->name($plan);
            $this->plans->delete($plan, $this->actor());
            $this->addFlash('success', \sprintf('Plan %s deleted.', $name));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_plans');
    }

    #[Route('/invitations', name: 'dashboard_operator_invitations', methods: ['GET'])]
    #[IsGranted('PLATFORM.CLIENT.APPROVE')]
    public function invitations(Invitations $invitations): Response
    {
        return $this->render('dashboard/operator/invitations.html.twig', ['section' => 'invitations', 'invitations' => $invitations->all(),
            'days' => Invitations::TTL_DAYS]);
    }

    #[Route('/invitations/new', name: 'dashboard_operator_invitation_new', methods: ['GET'])]
    #[IsGranted('PLATFORM.CLIENT.APPROVE')]
    public function newInvitation(): Response
    {
        return $this->invitationForm(['plan' => PlanCatalog::STANDARD, 'policy' => '', 'note' => ''], null);
    }

    #[Route('/invitations/new', name: 'dashboard_operator_invitation_create', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT.APPROVE')]
    public function createInvitation(Request $request, Invitations $invitations): Response
    {
        $this->assertCsrf($request);
        $values = ['plan' => $request->request->getString('plan'), 'policy' => $request->request->getString('policy'), 'note' => $request->request->getString('note')];
        try {
            if (!\in_array($values['policy'], [AccountOpening::MUST_ACCEPT, AccountOpening::EXEMPT], true)) {
                throw new DomainRuleViolation('Choose whether the person must accept the service policy.');
            }
            $made = $invitations->create($values['plan'], AccountOpening::MUST_ACCEPT === $values['policy'], $values['note'], $this->access->user());
        } catch (DomainRuleViolation $e) {
            return $this->invitationForm($values, $e->getMessage());
        }
        // The link exists only in this answer: it is never stored, so it is never cached either.
        $response = $this->render('dashboard/operator/invitation_link.html.twig', ['section' => 'invitations', 'url' => $made['url'],
            'note' => trim($values['note']), 'plan' => $this->plans->name($values['plan']), 'days' => Invitations::TTL_DAYS]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/invitations/{invitation}/revoke', name: 'dashboard_operator_invitation_revoke', methods: ['POST'], requirements: ['invitation' => self::UUID])]
    #[IsGranted('PLATFORM.CLIENT.APPROVE')]
    public function revokeInvitation(string $invitation, Request $request, Invitations $invitations): RedirectResponse
    {
        $this->assertCsrf($request);
        try {
            $invitations->revoke($invitation, $this->access->user());
            $this->addFlash('success', 'The invitation is withdrawn: its link no longer works.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_invitations');
    }

    #[Route('/{id}', name: 'dashboard_operator_client', methods: ['GET'], requirements: ['id' => self::UUID])]
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
            'plans' => $this->plans->choices(), 'custom' => $this->plans->isCustom($client),
            'limits' => $this->account->limits($client), 'limit_columns' => ClientLimits::FIELDS,
            'api_keys' => $this->account->apiKeys($id), 'endpoints' => $this->account->webhookEndpoints($id),
            'usage' => $this->account->usagePeriods($id), 'policy' => $this->account->policy($client),
            'statements' => $this->account->statements($id, true), 'notes' => $this->account->notes($id),
            'reputation' => $this->account->reputation($id), 'audit' => $this->account->audit($id),
        ]);
    }

    #[Route('/{id}/status', name: 'dashboard_operator_client_status', methods: ['POST'], requirements: ['id' => self::UUID])]
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

    #[Route('/{id}/account', name: 'dashboard_operator_client_account', methods: ['POST'], requirements: ['id' => self::UUID])]
    #[IsGranted('PLATFORM.CLIENT.MANAGE')]
    public function account(string $id, Request $request): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        try {
            $changed = $this->lifecycle->rename($client, $request->request->getString('company_name'), $this->actor());
            $this->addFlash($changed ? 'success' : 'info', $changed ? 'The organisation\'s name is changed.' : 'Nothing changed.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($id);
    }

    #[Route('/{id}/plan', name: 'dashboard_operator_client_plan', methods: ['POST'], requirements: ['id' => self::UUID])]
    #[IsGranted('PLATFORM.CLIENT_LIMIT.MANAGE')]
    public function setPlan(string $id, Request $request): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        try {
            $this->plans->assign($client, $request->request->getString('plan'), $this->actor());
            $this->addFlash('success', \sprintf('%s is on the %s plan, with its limits.', $client->getCompanyName(), $this->plans->name($client->getPlan())));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_client', ['id' => $id, 'tab' => 'limits']);
    }

    #[Route('/{id}/notes', name: 'dashboard_operator_client_note', methods: ['POST'], requirements: ['id' => self::UUID])]
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

    #[Route('/{id}/policy', name: 'dashboard_operator_client_policy', methods: ['POST'], requirements: ['id' => self::UUID])]
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

    #[Route('/{id}/limits', name: 'dashboard_operator_client_limits', methods: ['POST'], requirements: ['id' => self::UUID])]
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

    #[Route('/{id}/api-keys', name: 'dashboard_operator_client_key_create', methods: ['POST'], requirements: ['id' => self::UUID])]
    #[IsGranted('PLATFORM.CLIENT_KEY.MANAGE')]
    public function createKey(string $id, Request $request, ApiKeyManager $keys, InstallationTime $time): Response
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        try {
            [$key, $raw] = $keys->create($client, $request->request->getString('name'), $this->actor(), self::expiry($request, $time->zone));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->back($id);
        }

        $response = $this->render('dashboard/api_key_secret.html.twig', ['section' => 'clients', 'key' => $key, 'raw' => $raw,
            'back' => $this->generateUrl('dashboard_operator_client', ['id' => $id]), 'client_name' => $client->getCompanyName()]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/{id}/api-keys/{keyId}/revoke', name: 'dashboard_operator_client_key_revoke', methods: ['POST'], requirements: ['id' => self::UUID])]
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

    /** Optional expiry date (YYYY-MM-DD, the end of that day in the dashboard's time zone) from a key form. */
    public static function expiry(Request $request, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $raw = trim($request->request->getString('expires_on'));
        if ('' === $raw) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $zone);
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            throw new DomainRuleViolation('The expiry date must be YYYY-MM-DD.');
        }

        return $date->modify('+1 day');
    }

    /** @param array<string, string> $values */
    private function clientForm(array $values, ?string $error): Response
    {
        return $this->render('dashboard/operator/client_new.html.twig', ['section' => 'client_new', 'error' => $error,
            'values' => $values + ['company_name' => '', 'manager_email' => '', 'plan' => PlanCatalog::STANDARD, 'policy' => '', 'note' => ''],
            'plans' => $this->plans->all(), 'policy_version' => $this->lifecycle->policyVersionInForce()],
            new Response('', null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param array{id: string, name: string, limits: array<string, ?int>, clients: int, built_in: bool, updated_at: string}|null $plan
     * @param array<array-key, mixed>                                                                                            $limits
     */
    private function planForm(?array $plan, string $name, array $limits, ?string $error): Response
    {
        $ceilings = [];
        foreach (ClientLimits::FIELDS as $column) {
            $ceilings[$column] = $this->limitPolicy->ceiling($column);
        }

        return $this->render('dashboard/operator/plan_form.html.twig', ['section' => 'plans', 'plan' => $plan, 'name' => $name,
            'limits' => $limits, 'columns' => ClientLimits::FIELDS, 'ceilings' => $ceilings, 'error' => $error],
            new Response('', null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** @param array<string, string> $values */
    private function invitationForm(array $values, ?string $error): Response
    {
        return $this->render('dashboard/operator/invitation_new.html.twig', ['section' => 'invitations', 'error' => $error, 'values' => $values,
            'plans' => $this->plans->all(), 'policy_version' => $this->lifecycle->policyVersionInForce(), 'days' => Invitations::TTL_DAYS],
            new Response('', null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
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
