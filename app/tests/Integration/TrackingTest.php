<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\DashboardFixtures;
use App\Tests\Support\Db;
use App\Tracking\TrackingRecorder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6 tracking endpoints (spec message_tracking.engagement_tracking):
 * recorded opens and clicks, the bounded recording rule, the privacy of the
 * public responses and, as a hard acceptance condition, that the click endpoint
 * can never be used as an open redirect.
 */
final class TrackingTest extends ApiTestCase
{
    private const PIXEL_SHA256 = '548f2d6f4d0d820c6c5ffbeffcbd7f0e73193e2932eefe542accc84762deec87';

    /** @return array{client: string, job: string, message: string, token: string, other: string, otherToken: string} */
    private function trackedMessages(bool $opens = true, bool $clicks = true): array
    {
        $client = $this->newClient();
        $domain = $this->verifiedDomain($client);
        $o = Db::owner();
        $set = DashboardFixtures::sendJob($o, $client->getId()->toRfc4122(), $domain->getId()->toRfc4122(), 2,
            ['track_opens' => $opens, 'track_clicks' => $clicks]);
        [$message, $other] = $set['messages'];
        DashboardFixtures::link($o, $message, 1, 'https://shop.example.test/a?x=1&y=%20two#frag');
        DashboardFixtures::link($o, $message, 2, 'http://plain.example.test/path');
        DashboardFixtures::link($o, $other, 1, 'https://other.example.test/b');
        DashboardFixtures::link($o, $other, 3, 'https://other.example.test/only-on-the-other-message');

        return ['client' => $client->getId()->toRfc4122(), 'job' => $set['job'], 'message' => $message,
            'token' => (string) $o->fetchOne('SELECT tracking_token FROM messages WHERE id = ?', [$message]),
            'other' => $other, 'otherToken' => (string) $o->fetchOne('SELECT tracking_token FROM messages WHERE id = ?', [$other])];
    }

    private function get(string $uri, array $server = []): Response
    {
        $this->browser->request('GET', $uri, server: $server + ['REMOTE_ADDR' => '192.0.2.'.random_int(1, 254)]);

        return $this->browser->getResponse();
    }

    /** @return list<array<string, mixed>> */
    private static function events(string $messageId, string $type): array
    {
        return Db::owner()->fetchAllAssociative('SELECT * FROM message_events WHERE message_id = ? AND event_type = ? ORDER BY occurred_at, id',
            [$messageId, $type]);
    }

    private static function ageEvents(string $messageId, string $type, int $seconds): void
    {
        Db::owner()->executeStatement("UPDATE message_events SET occurred_at = occurred_at - make_interval(secs => ?) WHERE message_id = ? AND event_type = ?",
            [$seconds, $messageId, $type]);
    }

    private static function assertPixel(Response $r): void
    {
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('image/gif', $r->headers->get('Content-Type'));
        self::assertSame(self::PIXEL_SHA256, hash('sha256', (string) $r->getContent()));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
        self::assertFalse($r->headers->has('Set-Cookie'), 'tracking never sets cookies');
        self::assertFalse($r->headers->has('Content-Security-Policy'), 'dashboard headers are not applied to tracking');
    }

    public function testValidOpenRecordsOneAppendOnlyEventAndReturnsThePixel(): void
    {
        $t = $this->trackedMessages();
        $before = Db::owner()->fetchAssociative('SELECT current_status, resolved_at FROM messages WHERE id = ?', [$t['message']]);
        $r = $this->get("/t/o/{$t['token']}.gif");
        self::assertPixel($r);
        $events = self::events($t['message'], 'open_recorded');
        self::assertCount(1, $events);
        self::assertSame('tracking_endpoint', $events[0]['event_source']);
        self::assertNull($events[0]['source_event_key']);
        self::assertSame('{}', $events[0]['metadata_json']);
        self::assertNull($events[0]['smtp_code']);
        self::assertSame($before, Db::owner()->fetchAssociative('SELECT current_status, resolved_at FROM messages WHERE id = ?', [$t['message']]),
            'an open never changes the transport status');
        self::assertSame([], self::events($t['other'], 'open_recorded'));
    }

    public function testUnknownMalformedAndIneligibleTokensGetTheSamePixelAndRecordNothing(): void
    {
        $t = $this->trackedMessages();
        $valid = $this->get("/t/o/{$t['token']}.gif");
        $unknown = $this->get('/t/o/'.str_repeat('A', 43).'.gif');
        self::assertPixel($unknown);
        self::assertSame($valid->getContent(), $unknown->getContent());
        foreach (['Content-Type', 'Cache-Control', 'Referrer-Policy', 'X-Content-Type-Options', 'Content-Length'] as $h) {
            self::assertSame($valid->headers->get($h), $unknown->headers->get($h), "header $h does not reveal the token's existence");
        }
        foreach (['short', str_repeat('a', 200), 'abc$def'.str_repeat('x', 30), str_repeat('A', 43).'%00'] as $bad) {
            $r = $this->get('/t/o/'.$bad.'.gif');
            self::assertContains($r->getStatusCode(), [200, 404], "malformed token $bad never errors");
            if (200 === $r->getStatusCode()) {
                self::assertPixel($r);
            }
        }
        self::assertCount(1, self::events($t['message'], 'open_recorded'), 'only the valid request was recorded');
    }

    public function testOpenTrackingDisabledRecordsNothing(): void
    {
        $t = $this->trackedMessages(opens: false, clicks: true);
        self::assertPixel($this->get("/t/o/{$t['token']}.gif"));
        self::assertSame([], self::events($t['message'], 'open_recorded'));
    }

    public function testMessageNotYetHandedToPostfixOrWithoutTokenIsNeverTracked(): void
    {
        $t = $this->trackedMessages();
        Db::owner()->executeStatement('UPDATE messages SET postfix_queue_id = NULL WHERE id = ?', [$t['message']]);
        self::assertPixel($this->get("/t/o/{$t['token']}.gif"));
        self::assertSame(404, $this->get("/t/c/{$t['token']}/1")->getStatusCode());
        self::assertSame([], self::events($t['message'], 'open_recorded'));
        self::assertSame([], self::events($t['message'], 'click_recorded'));
        // A job without tracking allocates no token at all (Go): nothing can address its messages.
        $client = $this->newClient();
        $untracked = DashboardFixtures::sendJob(Db::owner(), $client->getId()->toRfc4122(), $this->verifiedDomain($client)->getId()->toRfc4122(), 1);
        self::assertNull(Db::owner()->fetchOne('SELECT tracking_token FROM messages WHERE id = ?', [$untracked['messages'][0]]));
    }

    public function testHeadRequestsAreAnsweredButNotRecorded(): void
    {
        $t = $this->trackedMessages();
        $this->browser->request('HEAD', "/t/o/{$t['token']}.gif");
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('HEAD', "/t/c/{$t['token']}/1");
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertSame([], self::events($t['message'], 'open_recorded'));
        self::assertSame([], self::events($t['message'], 'click_recorded'));
    }

    public function testRepeatedOpensAreRecordedOncePerIntervalAndHistoryIsKept(): void
    {
        $t = $this->trackedMessages();
        for ($i = 0; $i < 5; ++$i) {
            self::assertPixel($this->get("/t/o/{$t['token']}.gif"));
        }
        self::assertCount(1, self::events($t['message'], 'open_recorded'), 'repeats within the interval are not recorded');
        self::ageEvents($t['message'], 'open_recorded', TrackingRecorder::OPEN_INTERVAL_SECONDS + 1);
        $this->get("/t/o/{$t['token']}.gif");
        self::assertCount(2, self::events($t['message'], 'open_recorded'), 'a later open is a new recorded open');
        self::ageEvents($t['message'], 'open_recorded', TrackingRecorder::OPEN_INTERVAL_SECONDS + 1);
        $this->get("/t/o/{$t['token']}.gif");
        self::assertCount(3, self::events($t['message'], 'open_recorded'), 'earlier events are never removed');
    }

    public function testRecordingIsBoundedPerMessage(): void
    {
        $t = $this->trackedMessages();
        Db::owner()->executeStatement(<<<'SQL'
            INSERT INTO message_events (id, message_id, event_type, event_source, occurred_at)
            SELECT gen_random_uuid(), ?, 'open_recorded', 'tracking_endpoint', now() - interval '1 day' - i * interval '1 minute'
              FROM generate_series(1, ?) AS i
            SQL, [$t['message'], TrackingRecorder::MAX_EVENTS_PER_MESSAGE]);
        self::assertPixel($this->get("/t/o/{$t['token']}.gif"));
        self::assertCount(TrackingRecorder::MAX_EVENTS_PER_MESSAGE, self::events($t['message'], 'open_recorded'));
        // Over the cap a click still redirects; only the write is skipped.
        Db::owner()->executeStatement(<<<'SQL'
            INSERT INTO message_events (id, message_id, event_type, event_source, metadata_json, occurred_at)
            SELECT gen_random_uuid(), ?, 'click_recorded', 'tracking_endpoint', '{"link_index": 1}', now() - interval '1 day' - i * interval '1 minute'
              FROM generate_series(1, ?) AS i
            SQL, [$t['message'], TrackingRecorder::MAX_EVENTS_PER_MESSAGE]);
        $r = $this->get("/t/c/{$t['token']}/1");
        self::assertSame(302, $r->getStatusCode());
        self::assertCount(TrackingRecorder::MAX_EVENTS_PER_MESSAGE, self::events($t['message'], 'click_recorded'));
    }

    public function testRateLimitedRequestsAreServedWithoutWrites(): void
    {
        $t = $this->trackedMessages();
        $recorder = $this->container()->get(TrackingRecorder::class);
        self::assertFalse($recorder->recordOpen($t['token'], false));
        self::assertSame('https://shop.example.test/a?x=1&y=%20two#frag', $recorder->resolveClick($t['token'], 1, false));
        self::assertSame([], self::events($t['message'], 'open_recorded'));
        self::assertSame([], self::events($t['message'], 'click_recorded'));
        self::assertTrue($recorder->recordOpen($t['token'], true));
    }

    public function testExpiredTokensAreTreatedAsUnknown(): void
    {
        $t = $this->trackedMessages();
        Db::owner()->executeStatement("UPDATE messages SET created_at = now() - interval '31 days' WHERE id = ?", [$t['message']]);
        $this->withEnv(['APP_RETENTION_TRACKING_DAYS' => '30'], function () use ($t): void {
            self::assertPixel($this->get("/t/o/{$t['token']}.gif"));
            self::assertSame(404, $this->get("/t/c/{$t['token']}/1")->getStatusCode());
        });
        self::assertSame([], self::events($t['message'], 'open_recorded'));
        self::assertSame([], self::events($t['message'], 'click_recorded'));
        // Empty (the default) means no expiry.
        self::assertSame(302, $this->get("/t/c/{$t['token']}/1")->getStatusCode());
    }

    public function testValidClickRecordsThenRedirectsExactlyToTheStoredTarget(): void
    {
        $t = $this->trackedMessages();
        $r = $this->get("/t/c/{$t['token']}/1");
        self::assertSame(302, $r->getStatusCode());
        self::assertSame('https://shop.example.test/a?x=1&y=%20two#frag', $r->headers->get('Location'));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
        self::assertFalse($r->headers->has('Set-Cookie'));
        $clicks = self::events($t['message'], 'click_recorded');
        self::assertCount(1, $clicks, 'the click is committed before the redirect is returned');
        self::assertSame(['link_index' => 1], json_decode($clicks[0]['metadata_json'], true));
        self::assertSame('tracking_endpoint', $clicks[0]['event_source']);
        self::assertNull($clicks[0]['source_event_key']);
        self::assertSame([], self::events($t['message'], 'open_recorded'), 'a click is never recorded as an open');

        $r2 = $this->get("/t/c/{$t['token']}/2");
        self::assertSame('http://plain.example.test/path', $r2->headers->get('Location'));
    }

    public function testRepeatedClicksPerLink(): void
    {
        $t = $this->trackedMessages();
        $this->get("/t/c/{$t['token']}/1");
        $this->get("/t/c/{$t['token']}/1");
        $this->get("/t/c/{$t['token']}/2");
        self::assertSame([1, 2], array_map(fn ($e) => json_decode($e['metadata_json'], true)['link_index'], self::events($t['message'], 'click_recorded')),
            'a repeat of the same link within the interval is not recorded; another link is');
        self::ageEvents($t['message'], 'click_recorded', TrackingRecorder::CLICK_INTERVAL_SECONDS + 1);
        self::assertSame(302, $this->get("/t/c/{$t['token']}/1")->getStatusCode());
        self::assertCount(3, self::events($t['message'], 'click_recorded'));
    }

    public function testUnknownTokenWrongIndexAndForeignLinkAre404WithoutEvents(): void
    {
        $t = $this->trackedMessages();
        $unknown = $this->get('/t/c/'.str_repeat('B', 43).'/1');
        self::assertSame(404, $unknown->getStatusCode());
        foreach (["/t/c/{$t['token']}/99", "/t/c/{$t['token']}/0", "/t/c/{$t['token']}/3", "/t/c/short/1", "/t/c/{$t['token']}/-1",
            "/t/c/{$t['token']}/1.5", "/t/c/{$t['token']}/abc"] as $uri) {
            $r = $this->get($uri);
            self::assertSame(404, $r->getStatusCode(), $uri);
            self::assertNull($r->headers->get('Location'), $uri);
        }
        self::assertSame($unknown->getContent(), $this->get("/t/c/{$t['token']}/99")->getContent(), 'the same 404 for unknown token and unknown link');
        // Link 3 exists only on the other message: the token decides the message, never the index.
        self::assertSame('https://other.example.test/only-on-the-other-message', $this->get("/t/c/{$t['otherToken']}/3")->headers->get('Location'));
        self::assertSame([], self::events($t['message'], 'click_recorded'));
    }

    public function testClickTrackingDisabledIs404(): void
    {
        $t = $this->trackedMessages(opens: true, clicks: false);
        self::assertSame(404, $this->get("/t/c/{$t['token']}/1")->getStatusCode());
        self::assertSame([], self::events($t['message'], 'click_recorded'));
    }

    /** Hard acceptance condition: nothing in the request can choose the redirect target. */
    public function testOpenRedirectAttacksFail(): void
    {
        $t = $this->trackedMessages();
        $stored = 'https://shop.example.test/a?x=1&y=%20two#frag';
        $evil = 'https://evil.example.test/phish';
        $attempts = [
            "/t/c/{$t['token']}/1?url=".rawurlencode($evil),
            "/t/c/{$t['token']}/1?target=".rawurlencode($evil).'&next='.rawurlencode($evil).'&redirect='.rawurlencode($evil),
            "/t/c/{$t['token']}/1?u=//evil.example.test",
        ];
        foreach ($attempts as $uri) {
            $r = $this->get($uri, ['HTTP_REFERER' => $evil, 'HTTP_HOST' => 'evil.example.test', 'HTTP_X_FORWARDED_HOST' => 'evil.example.test']);
            self::assertSame(302, $r->getStatusCode(), $uri);
            self::assertSame($stored, $r->headers->get('Location'), "$uri: only the stored target");
        }
        foreach (["/t/c/{$t['token']}/1/".rawurlencode($evil), "/t/c/{$t['token']}/".rawurlencode($evil), '/t/c/'.rawurlencode($evil).'/1',
            "/t/c/{$t['token']}/1%2F..%2F..%2Fevil", "/t/c/{$t['token']}//evil.example.test", "/t/o/{$t['token']}.gif?url=".rawurlencode($evil)] as $uri) {
            $r = $this->get($uri);
            self::assertNotSame($evil, $r->headers->get('Location'), $uri);
            self::assertFalse($r->isRedirect() && !str_starts_with((string) $r->headers->get('Location'), 'https://shop.example.test/'), $uri);
        }
        // A target outside http/https can never be stored (database CHECK), so it can never be served.
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.example.test', 'ftp://files.example.test/'] as $target) {
            try {
                DashboardFixtures::link(Db::owner(), $t['message'], 7, $target);
                self::fail("stored $target");
            } catch (\Doctrine\DBAL\Exception\DriverException) {
            }
        }
        // Defence in depth for anything the CHECK lets through.
        self::assertFalse(TrackingRecorder::isSafeTarget("https://ok.example.test/\r\nSet-Cookie: x=1"));
        self::assertFalse(TrackingRecorder::isSafeTarget('https:///no-host'));
        self::assertFalse(TrackingRecorder::isSafeTarget('https://a.example.test/ space'));
        self::assertTrue(TrackingRecorder::isSafeTarget('HTTPS://Shop.Example.TEST/a?b=c#d'));
        Db::owner()->executeStatement("UPDATE message_links SET target_url = ? WHERE message_id = ? AND link_index = 2",
            ["https://ok.example.test/\tbad", $t['message']]);
        self::assertSame(404, $this->get("/t/c/{$t['token']}/2")->getStatusCode(), 'a stored target with control characters is never served');
    }

    public function testResponsesRevealNoRecipientClientOrMessageData(): void
    {
        $t = $this->trackedMessages();
        $address = (string) Db::owner()->fetchOne('SELECT recipient_address FROM messages WHERE id = ?', [$t['message']]);
        foreach (["/t/o/{$t['token']}.gif", "/t/c/{$t['token']}/1", "/t/c/{$t['token']}/99", '/t/o/'.str_repeat('C', 43).'.gif'] as $uri) {
            $r = $this->get($uri);
            $all = $r->getContent().json_encode($r->headers->all());
            foreach ([$address, $t['message'], $t['client'], $t['job'], $t['token']] as $secret) {
                self::assertStringNotContainsString($secret, $all, "$uri leaks $secret");
            }
        }
        self::assertStringNotContainsString('@', "/t/o/{$t['token']}.gif");
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $t['token']);
    }

    public function testTrackingRequestsDoNotLogTokens(): void
    {
        $t = $this->trackedMessages();
        $log = static::getContainer()->getParameter('kernel.logs_dir').'/test.log';
        $before = is_file($log) ? filesize($log) : 0;
        $this->get("/t/o/{$t['token']}.gif");
        $this->get("/t/c/{$t['token']}/1");
        $this->get("/t/c/{$t['token']}/99");
        $written = is_file($log) ? (string) file_get_contents($log, offset: $before) : '';
        self::assertStringNotContainsString($t['token'], $written);
    }

    public function testTrackingEventsDoNotDisturbTransportDeduplication(): void
    {
        $t = $this->trackedMessages();
        $this->get("/t/o/{$t['token']}.gif");
        self::ageEvents($t['message'], 'open_recorded', 3600);
        $this->get("/t/o/{$t['token']}.gif");
        // Two unkeyed tracking events coexist; a transport key is still unique.
        $o = Db::owner();
        $o->insert('message_events', ['id' => SchemaFixtures::id(), 'message_id' => $t['message'], 'event_type' => 'deferred',
            'event_source' => 'postfix_log', 'source_event_key' => 'k-'.$t['message'], 'occurred_at' => date('Y-m-d H:i:s')]);
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $o->insert('message_events', ['id' => SchemaFixtures::id(), 'message_id' => $t['message'], 'event_type' => 'deferred',
            'event_source' => 'postfix_log', 'source_event_key' => 'k-'.$t['message'], 'occurred_at' => date('Y-m-d H:i:s')]);
    }
}
