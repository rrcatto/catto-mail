<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\DashboardFixtures;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;

/**
 * Phase 6 operator dashboard: only the global operator role gets in; every
 * change goes through the existing audited services; the unmatched-DSN workflow
 * only records requests (Go applies them); suppressions are lifted, never deleted.
 */
final class OperatorDashboardTest extends DashboardTestCase
{
    private const PAGES = ['/dashboard/operator', '/dashboard/operator/clients', '/dashboard/operator/unmatched-dsns',
        '/dashboard/operator/suppressions', '/dashboard/operator/audit', '/dashboard/operator/webhooks'];

    private function unmatchedDsn(?string $candidate = null): string
    {
        $id = SchemaFixtures::id();
        Db::owner()->insert('unmatched_dsns', ['id' => $id, 'received_at' => gmdate('Y-m-d H:i:s'), 'spool_ingest_key' => 'k-'.$id,
            'content_sha256' => str_repeat('c', 64), 'classification' => 'hard_bounce', 'final_recipient' => 'lost@example.test',
            'enhanced_status_code' => '5.1.1', 'reporting_mta' => 'mx.example.test', 'raw_message' => "From: MAILER-DAEMON\r\n\r\n<script>alert(1)</script>",
            'detail_json' => json_encode(['diagnostic' => 'smtp; 550 5.1.1 unknown user', 'action' => 'failed',
                'candidates' => null === $candidate ? [] : [['message_id' => $candidate, 'sender_matches_returned_from' => true, 'created_at' => gmdate('c')]]])]);

        return $id;
    }

    public function testClientUsersAndAnonymousCannotReachTheOperatorArea(): void
    {
        $client = $this->newClient();
        $dsn = $this->unmatchedDsn();
        foreach (self::PAGES as $uri) {
            self::assertStringEndsWith('/dashboard/login', (string) $this->page($uri)->headers->get('Location'), "anonymous $uri");
        }
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        foreach ([...self::PAGES, '/dashboard/operator/clients/'.$client->getId()->toRfc4122(), "/dashboard/operator/unmatched-dsns/$dsn"] as $uri) {
            self::assertSame(403, $this->page($uri)->getStatusCode(), "client admin $uri");
        }
        $token = $this->crawler('/dashboard/c/'.$client->getId()->toRfc4122())->filter('input[name="_csrf_token"]')->attr('value');
        foreach (['/dashboard/operator/clients/'.$client->getId()->toRfc4122().'/status' => ['status' => 'active'],
            "/dashboard/operator/unmatched-dsns/$dsn/dismiss" => ['reason' => 'x'], '/dashboard/operator/suppressions' => ['value' => 'a@b.example']] as $uri => $body) {
            $this->browser->request('POST', $uri, $body + ['_token' => $token]);
            self::assertSame(403, $this->browser->getResponse()->getStatusCode(), "client admin POST $uri");
        }
        self::assertSame('open', Db::owner()->fetchOne('SELECT status FROM unmatched_dsns WHERE id = ?', [$dsn]));
        // Navigation does not even offer the operator area.
        self::assertStringNotContainsString('/dashboard/operator', (string) $this->page('/dashboard/c/'.$client->getId()->toRfc4122())->getContent());
    }

    public function testOperatorSeesEveryPageWithRealData(): void
    {
        $client = $this->newClient(ClientStatus::Active, 'Operator View Co');
        $o = Db::owner();
        $set = DashboardFixtures::sendJob($o, $client->getId()->toRfc4122(), $this->verifiedDomain($client)->getId()->toRfc4122(), 3);
        DashboardFixtures::validationJob($o, $client->getId()->toRfc4122(), 5);
        $this->signIn($this->newUser(true));
        self::assertStringEndsWith('/dashboard/operator', (string) $this->page('/dashboard')->headers->get('Location'), 'operators land on the overview');
        foreach (self::PAGES as $uri) {
            self::assertSame(200, $this->page($uri)->getStatusCode(), $uri);
        }
        $overview = self::text($this->page('/dashboard/operator'));
        self::assertStringContainsString('Workers and queues', $overview);
        self::assertStringContainsString('Operator View Co', $overview, 'cross-client rates');
        // Phase 8: the queue depth comes only from the delivery daemon's durable heartbeat; without
        // one, the panel says so instead of inventing a figure.
        self::assertStringContainsString('Postfix queue', $overview);
        self::assertStringContainsString('no snapshot yet', $overview);
        // Times are shown in the dashboard's time zone (APP_TIMEZONE; the test pod uses Africa/Johannesburg).
        self::assertStringContainsString('Times are shown in SAST (Africa/Johannesburg)', $overview);
        self::assertMatchesRegularExpression('/\d{4}-\d\d-\d\d \d\d:\d\d:\d\d SAST/', self::text($this->page('/dashboard/operator/audit')));
        // The figures of the period come from the job counters; the charts get them as JSON.
        $crawler = $this->crawler('/dashboard/operator?period=24h');
        self::assertSame('24 hours', $crawler->filter('.pills a[aria-current]')->text());
        self::assertSame(4, $crawler->filter('.kpi')->count());
        $flow = json_decode((string) $crawler->filter('canvas[data-chart-kind-value="flow"]')->attr('data-chart-config-value'), true);
        self::assertCount(24, $flow['totals'], 'hourly buckets');
        self::assertGreaterThanOrEqual(3, $flow['totals'][23], 'the three messages of the job created now, in the current hour');
        self::assertSame(7, \count(json_decode((string) $this->crawler('/dashboard/operator?period=bogus')
            ->filter('canvas[data-chart-kind-value="flow"]')->attr('data-chart-config-value'), true)['totals']), 'unknown periods fall back to 7 days');
        // Navigation: every operator area the user may open, the current one marked.
        $areas = $crawler->filter('.orbs .orb-label')->each(static fn ($n): string => $n->text());
        self::assertSame(['Overview', 'Mail flow', 'Clients', 'System', 'Access'], \array_slice($areas, 0, 5));
        self::assertSame('Overview', $crawler->filter('.orbs a[aria-current] .orb-label')->text());
        $mail = $this->crawler('/dashboard/operator/unmatched-dsns');
        self::assertSame(['Delivery', 'Suppressions', 'Unmatched DSNs', 'Webhooks'], $mail->filter('.pills a')->each(static fn ($n): string => trim(preg_replace('/\s+\d+$/', '', $n->text()))));
        self::assertSame('Mail flow', $mail->filter('.orbs a[aria-current] .orb-label')->text());
        $clients = $this->crawler('/dashboard/operator/clients?q=Operator%20View');
        self::assertSame(1, $clients->filter('tbody tr')->count());
        $detail = self::text($this->page('/dashboard/operator/clients/'.$client->getId()->toRfc4122()));
        self::assertStringContainsString('Metadata only', $detail);
        $hash = (string) $o->fetchOne('SELECT key_hash FROM api_keys WHERE client_id = ? LIMIT 1', [$client->getId()->toRfc4122()]);
        if ('' !== $hash) {
            self::assertStringNotContainsString($hash, $detail);
        }
        // Operators may open any client's dashboard.
        self::assertSame(200, $this->page('/dashboard/c/'.$client->getId()->toRfc4122()."/send-jobs/{$set['job']}")->getStatusCode());
        self::assertStringContainsString('Delivery is at-least-once', self::text($this->page('/dashboard/operator/webhooks')));
    }

    public function testClientAdministrationReusesAuditedServices(): void
    {
        [$client, $key] = $this->newApiClient(ClientStatus::PendingApproval);
        $id = $client->getId()->toRfc4122();
        $operator = $this->newUser(true);
        $this->signIn($operator);
        $page = "/dashboard/operator/clients/$id";
        // Phase 9: approval needs the policy version in force and a reason.
        $this->container()->get(\App\Client\ClientLifecycle::class)->recordPolicyAcceptance($this->reload($client), 'aup-test-1',
            \App\Enum\PolicyAcceptanceSource::OperatorRecorded, self::actor(), null, 'order form');
        $this->submit($page, "$page/status", ['status' => 'active', 'note' => 'reviewed']);
        self::assertSame('active', Db::owner()->fetchOne('SELECT status FROM clients WHERE id = ?', [$id]));
        $audit = Db::owner()->fetchAssociative("SELECT actor_type, actor_id FROM audit_log WHERE action = 'client.approved' AND target_id = ? ORDER BY occurred_at DESC LIMIT 1", [$id]);
        self::assertSame(['actor_type' => 'user', 'actor_id' => $operator->getId()->toRfc4122()], $audit);

        $this->submit($page, "$page/global-suppressions", ['setting' => 'enable', 'note' => '']);
        self::assertFalse((bool) Db::owner()->fetchOne('SELECT can_submit_global_suppressions FROM clients WHERE id = ?', [$id]), 'a note is required');
        $this->submit($page, "$page/global-suppressions", ['setting' => 'enable', 'note' => 'trusted list operator']);
        self::assertTrue((bool) Db::owner()->fetchOne('SELECT can_submit_global_suppressions FROM clients WHERE id = ?', [$id]));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'client.global_suppressions_enabled' AND target_id = ?", [$id]));
        self::assertNotEmpty($key);
    }

    public function testUnmatchedDsnWorkflowRecordsRequestsOnly(): void
    {
        $client = $this->newClient(ClientStatus::Active, 'DSN Co');
        $set = DashboardFixtures::sendJob(Db::owner(), $client->getId()->toRfc4122(), $this->verifiedDomain($client)->getId()->toRfc4122(), 1);
        $message = $set['messages'][0];
        $dsn = $this->unmatchedDsn($message);
        $toDismiss = $this->unmatchedDsn();
        $operator = $this->newUser(true);
        $this->signIn($operator);

        $list = $this->crawler('/dashboard/operator/unmatched-dsns');
        self::assertGreaterThan(0, $list->filter("a[href=\"/dashboard/operator/unmatched-dsns/$dsn\"]")->count());
        $page = "/dashboard/operator/unmatched-dsns/$dsn";
        $detail = $this->page($page);
        $html = (string) $detail->getContent();
        self::assertStringContainsString($message, $html, 'candidate shown');
        self::assertStringContainsString('DSN Co', $html);
        self::assertStringContainsString('550 5.1.1 unknown user', $html, 'parsed evidence');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'raw DSN is escaped');
        self::assertStringContainsString('&lt;script&gt;', $html);

        $events = (int) Db::owner()->fetchOne('SELECT count(*) FROM message_events WHERE message_id = ?', [$message]);
        $this->submit($page, "$page/match", ['message_id' => SchemaFixtures::id(), 'note' => '']);
        self::assertSame('open', Db::owner()->fetchOne('SELECT status FROM unmatched_dsns WHERE id = ?', [$dsn]), 'an unknown message is refused');
        $this->submit($page, "$page/match", ['message_id' => $message, 'note' => 'same recipient and campaign']);
        $row = Db::owner()->fetchAssociative('SELECT status, matched_message_id::text AS m, resolution_requested_by::text AS by, resolution_event_id FROM unmatched_dsns WHERE id = ?', [$dsn]);
        self::assertSame(['status' => 'match_requested', 'm' => $message, 'by' => $operator->getId()->toRfc4122(), 'resolution_event_id' => null], $row);
        self::assertSame($events, (int) Db::owner()->fetchOne('SELECT count(*) FROM message_events WHERE message_id = ?', [$message]),
            'the dashboard never manufactures a transport event (Go applies the match)');
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'unmatched_dsn.match_requested' AND target_id = ?", [$dsn]));
        self::assertSame(0, $this->crawler($page)->filter("form[action=\"$page/match\"]")->count(), 'no second request while one is pending');

        $dismiss = "/dashboard/operator/unmatched-dsns/$toDismiss";
        $this->submit($dismiss, "$dismiss/dismiss", ['reason' => '  ']);
        self::assertSame('open', Db::owner()->fetchOne('SELECT status FROM unmatched_dsns WHERE id = ?', [$toDismiss]), 'a reason is required');
        $this->submit($dismiss, "$dismiss/dismiss", ['reason' => 'spam backscatter, not ours']);
        self::assertSame(['status' => 'dismissed', 'resolution_note' => 'spam backscatter, not ours'],
            Db::owner()->fetchAssociative('SELECT status, resolution_note FROM unmatched_dsns WHERE id = ?', [$toDismiss]));
        self::assertSame(1, $this->crawler('/dashboard/operator/unmatched-dsns?status=dismissed&classification=hard_bounce')
            ->filter("a[href=\"$dismiss\"]")->count());
    }

    public function testSuppressionAdministration(): void
    {
        $operator = $this->newUser(true);
        $this->signIn($operator);
        $local = 'block'.bin2hex(random_bytes(3));
        $address = $local.'@Example.TEST';
        $normalized = $local.'@example.test'; // D-32: only the domain is lower-cased
        $this->submit('/dashboard/operator/suppressions', '/dashboard/operator/suppressions',
            ['value' => $address, 'scope_type' => 'address', 'reason' => 'operator_block', 'client_id' => '', 'expires_in_days' => '', 'note' => 'abuse report']);
        $row = Db::owner()->fetchAssociative('SELECT id::text AS id, client_id, reason, lifted_at FROM suppressions WHERE address_or_domain = ?', [$normalized]);
        self::assertSame('operator_block', $row['reason']);
        self::assertNull($row['client_id'], 'global');

        $search = $this->crawler('/dashboard/operator/suppressions?address='.rawurlencode((string) $normalized));
        self::assertSame(1, $search->filter('tbody tr')->count());
        self::assertSame(1, $this->crawler('/dashboard/operator/suppressions?address='.rawurlencode(' '.$address.' '))->filter('tbody tr')->count(),
            'the filter applies the D-32 normalisation (domain case, whitespace)');
        $lift = "/dashboard/operator/suppressions/{$row['id']}/lift";
        $this->submit('/dashboard/operator/suppressions?address='.rawurlencode((string) $normalized), $lift, ['note' => '']);
        self::assertNull(Db::owner()->fetchOne('SELECT lifted_at FROM suppressions WHERE id = ?', [$row['id']]), 'a note is required');
        $this->submit('/dashboard/operator/suppressions?address='.rawurlencode((string) $normalized), $lift, ['note' => 'resolved with the recipient']);
        self::assertNotNull(Db::owner()->fetchOne('SELECT lifted_at FROM suppressions WHERE id = ?', [$row['id']]));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM suppressions WHERE id = ?', [$row['id']]), 'never deleted');
        $audit = json_decode((string) Db::owner()->fetchOne("SELECT detail_json FROM audit_log WHERE action = 'suppression.operator_lifted' AND target_id = ?", [$row['id']]), true);
        self::assertSame('resolved with the recipient', $audit['note']);
        self::assertSame(1, $this->crawler('/dashboard/operator/suppressions?state=lifted&address='.rawurlencode((string) $normalized))->filter('tbody tr')->count());
        // System reasons cannot be created by an operator.
        $this->submit('/dashboard/operator/suppressions', '/dashboard/operator/suppressions',
            ['value' => 'x'.$address, 'scope_type' => 'address', 'reason' => 'hard_bounce', 'client_id' => '', 'expires_in_days' => '', 'note' => 'try']);
        self::assertSame(0, (int) Db::owner()->fetchOne("SELECT count(*) FROM suppressions WHERE address_or_domain = ?", ['x'.$normalized]));
    }

    public function testOperatorSeesProvenanceButClientsDoNot(): void
    {
        $reporter = $this->newClient(ClientStatus::Active, 'Reporter Ltd');
        $id = SchemaFixtures::id();
        $address = 'prov'.bin2hex(random_bytes(3)).'@example.test';
        Db::owner()->insert('suppressions', ['id' => $id, 'client_id' => null, 'address_or_domain' => $address, 'scope_type' => 'address',
            'reason' => 'recipient_global_opt_out', 'source_client_id' => $reporter->getId()->toRfc4122(), 'external_reference' => 'REF-42']);
        $this->signIn($this->newUser(true));
        $text = self::text($this->page('/dashboard/operator/suppressions?address='.rawurlencode($address)));
        self::assertStringContainsString('Reported by Reporter Ltd', $text);
        self::assertStringContainsString('REF-42', $text);
    }

    public function testAuditLogIsFilteredPaginatedAndSanitised(): void
    {
        $target = SchemaFixtures::id();
        foreach (range(1, 3) as $i) {
            Db::owner()->insert('audit_log', ['id' => SchemaFixtures::id(), 'actor_type' => 'system', 'actor_id' => 'test', 'action' => 'phase6.test_action',
                'target_type' => 'phase6_target', 'target_id' => $target, 'occurred_at' => gmdate('Y-m-d H:i:s', time() - $i),
                'detail_json' => json_encode(['note' => "entry $i", 'password' => 'hunter2-SECRET', 'api_key_hash' => 'deadbeefSECRET', 'nested' => ['signing_secret' => 'SECRETVALUE']])]);
        }
        $this->signIn($this->newUser(true));
        $page = $this->crawler("/dashboard/operator/audit?action=phase6.test_action&target_id=$target&limit=2");
        self::assertSame(2, $page->filter('tbody tr')->count());
        $html = $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString('SECRET', (string) $html);
        self::assertStringContainsString('[redacted]', (string) $html);
        self::assertStringContainsString('entry 1', (string) $html);
        $next = $page->filter('a[rel="next"]')->attr('href');
        self::assertSame(1, $this->crawler($next)->filter('tbody tr')->count());
        self::assertSame(0, $this->crawler("/dashboard/operator/audit?action=phase6.test_action&target_id=$target&from=2001-01-01&to=2001-01-01")->filter('tbody tr')->count());
    }
}
