<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Api\ApiProblem;
use App\Audit\AuditActor;
use App\Dashboard\ClientAccess;
use App\Dashboard\ClientReadModel;
use App\Dashboard\Listing;
use App\Dashboard\OverviewPeriod;
use App\Dashboard\TrendCharts;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Security\ClientVoter;
use App\Suppression\AccountBlocks;
use App\Suppression\GlobalSuppressionService;
use App\Util\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Client dashboard (Phase 6, spec user_interfaces.client_dashboard). Every route
 * names the client; ClientAccess answers 404 for a client the user may not see,
 * and every query carries that client's id, so an id of another client in any
 * URL is "not found". Reads need CLIENT_VIEW; lifting the client's own global
 * opt-out reuses the existing service (CLIENT_ADMIN plus the Phase 5 rules).
 * Sending domains: ClientDomainController.
 */
#[Route('/dashboard/c/{clientId}', requirements: ['clientId' => '[0-9a-fA-F-]{36}'])]
final class ClientDashboardController extends AbstractController
{
    public const CSRF_ID = 'dashboard-action';

    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientReadModel $read,
        private readonly \App\Dashboard\ClientAccountReadModel $account,
        private readonly \App\Util\InstallationTime $time,
        private readonly string $smarthostEnv,
    ) {
    }

    #[Route('', name: 'dashboard_client_overview', methods: ['GET'])]
    public function overview(string $clientId, Request $request): Response
    {
        $client = $this->access->client($clientId);
        $period = OverviewPeriod::fromKey($request->query->getString('period', OverviewPeriod::DEFAULT), $this->time->zone);
        $o = $this->read->overview($clientId, $period);

        return $this->page('client/overview.html.twig', $client, 'overview', ['o' => $o, 'period' => $period,
            'periods' => array_map(static fn (array $p): string => $p[0], OverviewPeriod::PERIODS),
            'kpis' => TrendCharts::kpis($o['trends'], $period),
            'limits' => $this->account->limits($client), 'policy' => $this->account->policy($client),
            'may_admin' => $this->access->may(ClientVoter::ADMIN, $client),
            'steps' => $this->account->nextSteps($client, 'production' === $this->smarthostEnv)]);
    }

    #[Route('/validation-jobs', name: 'dashboard_client_validation_jobs', methods: ['GET'])]
    public function validationJobs(string $clientId, Request $request): Response
    {
        $client = $this->access->client($clientId);
        $filters = self::filters($request, ['status', 'external_reference', 'from', 'to']);
        $listing = Listing::fromRequest($request, array_keys(ClientReadModel::VALIDATION_JOB_SORTS), 'submitted');

        return $this->page('client/validation_jobs.html.twig', $client, 'validation', [
            'page' => $this->read->validationJobs($clientId, $filters, $listing), 'filters' => $filters, 'listing' => $listing]);
    }

    #[Route('/validation-jobs/{jobId}', name: 'dashboard_client_validation_job', methods: ['GET'])]
    public function validationJob(string $clientId, string $jobId, Request $request): Response
    {
        $client = $this->access->client($clientId);
        $job = $this->read->validationJob($clientId, $jobId) ?? throw new NotFoundHttpException('Not found.');
        $filters = self::filters($request, ['classification', 'syntax', 'domain', 'smtp', 'confidence', 'role', 'disposable', 'typo', 'catch_all']);
        $listing = Listing::fromRequest($request, array_keys(ClientReadModel::ADDRESS_SORTS), 'input', 'asc');

        return $this->page('client/validation_job.html.twig', $client, 'validation', [
            'job' => $job, 'page' => $this->read->validationAddresses($clientId, $jobId, $filters, $listing),
            'filters' => $filters, 'listing' => $listing]);
    }

    /**
     * The job's (filtered) results as CSV, streamed in keyset batches. Columns
     * are exactly the OpenAPI ValidationAddress fields, in that order; cells that
     * a spreadsheet would treat as a formula are prefixed with an apostrophe.
     */
    #[Route('/validation-jobs/{jobId}/results.csv', name: 'dashboard_client_validation_export', methods: ['GET'])]
    public function exportValidationResults(string $clientId, string $jobId, Request $request): Response
    {
        $this->access->client($clientId);
        $this->read->validationJob($clientId, $jobId) ?? throw new NotFoundHttpException('Not found.');
        $filters = self::filters($request, ['classification', 'syntax', 'domain', 'smtp', 'confidence', 'role', 'disposable', 'typo', 'catch_all']);
        $rows = $this->read->exportValidationAddresses($clientId, $jobId, $filters);

        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ClientReadModel::EXPORT_COLUMNS, ',', '"', '');
            foreach ($rows as $row) {
                $line = [];
                foreach (ClientReadModel::EXPORT_COLUMNS as $column) {
                    $line[] = self::csvCell($column, $row[$column] ?? null);
                }
                fputcsv($out, $line, ',', '"', '');
                flush();
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="validation-'.$jobId.'.csv"');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/send-jobs', name: 'dashboard_client_send_jobs', methods: ['GET'])]
    public function sendJobs(string $clientId, Request $request): Response
    {
        $client = $this->access->client($clientId);
        $filters = self::filters($request, ['status', 'external_reference', 'from', 'to']);
        $listing = Listing::fromRequest($request, array_keys(ClientReadModel::SEND_JOB_SORTS), 'created');

        return $this->page('client/send_jobs.html.twig', $client, 'sending', [
            'page' => $this->read->sendJobs($clientId, $filters, $listing), 'filters' => $filters, 'listing' => $listing]);
    }

    #[Route('/send-jobs/{jobId}', name: 'dashboard_client_send_job', methods: ['GET'])]
    public function sendJob(string $clientId, string $jobId, Request $request): Response
    {
        $client = $this->access->client($clientId);
        $job = $this->read->sendJob($clientId, $jobId) ?? throw new NotFoundHttpException('Not found.');
        $filters = self::filters($request, ['status', 'recipient']);
        $listing = Listing::fromRequest($request, array_keys(ClientReadModel::MESSAGE_SORTS), 'created', 'asc');

        return $this->page('client/send_job.html.twig', $client, 'sending', [
            'job' => $job, 'page' => $this->read->messages($clientId, $jobId, $filters, $listing), 'filters' => $filters, 'listing' => $listing]);
    }

    #[Route('/messages/{messageId}', name: 'dashboard_client_message', methods: ['GET'])]
    public function message(string $clientId, string $messageId, Request $request): Response
    {
        $client = $this->access->client($clientId);
        $message = $this->read->message($clientId, $messageId) ?? throw new NotFoundHttpException('Not found.');
        $listing = Listing::fromRequest($request, array_keys(ClientReadModel::EVENT_SORTS), 'occurred', 'asc', 100);

        return $this->page('client/message.html.twig', $client, 'sending', [
            'message' => $message, 'page' => $this->read->events($clientId, $messageId, $listing), 'listing' => $listing]);
    }

    #[Route('/suppressions', name: 'dashboard_client_suppressions', methods: ['GET'])]
    public function suppressions(string $clientId, Request $request): Response
    {
        $client = $this->access->client($clientId);
        $filters = self::filters($request, ['address', 'state', 'recipient']);
        $listing = Listing::fromRequest($request, ['created'], 'created');
        $blocked = Listing::fromRequest(new Request(['cursor' => $request->query->getString('blocked_cursor')]), ['created'], 'created');

        return $this->page('client/suppressions.html.twig', $client, 'suppressions', [
            'page' => $this->read->suppressions($clientId, '' === ($filters['address'] ?? '') ? null : $filters['address'], 'all' !== ($filters['state'] ?? ''), $listing),
            'blocked' => $this->read->suppressedMessages($clientId, '' === ($filters['recipient'] ?? '') ? null : $filters['recipient'], $blocked),
            'filters' => $filters, 'listing' => $listing,
            'may_lift' => $client->canSubmitGlobalSuppressions() && $client->mayCreateWork() && $this->access->may(ClientVoter::ADMIN, $client),
            'may_block' => $this->access->may(ClientVoter::ADMIN, $client),
            'may_lift_block' => AccountBlocks::mayLift($client) && $this->access->may(ClientVoter::ADMIN, $client),
        ]);
    }

    /** The account's own block of an address or a whole domain (2.13): its own page. */
    #[Route('/suppressions/new', name: 'dashboard_client_block_new', methods: ['GET'])]
    public function newBlock(string $clientId): Response
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);

        return $this->page('client/block_new.html.twig', $client, 'suppressions', ['value' => '', 'error' => null]);
    }

    #[Route('/suppressions/new', name: 'dashboard_client_block_add', methods: ['POST'])]
    public function addBlock(string $clientId, Request $request, AccountBlocks $blocks): Response
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $this->assertCsrf($request);
        $input = mb_substr(trim($request->request->getString('value')), 0, 320);
        try {
            [$value, $scope] = AccountBlocks::parse($input);
            [, $status] = $blocks->block($client, AuditActor::user($this->access->user()), $value, $scope);
        } catch (DomainRuleViolation $e) {
            return $this->page('client/block_new.html.twig', $client, 'suppressions', ['value' => $input, 'error' => $e->getMessage()])->setStatusCode(422);
        }
        $this->addFlash('success', 201 === $status
            ? \sprintf('%s is blocked: this account sends nothing to %s.', $value, 'domain' === $scope->value ? 'any address at it' : 'it')
            : \sprintf('%s was already blocked.', $value));

        return $this->redirectToRoute('dashboard_client_suppressions', ['clientId' => $clientId]);
    }

    #[Route('/blocks/{id}/lift', name: 'dashboard_client_block_lift', methods: ['POST'])]
    public function liftBlock(string $clientId, string $id, Request $request, AccountBlocks $blocks): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $this->assertCsrf($request);
        $block = $blocks->find($client, $id) ?? throw new NotFoundHttpException('Not found.');
        try {
            $blocks->lift($client, AuditActor::user($this->access->user()), $block);
            $this->addFlash('success', \sprintf('The block of %s was lifted. Any other suppression of it still applies.', $block->getAddressOrDomain()));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_client_suppressions', ['clientId' => $clientId]);
    }

    /** Lifts this client's own recipient global opt-out (Phase 5 rules: capability, active/throttled client). */
    #[Route('/global-opt-outs/{id}/lift', name: 'dashboard_client_opt_out_lift', methods: ['POST'])]
    public function liftOptOut(string $clientId, string $id, Request $request, GlobalSuppressionService $service): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $this->assertCsrf($request);
        $optOut = $service->ownOptOut($client, $id) ?? throw new NotFoundHttpException('Not found.');
        try {
            $service->lift($client, AuditActor::user($this->access->user()), $optOut);
            $this->addFlash('success', 'The global opt-out was lifted.');
        } catch (ApiProblem $e) {
            $this->addFlash('error', $e->detail ?? $e->title);
        }

        return $this->redirectToRoute('dashboard_client_suppressions', ['clientId' => $clientId]);
    }

    #[Route('/usage', name: 'dashboard_client_usage', methods: ['GET'])]
    public function usage(string $clientId, Request $request, \App\Usage\UsageReporting $reporting, \App\Client\PlanCatalog $plans): Response
    {
        $client = $this->access->client($clientId);
        $filters = self::filters($request, ['type']);
        $listing = Listing::fromRequest($request, array_keys(ClientReadModel::USAGE_SORTS), 'occurred');

        $custom = null;
        if ('' !== $request->query->getString('from') || '' !== $request->query->getString('to')) {
            try {
                $period = \App\Usage\UsagePeriod::custom($request->query->getString('from'), $request->query->getString('to'), $this->time->zone);
                $custom = ['period' => $period, 'totals' => $reporting->clientTotals($clientId, $period), 'daily' => $reporting->daily($clientId, $period)];
            } catch (\App\Domain\DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->page('client/usage.html.twig', $client, 'usage', [
            'usage' => $this->read->usage($clientId, '' === ($filters['type'] ?? '') ? null : $filters['type'], $listing),
            'filters' => $filters, 'listing' => $listing, 'periods' => $this->account->usagePeriods($clientId), 'custom' => $custom,
            'range' => ['from' => $request->query->getString('from'), 'to' => $request->query->getString('to')],
            'statements' => $this->account->statements($clientId, false), 'limits' => $this->account->limits($client),
            'plan' => $plans->name($client->getPlan()), 'custom_limits' => $plans->isCustom($client)]);
    }

    /** @param array<string, mixed> $vars */
    private function page(string $template, Client $client, string $section, array $vars): Response
    {
        return $this->render('dashboard/'.$template, $vars + ['client' => $client, 'section' => $section]);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Known filter keys only, as bounded strings; values are bound parameters in SQL.
     *
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    public static function filters(Request $request, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = trim($request->query->getString($key));
            $out[$key] = mb_substr($value, 0, 320);
        }

        return $out;
    }

    private static function csvCell(string $column, mixed $value): string
    {
        if (null === $value) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ('checked_at' === $column) {
            return (new \DateTimeImmutable((string) $value))->setTimezone(Clock::zone())->format('Y-m-d\TH:i:s.vP');
        }
        $s = (string) $value;

        return '' !== $s && \in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
    }
}
