<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Dashboard\ClientAccess;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\WebhookEndpoint;
use App\Enum\SetupStepState;
use App\Enum\SystemRequestAction;
use App\System\ApplicationDiagnostics;
use App\System\DeliveryControl;
use App\System\SeedTestService;
use App\System\SetupWizard;
use App\System\SystemChecks;
use App\System\SystemRequests;
use App\System\SystemState;
use App\Webhook\WebhookTestService;
use Doctrine\DBAL\Connection;
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
 * Operator › System (specification 2.11): the health dashboard, diagnostics and their
 * history, the delivery controls ("stop sending email now"), the host-agent request log,
 * and the administrator setup wizard with its guided seed and bounce tests.
 *
 * Viewing needs PLATFORM.SYSTEM.VIEW; running checks and host actions SYSTEM.DIAGNOSTICS.RUN;
 * the wizard SYSTEM.SETUP.MANAGE; delivery controls SYSTEM.DELIVERY.CONTROL. Host actions
 * are requests to the host agent (SystemRequests): this controller never runs a host command.
 */
#[Route('/dashboard/operator')]
final class OperatorSystemController extends AbstractController
{
    public const LIVE_PHRASE = 'ENABLE LIVE DELIVERY';
    public const RESUME_PHRASE = 'RESUME SENDING';

    public function __construct(
        private readonly ClientAccess $access,
        private readonly SystemChecks $checks,
        private readonly SystemState $state,
        private readonly SystemRequests $requests,
        private readonly DeliveryControl $delivery,
        private readonly SetupWizard $wizard,
        private readonly Connection $connection,
    ) {
    }

    #[Route('/system', name: 'dashboard_operator_system', methods: ['GET'])]
    #[IsGranted('PLATFORM.SYSTEM.VIEW')]
    public function health(): Response
    {
        $all = $this->checks->latest();
        $components = [];
        foreach (self::HEALTH_ROWS as $label => [$comps, $prefixes]) {
            $sel = SetupWizard::select($all, $comps, $prefixes);
            $components[$label] = ['result' => SetupWizard::worst($sel), 'checks' => $sel];
        }
        $host = $this->state->get(SystemState::HOST);
        $backup = $this->state->get(SystemState::BACKUP);
        $tls = array_values(array_filter($all, static fn (array $c): bool => str_starts_with((string) $c['check_key'], 'tls.') && str_contains((string) $c['check_key'], 'validity')));

        return $this->render('dashboard/operator/system/health.html.twig', [
            'section' => 'system', 'components' => $components, 'delivery' => $this->delivery->status(),
            'work' => $this->connection->fetchAssociative(<<<'SQL'
                SELECT (SELECT count(*) FROM validation_jobs WHERE status IN ('queued', 'processing')) AS validation_jobs,
                       (SELECT count(*) FROM send_jobs WHERE status IN ('queued', 'processing')) AS send_jobs,
                       (SELECT count(*) FROM send_jobs WHERE status = 'collecting') AS collecting_jobs
                SQL),
            'host' => $host, 'backup' => $backup, 'tls' => $tls, 'agent_age' => $this->state->agentAge(),
            'pending_requests' => $this->requests->pendingCount(), 'setup_complete' => $this->wizard->isComplete(),
        ]);
    }

    /** Dashboard rows: label => [components, check key prefixes] */
    public const HEALTH_ROWS = [
        'Web application' => [['web'], []],
        'Database' => [['database'], []],
        'Validator' => [['validator'], []],
        'Delivery daemon' => [['delivery'], ['container.delivery']],
        'Postfix' => [['postfix'], ['container.postfix']],
        'OpenDKIM' => [['opendkim'], ['container.opendkim']],
        'Webhook worker' => [['webhook'], ['container.webhook']],
        'nginx and ingress' => [['nginx'], []],
        'Host' => [['host'], []],
        'Automatic restart' => [['boot'], []],
        'DNS' => [['dns'], []],
        'TLS' => [['tls'], []],
        'Tracking' => [['tracking'], []],
        'Bounce path' => [['bounce'], []],
        'Backups' => [['backup'], []],
        'Security' => [['security'], []],
    ];

    #[Route('/system/diagnostics', name: 'dashboard_operator_diagnostics', methods: ['GET'])]
    #[IsGranted('PLATFORM.SYSTEM.VIEW')]
    public function diagnostics(): Response
    {
        $grouped = [];
        foreach ($this->checks->latest() as $c) {
            $grouped[$c['component']][] = $c;
        }

        return $this->render('dashboard/operator/system/diagnostics.html.twig', [
            'section' => 'diagnostics', 'grouped' => $grouped, 'by_component' => $this->checks->byComponent(),
            'agent_age' => $this->state->agentAge(), 'last_run' => $this->requests->latest(SystemRequestAction::DiagnosticsRun),
        ]);
    }

    #[Route('/system/diagnostics/run', name: 'dashboard_operator_diagnostics_run', methods: ['POST'])]
    #[IsGranted('SYSTEM.DIAGNOSTICS.RUN')]
    public function runDiagnostics(Request $request, ApplicationDiagnostics $app): RedirectResponse
    {
        $this->assertCsrf($request);
        $results = $app->run(true);
        $fails = \count(array_filter($results, static fn (array $r): bool => 'fail' === $r['result']));
        $section = $request->request->getString('section');
        try {
            $this->requests->request(SystemRequestAction::DiagnosticsRun, '' === $section ? [] : ['section' => $section], $this->access->user());
            $this->addFlash('success', \sprintf('Application checks ran now (%d checks, %d FAIL). The host agent runs the host, DNS, TLS, Postfix and backup checks within a minute; reload to see them.', \count($results), $fails));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('info', \sprintf('Application checks ran now (%d checks, %d FAIL). %s', \count($results), $fails, $e->getMessage()));
        }

        return $this->redirect($this->safeReturn($request, $this->generateUrl('dashboard_operator_diagnostics')));
    }

    #[Route('/system/diagnostics/history', name: 'dashboard_operator_diagnostics_history', methods: ['GET'])]
    #[IsGranted('PLATFORM.SYSTEM.VIEW')]
    public function history(Request $request): Response
    {
        $key = $request->query->getString('check');
        $component = $request->query->getString('component');
        $result = $request->query->getString('result');

        return $this->render('dashboard/operator/system/history.html.twig', [
            'section' => 'diagnostics', 'last_runs' => $this->checks->lastRuns(),
            'runs' => $this->checks->history(1 === preg_match('/^[a-z0-9_.-]{1,100}$/', $key) ? $key : null,
                \App\Enum\SystemComponent::tryFrom($component)?->value, \App\Enum\SystemCheckResult::tryFrom($result)?->value),
            'filters' => ['check' => $key, 'component' => $component, 'result' => $result],
            'components' => array_map(static fn ($c) => $c->value, \App\Enum\SystemComponent::cases()),
        ]);
    }

    #[Route('/system/requests', name: 'dashboard_operator_system_requests', methods: ['GET'])]
    #[IsGranted('PLATFORM.SYSTEM.VIEW')]
    public function requestLog(): Response
    {
        return $this->render('dashboard/operator/system/requests.html.twig', [
            'section' => 'system', 'requests' => $this->requests->recent(100), 'agent_age' => $this->state->agentAge()]);
    }

    #[Route('/system/requests/{id}/cancel', name: 'dashboard_operator_system_request_cancel', methods: ['POST'])]
    #[IsGranted('SYSTEM.DIAGNOSTICS.RUN')]
    public function cancelRequest(string $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $cancelled = $this->requests->cancel($id, $this->access->user());
        $this->addFlash($cancelled ? 'success' : 'info', $cancelled ? 'Cancelled.' : 'Only a request the host agent has not claimed can be cancelled.');

        return $this->redirectToRoute('dashboard_operator_system_requests');
    }

    /** STOP SENDING EMAIL NOW: the delivery daemon stops within seconds; the Postfix queue is held by the agent. */
    #[Route('/system/stop', name: 'dashboard_operator_stop', methods: ['POST'])]
    #[IsGranted('SYSTEM.DELIVERY.CONTROL')]
    public function stop(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        try {
            $this->delivery->emergencyStop($this->access->user(), $request->request->getString('note'));
            $this->addFlash('success', 'Sending is STOPPED. The delivery daemon stops starting messages within seconds; the host agent holds the Postfix queue within a minute.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->safeReturn($request, $this->generateUrl('dashboard_operator_system')));
    }

    #[Route('/system/resume', name: 'dashboard_operator_resume', methods: ['POST'])]
    #[IsGranted('SYSTEM.DELIVERY.CONTROL')]
    public function resume(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        try {
            if (self::RESUME_PHRASE !== trim($request->request->getString('confirm'))) {
                throw new DomainRuleViolation('Type '.self::RESUME_PHRASE.' to confirm.');
            }
            $this->delivery->resume($this->access->user(), $request->request->getString('note'));
            $this->addFlash('success', 'The emergency stop is lifted. The delivery daemon resumes within seconds; the Postfix queue is released by the host agent.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->safeReturn($request, $this->generateUrl('dashboard_operator_system')));
    }

    /** Live activation or return to held mode: the agent runs `prod live-enable` (activation preflight, audit). */
    #[Route('/system/live', name: 'dashboard_operator_live', methods: ['POST'])]
    #[IsGranted('SYSTEM.DELIVERY.CONTROL')]
    public function live(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $enable = 'enable' === $request->request->getString('to');
        try {
            if ($enable && self::LIVE_PHRASE !== trim($request->request->getString('confirm'))) {
                throw new DomainRuleViolation('Type '.self::LIVE_PHRASE.' to confirm.');
            }
            $this->requests->request($enable ? SystemRequestAction::DeliveryLiveEnable : SystemRequestAction::DeliveryLiveDisable, [],
                $this->access->user(), $request->request->getString('note'));
            $this->addFlash('success', $enable
                ? 'Requested. The host agent runs the activation preflight; live delivery is enabled only if nothing fails. See System › Host requests for the outcome.'
                : 'Requested. The host agent returns the installation to held mode within a minute.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->safeReturn($request, $this->generateUrl('dashboard_operator_system')));
    }

    /** Backups, restore rehearsal, DKIM keys and TLS renewal: requests to the host agent. */
    #[Route('/system/request', name: 'dashboard_operator_system_request', methods: ['POST'])]
    #[IsGranted('SYSTEM.DIAGNOSTICS.RUN')]
    public function hostRequest(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $action = SystemRequestAction::tryFrom($request->request->getString('action'));
        try {
            if (!\in_array($action, [SystemRequestAction::BackupRun, SystemRequestAction::BackupRestoreRehearsal,
                SystemRequestAction::DkimGenerate, SystemRequestAction::DkimActivate, SystemRequestAction::TlsRenew], true)) {
                throw new DomainRuleViolation('Unknown request.');
            }
            $this->requests->request($action, ['domain' => $request->request->getString('domain'), 'selector' => $request->request->getString('selector')],
                $this->access->user(), $request->request->getString('note') ?: null);
            $this->addFlash('success', 'Requested. The host agent carries it out within a minute; the outcome appears under System › Host requests.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->safeReturn($request, $this->generateUrl('dashboard_operator_system_requests')));
    }

    // ------------------------------------------------------------------ setup wizard

    #[Route('/setup', name: 'dashboard_operator_setup', methods: ['GET'])]
    #[IsGranted('SYSTEM.SETUP.MANAGE')]
    public function setup(): Response
    {
        $all = $this->checks->latest();
        $steps = $this->wizard->steps();
        foreach ($steps as $key => $s) {
            [, , $comps, $prefixes] = SetupWizard::STEPS[$key];
            $steps[$key]['checks'] = SetupWizard::worst(SetupWizard::select($all, $comps, $prefixes));
        }

        return $this->render('dashboard/operator/setup/index.html.twig', [
            'section' => 'setup', 'steps' => $steps, 'current' => $this->wizard->current(), 'complete' => $this->wizard->isComplete(),
            'agent_age' => $this->state->agentAge()]);
    }

    #[Route('/setup/{step}', name: 'dashboard_operator_setup_step', methods: ['GET'], requirements: ['step' => '[a-z_]+'])]
    #[IsGranted('SYSTEM.SETUP.MANAGE')]
    public function step(string $step, SeedTestService $tests): Response
    {
        if (!isset(SetupWizard::STEPS[$step])) {
            throw new NotFoundHttpException('Not found.');
        }
        [$title, $purpose, $comps, $prefixes, $topics] = SetupWizard::STEPS[$step];
        $all = $this->checks->latest();
        $keys = array_keys(SetupWizard::STEPS);
        $i = array_search($step, $keys, true);
        $steps = $this->wizard->steps();
        $vars = [
            'section' => 'setup', 'step' => $steps[$step], 'title' => $title, 'purpose' => $purpose, 'topics' => $topics,
            'checks' => SetupWizard::select($all, $comps, $prefixes), 'prev' => $keys[$i - 1] ?? null, 'next' => $keys[$i + 1] ?? null,
            'steps' => $steps, 'host' => $this->state->get(SystemState::HOST), 'backup' => $this->state->get(SystemState::BACKUP),
            'boot' => $this->state->get(SystemState::BOOT), 'agent_age' => $this->state->agentAge(), 'delivery' => $this->delivery->status(),
            'requests' => array_slice($this->requests->recent(10), 0, 10),
        ];
        if ('readiness' === $step) {
            $rows = [];
            foreach (SetupWizard::READINESS as $label => [$c, $p]) {
                $sel = SetupWizard::select($all, $c, $p);
                $rows[$label] = ['result' => SetupWizard::worst($sel), 'checks' => $sel];
            }
            $vars['readiness'] = $rows;
        }
        if (\in_array($step, ['seed_test', 'bounce_test', 'webhook_worker', 'dkim'], true)) {
            $vars['clients'] = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT c.id::text AS id, c.company_name, string_agg(d.domain, ', ' ORDER BY d.domain) AS domains
                  FROM clients c JOIN sending_domains d ON d.client_id = c.id AND d.status = 'verified'
                 WHERE c.status IN ('active', 'throttled') GROUP BY c.id, c.company_name ORDER BY c.company_name
                SQL);
            $vars['tests'] = array_values(array_filter($tests->tests(), static fn (array $t): bool => ('bounce_test' === $step) === ('bounce' === $t['kind'])));
            $vars['reports'] = [];
            foreach (array_slice($vars['tests'], 0, 3) as $t) {
                try {
                    $vars['reports'][$t['job_id']] = $tests->report($t['job_id']);
                } catch (DomainRuleViolation) {
                }
            }
            $vars['endpoints'] = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT e.id::text AS id, e.url, c.company_name FROM webhook_endpoints e JOIN clients c ON c.id = e.client_id
                 WHERE e.status = 'enabled' ORDER BY c.company_name, e.created_at
                SQL);
            $vars['domains'] = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT d.domain, d.dkim_status, d.dkim_selector, c.company_name FROM sending_domains d JOIN clients c ON c.id = d.client_id
                 WHERE d.status = 'verified' ORDER BY d.domain
                SQL);
        }

        return $this->render('dashboard/operator/setup/step.html.twig', $vars);
    }

    #[Route('/setup/{step}/state', name: 'dashboard_operator_setup_state', methods: ['POST'], requirements: ['step' => '[a-z_]+'])]
    #[IsGranted('SYSTEM.SETUP.MANAGE')]
    public function stepState(string $step, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $state = match ($request->request->getString('state')) {
            'done' => SetupStepState::Done, 'skipped' => SetupStepState::Skipped, default => null,
        };
        try {
            $this->wizard->set($step, $state, $this->access->user(), $request->request->getString('note'));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_operator_setup');
        }
        $keys = array_keys(SetupWizard::STEPS);
        $next = $keys[array_search($step, $keys, true) + 1] ?? null;
        if (null !== $state && null !== $next) {
            return $this->redirectToRoute('dashboard_operator_setup_step', ['step' => $next]);
        }

        return $this->redirectToRoute(null === $state ? 'dashboard_operator_setup_step' : 'dashboard_operator_setup', null === $state ? ['step' => $step] : []);
    }

    #[Route('/setup/tests/start', name: 'dashboard_operator_setup_test_start', methods: ['POST'])]
    #[IsGranted('SYSTEM.SETUP.MANAGE')]
    public function startTest(Request $request, SeedTestService $tests, EntityManagerInterface $em): RedirectResponse
    {
        $this->assertCsrf($request);
        $kind = 'bounce' === $request->request->getString('kind') ? 'bounce' : 'seed';
        try {
            $client = Uuid::isValid($request->request->getString('client')) ? $em->find(Client::class, Uuid::fromString($request->request->getString('client'))) : null;
            if (null === $client) {
                throw new DomainRuleViolation('Choose the client to send as (it needs a verified sending domain).');
            }
            $tests->start($kind, $client, $request->request->getString('sender_email'),
                preg_split('/[\s,;]+/', $request->request->getString('addresses'), -1, \PREG_SPLIT_NO_EMPTY) ?: [],
                $request->request->getBoolean('i_own_these'), $this->access->user());
            $this->addFlash('success', 'The test was created. Follow its stages below; reload to update them.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_setup_step', ['step' => 'bounce' === $kind ? 'bounce_test' : 'seed_test']);
    }

    #[Route('/setup/tests/{jobId}/confirm', name: 'dashboard_operator_setup_test_confirm', methods: ['POST'])]
    #[IsGranted('SYSTEM.SETUP.MANAGE')]
    public function confirmTest(string $jobId, Request $request, SeedTestService $tests): RedirectResponse
    {
        $this->assertCsrf($request);
        try {
            $tests->confirm($jobId, $request->request->getString('what'), $this->access->user());
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->safeReturn($request, $this->generateUrl('dashboard_operator_setup_step', ['step' => 'seed_test'])));
    }

    #[Route('/setup/webhook-test', name: 'dashboard_operator_setup_webhook_test', methods: ['POST'])]
    #[IsGranted('SYSTEM.SETUP.MANAGE')]
    public function webhookTest(Request $request, WebhookTestService $webhooks, EntityManagerInterface $em): RedirectResponse
    {
        $this->assertCsrf($request);
        $id = $request->request->getString('endpoint');
        $endpoint = Uuid::isValid($id) ? $em->find(WebhookEndpoint::class, Uuid::fromString($id)) : null;
        try {
            if (null === $endpoint) {
                throw new DomainRuleViolation('Choose an endpoint.');
            }
            $webhooks->request($endpoint->getClient(), $endpoint, \App\Audit\AuditActor::user($this->access->user()));
            $this->addFlash('success', 'A webhook.test event was queued for that endpoint. Its delivery appears under Operator › Webhooks within seconds.');
        } catch (DomainRuleViolation|\App\Api\ApiProblem $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_setup_step', ['step' => 'webhook_worker']);
    }

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
