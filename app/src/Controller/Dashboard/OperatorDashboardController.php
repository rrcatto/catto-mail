<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Audit\AuditActor;
use App\Client\AccountAdministration;
use App\Dashboard\ClientAccess;
use App\Dashboard\ClientReadModel;
use App\Dashboard\Listing;
use App\Dashboard\OperatorReadModel;
use App\Domain\DomainRuleViolation;
use App\Dsn\UnmatchedDsnAdministration;
use App\Entity\Client;
use App\Enum\ClientStatus;
use App\Enum\SuppressionReason;
use App\Enum\SuppressionScopeType;
use App\Suppression\SuppressionAdministration;
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
 * Operator dashboard (Phase 6, spec user_interfaces.operator_dashboard). Each page
 * and action states the permission key it needs (App\Access\PermissionCatalog);
 * a user without it gets 403.
 * Every change goes through the existing audited application services
 * (AccountAdministration, SuppressionAdministration, UnmatchedDsnAdministration);
 * this controller only parses the form, checks CSRF and reports the outcome.
 */
#[Route('/dashboard/operator')]
final class OperatorDashboardController extends AbstractController
{
    public function __construct(
        private readonly OperatorReadModel $read,
        private readonly ClientAccess $access,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'dashboard_operator_overview', methods: ['GET'])]
    #[IsGranted('PLATFORM.OVERVIEW.VIEW')]
    public function overview(): Response
    {
        return $this->page('overview.html.twig', 'overview', ['o' => $this->read->overview()]);
    }

    #[Route('/clients', name: 'dashboard_operator_clients', methods: ['GET'])]
    #[IsGranted('PLATFORM.CLIENT.VIEW')]
    public function clients(Request $request): Response
    {
        $filters = ClientDashboardController::filters($request, ['status', 'q']);
        $listing = Listing::fromRequest($request, array_keys(OperatorReadModel::CLIENT_SORTS), 'created');

        return $this->page('clients.html.twig', 'clients', [
            'page' => $this->read->clients($filters, $listing), 'filters' => $filters, 'listing' => $listing]);
    }

    #[Route('/clients/{id}', name: 'dashboard_operator_client', methods: ['GET'])]
    #[IsGranted('PLATFORM.CLIENT.VIEW')]
    public function client(string $id): Response
    {
        $client = $this->read->client($id) ?? throw new NotFoundHttpException('Not found.');

        return $this->page('client.html.twig', 'clients', ['c' => $client, 'statuses' => ClientStatus::cases()]);
    }

    #[Route('/clients/{id}/status', name: 'dashboard_operator_client_status', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT.MANAGE')]
    public function setClientStatus(string $id, Request $request, AccountAdministration $accounts): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        $status = ClientStatus::tryFrom($request->request->getString('status'));
        if (null === $status) {
            $this->addFlash('error', 'Unknown client status.');
        } elseif ($status === $client->getStatus()) {
            $this->addFlash('info', 'The client already has that status.');
        } else {
            try {
                $accounts->setClientStatus($client, $status, AuditActor::user($this->access->user()));
                $this->addFlash('success', \sprintf('Client status set to %s.', $status->value));
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('dashboard_operator_client', ['id' => $id]);
    }

    #[Route('/clients/{id}/global-suppressions', name: 'dashboard_operator_client_capability', methods: ['POST'])]
    #[IsGranted('PLATFORM.CLIENT.MANAGE')]
    public function setCapability(string $id, Request $request, SuppressionAdministration $suppressions): RedirectResponse
    {
        $client = $this->loadClient($id);
        $this->assertCsrf($request);
        try {
            $changed = $suppressions->setGlobalSuppressionCapability($client, 'enable' === $request->request->getString('setting'),
                AuditActor::user($this->access->user()), $request->request->getString('note'));
            $this->addFlash($changed ? 'success' : 'info', $changed ? 'Global opt-out capability updated.' : 'Nothing changed.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_client', ['id' => $id]);
    }

    #[Route('/unmatched-dsns', name: 'dashboard_operator_dsns', methods: ['GET'])]
    #[IsGranted('PLATFORM.DSN.VIEW')]
    public function unmatchedDsns(Request $request): Response
    {
        $filters = ClientDashboardController::filters($request, ['status', 'classification']);
        if (!$request->query->has('status')) {
            $filters['status'] = 'open';
        }
        $listing = Listing::fromRequest($request, array_keys(OperatorReadModel::DSN_SORTS), 'received', 'asc');

        return $this->page('dsns.html.twig', 'dsns', [
            'page' => $this->read->unmatchedDsns($filters, $listing), 'filters' => $filters, 'listing' => $listing]);
    }

    #[Route('/unmatched-dsns/{id}', name: 'dashboard_operator_dsn', methods: ['GET'])]
    #[IsGranted('PLATFORM.DSN.VIEW')]
    public function unmatchedDsn(string $id, UnmatchedDsnAdministration $dsns): Response
    {
        $dsn = $dsns->find($id) ?? throw new NotFoundHttpException('Not found.');
        $detail = $dsn->getDetail();

        return $this->page('dsn.html.twig', 'dsns', [
            'dsn' => $dsn, 'detail' => array_diff_key($detail, ['candidates' => true, 'resolution_failures' => true]),
            'candidates' => $this->read->candidateMessages(\is_array($detail['candidates'] ?? null) ? $detail['candidates'] : []),
            'failures' => \is_array($detail['resolution_failures'] ?? null) ? $detail['resolution_failures'] : [],
            'matched' => null === $dsn->getMatchedMessage() ? null : $this->read->message($dsn->getMatchedMessage()->getId()->toRfc4122()),
        ]);
    }

    /** Records the operator's candidate; the Go delivery daemon applies it (never this request). */
    #[Route('/unmatched-dsns/{id}/match', name: 'dashboard_operator_dsn_match', methods: ['POST'])]
    #[IsGranted('PLATFORM.DSN.MANAGE')]
    public function matchDsn(string $id, Request $request, UnmatchedDsnAdministration $dsns): RedirectResponse
    {
        $dsn = $dsns->find($id) ?? throw new NotFoundHttpException('Not found.');
        $this->assertCsrf($request);
        try {
            $dsns->requestMatch($dsn, trim($request->request->getString('message_id')), $this->access->user(), $request->request->getString('note'));
            $this->addFlash('success', 'Match requested. The delivery daemon applies it and records the outcome.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_dsn', ['id' => $id]);
    }

    #[Route('/unmatched-dsns/{id}/dismiss', name: 'dashboard_operator_dsn_dismiss', methods: ['POST'])]
    #[IsGranted('PLATFORM.DSN.MANAGE')]
    public function dismissDsn(string $id, Request $request, UnmatchedDsnAdministration $dsns): RedirectResponse
    {
        $dsn = $dsns->find($id) ?? throw new NotFoundHttpException('Not found.');
        $this->assertCsrf($request);
        try {
            $dsns->dismiss($dsn, $request->request->getString('reason'), $this->access->user());
            $this->addFlash('success', 'The DSN was dismissed.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_dsn', ['id' => $id]);
    }

    #[Route('/suppressions', name: 'dashboard_operator_suppressions', methods: ['GET'])]
    #[IsGranted('PLATFORM.SUPPRESSION.VIEW')]
    public function suppressions(Request $request): Response
    {
        $filters = ClientDashboardController::filters($request, ['address', 'reason', 'state', 'scope']);
        if (!$request->query->has('state')) {
            $filters['state'] = 'active';
        }
        $listing = Listing::fromRequest($request, array_keys(OperatorReadModel::SUPPRESSION_SORTS), 'created');

        return $this->page('suppressions.html.twig', 'suppressions', [
            'page' => $this->read->suppressions($filters, $listing), 'filters' => $filters, 'listing' => $listing,
            'operator_reasons' => [SuppressionReason::OperatorBlock, SuppressionReason::ClientAbuseBlock]]);
    }

    /** An operator block (SuppressionAdministration::create; global unless a client is named). */
    #[Route('/suppressions', name: 'dashboard_operator_suppression_create', methods: ['POST'])]
    #[IsGranted('PLATFORM.SUPPRESSION.MANAGE')]
    public function createSuppression(Request $request, SuppressionAdministration $suppressions): RedirectResponse
    {
        $this->assertCsrf($request);
        $scope = null;
        $clientId = trim($request->request->getString('client_id'));
        try {
            if ('' !== $clientId) {
                $scope = $this->loadClient($clientId);
            }
            $reason = SuppressionReason::tryFrom($request->request->getString('reason')) ?? throw new DomainRuleViolation('Choose a reason.');
            $scopeType = SuppressionScopeType::tryFrom($request->request->getString('scope_type')) ?? throw new DomainRuleViolation('Choose address or domain.');
            $days = trim($request->request->getString('expires_in_days'));
            $s = $suppressions->create($scope, $request->request->getString('value'), $scopeType, $reason,
                '' === $days ? null : (ctype_digit($days) ? (int) $days : throw new DomainRuleViolation('Expiry must be a whole number of days.')),
                $this->access->user(), $request->request->getString('note'));
            $this->addFlash('success', \sprintf('Suppression created for %s.', $s->getAddressOrDomain()));
        } catch (DomainRuleViolation|NotFoundHttpException $e) {
            $this->addFlash('error', $e instanceof NotFoundHttpException ? 'No such client.' : $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_suppressions');
    }

    #[Route('/suppressions/{id}/lift', name: 'dashboard_operator_suppression_lift', methods: ['POST'])]
    #[IsGranted('PLATFORM.SUPPRESSION.MANAGE')]
    public function liftSuppression(string $id, Request $request, SuppressionAdministration $suppressions): RedirectResponse
    {
        $suppression = $suppressions->find($id) ?? throw new NotFoundHttpException('Not found.');
        $this->assertCsrf($request);
        try {
            $lifted = $suppressions->lift($suppression, $this->access->user(), $request->request->getString('note'));
            $this->addFlash($lifted ? 'success' : 'info', $lifted ? 'The suppression was lifted (the row is kept).' : 'It was already lifted.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_suppressions', array_filter([
            'address' => $request->request->getString('return_address')]));
    }

    #[Route('/audit', name: 'dashboard_operator_audit', methods: ['GET'])]
    #[IsGranted('PLATFORM.AUDIT.VIEW')]
    public function audit(Request $request): Response
    {
        $filters = ClientDashboardController::filters($request, ['action', 'actor', 'actor_type', 'target_type', 'target_id', 'from', 'to']);
        $listing = Listing::fromRequest($request, array_keys(OperatorReadModel::AUDIT_SORTS), 'occurred');

        return $this->page('audit.html.twig', 'audit', [
            'page' => $this->read->audit($filters, $listing), 'filters' => $filters, 'listing' => $listing,
            'actions' => $this->read->auditActions()]);
    }

    #[Route('/webhooks', name: 'dashboard_operator_webhooks', methods: ['GET'])]
    #[IsGranted('PLATFORM.WEBHOOK.VIEW')]
    public function webhooks(Request $request): Response
    {
        $filters = ClientDashboardController::filters($request, ['state', 'status', 'event_type', 'client']);
        $listing = Listing::fromRequest($request, array_keys(OperatorReadModel::OUTBOX_SORTS_DELIVERIES), 'created');
        $events = Listing::fromRequest(new Request(['cursor' => $request->query->getString('events_cursor')]), array_keys(OperatorReadModel::OUTBOX_SORTS), 'created');

        return $this->page('webhooks.html.twig', 'webhooks', [
            'page' => $this->read->webhookDeliveries($filters, $listing), 'events' => $this->read->webhookEvents($filters, $events),
            'filters' => $filters, 'listing' => $listing, 'summary' => $this->read->webhookSummary()]);
    }

    /** @param array<string, mixed> $vars */
    private function page(string $template, string $section, array $vars): Response
    {
        return $this->render('dashboard/operator/'.$template, $vars + ['section' => $section]);
    }

    private function loadClient(string $id): Client
    {
        $client = ClientReadModel::isUuid($id) ? $this->em->find(Client::class, Uuid::fromString($id)) : null;

        return $client ?? throw new NotFoundHttpException('Not found.');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
