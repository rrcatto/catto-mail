<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\OpenApiContract;
use App\Audit\AuditActor;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookEndpointStatus;
use App\Enum\WebhookEventType;
use App\Enum\WebhookSubjectType;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\DashboardFixtures;
use App\Tests\Support\Db;
use App\Tests\Support\StubHostResolver;
use App\Webhook\WebhookDispatcher;
use App\Webhook\WebhookEndpointService;
use App\Webhook\WebhookOutbox;
use App\Webhook\WebhookPayloadFactory;
use App\Webhook\WebhookSecrets;
use App\Webhook\WebhookSigner;
use App\Webhook\WebhookTargetGuard;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * The Symfony webhook worker (Phase 7, specification 2.8): fan-out, claiming,
 * signed delivery, retries, fencing, SSRF policy and the contract of the body.
 * HTTP is a MockHttpClient and DNS a stub: nothing leaves the test pod.
 */
final class WebhookWorkerTest extends ApiTestCase
{
    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];
    /** @var list<MockResponse|callable> */
    private array $script = [];
    private ?CollectingLogger $log = null;

    protected function setUp(): void
    {
        parent::setUp();
        StubHostResolver::$answers = ['hooks.example.test' => ['93.184.216.34'], 'other.example.test' => ['93.184.216.35']];
        // The test database is shared: start every test from an empty outbox and no due deliveries.
        Db::owner()->executeStatement('UPDATE webhook_events SET fanned_out_at = now() WHERE fanned_out_at IS NULL');
        Db::owner()->executeStatement("UPDATE webhook_deliveries SET status = 'failed', claimed_by = NULL, lease_expires_at = NULL, next_attempt_at = NULL, last_error = 'test reset' WHERE status = 'pending'");
    }

    private function dispatcher(int $maxAttempts = 4, array $allowedPrivate = [], string $env = 'test', int $timeout = 2): WebhookDispatcher
    {
        $c = $this->container();
        $this->log = new CollectingLogger();
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $line) {
                [$k, $v] = explode(': ', $line, 2);
                $headers[strtolower($k)] = $v;
            }
            $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => (string) $options['body'],
                'resolve' => $options['resolve'] ?? [], 'max_redirects' => $options['max_redirects'] ?? null];
            $next = array_shift($this->script) ?? new MockResponse('ok', ['http_code' => 200]);

            return \is_callable($next) ? $next() : $next;
        });

        return new WebhookDispatcher($c->get('doctrine.dbal.webhook_connection'), $c->get('doctrine.orm.webhook_entity_manager'),
            $c->get(WebhookPayloadFactory::class), $c->get(WebhookSecrets::class),
            new WebhookTargetGuard(new StubHostResolver(), $env, implode(',', $allowedPrivate)), $http, $this->log,
            $maxAttempts, $timeout, $timeout + 3, 1, 30);
    }

    /** @return array{0: WebhookEndpoint, 1: string} */
    private function endpoint(\App\Entity\Client $client, string $url = 'https://hooks.example.test/smarthost', array $types = ['send.completed', 'validation.completed']): array
    {
        return $this->container()->get(WebhookEndpointService::class)->create($this->reload($client), $url, $types, self::actor());
    }

    private function event(\App\Entity\Client $client, WebhookEventType $type, WebhookSubjectType $subjectType, string $subjectId): string
    {
        $conn = $this->container()->get('doctrine')->getConnection();
        $conn->beginTransaction();
        $id = $this->container()->get(WebhookOutbox::class)->record($client->getId(), $type, $subjectType, Uuid::fromString($subjectId));
        $conn->commit();

        return $id->toRfc4122();
    }

    /** A completed send job of $client and its send.completed outbox event. */
    private function sendCompleted(\App\Entity\Client $client): array
    {
        $set = DashboardFixtures::sendJob(Db::owner(), $client->getId()->toRfc4122(), $this->verifiedDomain($client)->getId()->toRfc4122(), 2);
        Db::owner()->executeStatement("UPDATE send_jobs SET status = 'completed', completed_at = now() WHERE id = ?", [$set['job']]);

        return ['job' => $set['job'], 'event' => $this->event($client, WebhookEventType::SendCompleted, WebhookSubjectType::SendJob, $set['job'])];
    }

    private function work(WebhookDispatcher $d, string $worker = 'test-worker'): void
    {
        $d->fanOut(1000);
        $d->attempt($worker, $d->claim($worker, 50));
    }

    private static function delivery(string $eventId, ?string $endpointId = null): array
    {
        return Db::owner()->fetchAssociative('SELECT * FROM webhook_deliveries WHERE webhook_event_id = ?'.(null === $endpointId ? '' : ' AND webhook_endpoint_id = ?'),
            array_values(array_filter([$eventId, $endpointId]))) ?: [];
    }

    private static function due(string $eventId): void
    {
        Db::owner()->executeStatement("UPDATE webhook_deliveries SET next_attempt_at = now() - interval '1 second' WHERE webhook_event_id = ? AND status = 'pending'", [$eventId]);
    }

    public function testOneEventOneEndpointIsDeliveredSignedWithTheContractBody(): void
    {
        $client = $this->newClient();
        [$endpoint, $secret] = $this->endpoint($client);
        $sent = $this->sendCompleted($client);
        $d = $this->dispatcher();
        $this->work($d);

        self::assertCount(1, $this->requests);
        $r = $this->requests[0];
        self::assertSame('https://hooks.example.test/smarthost', $r['url']);
        self::assertSame('application/json', $r['headers']['content-type']);
        self::assertSame($sent['event'], $r['headers']['smarthost-event-id']);
        self::assertSame('send.completed', $r['headers']['smarthost-event-type']);
        self::assertStringStartsWith('Catto-Mail-Smarthost/', $r['headers']['user-agent']);
        self::assertTrue(WebhookSigner::verify($r['body'], $r['headers']['smarthost-signature'], [$secret], time()), 'the exact body bytes are signed');
        self::assertFalse(WebhookSigner::verify($r['body'].' ', $r['headers']['smarthost-signature'], [$secret], time()), 'an altered body fails');
        self::assertFalse(WebhookSigner::verify($r['body'], $r['headers']['smarthost-signature'], ['whsec_wrong'], time()));
        self::assertFalse(WebhookSigner::verify($r['body'], $r['headers']['smarthost-signature'], [$secret], time() + 301), 'a stale timestamp fails');
        preg_match('/t=(\d+)/', $r['headers']['smarthost-signature'], $t);
        self::assertFalse(WebhookSigner::verify($r['body'], str_replace('t='.$t[1], 't='.($t[1] + 1), $r['headers']['smarthost-signature']), [$secret], time()),
            'an altered timestamp fails');
        $row = self::delivery($sent['event']);
        self::assertSame($r['body'], (string) Db::owner()->fetchOne('SELECT payload_json::text FROM webhook_deliveries WHERE id = ?', [$row['id']]), 'stored bytes are the sent bytes');
        self::assertSame(hash('sha256', $r['body']), $row['payload_hash']);

        $contract = $this->service(OpenApiContract::class);
        self::assertSame([], $contract->validate(json_decode($r['body'], false), '#/components/schemas/WebhookEvent'));
        $body = json_decode($r['body'], true);
        self::assertSame(['id', 'type', 'created_at', 'data'], array_values(array_intersect(['id', 'type', 'created_at', 'data'], array_keys($body))));
        self::assertSame($sent['job'], $body['data']['id']);
        self::assertSame(['delivered', 1, 200], [$row['status'], (int) $row['attempt_count'], (int) $row['last_response_status']]);
        self::assertNotNull($row['delivered_at']);
        self::assertNull($row['claimed_by']);
        self::assertNotNull(Db::owner()->fetchOne('SELECT fanned_out_at FROM webhook_events WHERE id = ?', [$sent['event']]));
        foreach (['whsec_', $secret, 'tracking_token', 'verp'] as $secretish) {
            self::assertStringNotContainsString($secretish, $r['body']);
            self::assertStringNotContainsString($secret, $this->log->all(), 'no secret is logged');
        }
        self::assertStringNotContainsString($r['headers']['smarthost-signature'], $this->log->all(), 'no signature is logged');

        // Re-running fan-out and the worker changes nothing: one logical delivery, one request.
        $this->work($d);
        self::assertCount(1, $this->requests);
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_deliveries WHERE webhook_event_id = ?', [$sent['event']]));
    }

    public function testFanOutRespectsSubscriptionsStatusAndClient(): void
    {
        $client = $this->newClient();
        $other = $this->newClient();
        [$a] = $this->endpoint($client);
        [$b] = $this->endpoint($client, 'https://other.example.test/b', ['send.completed']);
        [$unsubscribed] = $this->endpoint($client, 'https://hooks.example.test/c', ['validation.completed']);
        [$disabled] = $this->endpoint($client, 'https://hooks.example.test/d', ['send.completed']);
        $this->container()->get(WebhookEndpointService::class)->setStatus($this->reload($disabled), WebhookEndpointStatus::Disabled, self::actor());
        [$foreign] = $this->endpoint($other, 'https://hooks.example.test/foreign', ['send.completed']);
        $sent = $this->sendCompleted($client);
        $this->work($this->dispatcher());
        $endpoints = Db::owner()->fetchFirstColumn('SELECT webhook_endpoint_id::text FROM webhook_deliveries WHERE webhook_event_id = ? ORDER BY 1', [$sent['event']]);
        $expected = [$a->getId()->toRfc4122(), $b->getId()->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $endpoints, 'one delivery per subscribed, enabled endpoint of the same client');
        self::assertCount(2, $this->requests);
        self::assertNotContains('https://hooks.example.test/foreign', array_column($this->requests, 'url'), 'never another client');
        self::assertNotContains('https://hooks.example.test/c', array_column($this->requests, 'url'));
        self::assertNotEmpty($unsubscribed->getId());
        self::assertNotEmpty($foreign->getId());
    }

    public function testEventWithoutMatchingEndpointCreatesNothingAndIsDone(): void
    {
        $client = $this->newClient();
        $sent = $this->sendCompleted($client);
        $this->work($this->dispatcher());
        self::assertSame([], $this->requests);
        self::assertSame([], self::delivery($sent['event']));
        self::assertNotNull(Db::owner()->fetchOne('SELECT fanned_out_at FROM webhook_events WHERE id = ?', [$sent['event']]));
    }

    public function testPermanentAndRetryableResponses(): void
    {
        $cases = [
            [new MockResponse('bad request', ['http_code' => 400]), 'failed', 400],
            [new MockResponse('', ['http_code' => 401]), 'failed', 401],
            [new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://169.254.169.254/latest/meta-data']]), 'failed', 302],
            [new MockResponse('', ['http_code' => 408]), 'pending', 408],
            [new MockResponse('slow down', ['http_code' => 429, 'response_headers' => ['Retry-After: 7']]), 'pending', 429],
            [new MockResponse('', ['http_code' => 500]), 'pending', 500],
            [new MockResponse('', ['http_code' => 503]), 'pending', 503],
            [new MockResponse([''], ['http_code' => 200]), 'pending', null],                         // timeout
            [new MockResponse('', ['error' => 'Connection refused for URI https://hooks.example.test']), 'pending', null],
        ];
        foreach ($cases as $i => [$response, $status, $http]) {
            $client = $this->newClient();
            [$endpoint] = $this->endpoint($client);
            $sent = $this->sendCompleted($client);
            $this->requests = [];
            $this->script = [$response];
            $this->work($this->dispatcher());
            $row = self::delivery($sent['event']);
            self::assertSame($status, $row['status'], "case $i");
            self::assertSame($http, null === $row['last_response_status'] ? null : (int) $row['last_response_status'], "case $i");
            self::assertNotNull($row['last_error'], "case $i");
            self::assertNull($row['claimed_by'], "case $i: the lease is released");
            self::assertCount(1, $this->requests, "case $i: never follows a redirect, never re-sends in the same round");
            if ('pending' === $status) {
                $delay = (int) Db::owner()->fetchOne('SELECT extract(epoch FROM next_attempt_at - now()) FROM webhook_deliveries WHERE id = ?', [$row['id']]);
                self::assertGreaterThanOrEqual(0, $delay, "case $i");
                if (429 === $http) {
                    self::assertGreaterThanOrEqual(5, $delay, 'Retry-After is honoured');
                }
            }
            self::assertNotEmpty($endpoint->getId());
        }
    }

    public function testRetryThenSuccessAndExhaustion(): void
    {
        $client = $this->newClient();
        $this->endpoint($client);
        $sent = $this->sendCompleted($client);
        $d = $this->dispatcher(maxAttempts: 3);
        $this->script = [new MockResponse('', ['http_code' => 500]), new MockResponse('', ['http_code' => 503]), new MockResponse('ok', ['http_code' => 204])];
        $this->work($d);
        self::due($sent['event']);
        $this->work($d);
        self::due($sent['event']);
        $this->work($d);
        $row = self::delivery($sent['event']);
        self::assertSame(['delivered', 3, 204], [$row['status'], (int) $row['attempt_count'], (int) $row['last_response_status']]);
        self::assertSame(array_fill(0, 3, $sent['event']), array_map(fn ($r) => $r['headers']['smarthost-event-id'], $this->requests), 'the same event id on every attempt');
        self::assertSame(['1', '2', '3'], array_map(fn ($r) => $r['headers']['smarthost-delivery-attempt'], $this->requests));

        $client2 = $this->newClient();
        $this->endpoint($client2);
        $sent2 = $this->sendCompleted($client2);
        $this->script = array_fill(0, 5, new MockResponse('', ['http_code' => 500]));
        for ($i = 0; $i < 5; ++$i) {
            $this->work($d);
            self::due($sent2['event']);
        }
        $row = self::delivery($sent2['event']);
        self::assertSame(['failed', 3], [$row['status'], (int) $row['attempt_count']], 'never retried forever');
        self::assertStringContainsString('exhausted', $row['last_error']);
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_deliveries WHERE id = ?', [$row['id']]), 'failed deliveries are kept');
    }

    public function testDnsRebindingIsPinnedAndRecheckedOnEveryAttempt(): void
    {
        $client = $this->newClient();
        $this->endpoint($client);
        $sent = $this->sendCompleted($client);
        $d = $this->dispatcher(env: 'production');
        $this->script = [new MockResponse('', ['http_code' => 503])];
        $this->work($d);
        self::assertCount(1, $this->requests);
        self::assertSame(['hooks.example.test' => '93.184.216.34'], $this->requests[0]['resolve'], 'the connection is pinned to the checked address');
        self::assertSame(0, $this->requests[0]['max_redirects'], 'redirects are never followed');
        // The name now rebinds to a private address: the next attempt is refused before any request.
        StubHostResolver::$answers['hooks.example.test'] = ['10.0.0.8'];
        self::due($sent['event']);
        $this->work($d);
        $row = self::delivery($sent['event']);
        self::assertCount(1, $this->requests, 'no request to the rebound address');
        self::assertSame(['failed', 2], [$row['status'], (int) $row['attempt_count']]);
        self::assertStringContainsString('non-public', $row['last_error']);
    }

    public function testCrashedWorkerIsReclaimedAndItsLateResultIsFenced(): void
    {
        $client = $this->newClient();
        $this->endpoint($client);
        $sent = $this->sendCompleted($client);
        $d = $this->dispatcher();
        $d->fanOut(1000);
        $claims = $d->claim('crashed-worker', 10);
        self::assertCount(1, $claims);
        self::assertSame([], $d->claim('second-worker', 10), 'a live lease is not claimed twice');
        // The first worker "dies" after sending: its lease expires.
        Db::owner()->executeStatement("UPDATE webhook_deliveries SET lease_expires_at = now() - interval '1 second' WHERE webhook_event_id = ?", [$sent['event']]);
        $reclaimed = $d->claim('second-worker', 10);
        self::assertCount(1, $reclaimed);
        self::assertSame(2, $reclaimed[0]['attempt']);
        $d->attempt('second-worker', $reclaimed);
        self::assertSame('delivered', self::delivery($sent['event'])['status']);
        // The crashed worker's late result changes nothing (fencing).
        $this->script = [new MockResponse('', ['http_code' => 500])];
        $d->attempt('crashed-worker', $claims);
        $row = self::delivery($sent['event']);
        self::assertSame(['delivered', 2], [$row['status'], (int) $row['attempt_count']]);
        self::assertSame(1, $d->stats['lost_lease']);
        // The receiver saw the same event twice: at-least-once.
        self::assertSame([$sent['event'], $sent['event']], array_map(fn ($r) => $r['headers']['smarthost-event-id'], $this->requests));
    }

    public function testConcurrentFanOutCreatesOneLogicalDeliveryEach(): void
    {
        $client = $this->newClient();
        $this->endpoint($client);
        $sent = $this->sendCompleted($client);
        // Worker A holds the event row; worker B's fan-out skips it.
        $holder = \Doctrine\DBAL\DriverManager::getConnection(Db::owner()->getParams());
        $holder->beginTransaction();
        $holder->fetchAllAssociative('SELECT id FROM webhook_events WHERE id = ? FOR UPDATE', [$sent['event']]);
        $b = $this->dispatcher();
        $b->fanOut(1000);
        self::assertSame([], self::delivery($sent['event']), 'locked rows are skipped, not waited on or duplicated');
        $holder->rollBack();
        $b->fanOut(1000);
        $b->fanOut(1000);
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_deliveries WHERE webhook_event_id = ?', [$sent['event']]));
        // Even a forced second insert is refused by the unique index.
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $row = self::delivery($sent['event']);
        Db::owner()->insert('webhook_deliveries', ['id' => SchemaFixtures::id(), 'client_id' => $row['client_id'], 'webhook_event_id' => $row['webhook_event_id'],
            'webhook_endpoint_id' => $row['webhook_endpoint_id'], 'event_type' => $row['event_type'], 'payload_json' => '{}', 'payload_hash' => 'x']);
    }

    public function testSecretRotationSignsWithBothSecretsOnlyDuringTheOverlap(): void
    {
        $client = $this->newClient();
        [$endpoint, $old] = $this->endpoint($client);
        $new = $this->container()->get(WebhookEndpointService::class)->rotateSecret($this->reload($endpoint), self::actor());
        $this->sendCompleted($client);
        $this->work($this->dispatcher());
        $sig = $this->requests[0]['headers']['smarthost-signature'];
        self::assertSame(2, substr_count($sig, 'v1='), 'current and previous secret during the overlap');
        self::assertTrue(WebhookSigner::verify($this->requests[0]['body'], $sig, [$new], time()));
        self::assertTrue(WebhookSigner::verify($this->requests[0]['body'], $sig, [$old], time()), 'a receiver still on the old secret accepts it');

        Db::owner()->executeStatement("UPDATE webhook_endpoints SET previous_signing_secret_expires_at = now() - interval '1 second' WHERE id = ?", [$endpoint->getId()->toRfc4122()]);
        $this->sendCompleted($client);
        $this->work($this->dispatcher());
        $sig = $this->requests[1]['headers']['smarthost-signature'];
        self::assertSame(1, substr_count($sig, 'v1='));
        self::assertTrue(WebhookSigner::verify($this->requests[1]['body'], $sig, [$new], time()));
        self::assertFalse(WebhookSigner::verify($this->requests[1]['body'], $sig, [$old], time()), 'the expired previous secret is not used');
    }

    public function testSsrfDestinationsAreRefusedAndNothingIsSent(): void
    {
        $targets = [
            'https://127.0.0.1/x', 'https://0.0.0.0/x', 'https://10.1.2.3/x', 'https://172.16.5.4/x', 'https://192.168.1.1/x', 'https://[::1]/x',
            'https://[fe80::1]/x', 'https://[fd00::1]/x', 'https://169.254.169.254/latest/meta-data', 'https://100.64.0.1/x', 'https://[::ffff:127.0.0.1]/x',
            'https://224.0.0.1/x', 'https://loopback.example.test/x', 'https://metadata.example.test/x', 'https://mixed.example.test/x',
            'http://hooks.example.test/plain-http-in-production',
        ];
        StubHostResolver::$answers += ['loopback.example.test' => ['127.0.0.1'], 'metadata.example.test' => ['169.254.169.254'],
            'mixed.example.test' => ['93.184.216.34', '10.0.0.5'], 'webhook-receiver' => ['10.89.20.50']];
        foreach ($targets as $url) {
            $client = $this->newClient();
            $endpoint = $this->endpointRow($client, $url);
            $sent = $this->sendCompleted($client);
            $this->requests = [];
            $this->work($this->dispatcher(env: 'production'));
            $row = self::delivery($sent['event']);
            self::assertSame('failed', $row['status'], $url);
            self::assertSame([], $this->requests, "$url: no request is made");
            self::assertNotEmpty($endpoint);
        }
        // Rebinding: the address checked is the address connected to (pinned), whatever DNS answers later.
        self::assertSame('93.184.216.34', (new WebhookTargetGuard(new StubHostResolver(), 'production', ''))->check('https://hooks.example.test/x')->ip);
        foreach (['10.0.0.1', '127.0.0.1', '::1', 'fe80::1', 'fc00::1', '169.254.169.254', '::ffff:10.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::1', 'not-an-ip'] as $ip) {
            self::assertTrue(WebhookTargetGuard::isRefused($ip), $ip);
        }
        foreach (['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946', '8.8.8.8'] as $ip) {
            self::assertFalse(WebhookTargetGuard::isRefused($ip), $ip);
        }
        // The development exception is explicit, development/test only, and fails closed in production.
        self::assertSame('10.89.20.50', (new WebhookTargetGuard(new StubHostResolver(), 'development', 'webhook-receiver'))->check('http://webhook-receiver:8080/h')->ip);
        $this->expectException(\RuntimeException::class);
        new WebhookTargetGuard(new StubHostResolver(), 'production', 'webhook-receiver');
    }

    public function testDevelopmentReceiverIsAllowedOnlyByName(): void
    {
        StubHostResolver::$answers['webhook-receiver'] = ['10.89.20.50'];
        $client = $this->newClient();
        $this->endpointRow($client, 'http://webhook-receiver:8080/hooks/a');
        $sent = $this->sendCompleted($client);
        $this->work($this->dispatcher(allowedPrivate: ['webhook-receiver']));
        self::assertSame('delivered', self::delivery($sent['event'])['status']);
        self::assertSame('http://webhook-receiver:8080/hooks/a', $this->requests[0]['url']);
    }

    public function testOversizedResponseIsBoundedAndDisabledEndpointsFail(): void
    {
        $client = $this->newClient();
        [$endpoint] = $this->endpoint($client);
        $sent = $this->sendCompleted($client);
        $this->script = [new MockResponse(str_repeat('A', 300000), ['http_code' => 200])];
        $this->work($this->dispatcher());
        $row = self::delivery($sent['event']);
        self::assertSame('delivered', $row['status']);
        self::assertLessThanOrEqual(1024, mb_strlen((string) $row['last_response_excerpt']));

        $sent2 = $this->sendCompleted($client);
        $d = $this->dispatcher();
        $d->fanOut(1000);
        $this->container()->get(WebhookEndpointService::class)->setStatus($this->reload($endpoint), WebhookEndpointStatus::Disabled, self::actor());
        $d->attempt('w', $d->claim('w', 10));
        self::assertSame('failed', self::delivery($sent2['event'])['status'], 'disabled after fan-out: not sent');
        self::assertSame(1, \count($this->requests));
        self::assertSame('disabled', Db::owner()->fetchOne('SELECT status FROM webhook_endpoints WHERE id = ?', [$endpoint->getId()->toRfc4122()]));
    }

    public function testMessageEventsCarryTheMessageAndItsTriggeringEvent(): void
    {
        $client = $this->newClient();
        [$endpoint, $secret] = $this->endpoint($client, 'https://hooks.example.test/m', ['message.hard_bounced', 'message.complained']);
        $set = DashboardFixtures::sendJob(Db::owner(), $client->getId()->toRfc4122(), $this->verifiedDomain($client)->getId()->toRfc4122(), 1, ['track_opens' => true]);
        $message = $set['messages'][0];
        Db::owner()->executeStatement("UPDATE messages SET current_status = 'hard_bounced', resolved_at = now() WHERE id = ?", [$message]);
        Db::owner()->insert('message_events', ['id' => SchemaFixtures::id(), 'message_id' => $message, 'event_type' => 'hard_bounce', 'event_source' => 'dsn_spool',
            'source_event_key' => 'k-'.$message, 'smtp_code' => 550, 'enhanced_status_code' => '5.1.1', 'failure_scope' => 'recipient',
            'diagnostic' => 'smtp; 550 5.1.1 user unknown', 'occurred_at' => gmdate('Y-m-d H:i:s')]);
        $event = $this->event($client, WebhookEventType::MessageHardBounced, WebhookSubjectType::Message, $message);
        $this->work($this->dispatcher());
        $body = json_decode($this->requests[0]['body'], true);
        self::assertSame([], $this->service(OpenApiContract::class)->validate(json_decode($this->requests[0]['body'], false), '#/components/schemas/WebhookEvent'));
        self::assertSame($message, $body['data']['message']['id']);
        self::assertSame('hard_bounced', $body['data']['message']['current_status']);
        self::assertSame(['hard_bounce', 550, '5.1.1'], [$body['data']['event']['event_type'], $body['data']['event']['smtp_code'], $body['data']['event']['enhanced_status_code']]);
        $token = (string) Db::owner()->fetchOne('SELECT tracking_token FROM messages WHERE id = ?', [$message]);
        $verp = (string) Db::owner()->fetchOne('SELECT verp_token FROM messages WHERE id = ?', [$message]);
        self::assertStringNotContainsString($token, $this->requests[0]['body']);
        self::assertStringNotContainsString($verp, $this->requests[0]['body']);
        self::assertTrue(WebhookSigner::verify($this->requests[0]['body'], $this->requests[0]['headers']['smarthost-signature'], [$secret], time()));
        self::assertSame($event, $body['id']);
        self::assertNotEmpty($endpoint->getId());
    }

    public function testWebhookTestGoesThroughTheOutboxAndWorker(): void
    {
        [$client, $key] = $this->newApiClient();
        [$a] = $this->endpoint($client, 'https://hooks.example.test/a', ['send.completed']);
        [$b] = $this->endpoint($client, 'https://other.example.test/b', ['validation.completed']);
        $r = $this->api('POST', '/v1/webhooks/test', $key, ['webhook_endpoint_id' => $a->getId()->toRfc4122()]);
        $result = $this->assertContract($r, 202, '/webhooks/test', 'post');
        self::assertSame([], $this->requests, 'the API never calls the endpoint itself');
        self::assertSame(['webhook.test', 'webhook_endpoint'], array_values(Db::owner()->fetchAssociative('SELECT event_type, subject_type FROM webhook_events WHERE id = ?', [$result['webhook_event_id']])));
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'webhook.test_requested' AND target_id = ?", [$result['webhook_event_id']]));
        $this->work($this->dispatcher());
        self::assertSame(['https://hooks.example.test/a'], array_column($this->requests, 'url'));
        self::assertSame('{}', json_encode(json_decode($this->requests[0]['body'])->data));

        // Exactly one endpoint per request, whatever its subscriptions (b is not subscribed to anything it is tested with).
        $this->requests = [];
        $one = self::json($this->api('POST', '/v1/webhooks/test', $key, ['webhook_endpoint_id' => $b->getId()->toRfc4122()]));
        $this->work($this->dispatcher());
        self::assertSame(['https://other.example.test/b'], array_column($this->requests, 'url'));
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_deliveries WHERE webhook_event_id = ?', [$one['webhook_event_id']]));

        // The endpoint id is required: a missing id never fans out to every endpoint.
        $events = static fn (): int => (int) Db::owner()->fetchOne("SELECT count(*) FROM webhook_events WHERE client_id = ? AND event_type = 'webhook.test'", [$client->getId()->toRfc4122()]);
        $before = $events();
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $key, '{}'), 422, 'validation-error');
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $key), 400);
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $key, ['webhook_endpoint_id' => 'nope']), 422, 'validation-error');
        // Another client's endpoint is indistinguishable from a missing one.
        [$foreign] = $this->endpoint($this->newClient());
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $key, ['webhook_endpoint_id' => $foreign->getId()->toRfc4122()]), 404, 'not-found');
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $key, ['webhook_endpoint_id' => SchemaFixtures::id()]), 404, 'not-found');
        // A disabled endpoint: 409, nothing recorded, no HTTP.
        $this->container()->get(WebhookEndpointService::class)->setStatus($this->reload($b), WebhookEndpointStatus::Disabled, self::actor());
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $key, ['webhook_endpoint_id' => $b->getId()->toRfc4122()]), 409, 'webhook-endpoint-disabled');
        self::assertSame($before, $events(), 'rejected requests record no event');
        // Disabled after the request but before fan-out: the event reaches nobody.
        $late = self::json($this->api('POST', '/v1/webhooks/test', $key, ['webhook_endpoint_id' => $a->getId()->toRfc4122()]));
        $this->container()->get(WebhookEndpointService::class)->setStatus($this->reload($a), WebhookEndpointStatus::Disabled, self::actor());
        $this->requests = [];
        $this->work($this->dispatcher());
        self::assertSame([], $this->requests);
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_deliveries WHERE webhook_event_id = ?', [$late['webhook_event_id']]));
    }

    /** An endpoint row with an arbitrary URL (bypassing the registration check, as a hostile or stale row would). */
    private function endpointRow(\App\Entity\Client $client, string $url): string
    {
        [$endpoint] = $this->endpoint($client);
        Db::owner()->executeStatement('UPDATE webhook_endpoints SET url = ? WHERE id = ?', [$url, $endpoint->getId()->toRfc4122()]);

        return $endpoint->getId()->toRfc4122();
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->lines[] = $level.' '.$message.' '.json_encode($context);
    }

    public function all(): string
    {
        return implode("\n", $this->lines);
    }
}
