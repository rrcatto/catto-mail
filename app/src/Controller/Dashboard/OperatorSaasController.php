<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Audit\AuditActor;
use App\Dashboard\ClientAccess;
use App\Dashboard\ClientReadModel;
use App\Dashboard\Listing;
use App\Dashboard\OperatorReadModel;
use App\Domain\DomainRuleViolation;
use App\Entity\BillingStatement;
use App\Entity\Client;
use App\Enum\ClientAlertMetric;
use App\Reputation\AlertAdministration;
use App\Reputation\ReputationEvaluator;
use App\Usage\BillingStatementService;
use App\Usage\UsageExporter;
use App\Usage\UsagePeriod;
use App\Usage\UsageReconciliation;
use App\Usage\UsageReporting;
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
 * Operator pages of Phase 9: abuse and reputation monitoring (alerts, per-client
 * metrics) and usage and billing (period summaries, reconciliation, statements,
 * exports). Transport facts and inferred risk are shown separately; alerts never act on
 * a client - the client page holds the throttle and suspend actions.
 */
#[Route('/dashboard/operator')]
final class OperatorSaasController extends AbstractController
{
    public function __construct(
        private readonly OperatorReadModel $read,
        private readonly ClientAccess $access,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/alerts', name: 'dashboard_operator_alerts', methods: ['GET'])]
    #[IsGranted('PLATFORM.ABUSE.VIEW')]
    public function alerts(Request $request): Response
    {
        $filters = ClientDashboardController::filters($request, ['state', 'severity', 'metric', 'client']);
        $listing = Listing::fromRequest($request, array_keys(OperatorReadModel::ALERT_SORTS), 'observed');
        $window = 168 === $request->query->getInt('window') ? 168 : 24;

        return $this->render('dashboard/operator/alerts.html.twig', [
            'section' => 'alerts', 'page' => $this->read->alerts($filters, $listing), 'filters' => $filters, 'listing' => $listing,
            'metrics' => array_map(static fn (ClientAlertMetric $m): string => $m->value, ClientAlertMetric::cases()),
            'reputation' => $this->read->reputation($window), 'window' => $window, 'computed_at' => $this->read->reputationComputedAt(),
        ]);
    }

    #[Route('/alerts/evaluate', name: 'dashboard_operator_alerts_evaluate', methods: ['POST'])]
    #[IsGranted('PLATFORM.ABUSE.VIEW')]
    public function evaluate(Request $request, ReputationEvaluator $evaluator): RedirectResponse
    {
        $this->assertCsrf($request);
        $r = $evaluator->evaluate();
        $this->addFlash('success', $r['skipped'] ? 'Another evaluation is running; try again shortly.'
            : \sprintf('Evaluated %d clients: %d alerts opened, %d updated, %d resolved.', $r['evaluated_clients'], $r['opened'], $r['updated'], $r['resolved']));

        return $this->redirectToRoute('dashboard_operator_alerts');
    }

    #[Route('/alerts/{id}/acknowledge', name: 'dashboard_operator_alert_ack', methods: ['POST'])]
    #[IsGranted('PLATFORM.ABUSE.MANAGE')]
    public function acknowledge(string $id, Request $request, AlertAdministration $alerts): RedirectResponse
    {
        $this->assertCsrf($request);
        try {
            $alerts->acknowledge($id, $this->access->user(), $request->request->getString('note'));
            $this->addFlash('success', 'Alert acknowledged.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->safeReturn($request, $this->generateUrl('dashboard_operator_alerts')));
    }

    #[Route('/usage', name: 'dashboard_operator_usage', methods: ['GET'])]
    #[IsGranted('PLATFORM.USAGE.VIEW')]
    public function usage(Request $request, UsageReporting $reporting): Response
    {
        try {
            $period = self::period($request);
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
            $period = UsagePeriod::named('current_month');
        }
        $statements = $this->em->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT s.id::text AS id, s.client_id::text AS client_id, c.company_name, s.status, s.reconciliation_status,
                   s.external_reference, s.finalized_at, s.exported_at,
                   (SELECT jsonb_object_agg(l.usage_type, l.quantity) FROM billing_statement_lines l WHERE l.statement_id = s.id) AS totals
              FROM billing_statements s JOIN clients c ON c.id = s.client_id
             WHERE s.period_start = ? AND s.period_end = ? ORDER BY c.company_name, s.created_at
            SQL, [$period->start->format('Y-m-d'), $period->end->format('Y-m-d')]);
        foreach ($statements as $i => $st) {
            $statements[$i]['totals'] = null === $st['totals'] ? [] : json_decode((string) $st['totals'], true, 8, \JSON_THROW_ON_ERROR);
        }
        $after = $request->query->getString('after');
        $rows = $reporting->allClients($period, 101, '' === $after ? null : mb_substr($after, 0, 200));
        $next = \count($rows) > 100 ? $rows[99]['company_name'] : null;

        return $this->render('dashboard/operator/usage.html.twig', [
            'section' => 'usage', 'period' => $period, 'rows' => \array_slice($rows, 0, 100), 'next' => $next,
            'query' => array_filter(['period' => $request->query->getString('period'), 'month' => $request->query->getString('month'),
                'from' => $request->query->getString('from'), 'to' => $request->query->getString('to')]),
            'statements' => $statements,
        ]);
    }

    #[Route('/usage/clients/{id}/reconcile', name: 'dashboard_operator_usage_reconcile', methods: ['POST'])]
    #[IsGranted('PLATFORM.USAGE.VIEW')]
    public function reconcile(string $id, Request $request, UsageReconciliation $reconciliation): Response
    {
        $this->assertCsrf($request);
        $client = $this->loadClient($id);
        try {
            $period = self::period($request, true);
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_operator_usage');
        }

        return $this->render('dashboard/operator/reconciliation.html.twig', ['section' => 'usage', 'client' => $client,
            'period' => $period, 'r' => $reconciliation->reconcile($id, $period)]);
    }

    #[Route('/usage/clients/{id}/export', name: 'dashboard_operator_usage_export', methods: ['POST'])]
    #[IsGranted('PLATFORM.USAGE.EXPORT')]
    public function export(string $id, Request $request, UsageExporter $exporter): Response
    {
        $this->assertCsrf($request);
        $client = $this->loadClient($id);
        try {
            $period = self::period($request, true);
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_operator_usage');
        }
        $export = $exporter->export($client, $period, AuditActor::user($this->access->user()));
        $csv = 'csv' === $request->request->getString('format');
        $name = \sprintf('usage-%s-%s.%s', $id, substr((string) $export['period']['start'], 0, 10), $csv ? 'csv' : 'json');
        $response = new Response($csv ? UsageExporter::csv($export) : json_encode($export, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n", 200,
            ['Content-Type' => $csv ? 'text/csv; charset=utf-8' : 'application/json', 'Content-Disposition' => 'attachment; filename="'.$name.'"',
             'Cache-Control' => 'no-store, private']);

        return $response;
    }

    #[Route('/usage/statements', name: 'dashboard_operator_statements_prepare', methods: ['POST'])]
    #[IsGranted('PLATFORM.USAGE.EXPORT')]
    public function prepare(Request $request, BillingStatementService $statements): RedirectResponse
    {
        $this->assertCsrf($request);
        try {
            $period = UsagePeriod::month($request->request->getString('month'));
            $ids = $this->em->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT client_id::text FROM usage_records WHERE occurred_at >= ? AND occurred_at < ? ORDER BY 1',
                [$period->startSql(), $period->endSql()]);
            $n = 0;
            foreach ($ids as $clientId) {
                try {
                    $statements->prepare($this->loadClient($clientId), $period, AuditActor::user($this->access->user()));
                    ++$n;
                } catch (DomainRuleViolation $e) {
                    $this->addFlash('info', $e->getMessage());
                }
            }
            $this->addFlash('success', \sprintf('%d draft statements prepared for %s.', $n, $period->label));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_operator_usage');
        }

        return $this->redirectToRoute('dashboard_operator_usage', ['month' => $request->request->getString('month')]);
    }

    #[Route('/usage/statements/{id}/{action}', name: 'dashboard_operator_statement_action', methods: ['POST'], requirements: ['action' => 'finalize|export|void'])]
    #[IsGranted('PLATFORM.USAGE.EXPORT')]
    public function statementAction(string $id, string $action, Request $request, BillingStatementService $statements): RedirectResponse
    {
        $this->assertCsrf($request);
        $statement = ClientReadModel::isUuid($id) ? $this->em->find(BillingStatement::class, Uuid::fromString($id)) : null;
        if (null === $statement) {
            throw new NotFoundHttpException('Not found.');
        }
        $actor = AuditActor::user($this->access->user());
        try {
            match ($action) {
                'finalize' => $statements->finalize($statement, $actor),
                'export' => $statements->markExported($statement, $request->request->getString('external_reference'), $actor),
                default => $statements->void($statement, $request->request->getString('note'), $actor),
            };
            $this->addFlash('success', \sprintf('Statement %s.', 'export' === $action ? 'marked exported' : $action.'d'));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_usage', ['from' => $statement->getPeriodStart()->format('Y-m-d'),
            'to' => $statement->getPeriodEnd()->format('Y-m-d')]);
    }

    private static function period(Request $request, bool $post = false): UsagePeriod
    {
        $bag = $post ? $request->request : $request->query;
        if ('' !== $bag->getString('from') || '' !== $bag->getString('to')) {
            return UsagePeriod::custom($bag->getString('from'), $bag->getString('to'));
        }
        if ('' !== $bag->getString('month')) {
            return UsagePeriod::month($bag->getString('month'));
        }

        return UsagePeriod::named('' === $bag->getString('period') ? 'current_month' : $bag->getString('period'));
    }

    private function loadClient(string $id): Client
    {
        $client = ClientReadModel::isUuid($id) ? $this->em->find(Client::class, Uuid::fromString($id)) : null;

        return $client ?? throw new NotFoundHttpException('Not found.');
    }

    /** Only same-site dashboard paths are followed after an action. */
    private function safeReturn(Request $request, string $default): string
    {
        $to = $request->request->getString('return_to');

        return 1 === preg_match('#^/dashboard/operator/[A-Za-z0-9/_?=&%.-]*$#', $to) && !str_contains($to, '//') ? $to : $default;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
