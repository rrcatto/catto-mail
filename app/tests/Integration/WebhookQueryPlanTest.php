<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ClientMembershipRole;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\DashboardFixtures;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\StubHostResolver;
use App\Webhook\WebhookDispatcher;
use App\Webhook\WebhookPayloadFactory;
use App\Webhook\WebhookSecrets;
use App\Webhook\WebhookTargetGuard;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Phase 7 query plans: the webhook worker's statements (fan-out of pending events,
 * subscription matching, leased claiming, exhausted-lease recovery, fenced
 * outcomes) and the client/operator webhook pages, over a large history. 180,000
 * deliveries and 182,000 events in two clients, with a small pending/due set as in
 * steady operation. Every statement is run with EXPLAIN (ANALYZE, BUFFERS); writes
 * are explained inside a rolled-back transaction; repeated statements once. No
 * statement may scan a whole webhook table. The report is written to
 * phase7-webhook-query-plans.txt in the harness output directory. The evidence led
 * to migration Version20261007000200 and to time-bounded dashboard counts.
 */
final class WebhookQueryPlanTest extends DashboardTestCase
{
    private const MAX_STATEMENT_MS = 100.0;

    private array $clients = [];

    protected function tearDown(): void
    {
        // The test database is shared: remove the bulk history again.
        foreach ($this->clients as $id) {
            Db::owner()->executeStatement('DELETE FROM webhook_deliveries WHERE client_id = ?', [$id]);
            Db::owner()->executeStatement('DELETE FROM webhook_events WHERE client_id = ?', [$id]);
        }
        parent::tearDown();
    }

    public function testWorkerAndDashboardQueriesUseIndexesAtVolume(): void
    {
        StubHostResolver::$answers = ['hooks.example.test' => ['93.184.216.34']];
        $o = Db::owner();
        $o->executeStatement('UPDATE webhook_events SET fanned_out_at = now() WHERE fanned_out_at IS NULL');
        $o->executeStatement("UPDATE webhook_deliveries SET status = 'failed', claimed_by = NULL, lease_expires_at = NULL, next_attempt_at = NULL, last_error = 'test reset' WHERE status = 'pending'");
        $started = microtime(true);
        $a = $this->newClient(\App\Enum\ClientStatus::Active, 'Measured client');
        $b = $this->newClient(\App\Enum\ClientStatus::Active, 'Other client');
        $this->clients = [$a->getId()->toRfc4122(), $b->getId()->toRfc4122()];
        $service = $this->container()->get(\App\Webhook\WebhookEndpointService::class);
        foreach ([$a, $b] as $client) {
            foreach (['send.completed', 'validation.completed', 'message.hard_bounced'] as $type) {
                $service->create($this->reload($client), "https://hooks.example.test/$type", [$type, 'send.failed'], self::actor());
            }
        }
        foreach ([[$this->clients[0], 30000], [$this->clients[1], 150000]] as [$client, $n]) {
            $o->executeStatement(<<<'SQL'
                INSERT INTO webhook_events (id, client_id, event_type, subject_type, subject_id, created_at, fanned_out_at)
                SELECT gen_random_uuid(), ?, t.type, t.subject, gen_random_uuid(), now() - i * interval '10 seconds', now() - i * interval '10 seconds' + interval '2 seconds'
                  FROM generate_series(1, ?) AS i
                  CROSS JOIN LATERAL (SELECT (ARRAY['send.completed', 'validation.completed', 'message.hard_bounced'])[1 + i % 3] AS type,
                                             (ARRAY['send_job', 'validation_job', 'message'])[1 + i % 3] AS subject) t
                SQL, [$client, $n]);
            // One delivery per event to the endpoint subscribed to its type: 97 % delivered,
            // 2 % failed, the rest pending (retries scheduled later); 100 due now.
            $o->executeStatement(<<<'SQL'
                INSERT INTO webhook_deliveries (id, client_id, webhook_event_id, webhook_endpoint_id, event_type, payload_json, payload_hash, status,
                                                attempt_count, next_attempt_at, last_response_status, last_error, delivered_at, created_at, last_attempt_at)
                SELECT gen_random_uuid(), w.client_id, w.id, e.id, w.event_type, '{"id": "x", "data": {}}', repeat('0', 64),
                       CASE WHEN n % 100 < 97 THEN 'delivered' WHEN n % 100 < 99 THEN 'failed' ELSE 'pending' END,
                       CASE WHEN n % 100 < 97 THEN 1 WHEN n % 100 < 99 THEN 8 ELSE 3 END,
                       CASE WHEN n % 100 = 99 THEN now() + CASE WHEN n % 1000 = 99 THEN interval '-1 minute' ELSE interval '1 hour' END END,
                       CASE WHEN n % 100 < 97 THEN 200 ELSE 503 END, CASE WHEN n % 100 >= 97 THEN 'HTTP 503' END,
                       CASE WHEN n % 100 < 97 THEN w.created_at + interval '3 seconds' END, w.fanned_out_at, w.fanned_out_at
                  FROM (SELECT *, row_number() OVER (ORDER BY created_at) AS n FROM webhook_events WHERE client_id = ?) w
                  JOIN webhook_endpoints e ON e.client_id = w.client_id AND e.url = 'https://hooks.example.test/' || w.event_type
                SQL, [$client]);
        }
        // Pending fan-out work: test events, each addressed to one of the client's endpoints, and one
        // real send-job event (subscription matching).
        $o->executeStatement(<<<'SQL'
            INSERT INTO webhook_events (id, client_id, event_type, subject_type, subject_id)
            SELECT gen_random_uuid(), e.client_id, 'webhook.test', 'webhook_endpoint', e.id
              FROM generate_series(1, 50) AS i
              JOIN LATERAL (SELECT id, client_id FROM webhook_endpoints WHERE client_id = ? ORDER BY id OFFSET i % 3 LIMIT 1) e ON true
            SQL, [$this->clients[0]]);
        $job = DashboardFixtures::sendJob($o, $this->clients[0], $this->verifiedDomain($a)->getId()->toRfc4122(), 5);
        $o->insert('webhook_events', ['id' => SchemaFixtures::id(), 'client_id' => $this->clients[0], 'event_type' => 'send.completed',
            'subject_type' => 'send_job', 'subject_id' => $job['job']]);
        $o->executeStatement('ANALYZE webhook_events, webhook_deliveries, webhook_endpoints');
        $fixtureSeconds = microtime(true) - $started;
        $deliveries = (int) $o->fetchOne('SELECT count(*) FROM webhook_deliveries WHERE client_id IN (?, ?)', $this->clients);
        self::assertSame(180000, $deliveries);

        // The worker: one round as the long-running command runs it.
        $c = $this->container();
        $d = new WebhookDispatcher($c->get('doctrine.dbal.webhook_connection'), $c->get('doctrine.orm.webhook_entity_manager'),
            $c->get(WebhookPayloadFactory::class), $c->get(WebhookSecrets::class), new WebhookTargetGuard(new StubHostResolver(), 'test', ''),
            new MockHttpClient(static fn (): MockResponse => new MockResponse('ok', ['http_code' => 200])), new \Psr\Log\NullLogger(), 8, 2, 5, 1, 30);
        $observations = [];
        QueryRecorder::start();
        $d->failExhaustedLeases();
        $d->fanOut(100);
        $observations['worker: exhausted leases + fan-out (51 events)'] = $this->explain(QueryRecorder::stop());
        QueryRecorder::start();
        $claims = $d->claim('plan-worker', 50);
        $observations['worker: claim 50 due deliveries'] = $this->explain(QueryRecorder::stop());
        QueryRecorder::start();
        $d->attempt('plan-worker', $claims);
        $observations['worker: send and record outcomes'] = $this->explain(QueryRecorder::stop());
        self::assertCount(50, $claims);

        // The pages.
        $this->signIn($this->newUser(false, $a, ClientMembershipRole::Viewer));
        $this->browser->disableReboot();
        $base = '/dashboard/c/'.$this->clients[0].'/webhooks';
        foreach (['client webhooks (endpoints + deliveries)' => $base, 'client webhooks, failed' => "$base?status=failed",
            'client webhooks, retrying' => "$base?status=retrying", 'client webhooks, one endpoint' => "$base?endpoint=".Db::owner()->fetchOne('SELECT id FROM webhook_endpoints WHERE client_id = ? ORDER BY id LIMIT 1', [$this->clients[0]])] as $name => $uri) {
            $observations[$name] = $this->page2($uri);
        }
        $this->browser->getCookieJar()->clear();
        $this->browser->enableReboot();
        $this->signIn($this->newUser(true));
        $this->browser->disableReboot();
        foreach (['operator webhooks' => '/dashboard/operator/webhooks', 'operator webhooks, failed' => '/dashboard/operator/webhooks?status=failed',
            'operator webhooks, client + failed' => '/dashboard/operator/webhooks?status=failed&client='.$this->clients[0],
            'operator overview' => '/dashboard/operator'] as $name => $uri) {
            $observations[$name] = $this->page2($uri);
        }

        $out = \sprintf("Phase 7 webhook query plans (fixture build %.1f s): 180,000 deliveries (30,000 measured client, 150,000 other),\n"
            ."97 %% delivered / 2 %% failed / 1 %% pending (180 due), 51 events awaiting fan-out, 6 endpoints\n\n", $fixtureSeconds);
        $out .= \sprintf("%-52s %8s %14s\n", 'step', 'queries', 'slowest SQL ms');
        foreach ($observations as $name => $obs) {
            $out .= \sprintf("%-52s %8d %14.2f\n", $name, $obs['queries'], [] === $obs['statements'] ? 0.0 : max(array_column($obs['statements'], 'ms')));
        }
        $out .= "\nPlans (EXPLAIN ANALYZE, BUFFERS):\n";
        foreach ($observations as $name => $obs) {
            foreach ($obs['statements'] as $s) {
                $out .= "\n== $name ({$s['ms']} ms)\n{$s['sql']}\n{$s['plan']}\n";
            }
        }
        $dir = is_dir('/srv/test-output') ? '/srv/test-output' : static::getContainer()->getParameter('kernel.logs_dir');
        file_put_contents($dir.'/phase7-webhook-query-plans.txt', $out);
        fwrite(\STDERR, "\n".strstr($out, "\nPlans", true)."\n(plans: $dir/phase7-webhook-query-plans.txt)\n");

        foreach ($observations as $name => $obs) {
            foreach ($obs['statements'] as $s) {
                self::assertLessThan(self::MAX_STATEMENT_MS, $s['ms'], "$name: {$s['sql']}");
                self::assertDoesNotMatchRegularExpression('/Seq Scan on webhook_(deliveries|events)\b/', $s['plan'], "$name: {$s['sql']}");
            }
        }
    }

    /** @return array{queries: int, statements: list<array{sql: string, ms: float, plan: string}>} */
    private function page2(string $uri): array
    {
        QueryRecorder::start();
        $response = $this->page($uri);
        $queries = QueryRecorder::stop();
        self::assertSame(200, $response->getStatusCode(), $uri);

        return $this->explain($queries);
    }

    /**
     * EXPLAIN ANALYZE of every statement on the webhook tables; writes run in a
     * transaction that is rolled back.
     *
     * @return array{queries: int, statements: list<array{sql: string, ms: float, plan: string}>}
     */
    private function explain(array $queries): array
    {
        $statements = [];
        $seen = [];
        $db = Db::owner();
        foreach ($queries as $q) {
            $sql = $q['sql'];
            if (isset($seen[$sql])) {
                continue;
            }
            $seen[$sql] = true;
            if (!preg_match('/webhook_(deliveries|events|endpoints|worker)/', $sql) || preg_match('/^\s*(BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE|LISTEN|SELECT pg_notify)/i', $sql)) {
                continue;
            }
            $db->beginTransaction();
            try {
                $plan = $db->fetchFirstColumn('EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) '.$sql, array_values($q['params']));
            } finally {
                $db->rollBack();
            }
            preg_match('/Execution Time: ([0-9.]+) ms/', end($plan), $m);
            $statements[] = ['sql' => preg_replace('/\s+/', ' ', trim($sql)), 'ms' => (float) ($m[1] ?? 0), 'plan' => implode("\n", $plan)];
        }

        return ['queries' => \count($queries), 'statements' => $statements];
    }
}
