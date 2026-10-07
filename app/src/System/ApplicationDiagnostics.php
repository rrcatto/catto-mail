<?php

declare(strict_types=1);

namespace App\System;

use App\Api\IdempotencyKey;
use App\Audit\AuditActor;
use App\Client\ClientLifecycle;
use App\Entity\Client;
use App\Enum\ClientStatus;
use App\Enum\SystemCheckSource;
use App\Validation\ValidationJobService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The checks the Web Application can run itself (specification 2.11): its database
 * connection and schema, the tracking endpoints, the workers' heartbeats, the host
 * agent, the bounce path, the emergency stop, and a deterministic end-to-end validation
 * job through the Email Validator (syntax-invalid addresses only: no DNS lookup and no
 * SMTP connection, so it proves the validator claims and finishes work without touching
 * the Internet). Host-side checks (systemd, DNS, TLS, Postfix, backups) come from the
 * host agent; see SystemRequests.
 */
final class ApplicationDiagnostics
{
    public const SYSTEM_TEST_CLIENT = 'catto-mail system tests';
    private const VALIDATOR_TEST_ADDRESSES = ['system-test-without-at-sign', 'system-test@@double-at.invalid'];
    private const VALIDATOR_TIMEOUT_SECONDS = 180;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly SystemChecks $checks,
        private readonly SystemState $state,
        private readonly DeliveryControl $delivery,
        private readonly ClientLifecycle $lifecycle,
        private readonly ValidationJobService $validationJobs,
        private readonly HttpKernelInterface $kernel,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        // The schema owner's connection, which the application already uses to run the
        // migrations; read here only to compare the applied migrations with this release.
        #[Autowire(service: 'doctrine.dbal.owner_connection')] private readonly Connection $owner,
    ) {
    }

    /** Runs every application check and records the results; returns them. */
    public function run(bool $requested): array
    {
        $results = [];
        foreach (['database', 'migrations', 'web', 'tracking', 'validator', 'delivery', 'webhook', 'agent', 'bounce', 'stop'] as $name) {
            $t = microtime(true);
            try {
                $rows = $this->{'check'.ucfirst($name)}();
            } catch (\Throwable $e) {
                $rows = [['key' => 'app.'.$name, 'component' => 'web', 'title' => ucfirst($name), 'result' => 'fail',
                    'summary' => 'The check itself failed: '.$e->getMessage()]];
            }
            foreach ($rows as $r) {
                $results[] = $r + ['duration_ms' => (int) round(1000 * (microtime(true) - $t))];
            }
        }
        $this->checks->record($results, SystemCheckSource::Application, $requested);

        return $results;
    }

    /** @return list<array<string, mixed>> */
    private function checkDatabase(): array
    {
        $role = (string) $this->connection->fetchOne('SELECT current_user');
        $version = (string) $this->connection->fetchOne('SHOW server_version');
        $super = (bool) $this->connection->fetchOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user');

        return [['key' => 'app.database', 'component' => 'database', 'title' => 'Database connection',
            'result' => $super ? 'fail' : 'pass',
            'summary' => $super ? "Connected as $role, a superuser: the application must use its least-privilege role."
                : "Connected to PostgreSQL $version as the least-privilege role $role."]];
    }

    /** @return list<array<string, mixed>> */
    private function checkMigrations(): array
    {
        $available = array_map(static fn (string $f): string => 'DoctrineMigrations\\'.basename($f, '.php'),
            glob($this->projectDir.'/migrations/Version*.php') ?: []);
        $done = $this->owner->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
        $missing = array_values(array_diff($available, $done));

        return [['key' => 'app.migrations', 'component' => 'database', 'title' => 'Database schema version',
            'result' => [] === $missing ? 'pass' : 'fail',
            'summary' => [] === $missing ? \count($done).' migrations applied; the schema matches this release.'
                : \count($missing).' migration(s) not applied: '.implode(', ', array_map(static fn ($m) => substr($m, -14), $missing)).'.']];
    }

    /** @return list<array<string, mixed>> */
    private function checkWeb(): array
    {
        $r = $this->kernel->handle(Request::create('/healthz'), HttpKernelInterface::SUB_REQUEST, false);

        return [['key' => 'app.web', 'component' => 'web', 'title' => 'Web application health endpoint',
            'result' => 200 === $r->getStatusCode() ? 'pass' : 'fail',
            'summary' => 200 === $r->getStatusCode() ? 'GET /healthz answered 200.' : 'GET /healthz answered '.$r->getStatusCode().'.']];
    }

    /** The public endpoints answer an unknown token exactly like a valid one would be answered to a stranger. */
    private function checkTracking(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $open = $this->kernel->handle(Request::create("/t/o/$token.gif"), HttpKernelInterface::SUB_REQUEST, false);
        $click = $this->kernel->handle(Request::create("/t/c/$token/1"), HttpKernelInterface::SUB_REQUEST, false);
        $openOk = 200 === $open->getStatusCode() && str_starts_with((string) $open->headers->get('Content-Type'), 'image/gif');
        $clickOk = $click->getStatusCode() < 500;
        $events = (int) $this->connection->fetchOne("SELECT count(*) FROM message_events WHERE event_type IN ('open_recorded', 'click_recorded') AND occurred_at > now() - interval '7 days'");

        return [
            ['key' => 'app.tracking.pixel', 'component' => 'tracking', 'title' => 'Open-tracking pixel endpoint',
                'result' => $openOk ? 'pass' : 'fail',
                'summary' => $openOk ? 'GET /t/o/<token>.gif returns the 1x1 GIF (an unknown token is answered like any other).'
                    : 'GET /t/o/<token>.gif answered '.$open->getStatusCode().'.'],
            ['key' => 'app.tracking.redirect', 'component' => 'tracking', 'title' => 'Click-redirect endpoint',
                'result' => $clickOk ? 'pass' : 'fail',
                'summary' => $clickOk ? 'GET /t/c/<token>/1 answers without an error (an unknown token never redirects).'
                    : 'GET /t/c/<token>/1 answered '.$click->getStatusCode().'.'],
            ['key' => 'app.tracking.events', 'component' => 'tracking', 'title' => 'Recorded opens and clicks (7 days)',
                'result' => 'info', 'summary' => "$events recorded open/click events in the last 7 days. The seed test proves recording end to end."],
        ];
    }

    /** A deterministic job: started when none is recent, judged on the next run. */
    private function checkValidator(): array
    {
        $client = $this->systemTestClient();
        $recent = $this->connection->fetchAssociative(<<<'SQL'
            SELECT id::text AS id, status, extract(epoch FROM now() - submitted_at)::int AS age,
                   extract(epoch FROM completed_at - submitted_at)::int AS took, classification_counts_json
              FROM validation_jobs WHERE client_id = ? AND external_reference = 'system-test' ORDER BY submitted_at DESC LIMIT 1
            SQL, [$client->getId()->toRfc4122()]);
        $out = [];
        if (false !== $recent && 'completed' === $recent['status']) {
            $counts = json_decode((string) $recent['classification_counts_json'], true) ?: [];
            $ok = ($counts['undeliverable'] ?? 0) === \count(self::VALIDATOR_TEST_ADDRESSES);
            $out[] = ['key' => 'app.validator.e2e', 'component' => 'validator', 'title' => 'Deterministic validation job',
                'result' => $ok ? 'pass' : 'fail',
                'summary' => $ok ? 'The validator claimed and finished the test job in '.max(0, (int) $recent['took']).' s; both syntax-invalid addresses were classified undeliverable.'
                    : 'The test job finished with unexpected results: '.json_encode($counts).'.'];
        } elseif (false !== $recent && \in_array($recent['status'], ['queued', 'processing'], true)) {
            $late = (int) $recent['age'] > self::VALIDATOR_TIMEOUT_SECONDS;
            $out[] = ['key' => 'app.validator.e2e', 'component' => 'validator', 'title' => 'Deterministic validation job',
                'result' => $late ? 'fail' : 'info',
                'summary' => $late ? 'The test job has waited '.$recent['age'].' s without finishing: the validator is not working.'
                    : 'Test job '.$recent['status'].' ('.$recent['age'].' s); run the checks again in a minute for the result.'];
        }
        if (false === $recent || (int) $recent['age'] > 600 || \in_array($recent['status'], ['completed', 'failed', 'cancelled'], true) && (int) $recent['age'] > 60) {
            $key = IdempotencyKey::internal('system-test-'.Uuid::v7()->toRfc4122());
            $this->validationJobs->create($client, $key, hash('sha256', $key->value), ['external_reference' => 'system-test',
                'addresses' => array_map(static fn (string $a): array => ['address' => $a], self::VALIDATOR_TEST_ADDRESSES)]);
            if ([] === $out) {
                $out[] = ['key' => 'app.validator.e2e', 'component' => 'validator', 'title' => 'Deterministic validation job',
                    'result' => 'info', 'summary' => 'A test job was started; run the checks again in a minute for the result.'];
            }
        }
        $host = $this->state->get(SystemState::HOST);
        $container = $host['value']['containers']['validator'] ?? null;
        $out[] = ['key' => 'app.validator.heartbeat', 'component' => 'validator', 'title' => 'Validator heartbeat (container health)',
            'result' => null === $container ? 'skipped' : ('healthy' === ($container['health'] ?? '') ? 'pass' : 'fail'),
            'summary' => null === $container ? 'No host report yet: the host agent reports the container health check (heartbeat file age).'
                : 'Container '.($container['state'] ?? '?').', health '.($container['health'] ?? '?').' (reported '.$host['updated_at'].').'];

        return $out;
    }

    private function checkDelivery(): array
    {
        $s = $this->delivery->status();
        $age = $s['heartbeat_age'];
        $out = [['key' => 'app.delivery.heartbeat', 'component' => 'delivery', 'title' => 'Delivery daemon heartbeat',
            'result' => null === $age ? 'fail' : ($age <= DeliveryControl::HEARTBEAT_STALE_SECONDS ? 'pass' : 'fail'),
            'summary' => null === $age ? 'The delivery daemon has never reported.' : 'Last heartbeat '.DeliveryControl::duration($age).' ago; mode '.$s['mode'].'.']];
        $snap = $s['queue_snapshot_age'];
        $out[] = ['key' => 'app.delivery.queue-snapshot', 'component' => 'delivery', 'title' => 'Postfix queue snapshots (reconciliation)',
            'result' => null === $snap ? 'warn' : ($snap <= 600 ? 'pass' : 'warn'),
            'summary' => null === $snap ? 'No queue snapshot recorded yet (the snapshot timer runs every minute after start).'
                : 'Newest snapshot '.DeliveryControl::duration($snap).' old; queue: '.json_encode($s['queue']).'.'];
        $out[] = ['key' => 'app.delivery.mode', 'component' => 'delivery', 'title' => 'Delivery mode', 'result' => 'info',
            'summary' => $s['mode'].': '.$s['explanation']];

        return $out;
    }

    private function checkWebhook(): array
    {
        $age = $this->connection->fetchOne('SELECT extract(epoch FROM now() - max(last_seen_at))::int FROM webhook_worker_heartbeats WHERE stopped_at IS NULL');

        return [['key' => 'app.webhook.heartbeat', 'component' => 'webhook', 'title' => 'Webhook worker heartbeat',
            'result' => null === $age || false === $age ? 'fail' : ((int) $age <= 120 ? 'pass' : 'fail'),
            'summary' => null === $age || false === $age ? 'No running webhook worker has reported.' : 'Last heartbeat '.DeliveryControl::duration((int) $age).' ago.']];
    }

    private function checkAgent(): array
    {
        $age = $this->state->agentAge();

        return [['key' => 'app.agent', 'component' => 'host', 'title' => 'Host agent',
            'result' => null === $age ? 'warn' : ($age <= 180 ? 'pass' : 'fail'),
            'summary' => null === $age ? 'The host agent has never reported: host, DNS, TLS, Postfix and backup checks are unavailable until it runs (installed by smarthostctl prod install).'
                : 'Last report '.DeliveryControl::duration($age).' ago.']];
    }

    private function checkBounce(): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT max(occurred_at) AS last_dsn,
                   count(*) FILTER (WHERE event_type IN ('hard_bounce', 'soft_bounce', 'complaint') AND occurred_at > now() - interval '7 days') AS week
              FROM message_events WHERE event_source IN ('dsn_spool', 'unmatched_dsn_resolution') AND occurred_at > now() - interval '90 days'
            SQL) ?: ['last_dsn' => null, 'week' => 0];
        $open = (int) $this->connection->fetchOne("SELECT count(*) FROM unmatched_dsns WHERE status = 'open'");

        return [['key' => 'app.bounce.processing', 'component' => 'bounce', 'title' => 'Bounce (DSN) processing',
            'result' => 'info',
            'summary' => null === $row['last_dsn'] ? "No DSN processed in the last 90 days; $open unmatched DSNs open. The bounce test proves the path."
                : 'Last DSN processed '.$row['last_dsn'].'; '.$row['week']." bounce and complaint events in 7 days; $open unmatched DSNs open."]];
    }

    private function checkStop(): array
    {
        $s = $this->delivery->status();

        return [['key' => 'app.emergency-stop', 'component' => 'delivery', 'title' => 'Emergency stop',
            'result' => $s['emergency_stop'] ? 'warn' : 'pass',
            'summary' => $s['emergency_stop'] ? 'Sending is STOPPED by the emergency control since '.$s['stop_changed_at'].' ('.$s['stop_note'].').'
                : 'Not in force.']];
    }

    /** The internal client the system tests run as (created once, active, no policy requirement). */
    public function systemTestClient(): Client
    {
        $client = $this->em->getRepository(Client::class)->findOneBy(['companyName' => self::SYSTEM_TEST_CLIENT]);

        return $client ?? $this->lifecycle->create(self::SYSTEM_TEST_CLIENT, 'postmaster@localhost.invalid', 'system',
            ClientStatus::Active, AuditActor::system('system-diagnostics'), false, note: 'Internal client for the built-in system tests.');
    }
}
