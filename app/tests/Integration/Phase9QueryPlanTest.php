<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ClientMembershipRole;
use App\Reputation\ReputationEvaluator;
use App\Tests\Support\DashboardFixtures;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\QueryRecorder;
use App\Usage\UsagePeriod;
use App\Usage\UsageReconciliation;

/**
 * Phase 9 query plans at multi-client scale (specification 2.10, Phase 9 item 23).
 *
 * Fixture: 200 clients (one measured client with a large history, 199 others with
 * metered traffic), 1,000,000 usage_records over 40 days, a 60,000-message send history
 * of the measured client (240,000 transport events, 5 % bounces/deferrals/complaints in
 * the last day), 4,000 alerts (mostly resolved), 2,000 API keys, 20,000 webhook
 * deliveries and quota counters. Every statement of the Phase 9 pages and services
 * (client list and approval queue, client account page, client usage and API-key pages,
 * alerts, cross-client usage, the reputation evaluation, the reconciliation) is run
 * with EXPLAIN (ANALYZE, BUFFERS); none may scan a whole large table. The report is
 * phase9-query-plans.txt in the harness output directory.
 */
final class Phase9QueryPlanTest extends DashboardTestCase
{
    private const MAX_PAGE_STATEMENT_MS = 250.0;
    /** The cross-client usage report sums a month of every client (index-only, 750,000 entries here; cold cache). */
    private const MAX_USAGE_REPORT_STATEMENT_MS = 500.0;
    private const MAX_BATCH_STATEMENT_MS = 3000.0;
    /**
     * The tables this fixture makes large. Small ones here (send_jobs, validation_jobs,
     * validation_addresses) are rightly scanned; Phase 6's query-plan review covers them at scale.
     */
    private const LARGE_TABLES = 'usage_records|message_events|messages|client_alerts|api_keys|webhook_deliveries|webhook_events|audit_log|client_quota_usage';
    /** The reputation evaluation reads time windows across clients; it may hash-join messages and send_jobs by key, but never scan these whole. */
    private const BATCH_LARGE_TABLES = 'usage_records|message_events|webhook_deliveries|client_alerts';
    /**
     * The reconciliation of one client and month reads that client's accepted messages: when the
     * client holds a large share of all messages (23 % here) PostgreSQL rightly prefers a parallel
     * scan of message_events to tens of thousands of index probes. It is an offline per-client
     * batch whose cost grows with the client's monthly volume; usage_records is never scanned whole.
     */
    private const RECONCILIATION_LARGE_TABLES = 'usage_records|webhook_deliveries|client_alerts';

    /** @var list<string> */
    private array $clients = [];
    /** @var list<string> */
    private array $measuredJobs = [];

    protected function tearDown(): void
    {
        // The test database is shared: remove the bulk rows again (clients stay, closed and empty).
        $o = Db::owner();
        if ([] !== $this->clients) {
            $ids = '{'.implode(',', $this->clients).'}';
            $o->executeStatement('DELETE FROM usage_records WHERE client_id = ANY(?::uuid[])', [$ids]);
            $o->executeStatement('DELETE FROM client_alerts WHERE client_id = ANY(?::uuid[])', [$ids]);
            $o->executeStatement('DELETE FROM client_reputation_metrics WHERE client_id = ANY(?::uuid[])', [$ids]);
            $o->executeStatement('DELETE FROM webhook_deliveries WHERE client_id = ANY(?::uuid[])', [$ids]);
            $o->executeStatement('DELETE FROM webhook_events WHERE client_id = ANY(?::uuid[])', [$ids]);
            $o->executeStatement('DELETE FROM client_quota_usage WHERE client_id = ANY(?::uuid[])', [$ids]);
            $o->executeStatement('UPDATE api_keys SET revoked_at = now() WHERE client_id = ANY(?::uuid[]) AND revoked_at IS NULL', [$ids]);
            if ([] !== $this->measuredJobs) {
                // Deleting a million events would check each against suppressions.source_event_id (unindexed:
                // production never deletes events). Moving the windowed kinds 20 years back takes them out of
                // every reputation window and usage period the later tests measure.
                $o->executeStatement("UPDATE message_events SET occurred_at = occurred_at - interval '20 years'
                    WHERE event_type IN ('submitted_to_postfix', 'hard_bounce', 'soft_bounce', 'deferred', 'complaint', 'transport_outcome_unknown', 'message_suppressed')
                      AND message_id IN (SELECT id FROM messages WHERE send_job_id = ANY(?::uuid[]))",
                    ['{'.implode(',', $this->measuredJobs).'}']);
            }
            $o->executeStatement("UPDATE clients SET status = 'closed', closed_at = now(), status_changed_at = now() WHERE id = ANY(?::uuid[]) AND status <> 'closed'", [$ids]);
        }
        parent::tearDown();
    }

    public function testPhase9QueriesUseIndexesAtScale(): void
    {
        $o = Db::owner();
        $started = microtime(true);
        $measured = $this->newClient(\App\Enum\ClientStatus::Active, 'Measured SaaS client');
        $m = $measured->getId()->toRfc4122();
        $this->clients[] = $m;
        // 199 more clients, created in bulk (approved operator clients).
        $others = $o->fetchFirstColumn(<<<'SQL'
            INSERT INTO clients (id, company_name, contact_email, status, plan, created_at, status_changed_at, approved_at, policy_acceptance_required)
            SELECT gen_random_uuid(), 'Bulk client ' || lpad(i::text, 3, '0') || ' ' || md5(random()::text), 'ops' || i || '@bulk.example',
                   CASE WHEN i % 20 = 0 THEN 'pending_approval' WHEN i % 33 = 0 THEN 'throttled' ELSE 'active' END, 'standard',
                   now() - interval '60 days', now() - interval '30 days', CASE WHEN i % 20 <> 0 THEN now() - interval '30 days' END, false
              FROM generate_series(1, 199) AS i
            RETURNING id::text
            SQL);
        array_push($this->clients, ...$others);
        $all = '{'.implode(',', $this->clients).'}';

        // 1,000,000 usage records over 40 days: 200,000 for the measured client, 800,000 spread over the others.
        $o->executeStatement(<<<'SQL'
            INSERT INTO usage_records (id, client_id, usage_type, quantity, reference_type, reference_id, occurred_at)
            SELECT gen_random_uuid(), CASE WHEN i % 5 = 0 THEN ?::uuid ELSE (?::uuid[])[2 + i % 199] END,
                   CASE WHEN i % 4 = 0 THEN 'validation_address' ELSE 'message_submitted' END,
                   CASE WHEN i % 4 = 0 THEN 1 + i % 50 ELSE 1 END,
                   CASE WHEN i % 4 = 0 THEN 'validation_job' ELSE 'message' END, gen_random_uuid(),
                   now() - (i % 57600) * interval '1 minute'
              FROM generate_series(1, 1000000) AS i
            SQL, [$m, $all]);

        // The measured client's send history: 60,000 messages, 4 transport events each, and in the last day
        // 5 % hard bounces, 5 % deferrals (provider policy), 0.5 % complaints and 1 % outcome unknown.
        $domain = $this->verifiedDomain($measured)->getId()->toRfc4122();
        // Six jobs of 10,000 messages (the per-job ceiling).
        for ($j = 0; $j < 6; ++$j) {
            $job = DashboardFixtures::sendJob($o, $m, $domain, 10000, ['created_at' => gmdate('Y-m-d H:i:s', time() - 7200 - $j)]);
            $this->measuredJobs[] = $job['job'];
            DashboardFixtures::transportEvents($o, $job['job']);
        }
        $o->executeStatement(<<<'SQL'
            INSERT INTO message_events (id, message_id, event_type, event_source, source_event_key, failure_scope, occurred_at)
            SELECT gen_random_uuid(), m.id, e.t, 'postfix_log', e.t || ':p9:' || m.id, e.scope, now() - interval '1 hour'
              FROM (SELECT id, row_number() OVER (ORDER BY id) AS n FROM messages WHERE send_job_id = ANY(?::uuid[])) m
              JOIN (VALUES ('hard_bounce', 'recipient', 0), ('deferred', 'provider_policy', 1), ('complaint', NULL, 2),
                           ('transport_outcome_unknown', NULL, 3)) AS e(t, scope, k)
                ON (e.k = 0 AND m.n % 20 = 0) OR (e.k = 1 AND m.n % 20 = 1) OR (e.k = 2 AND m.n % 200 = 2) OR (e.k = 3 AND m.n % 100 = 3)
            SQL, ['{'.implode(',', $this->measuredJobs).'}']);

        // 4,000 alerts (3,800 resolved), 2,000 API keys, 20,000 webhook deliveries, quota counters.
        $o->executeStatement(<<<'SQL'
            INSERT INTO client_alerts (id, client_id, metric, window_hours, severity, numerator, denominator, value, threshold, first_observed_at, last_observed_at, resolved_at)
            SELECT gen_random_uuid(), (?::uuid[])[1 + i % 200], (ARRAY['hard_bounce_rate', 'complaint_rate', 'deferral_rate', 'volume_increase'])[1 + i % 4],
                   CASE WHEN i % 2 = 0 THEN 24 ELSE 168 END, CASE WHEN i % 3 = 0 THEN 'critical' ELSE 'warning' END, 10, 100, 10, 5,
                   now() - (i % 800 + i % 100) * interval '1 hour', now() - (i % 800) * interval '1 hour',
                   CASE WHEN i > 200 THEN now() - (i % 800) * interval '1 hour' + interval '1 minute' END
              FROM generate_series(1, 4000) AS i
            SQL, [$all]);
        $o->executeStatement(<<<'SQL'
            INSERT INTO api_keys (id, client_id, key_hash, key_prefix, name, created_at, last_used_at, revoked_at)
            SELECT gen_random_uuid(), (?::uuid[])[1 + i % 200], md5(i::text || random()::text) || md5(i::text), 'shk_' || substr(md5(i::text), 1, 8),
                   'key ' || i, now() - (i % 300) * interval '1 day', now() - (i % 30) * interval '1 hour', CASE WHEN i % 3 = 0 THEN now() END
              FROM generate_series(1, 2000) AS i
            SQL, [$all]);
        $endpoint = $this->container()->get(\App\Webhook\WebhookEndpointService::class)->create($this->reload($measured), 'https://hooks.example.test/p9', ['send.completed'], self::actor())[0]->getId()->toRfc4122();
        $o->executeStatement(<<<'SQL'
            INSERT INTO webhook_events (id, client_id, event_type, subject_type, subject_id, created_at, fanned_out_at)
            SELECT gen_random_uuid(), ?, 'send.completed', 'send_job', gen_random_uuid(), now() - i * interval '1 minute', now() - i * interval '1 minute'
              FROM generate_series(1, 20000) AS i
            SQL, [$m]);
        $o->executeStatement(<<<'SQL'
            INSERT INTO webhook_deliveries (id, client_id, webhook_event_id, webhook_endpoint_id, event_type, payload_json, payload_hash, status, attempt_count,
                                            delivered_at, created_at, updated_at)
            SELECT gen_random_uuid(), w.client_id, w.id, ?, 'send.completed', '{}', repeat('0', 64), CASE WHEN n % 10 = 0 THEN 'failed' ELSE 'delivered' END, 1,
                   CASE WHEN n % 10 <> 0 THEN w.created_at END, w.created_at, w.created_at
              FROM (SELECT *, row_number() OVER (ORDER BY created_at) AS n FROM webhook_events WHERE client_id = ?) w
            SQL, [$endpoint, $m]);
        $o->executeStatement(<<<'SQL'
            INSERT INTO client_quota_usage (client_id, metric, period, period_start, used)
            SELECT c, metric, 'day', current_date - d, 100 FROM unnest(?::uuid[]) AS c,
                   unnest(ARRAY['validation_jobs', 'validation_addresses', 'send_jobs', 'send_recipients']) AS metric, generate_series(0, 30) AS d
            SQL, [$all]);
        // 300,000 audit rows: lifecycle-style rows for every client and key-style rows for every API key.
        $o->executeStatement(<<<'SQL'
            INSERT INTO audit_log (id, actor_type, actor_id, action, target_type, target_id, detail_json, occurred_at)
            SELECT gen_random_uuid(), 'system', 'p9-fixture', 'client.limits_changed', 'client', ((?::uuid[])[1 + i % 200])::text, '{"note": "fixture"}',
                   now() - (i % 5000) * interval '1 hour'
              FROM generate_series(1, 200000) AS i
            SQL, [$all]);
        $o->executeStatement(<<<'SQL'
            INSERT INTO audit_log (id, actor_type, actor_id, action, target_type, target_id, detail_json, occurred_at)
            SELECT gen_random_uuid(), 'system', 'p9-fixture', 'api_key.created', 'api_key', k.id::text, '{}', k.created_at + (i % 50) * interval '1 minute'
              FROM (SELECT id, created_at, row_number() OVER () AS r FROM api_keys WHERE client_id = ANY(?::uuid[])) k, generate_series(1, 50) AS i
            SQL, [$all]);
        // Twenty other clients' send history (200,000 messages, 800,000 events), so the measured client is a minority of the tables.
        for ($j = 0; $j < 20; ++$j) {
            $other = $others[$j];
            $d = $o->fetchOne("INSERT INTO sending_domains (id, client_id, domain, status, verification_token, verified_at) VALUES (gen_random_uuid(), ?, ?, 'verified', ?, now()) RETURNING id",
                [$other, 'bulk'.$j.'-'.bin2hex(random_bytes(3)).'.example', bin2hex(random_bytes(16))]);
            $bulk = DashboardFixtures::sendJob($o, $other, (string) $d, 10000, ['created_at' => gmdate('Y-m-d H:i:s', time() - 86400 * (1 + $j % 5))]);
            $this->measuredJobs[] = $bulk['job'];
            DashboardFixtures::transportEvents($o, $bulk['job']);
        }
        // Production autovacuum keeps insert-only tables' visibility maps current; the fixture does it explicitly.
        foreach (['usage_records', 'message_events', 'messages', 'send_jobs', 'client_alerts', 'api_keys', 'webhook_deliveries', 'webhook_events', 'client_quota_usage', 'clients', 'audit_log'] as $table) {
            $o->executeStatement("VACUUM (ANALYZE) $table");
        }
        $fixtureSeconds = microtime(true) - $started;

        $observations = [];
        // Batch work: the reputation evaluation and the reconciliation of the measured client.
        QueryRecorder::start();
        $result = $this->container()->get(ReputationEvaluator::class)->evaluate();
        $observations['reputation evaluation (all clients, both windows)'] = $this->explain(QueryRecorder::stop(), true);
        self::assertGreaterThanOrEqual(200, $result['evaluated_clients']);
        QueryRecorder::start();
        $r = $this->container()->get(UsageReconciliation::class)->reconcile($m, UsagePeriod::named('current_month'));
        $observations['reconciliation, measured client, current month'] = $this->explain(QueryRecorder::stop(), true);
        self::assertGreaterThan(0, $r['checked']['message_usage_records']);

        // The pages.
        $this->signIn($this->newUser(true));
        $this->browser->disableReboot();
        foreach (['operator clients' => '/dashboard/operator/clients', 'approval queue' => '/dashboard/operator/clients?status=pending_approval&sort=status_changed&dir=asc',
            'operator client page (measured)' => "/dashboard/operator/clients/$m", 'alerts (open)' => '/dashboard/operator/alerts',
            'alerts, 7-day metrics' => '/dashboard/operator/alerts?window=168', 'alerts of one client' => "/dashboard/operator/alerts?client=$m&state=all",
            'usage, current month, all clients' => '/dashboard/operator/usage?period=current_month', 'usage, previous month' => '/dashboard/operator/usage?period=previous_month',
            'operator overview' => '/dashboard/operator'] as $name => $uri) {
            $observations[$name] = $this->page2($uri);
        }
        $this->browser->getCookieJar()->clear();
        $this->browser->enableReboot();
        $this->signIn($this->newUser(false, $measured, ClientMembershipRole::Admin));
        $this->browser->disableReboot();
        foreach (['client overview' => "/dashboard/c/$m", 'client usage' => "/dashboard/c/$m/usage", 'client API keys' => "/dashboard/c/$m/api-keys",
            'client webhooks' => "/dashboard/c/$m/webhooks"] as $name => $uri) {
            $observations[$name] = $this->page2($uri);
        }

        $out = \sprintf("Phase 9 query plans (fixture build %.1f s): 200 clients, 1,000,000 usage records, 260,000 messages\n"
            ."(60,000 of the measured client; 1,040,000 transport + 6,300 reputation events), 4,000 alerts (200 open), 2,000 API keys,\n"
            ."20,000 webhook deliveries, 300,000 audit rows\n\n", $fixtureSeconds);
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
        file_put_contents($dir.'/phase9-query-plans.txt', $out);
        fwrite(\STDERR, "\n".strstr($out, "\nPlans", true)."\n(plans: $dir/phase9-query-plans.txt)\n");

        foreach ($observations as $name => $obs) {
            $budget = $obs['batch'] ? self::MAX_BATCH_STATEMENT_MS
                : (str_starts_with($name, 'usage,') ? self::MAX_USAGE_REPORT_STATEMENT_MS : self::MAX_PAGE_STATEMENT_MS);
            $tables = str_starts_with($name, 'reconciliation') ? self::RECONCILIATION_LARGE_TABLES
                : ($obs['batch'] ? self::BATCH_LARGE_TABLES : self::LARGE_TABLES);
            foreach ($obs['statements'] as $s) {
                self::assertLessThan($budget, $s['ms'], "$name: {$s['sql']}");
                self::assertDoesNotMatchRegularExpression('/Seq Scan on ('.$tables.')\b/', $s['plan'], "$name: {$s['sql']}");
            }
        }
    }

    /** @return array{queries: int, batch: bool, statements: list<array{sql: string, ms: float, plan: string}>} */
    private function page2(string $uri): array
    {
        QueryRecorder::start();
        $response = $this->page($uri);
        $queries = QueryRecorder::stop();
        self::assertSame(200, $response->getStatusCode(), $uri.': '.substr((string) $response->getContent(), 0, 300));

        return $this->explain($queries, false);
    }

    /**
     * EXPLAIN ANALYZE of every distinct statement touching a large table; writes run in a
     * transaction that is rolled back.
     *
     * @param list<array{sql: string, params: array<int|string, mixed>}> $queries
     *
     * @return array{queries: int, batch: bool, statements: list<array{sql: string, ms: float, plan: string}>}
     */
    private function explain(array $queries, bool $batch): array
    {
        $statements = [];
        $seen = [];
        $db = Db::owner();
        foreach ($queries as $q) {
            $sql = $q['sql'];
            if (isset($seen[$sql]) || !preg_match('/\b('.self::LARGE_TABLES.'|client_reputation_metrics|billing_statements|client_limits)\b/', $sql)
                || preg_match('/^\s*(BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE|LISTEN|SELECT pg_notify|SELECT pg_try_advisory)/i', $sql)) {
                continue;
            }
            $seen[$sql] = true;
            $params = array_map(static fn (mixed $v): mixed => \is_bool($v) ? ($v ? 'true' : 'false') : $v, array_values($q['params']));
            $db->beginTransaction();
            try {
                $plan = $db->fetchFirstColumn('EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) '.$sql, $params);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // A single-row insert of an id that was already written cannot run again: show its plan only.
                $db->rollBack();
                $db->beginTransaction();
                $plan = array_merge(['(plan only: re-execution would repeat a committed key)'], $db->fetchFirstColumn('EXPLAIN (COSTS OFF) '.$sql, $params));
            } finally {
                $db->rollBack();
            }
            preg_match('/Execution Time: ([0-9.]+) ms/', (string) end($plan), $mm);
            $statements[] = ['sql' => preg_replace('/\s+/', ' ', trim($sql)), 'ms' => (float) ($mm[1] ?? 0), 'plan' => implode("\n", $plan)];
        }

        return ['queries' => \count($queries), 'batch' => $batch, 'statements' => $statements];
    }
}
