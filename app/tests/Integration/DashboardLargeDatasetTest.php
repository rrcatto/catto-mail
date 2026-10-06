<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ClientMembershipRole;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\DashboardFixtures;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\QueryRecorder;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Phase 6 load acceptance (spec testing.load: "dashboard pagination does not
 * aggregate entire job/message tables in memory"): a 10,000-address validation
 * job and a 10,000-message send job with substantial event history. Every page is
 * rendered through the real kernel; the test records the number of SQL
 * statements, the PHP memory growth and, with EXPLAIN (ANALYZE, BUFFERS), the
 * plan and time of every statement the pages ran. The observations are written
 * to phase6-dashboard-observations.txt in the harness output directory
 * (infra/.generated/test-output/) and summarised on stderr.
 */
final class DashboardLargeDatasetTest extends DashboardTestCase
{
    private const MAX_QUERIES_PER_PAGE = 20;
    private const MAX_MEMORY_GROWTH = 24 * 1024 * 1024;
    private const MAX_STATEMENT_MS = 1500.0;

    public function testLargeJobsStayBoundedInMemoryAndQueries(): void
    {
        $client = $this->newClient();
        $clientId = $client->getId()->toRfc4122();
        $o = Db::owner();
        $started = microtime(true);
        $validation = DashboardFixtures::validationJob($o, $clientId, 10000, 'big-import');
        $set = DashboardFixtures::sendJob($o, $clientId, $this->verifiedDomain($client)->getId()->toRfc4122(), 10000,
            ['track_opens' => true, 'track_clicks' => true, 'external_reference' => 'big-send']);
        DashboardFixtures::transportEvents($o, $set['job']);
        // Recorded engagement on a fifth of the messages, clicks on half of those.
        $o->executeStatement(<<<'SQL'
            INSERT INTO message_events (id, message_id, event_type, event_source, metadata_json, occurred_at)
            SELECT gen_random_uuid(), m.id, t.type, 'tracking_endpoint', t.meta::jsonb, m.created_at + interval '1 hour' + t.n * interval '1 minute'
              FROM (SELECT id, created_at, row_number() OVER (ORDER BY id) AS i FROM messages WHERE send_job_id = ?) m
              CROSS JOIN (VALUES ('open_recorded', '{}', 0), ('open_recorded', '{}', 90), ('click_recorded', '{"link_index": 1}', 2)) AS t(type, meta, n)
             WHERE m.i % 5 = 0 AND (t.type = 'open_recorded' OR m.i % 10 = 0)
            SQL, [$set['job']]);
        $o->executeStatement(<<<'SQL'
            INSERT INTO message_links (id, message_id, link_index, target_url)
            SELECT gen_random_uuid(), id, 1, 'https://shop.example.test/item' FROM messages WHERE send_job_id = ?
            SQL, [$set['job']]);
        // A sizeable suppression list and audit history for the operator pages.
        $o->executeStatement(<<<'SQL'
            INSERT INTO suppressions (id, client_id, address_or_domain, scope_type, reason, created_at)
            SELECT gen_random_uuid(), NULL, 'bounced' || i || '.' || md5(random()::text) || '@example.test', 'address', 'hard_bounce', now() - i * interval '1 minute'
              FROM generate_series(1, 20000) AS i
            SQL);
        $o->executeStatement(<<<'SQL'
            INSERT INTO audit_log (id, actor_type, actor_id, action, target_type, target_id, detail_json, occurred_at)
            SELECT gen_random_uuid(), 'system', 'load-test', 'phase6.load', 'thing', i::text, '{}', now() - i * interval '1 second'
              FROM generate_series(1, 20000) AS i
            SQL);
        // Other tenants' jobs of the same size, so that the measured job is not the whole table.
        $other = $this->newClient();
        $otherDomain = $this->verifiedDomain($other)->getId()->toRfc4122();
        for ($i = 0; $i < 3; ++$i) {
            DashboardFixtures::validationJob($o, $other->getId()->toRfc4122(), 10000);
            $filler = DashboardFixtures::sendJob($o, $other->getId()->toRfc4122(), $otherDomain, 10000, ['track_opens' => true]);
            DashboardFixtures::transportEvents($o, $filler['job']);
        }
        $o->executeStatement('ANALYZE validation_addresses, messages, message_events, message_links, suppressions, audit_log, send_jobs, validation_jobs');
        $fixtureSeconds = microtime(true) - $started;
        $eventCount = (int) $o->fetchOne('SELECT count(*) FROM message_events e JOIN messages m ON m.id = e.message_id WHERE m.send_job_id = ?', [$set['job']]);
        self::assertSame(45000, $eventCount, "40,000 transport + 4,000 recorded opens + 1,000 recorded clicks");

        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Viewer));
        $this->browser->disableReboot();
        $base = "/dashboard/c/$clientId";
        $message = $set['messages'][5000];
        $pages = [
            'client overview' => $base,
            'validation jobs' => "$base/validation-jobs",
            'validation job, first page' => "$base/validation-jobs/$validation",
            'validation job, undeliverable filter' => "$base/validation-jobs/$validation?classification=undeliverable",
            'validation job, sorted by address, limit 200' => "$base/validation-jobs/$validation?sort=address&dir=desc&limit=200",
            'validation job, typo + role filters' => "$base/validation-jobs/$validation?typo=yes&role=yes",
            'send jobs' => "$base/send-jobs",
            'send job + messages, first page' => "$base/send-jobs/{$set['job']}",
            'send job, messages by recipient' => "$base/send-jobs/{$set['job']}?sort=recipient&dir=asc",
            'send job, status filter' => "$base/send-jobs/{$set['job']}?status=remote_accepted&limit=100",
            'message timeline' => "$base/messages/$message",
            'suppressions (client)' => "$base/suppressions",
            'usage' => "$base/usage",
        ];
        $observations = [];
        foreach ($pages as $name => $uri) {
            $observations[$name] = $this->observe($uri);
        }
        // Deep pages through the cursor (no OFFSET): the 3rd page of each big table.
        foreach (['validation job, page 3' => "$base/validation-jobs/$validation", 'send job messages, page 3' => "$base/send-jobs/{$set['job']}"] as $name => $uri) {
            for ($i = 0; $i < 2; ++$i) {
                $uri = (new Crawler((string) $this->page($uri)->getContent(), 'https://localhost'))->filter('a[rel="next"]')->attr('href');
            }
            $observations[$name] = $this->observe($uri);
        }

        // The export streams all 10,000 rows in bounded batches.
        QueryRecorder::start();
        $memory = memory_get_usage();
        $this->browser->request('GET', "$base/validation-jobs/$validation/results.csv");
        $csv = $this->browser->getInternalResponse()->getContent();
        $exportQueries = QueryRecorder::stop();
        $lines = substr_count($csv, "\n");
        self::assertSame(10001, $lines, 'header + 10,000 rows');
        $observations['CSV export (10,000 rows)'] = ['queries' => \count($exportQueries), 'memory' => memory_get_usage() - $memory - \strlen($csv),
            'ms' => null, 'statements' => []];
        unset($csv);

        // Operator pages over the same data.
        $this->browser->getCookieJar()->clear();
        $this->browser->enableReboot();
        $this->signIn($this->newUser(true));
        $this->browser->disableReboot();
        foreach (['operator overview' => '/dashboard/operator', 'operator suppressions (20,000 rows)' => '/dashboard/operator/suppressions',
            'operator suppressions, address search' => '/dashboard/operator/suppressions?address=nobody%40example.test&state=',
            'operator audit (20,000+ rows)' => '/dashboard/operator/audit', 'operator audit, action filter' => '/dashboard/operator/audit?action=phase6.load',
            'operator unmatched DSNs' => '/dashboard/operator/unmatched-dsns', 'operator clients' => '/dashboard/operator/clients',
            'operator webhook outbox' => '/dashboard/operator/webhooks'] as $name => $uri) {
            $observations[$name] = $this->observe($uri);
        }

        $report = $this->report($observations, $fixtureSeconds, $eventCount);
        // The Phase 2 harness mounts /srv/test-output (infra/.generated/test-output on the host).
        $dir = is_dir('/srv/test-output') ? '/srv/test-output' : static::getContainer()->getParameter('kernel.logs_dir');
        file_put_contents($dir.'/phase6-dashboard-observations.txt', $report);
        fwrite(\STDERR, "\n".strstr($report, "\nPlans", true)."\n(plans: $dir/phase6-dashboard-observations.txt)\n");

        foreach ($observations as $name => $obs) {
            self::assertLessThanOrEqual($name === 'CSV export (10,000 rows)' ? 30 : self::MAX_QUERIES_PER_PAGE, $obs['queries'], "$name: query count");
            self::assertLessThan(self::MAX_MEMORY_GROWTH, $obs['memory'], "$name: memory growth");
            foreach ($obs['statements'] as $s) {
                self::assertLessThan(self::MAX_STATEMENT_MS, $s['ms'], "$name: {$s['sql']}");
            }
        }
        // Job-scoped pages read the job through its index, never a whole table.
        foreach (['validation job, first page', 'validation job, page 3', 'send job + messages, first page', 'send job messages, page 3', 'message timeline'] as $name) {
            foreach ($observations[$name]['statements'] as $s) {
                self::assertDoesNotMatchRegularExpression('/Seq Scan on (validation_addresses|messages|message_events)\b/', $s['plan'], "$name: {$s['sql']}");
            }
        }
    }

    /** @return array{queries: int, memory: int, ms: float, statements: list<array{sql: string, ms: float, plan: string}>} */
    private function observe(string $uri): array
    {
        gc_collect_cycles();
        $memory = memory_get_usage();
        QueryRecorder::start();
        $t = microtime(true);
        $response = $this->page($uri);
        $ms = 1000 * (microtime(true) - $t);
        $queries = QueryRecorder::stop();
        self::assertSame(200, $response->getStatusCode(), $uri);
        $growth = memory_get_usage() - $memory - \strlen((string) $response->getContent());
        $statements = [];
        foreach ($queries as $q) {
            if (!preg_match('/^\s*SELECT\b/i', $q['sql']) || str_contains($q['sql'], 'pg_advisory')) {
                continue;
            }
            $plan = Db::app()->fetchFirstColumn('EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) '.$q['sql'], array_values($q['params']));
            preg_match('/Execution Time: ([0-9.]+) ms/', end($plan), $m);
            $statements[] = ['sql' => preg_replace('/\s+/', ' ', trim($q['sql'])), 'ms' => (float) ($m[1] ?? 0), 'plan' => implode("\n", $plan)];
        }

        return ['queries' => \count($queries), 'memory' => $growth, 'ms' => $ms, 'statements' => $statements];
    }

    private function report(array $observations, float $fixtureSeconds, int $events): string
    {
        $out = \sprintf("Phase 6 dashboard observations (fixture build %.1f s; measured client: 10,000 validation addresses, 10,000 messages, %d events;\n"
            ."another client: 3 x 10,000 addresses, 3 x 10,000 messages, 120,000 events; 20,000 global suppressions; 20,000 audit rows)\n\n", $fixtureSeconds, $events);
        $out .= \sprintf("%-48s %8s %12s %10s %14s\n", 'page', 'queries', 'memory KiB', 'page ms', 'slowest SQL ms');
        foreach ($observations as $name => $o) {
            $slowest = [] === $o['statements'] ? 0.0 : max(array_column($o['statements'], 'ms'));
            $out .= \sprintf("%-48s %8d %12d %10s %14.2f\n", $name, $o['queries'], intdiv(max(0, $o['memory']), 1024), null === $o['ms'] ? '-' : \sprintf('%.0f', $o['ms']), $slowest);
        }
        $out .= "\nPlans (EXPLAIN ANALYZE, BUFFERS) of every SELECT the pages ran:\n";
        foreach ($observations as $name => $o) {
            foreach ($o['statements'] as $s) {
                $out .= "\n== $name ({$s['ms']} ms)\n{$s['sql']}\n{$s['plan']}\n";
            }
        }

        return $out;
    }
}
