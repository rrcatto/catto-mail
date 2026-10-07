<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dashboard\ClientReadModel;
use App\Entity\Client;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\DashboardFixtures;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Phase 6 client dashboard: pages, filters, keyset pagination, CSV export,
 * actions through the existing services, terminology, security headers, and
 * tenant isolation between two clients.
 */
final class ClientDashboardTest extends DashboardTestCase
{
    /** @return array{client: Client, id: string, domain: string, validation: string, send: array{job: string, messages: list<string>}} */
    private function richClient(string $name = 'Client A'): array
    {
        $client = $this->newClient(ClientStatus::Active, $name);
        $id = $client->getId()->toRfc4122();
        $domain = $this->verifiedDomain($client)->getId()->toRfc4122();
        $o = Db::owner();
        $validation = DashboardFixtures::validationJob($o, $id, 30, 'list-import-1');
        $o->executeStatement("UPDATE validation_addresses SET original_address = '=HYPERLINK(\"x\")@example.test' WHERE id = (SELECT id FROM validation_addresses WHERE job_id = ? ORDER BY id LIMIT 1)", [$validation]);
        $send = DashboardFixtures::sendJob($o, $id, $domain, 6, ['track_opens' => true, 'track_clicks' => true, 'external_reference' => 'newsletter-7']);
        DashboardFixtures::transportEvents($o, $send['job']);
        DashboardFixtures::link($o, $send['messages'][0], 1, 'https://shop.example.test/offer');
        DashboardFixtures::trackingEvent($o, $send['messages'][0], 'open_recorded', gmdate('Y-m-d H:i:s', time() + 3600));
        DashboardFixtures::trackingEvent($o, $send['messages'][0], 'click_recorded', gmdate('Y-m-d H:i:s', time() + 3660), 1);
        DashboardFixtures::usage($o, $id, 'validation_address', 30, 'validation_job', $validation);
        DashboardFixtures::usage($o, $id, 'message_submitted', 6, 'send_job', $send['job']);

        return ['client' => $client, 'id' => $id, 'domain' => $domain, 'validation' => $validation, 'send' => $send];
    }

    public function testClientPagesRenderRealData(): void
    {
        $a = $this->richClient();
        $this->signIn($this->newUser(false, $a['client']));
        $base = "/dashboard/c/{$a['id']}";

        $overview = $this->crawler($base);
        self::assertStringContainsString('Client A', $overview->filter('h1')->text());
        self::assertStringContainsString('Remote accepted', $overview->text());
        self::assertStringContainsString('does not prove that a person read the message', $overview->text());

        $jobs = $this->crawler("$base/validation-jobs?status=completed&external_reference=list-import-1");
        self::assertSame(1, $jobs->filter('tbody tr')->count());
        self::assertSame(0, $this->crawler("$base/validation-jobs?status=failed")->filter('tbody tr')->count());
        self::assertSame(0, $this->crawler("$base/validation-jobs?from=2001-01-01&to=2001-01-02")->filter('tbody tr')->count());

        $detail = $this->crawler("$base/validation-jobs/{$a['validation']}?classification=undeliverable");
        self::assertSame(3, $detail->filter('tbody tr')->count(), 'i % 10 = 0 among 30');
        self::assertSame(2, $this->crawler("$base/validation-jobs/{$a['validation']}?typo=yes")->filter('tbody tr')->count(), 'i % 13 = 0 among 30');
        self::assertSame(4, $this->crawler("$base/validation-jobs/{$a['validation']}?role=yes")->filter('tbody tr')->count(), 'i % 7 = 0 among 30');
        self::assertSame(27, $this->crawler("$base/validation-jobs/{$a['validation']}?smtp=accepted")->filter('tbody tr')->count());

        $send = $this->crawler("$base/send-jobs?external_reference=newsletter-7");
        self::assertSame(1, $send->filter('tbody tr')->count());
        $job = $this->crawler("$base/send-jobs/{$a['send']['job']}");
        self::assertStringContainsString('Messages with a recorded open', $job->text());
        self::assertStringContainsString('https://shop.example.test/offer', $job->text(), 'clicks by stored target');
        self::assertSame(6, $job->filter('table.dense tbody tr')->count());
        self::assertSame(0, $this->crawler("$base/send-jobs/{$a['send']['job']}?status=hard_bounced")->filter('table.dense tbody tr')->count());

        $message = $this->crawler("$base/messages/{$a['send']['messages'][0]}");
        $items = $message->filter('ol.timeline li strong')->each(fn (Crawler $c) => $c->text());
        self::assertSame(['Message created', 'Queued for submission', 'Submitted to Postfix', 'Remote accepted', 'Recorded open', 'Recorded click'], $items);
        self::assertStringContainsString('not proof the message was read', $message->text());
        $token = (string) Db::owner()->fetchOne('SELECT tracking_token FROM messages WHERE id = ?', [$a['send']['messages'][0]]);
        self::assertStringNotContainsString($token, (string) $this->browser->getResponse()->getContent(), 'tracking tokens are never shown');

        $domains = $this->crawler("$base/sending-domains");
        self::assertStringContainsString('Verified', $domains->filter('tbody')->text());
        $usage = $this->crawler("$base/usage");
        self::assertStringContainsString('Validation addresses', $usage->text());
        self::assertSame(2, $usage->filter('table#usage-records tbody tr')->count());
        self::assertSame(1, $usage->filter('table#usage-months tbody tr')->count(), 'both records are in the current month');
        self::assertSame(200, $this->page("$base/suppressions")->getStatusCode());
    }

    public function testKeysetPaginationIsStableAndComplete(): void
    {
        $a = $this->richClient();
        $this->signIn($this->newUser(false, $a['client']));
        $uri = "/dashboard/c/{$a['id']}/validation-jobs/{$a['validation']}?limit=7&sort=address&dir=asc";
        $seen = [];
        $pages = 0;
        while (null !== $uri) {
            $c = $this->crawler($uri);
            $rows = $c->filter('tbody tr td:first-child')->each(fn (Crawler $td) => $td->text());
            self::assertLessThanOrEqual(7, \count($rows));
            $seen = array_merge($seen, $rows);
            $next = $c->filter('a[rel="next"]');
            $uri = $next->count() ? $next->attr('href') : null;
            ++$pages;
        }
        self::assertSame(5, $pages);
        self::assertCount(30, $seen);
        self::assertCount(30, array_unique($seen), 'no row repeated across pages');
        $sorted = $seen;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $seen, 'server-side order is stable');
        // Unknown sort names and tampered cursors fall back safely (never SQL text).
        self::assertSame(200, $this->page("/dashboard/c/{$a['id']}/validation-jobs/{$a['validation']}?sort=id;DROP%20TABLE%20x&dir=sideways")->getStatusCode());
        self::assertSame(200, $this->page("/dashboard/c/{$a['id']}/validation-jobs/{$a['validation']}?cursor=WyInOyBEUk9QIiwiJyJd")->getStatusCode());
        self::assertSame(200, $this->page("/dashboard/c/{$a['id']}/validation-jobs/{$a['validation']}?cursor=%%%")->getStatusCode());
        self::assertLessThan(500, $this->page("/dashboard/c/{$a['id']}/send-jobs?cursor=".rtrim(strtr(base64_encode('["not-a-date","x"]'), '+/', '-_'), '='))->getStatusCode(),
            'a cursor with a malformed value is a client error at worst');
    }

    public function testCsvExportStreamsTheFilteredResultsWithTheApiFields(): void
    {
        $a = $this->richClient();
        $this->signIn($this->newUser(false, $a['client']));
        $this->browser->request('GET', "/dashboard/c/{$a['id']}/validation-jobs/{$a['validation']}/results.csv");
        $r = $this->browser->getInternalResponse();
        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('text/csv', (string) $r->getHeader('Content-Type'));
        self::assertStringContainsString('attachment', (string) $r->getHeader('Content-Disposition'));
        $lines = array_values(array_filter(explode("\n", $r->getContent())));
        self::assertCount(31, $lines);
        self::assertSame(ClientReadModel::EXPORT_COLUMNS, str_getcsv($lines[0], ',', '"', ''));
        self::assertStringContainsString("'=HYPERLINK", $r->getContent(), 'formula-like cells are neutralised');
        $this->browser->request('GET', "/dashboard/c/{$a['id']}/validation-jobs/{$a['validation']}/results.csv?classification=undeliverable");
        self::assertCount(4, array_filter(explode("\n", $this->browser->getInternalResponse()->getContent())));
    }

    public function testDomainVerificationReusesTheServiceAndNeedsOperateRole(): void
    {
        $client = $this->newClient();
        $domain = $this->pendingDomain($client);
        $viewer = $this->newUser(false, $client, ClientMembershipRole::Viewer);
        $member = $this->newUser(false, $client, ClientMembershipRole::Member);
        $base = '/dashboard/c/'.$client->getId()->toRfc4122();
        $action = "$base/sending-domains/{$domain->getId()->toRfc4122()}/verify";

        $this->signIn($viewer);
        self::assertSame(0, $this->crawler("$base/sending-domains")->filter("form[action=\"$action\"]")->count(), 'no action for viewers');
        $token = $this->browser->getCrawler()->filter('form input[name="_csrf_token"]')->attr('value'); // logout token only
        $this->browser->request('POST', $action, ['_token' => $token]);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'viewer cannot operate');

        $this->browser->request('POST', '/dashboard/logout', ['_csrf_token' => $token]);
        $this->signIn($member);
        $this->browser->disableReboot();
        $this->container()->get(\App\Domain\Dns\TxtResolver::class)->set('_smarthost-verification.'.$domain->getDomain(),
            ['smarthost-verification='.$domain->getVerificationToken()]);
        $r = $this->submit("$base/sending-domains", $action);
        self::assertSame(302, $r->getStatusCode());
        self::assertSame('verified', Db::owner()->fetchOne('SELECT status FROM sending_domains WHERE id = ?', [$domain->getId()->toRfc4122()]));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'sending_domain.verified' AND target_id = ?", [$domain->getId()->toRfc4122()]));
    }

    public function testMutationsNeedPostAndCsrf(): void
    {
        $client = $this->newClient();
        $domain = $this->pendingDomain($client);
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $action = '/dashboard/c/'.$client->getId()->toRfc4122()."/sending-domains/{$domain->getId()->toRfc4122()}/verify";
        self::assertSame(405, $this->page($action)->getStatusCode(), 'no mutating GET');
        $this->browser->request('POST', $action, ['_token' => 'forged']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        self::assertSame(0, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE target_id = ? AND action LIKE 'sending_domain.verif%'", [$domain->getId()->toRfc4122()]));
    }

    public function testOwnGlobalOptOutCanBeLiftedUnderPhase5Rules(): void
    {
        $client = $this->newClient();
        Db::owner()->executeStatement('UPDATE clients SET can_submit_global_suppressions = true WHERE id = ?', [$client->getId()->toRfc4122()]);
        $id = SchemaFixtures::id();
        Db::owner()->insert('suppressions', ['id' => $id, 'client_id' => null, 'address_or_domain' => 'gone'.bin2hex(random_bytes(3)).'@example.test',
            'scope_type' => 'address', 'reason' => 'recipient_global_opt_out', 'source_client_id' => $client->getId()->toRfc4122(), 'external_reference' => 'crm-1']);
        Db::owner()->insert('global_suppression_requests', ['id' => SchemaFixtures::id(), 'client_id' => $client->getId()->toRfc4122(),
            'operation' => 'create_global_opt_out', 'idempotency_key' => SchemaFixtures::id(), 'request_hash' => str_repeat('a', 64),
            'suppression_id' => $id, 'response_status' => 201]);
        $base = '/dashboard/c/'.$client->getId()->toRfc4122();
        $action = "$base/global-opt-outs/$id/lift";

        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Member));
        self::assertSame(0, $this->crawler("$base/suppressions")->filter("form[action=\"$action\"]")->count(), 'members do not get the lift action');
        $this->browser->request('POST', $action, ['_token' => 'x']);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());

        $this->browser->getCookieJar()->clear(); // start a new session
        $admin = $this->newUser(false, $client, ClientMembershipRole::Admin);
        $this->signIn($admin);
        // Suspended: the page offers no lift and a forged attempt is refused by the Phase 5 rule.
        Db::owner()->executeStatement("UPDATE clients SET status = 'suspended' WHERE id = ?", [$client->getId()->toRfc4122()]);
        $page = $this->crawler("$base/suppressions");
        self::assertSame(0, $page->filter("form[action=\"$action\"]")->count());
        $logoutToken = $page->filter('input[name="_csrf_token"]')->attr('value');
        Db::owner()->executeStatement("UPDATE clients SET status = 'active' WHERE id = ?", [$client->getId()->toRfc4122()]);
        $form = $this->crawler("$base/suppressions")->filter("form[action=\"$action\"]");
        $token = $form->filter('input[name="_token"]')->attr('value');
        Db::owner()->executeStatement("UPDATE clients SET status = 'suspended' WHERE id = ?", [$client->getId()->toRfc4122()]);
        $this->browser->request('POST', $action, ['_token' => $token]);
        self::assertNull(Db::owner()->fetchOne('SELECT lifted_at FROM suppressions WHERE id = ?', [$id]), 'not while suspended (D-37)');
        self::assertStringContainsString('may not lift', self::text($this->page("$base/suppressions")));

        Db::owner()->executeStatement("UPDATE clients SET status = 'active' WHERE id = ?", [$client->getId()->toRfc4122()]);
        $this->submit("$base/suppressions", $action);
        self::assertNotNull(Db::owner()->fetchOne('SELECT lifted_at FROM suppressions WHERE id = ?', [$id]));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'suppression.global_opt_out_lifted' AND target_id = ?", [$id]));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM suppressions WHERE id = ?', [$id]), 'history is kept');
        self::assertNotEmpty($logoutToken);
    }

    public function testTerminologyNeverClaimsDeliveryOrReading(): void
    {
        $a = $this->richClient();
        $this->signIn($this->newUser(false, $a['client']));
        $base = "/dashboard/c/{$a['id']}";
        foreach (['', '/send-jobs', "/send-jobs/{$a['send']['job']}", "/messages/{$a['send']['messages'][0]}", '/validation-jobs',
            "/validation-jobs/{$a['validation']}", '/suppressions', '/sending-domains', '/usage'] as $path) {
            $text = self::text($this->page($base.$path));
            self::assertDoesNotMatchRegularExpression('/\b(Delivered|delivered to (the )?inbox|Read|Seen|Opened|Confirmed read)\b/', $text, $path);
        }
    }

    public function testSecurityHeadersAndAssets(): void
    {
        $a = $this->richClient();
        $this->signIn($this->newUser(false, $a['client']));
        $r = $this->page("/dashboard/c/{$a['id']}");
        $csp = (string) $r->headers->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9_-]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $csp, $m);
        $crawler = new Crawler((string) $r->getContent());
        self::assertSame($m[1], $crawler->filter('script[type="importmap"]')->attr('nonce'), 'the import map carries the request nonce');
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertSame('DENY', $r->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertStringNotContainsString('ga.jspm.io', (string) $r->getContent(), 'no CDN polyfill');
        self::assertDoesNotMatchRegularExpression('#(src|href)="https?://#', (string) $r->getContent(), 'no third-party assets');
        $css = $crawler->filter('link[rel="stylesheet"]')->attr('href');
        $this->browser->request('GET', $css);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringStartsWith('text/css', (string) $this->browser->getResponse()->headers->get('Content-Type'));
    }

    public function testLogoutIsAPostWithCsrf(): void
    {
        $client = $this->newClient();
        $this->signIn($this->newUser(false, $client));
        $crawler = $this->crawler('/dashboard/c/'.$client->getId()->toRfc4122());
        $this->browser->request('GET', '/dashboard/logout');
        self::assertContains($this->browser->getResponse()->getStatusCode(), [403, 405], 'GET never signs out');
        self::assertSame(200, $this->page('/dashboard/c/'.$client->getId()->toRfc4122())->getStatusCode(), 'still signed in');
        $this->browser->request('POST', '/dashboard/logout', ['_csrf_token' => 'forged']);
        self::assertSame(200, $this->page('/dashboard/c/'.$client->getId()->toRfc4122())->getStatusCode(), 'a forged token does not sign out');
        $this->browser->request('POST', '/dashboard/logout', ['_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
        self::assertStringEndsWith('/dashboard/login', (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertStringEndsWith('/dashboard/login', (string) $this->page('/dashboard/c/'.$client->getId()->toRfc4122())->headers->get('Location'));
    }

    /** Client A's user can never see client B's data, whatever id is put in the URL. */
    public function testTenantIsolationBetweenTwoClients(): void
    {
        $a = $this->richClient('Client A');
        $b = $this->richClient('Client B Secret Name');
        $o = Db::owner();
        // B reported a global opt-out of an address A also sends to; A's message to it was suppressed.
        $shared = 'shared'.bin2hex(random_bytes(3)).'@example.test';
        $optOut = SchemaFixtures::id();
        $o->executeStatement('UPDATE clients SET can_submit_global_suppressions = true WHERE id = ?', [$b['id']]);
        $o->insert('suppressions', ['id' => $optOut, 'client_id' => null, 'address_or_domain' => $shared, 'scope_type' => 'address',
            'reason' => 'recipient_global_opt_out', 'source_client_id' => $b['id'], 'external_reference' => 'B-PRIVATE-REF']);
        $o->insert('global_suppression_requests', ['id' => SchemaFixtures::id(), 'client_id' => $b['id'], 'operation' => 'create_global_opt_out',
            'idempotency_key' => SchemaFixtures::id(), 'request_hash' => str_repeat('b', 64), 'suppression_id' => $optOut, 'response_status' => 201]);
        $aMessage = $a['send']['messages'][1];
        $o->executeStatement("UPDATE messages SET recipient_address = ?, current_status = 'suppressed', postfix_queue_id = NULL WHERE id = ?", [$shared, $aMessage]);
        $o->insert('message_events', ['id' => SchemaFixtures::id(), 'message_id' => $aMessage, 'event_type' => 'message_suppressed',
            'event_source' => 'delivery_daemon', 'source_event_key' => 'message_suppressed:'.$aMessage, 'occurred_at' => gmdate('Y-m-d H:i:s'),
            'metadata_json' => json_encode(['suppression_id' => $optOut, 'reason' => 'recipient_global_opt_out', 'checked' => 'before_submission'])]);

        $this->signIn($this->newUser(false, $a['client'], ClientMembershipRole::Admin));
        $bBase = "/dashboard/c/{$b['id']}";
        $aBase = "/dashboard/c/{$a['id']}";
        $foreign = [
            $bBase, "$bBase/validation-jobs", "$bBase/validation-jobs/{$b['validation']}", "$bBase/validation-jobs/{$b['validation']}/results.csv",
            "$bBase/send-jobs", "$bBase/send-jobs/{$b['send']['job']}", "$bBase/messages/{$b['send']['messages'][0]}",
            "$bBase/suppressions", "$bBase/sending-domains", "$bBase/usage",
            // B's ids under A's own client id: still not found.
            "$aBase/validation-jobs/{$b['validation']}", "$aBase/validation-jobs/{$b['validation']}/results.csv", "$aBase/send-jobs/{$b['send']['job']}",
            "$aBase/messages/{$b['send']['messages'][0]}",
            // Unknown ids answer the same.
            '/dashboard/c/'.SchemaFixtures::id(), "$aBase/messages/".SchemaFixtures::id(), "$aBase/send-jobs/not-a-uuid",
        ];
        $notFound = null;
        foreach ($foreign as $uri) {
            $r = $this->page($uri);
            self::assertSame(404, $r->getStatusCode(), $uri);
            $body = (string) $r->getContent();
            self::assertStringNotContainsString('Client B Secret Name', $body, $uri);
            $notFound ??= $body;
        }
        foreach (["$bBase/global-opt-outs/$optOut/lift", "$aBase/global-opt-outs/$optOut/lift",
            "$aBase/sending-domains/{$b['domain']}/verify", "$bBase/sending-domains/{$b['domain']}/verify"] as $action) {
            $this->browser->request('POST', $action, ['_token' => 'x']);
            self::assertContains($this->browser->getResponse()->getStatusCode(), [403, 404], $action);
        }
        self::assertNull($o->fetchOne('SELECT lifted_at FROM suppressions WHERE id = ?', [$optOut]));

        // A sees the effect on its own message, with only the broad reason: no reporter, reference or ids.
        $page = $this->page("$aBase/suppressions?recipient=".rawurlencode($shared));
        $html = (string) $page->getContent();
        self::assertStringContainsString($shared, $html);
        self::assertStringContainsString('Recipient asked not to be contacted', $html);
        foreach (['B-PRIVATE-REF', 'Client B Secret Name', $optOut, $b['id']] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        $timeline = (string) $this->page("$aBase/messages/$aMessage")->getContent();
        self::assertStringContainsString('Recipient asked not to be contacted', $timeline);
        foreach (['B-PRIVATE-REF', 'Client B Secret Name', $optOut] as $secret) {
            self::assertStringNotContainsString($secret, $timeline);
        }
        // A's own lists contain nothing of B.
        foreach (['', '/validation-jobs', '/send-jobs', '/usage', '/sending-domains', '/suppressions?state=all'] as $p) {
            $body = (string) $this->page($aBase.$p)->getContent();
            foreach ([$b['validation'], $b['send']['job'], $b['domain'], 'Client B Secret Name'] as $secret) {
                self::assertStringNotContainsString($secret, $body, "$p shows $secret");
            }
        }
        // The home page lists only A.
        self::assertStringNotContainsString('Client B', (string) $this->page('/dashboard')->getContent());
    }

    public function testUserWithoutMembershipSeesNoClientData(): void
    {
        $a = $this->richClient();
        $this->signIn($this->newUser(false, null));
        self::assertStringContainsString('not a member of any client', self::text($this->page('/dashboard')));
        self::assertSame(404, $this->page("/dashboard/c/{$a['id']}")->getStatusCode());
        $disabled = $this->newUser(false, $a['client']);
        $this->browser->getCookieJar()->clear();
        $this->signIn($disabled);
        $this->accounts()->setDisabled($this->reload($disabled), true, self::actor());
        self::assertNotSame(200, $this->page("/dashboard/c/{$a['id']}")->getStatusCode(), 'a disabled user loses access at once');
    }
}
