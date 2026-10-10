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
use App\Domain\SendingDomainService;
use App\Entity\Client;
use App\Entity\SendingDomain;
use App\Security\ClientVoter;
use App\Suppression\GlobalSuppressionService;
use App\Util\Clock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Client dashboard (Phase 6, spec user_interfaces.client_dashboard). Every route
 * names the client; ClientAccess answers 404 for a client the user may not see,
 * and every query carries that client's id, so an id of another client in any
 * URL is "not found". Reads need CLIENT_VIEW; the two actions reuse the existing
 * application services (domain verification: CLIENT_OPERATE; lifting the
 * client's own global opt-out: CLIENT_ADMIN plus the Phase 5 rules).
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
            'kpis' => TrendCharts::kpis($o['trends'], $period), 'flow' => TrendCharts::flow($o['trends'], $period),
            'validation' => TrendCharts::validation($o['trends']['validation']),
            'limits' => $this->account->limits($client), 'policy' => $this->account->policy($client),
            'may_admin' => $this->access->may(ClientVoter::ADMIN, $client)]);
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
        ]);
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

    #[Route('/sending-domains', name: 'dashboard_client_domains', methods: ['GET'])]
    public function sendingDomains(string $clientId): Response
    {
        $client = $this->access->client($clientId);

        return $this->page('client/domains.html.twig', $client, 'domains', [
            'domains' => $this->read->sendingDomains($clientId), 'may_operate' => $this->access->may(ClientVoter::OPERATE, $client)]);
    }

    /** Runs the existing DNS TXT verification (SendingDomainService::verify, audited). */
    #[Route('/sending-domains/{domainId}/verify', name: 'dashboard_client_domain_verify', methods: ['POST'])]
    public function verifyDomain(string $clientId, string $domainId, Request $request, SendingDomainService $domains, EntityManagerInterface $em): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::OPERATE);
        $this->assertCsrf($request);
        $domain = ClientReadModel::isUuid($domainId)
            ? $em->getRepository(SendingDomain::class)->findOneBy(['id' => Uuid::fromString($domainId), 'client' => $client]) : null;
        if (null === $domain) {
            throw new NotFoundHttpException('Not found.');
        }
        try {
            $ok = $domains->verify($domain, AuditActor::user($this->access->user()));
            $this->addFlash($ok ? 'success' : 'error', $ok ? \sprintf('%s is verified.', $domain->getDomain())
                : \sprintf('%s could not be verified: %s', $domain->getDomain(), $domain->getLastCheckError() ?? 'the TXT record was not found.'));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_client_domains', ['clientId' => $clientId]);
    }

    #[Route('/usage', name: 'dashboard_client_usage', methods: ['GET'])]
    public function usage(string $clientId, Request $request, \App\Usage\UsageReporting $reporting): Response
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
            'statements' => $this->account->statements($clientId, false), 'limits' => $this->account->limits($client)]);
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
