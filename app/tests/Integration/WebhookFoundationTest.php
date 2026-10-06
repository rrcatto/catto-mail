<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Crypto\Keyring;
use App\Domain\DomainRuleViolation;
use App\Enum\WebhookEndpointStatus;
use App\Enum\WebhookEventType;
use App\Enum\WebhookSubjectType;
use App\Tests\Schema\SchemaFixtures;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;
use App\Webhook\WebhookEndpointService;
use App\Webhook\WebhookOutbox;
use App\Util\Clock;
use Symfony\Component\Uid\Uuid;

/** Webhook endpoint/secret model and the transactional outbox (D-10, D-22). No HTTP delivery in Phase 2. */
final class WebhookFoundationTest extends ApiTestCase
{
    private function endpoints(?string $env = null): WebhookEndpointService
    {
        $c = $this->container();

        return null === $env ? $c->get(WebhookEndpointService::class)
            : new WebhookEndpointService($c->get('doctrine')->getManager(), $c->get(Keyring::class), $c->get(\App\Audit\AuditLogger::class), $env, 24);
    }

    public function testSecretsAreShownOnceAndStoredEncrypted(): void
    {
        $client = $this->newClient();
        [$endpoint, $secret] = $this->endpoints()->create($client, 'https://hooks.example/smarthost', ['send.completed', 'message.hard_bounced', 'send.completed'], self::actor());
        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9_-]{43}$/', $secret);
        self::assertSame(['message.hard_bounced', 'send.completed'], $endpoint->getSubscribedEventTypes());
        $row = Db::owner()->fetchAssociative('SELECT * FROM webhook_endpoints WHERE id = ?', [$endpoint->getId()->toRfc4122()]);
        foreach ($row as $value) {
            self::assertStringNotContainsString(substr($secret, 6), (string) $value);
        }
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM audit_log WHERE detail_json::text LIKE ?', ['%'.substr($secret, 6).'%']));
        self::assertSame([$secret], $this->endpoints()->activeSecrets($endpoint));
        self::assertSame('enabled', $row['status']);
    }

    public function testRotationKeepsThePreviousSecretForTheOverlap(): void
    {
        [$endpoint, $old] = $this->endpoints()->create($this->newClient(), 'https://hooks.example/a', ['send.failed'], self::actor());
        $new = $this->endpoints()->rotateSecret($endpoint, self::actor());
        self::assertNotSame($old, $new);
        self::assertSame([$new, $old], $this->endpoints()->activeSecrets($endpoint));
        $expires = $endpoint->getPreviousSigningSecretExpiresAt();
        self::assertEqualsWithDelta(Clock::now()->getTimestamp() + 24 * 3600, $expires->getTimestamp(), 5);
        self::assertSame([$new], $this->endpoints()->activeSecrets($endpoint, $expires->modify('+1 second')), 'previous secret retired after the overlap');
        self::assertContains('webhook_endpoint.secret_rotated', Db::owner()->fetchFirstColumn('SELECT action FROM audit_log WHERE target_id = ?', [$endpoint->getId()->toRfc4122()]));
    }

    public function testEndpointRules(): void
    {
        $client = $this->newClient();
        foreach ([['ftp://x.example', ['send.failed']], ['https://user:pw@x.example', ['send.failed']], ['https://x.example', []],
            ['https://x.example', ['webhook.test']], ['https://x.example', ['nope']]] as [$url, $types]) {
            try {
                $this->endpoints()->create($this->reload($client), $url, $types, self::actor());
                self::fail("accepted $url ".json_encode($types));
            } catch (DomainRuleViolation) {
            }
        }
        // http is a development/test convenience only.
        $this->endpoints()->create($this->reload($client), 'http://receiver.test/hook', ['send.failed'], self::actor());
        $this->expectException(DomainRuleViolation::class);
        $this->endpoints('production')->create($this->reload($client), 'http://receiver.example/hook', ['send.failed'], self::actor());
    }

    public function testStatusChangesAreAudited(): void
    {
        [$endpoint] = $this->endpoints()->create($this->newClient(), 'https://hooks.example/s', ['send.failed'], self::actor());
        $this->endpoints()->setStatus($endpoint, WebhookEndpointStatus::Disabled, self::actor());
        $this->endpoints()->update($endpoint, 'https://hooks.example/t', ['send.completed'], self::actor());
        self::assertSame(['webhook_endpoint.created', 'webhook_endpoint.disabled', 'webhook_endpoint.updated'],
            Db::owner()->fetchFirstColumn('SELECT action FROM audit_log WHERE target_id = ? ORDER BY occurred_at', [$endpoint->getId()->toRfc4122()]));
    }

    public function testOutboxRecordsEachEventOnceInsideTheTransaction(): void
    {
        $client = $this->newClient();
        $outbox = $this->container()->get(WebhookOutbox::class);
        $conn = $this->container()->get('doctrine')->getConnection();
        $subject = Uuid::v7();
        try {
            $outbox->record($client->getId(), WebhookEventType::SendCompleted, WebhookSubjectType::SendJob, $subject);
            self::fail('outbox writes outside a transaction must be refused');
        } catch (\LogicException) {
        }
        $conn->beginTransaction();
        $id = $outbox->record($client->getId(), WebhookEventType::SendCompleted, WebhookSubjectType::SendJob, $subject);
        self::assertNull($outbox->record($client->getId(), WebhookEventType::SendCompleted, WebhookSubjectType::SendJob, $subject));
        $conn->rollBack();
        self::assertNotNull($id);
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_events WHERE subject_id = ?', [$subject->toRfc4122()]), 'rolled back with the state change');

        $conn->beginTransaction();
        $outbox->record($client->getId(), WebhookEventType::SendCompleted, WebhookSubjectType::SendJob, $subject);
        $outbox->record($client->getId(), WebhookEventType::WebhookTest, WebhookSubjectType::WebhookEndpoint, $subject);
        $outbox->record($client->getId(), WebhookEventType::WebhookTest, WebhookSubjectType::WebhookEndpoint, $subject);
        $conn->commit();
        self::assertSame(3, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_events WHERE subject_id = ? AND fanned_out_at IS NULL', [$subject->toRfc4122()]));
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_deliveries WHERE client_id = ?', [$client->getId()->toRfc4122()]), 'only the webhook worker creates deliveries');
    }

    public function testWebhookTestNeedsAKeyAndExactlyOneEndpointId(): void
    {
        [, $key] = $this->newApiClient();
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $key, '{}'), 422, 'validation-error');
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', $key, ['webhook_endpoint_id' => SchemaFixtures::id(), 'all' => true]), 422, 'validation-error');
        $this->assertProblem($this->api('POST', '/v1/webhooks/test', null), 401, 'unauthorized');
    }
}
