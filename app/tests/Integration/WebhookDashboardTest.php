<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ClientMembershipRole;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;

/**
 * Webhook endpoint management in the client dashboard and delivery visibility for
 * operators (Phase 7): admins manage endpoints through the audited service; the
 * signing secret is shown once; viewers and other clients cannot manage them.
 */
final class WebhookDashboardTest extends DashboardTestCase
{
    public function testClientAdminManagesEndpointsAndTheSecretIsShownOnce(): void
    {
        $client = $this->newClient();
        $base = '/dashboard/c/'.$client->getId()->toRfc4122().'/webhooks';
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));

        $r = $this->submit($base, $base, ['url' => 'https://app.example.test/hooks/smarthost', 'event_types' => ['send.completed', 'message.hard_bounced']]);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(1, preg_match('/whsec_[A-Za-z0-9_-]{43}/', (string) $r->getContent(), $m), 'the new secret is shown');
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $secret = $m[0];
        $id = (string) Db::owner()->fetchOne('SELECT id FROM webhook_endpoints WHERE client_id = ?', [$client->getId()->toRfc4122()]);
        self::assertStringNotContainsString($secret, (string) Db::owner()->fetchOne('SELECT signing_secret_ciphertext FROM webhook_endpoints WHERE id = ?', [$id]));
        $list = (string) $this->page($base)->getContent();
        self::assertStringNotContainsString($secret, $list, 'never retrievable later');
        self::assertStringContainsString('https://app.example.test/hooks/smarthost', $list);

        $this->submit($base, "$base/$id", ['url' => 'https://app.example.test/hooks/v2', 'event_types' => ['send.completed'], 'status' => 'disabled']);
        self::assertSame(['https://app.example.test/hooks/v2', 'disabled', '["send.completed"]'],
            array_values(Db::owner()->fetchAssociative('SELECT url, status, subscribed_event_types::text FROM webhook_endpoints WHERE id = ?', [$id])));
        $r = $this->submit($base, "$base/$id/test");
        self::assertSame(0, (int) Db::owner()->fetchOne("SELECT count(*) FROM webhook_events WHERE subject_id = ? AND event_type = 'webhook.test'", [$id]), 'disabled: no test event');
        $this->submit($base, "$base/$id", ['url' => 'https://app.example.test/hooks/v2', 'event_types' => ['send.completed'], 'status' => 'enabled']);
        $this->submit($base, "$base/$id/test");
        self::assertSame(1, (int) Db::owner()->fetchOne("SELECT count(*) FROM webhook_events WHERE subject_id = ? AND event_type = 'webhook.test' AND subject_type = 'webhook_endpoint'", [$id]));

        $r = $this->submit($base, "$base/$id/rotate");
        self::assertSame(1, preg_match('/whsec_[A-Za-z0-9_-]{43}/', (string) $r->getContent(), $m2));
        self::assertNotSame($secret, $m2[0]);
        self::assertNotNull(Db::owner()->fetchOne('SELECT previous_signing_secret_expires_at FROM webhook_endpoints WHERE id = ?', [$id]));
        $actions = Db::owner()->fetchFirstColumn("SELECT action FROM audit_log WHERE target_id = ? ORDER BY occurred_at", [$id]);
        foreach (['webhook_endpoint.created', 'webhook_endpoint.updated', 'webhook_endpoint.disabled', 'webhook_endpoint.enabled', 'webhook_endpoint.secret_rotated'] as $a) {
            self::assertContains($a, $actions);
        }
        self::assertStringNotContainsString($m2[0], (string) Db::owner()->fetchOne("SELECT string_agg(detail_json::text, ' ') FROM audit_log WHERE target_id = ?", [$id]));

        $this->submit($base, $base, ['url' => 'ftp://nope', 'event_types' => ['send.completed']]);
        self::assertStringContainsString('The webhook URL must be an absolute', self::text($this->page($base)), 'the service rule is reported');
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_endpoints WHERE client_id = ?', [$client->getId()->toRfc4122()]));
    }

    public function testViewersAndOtherClientsCannotManage(): void
    {
        $client = $this->newClient();
        $other = $this->newClient();
        [$endpoint] = $this->container()->get(\App\Webhook\WebhookEndpointService::class)->create($this->reload($other), 'https://b.example.test/h', ['send.completed'], self::actor());
        [$own] = $this->container()->get(\App\Webhook\WebhookEndpointService::class)->create($this->reload($client), 'https://a.example.test/h', ['send.completed'], self::actor());
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Viewer));
        $base = '/dashboard/c/'.$client->getId()->toRfc4122().'/webhooks';
        $page = $this->crawler($base);
        self::assertSame(0, $page->filter('form[method="post"] input[name="url"]')->count(), 'viewers get no management forms');
        self::assertStringNotContainsString('https://b.example.test/h', (string) $this->browser->getResponse()->getContent());
        $token = $page->filter('input[name="_csrf_token"]')->attr('value');
        foreach (["$base/{$own->getId()->toRfc4122()}/rotate", "$base/{$own->getId()->toRfc4122()}/test", "$base/{$endpoint->getId()->toRfc4122()}/rotate", $base] as $action) {
            $this->browser->request('POST', $action, ['_token' => $token, 'url' => 'https://x.example.test', 'event_types' => ['send.completed']]);
            self::assertContains($this->browser->getResponse()->getStatusCode(), [403, 404], $action);
        }
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_endpoints WHERE client_id = ?', [$client->getId()->toRfc4122()]));
        self::assertNull(Db::owner()->fetchOne('SELECT previous_signing_secret_expires_at FROM webhook_endpoints WHERE id = ?', [$own->getId()->toRfc4122()]));

        $this->browser->getCookieJar()->clear();
        $this->signIn($this->newUser(false, $client, ClientMembershipRole::Admin));
        $this->browser->request('POST', '/dashboard/c/'.$client->getId()->toRfc4122().'/webhooks/'.$endpoint->getId()->toRfc4122().'/rotate', ['_token' => 'x']);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), "another client's endpoint is not found");
    }

    public function testOperatorSeesDeliveriesAndWorkersWithoutSecrets(): void
    {
        $client = $this->newClient(\App\Enum\ClientStatus::Active, 'Webhook Co');
        [$endpoint, $secret] = $this->container()->get(\App\Webhook\WebhookEndpointService::class)->create($this->reload($client), 'https://ops.example.test/h', ['send.completed'], self::actor());
        $event = \App\Tests\Schema\SchemaFixtures::id();
        Db::owner()->insert('webhook_events', ['id' => $event, 'client_id' => $client->getId()->toRfc4122(), 'event_type' => 'webhook.test',
            'subject_type' => 'webhook_endpoint', 'subject_id' => $endpoint->getId()->toRfc4122(), 'fanned_out_at' => date('Y-m-d H:i:s')]);
        Db::owner()->insert('webhook_deliveries', ['id' => \App\Tests\Schema\SchemaFixtures::id(), 'client_id' => $client->getId()->toRfc4122(), 'webhook_event_id' => $event,
            'webhook_endpoint_id' => $endpoint->getId()->toRfc4122(), 'event_type' => 'webhook.test', 'payload_json' => '{}', 'payload_hash' => str_repeat('0', 64),
            'status' => 'failed', 'attempt_count' => 8, 'last_response_status' => 500, 'last_error' => 'HTTP 500 Attempts exhausted.']);
        Db::owner()->insert('webhook_worker_heartbeats', ['worker_id' => 'w-'.bin2hex(random_bytes(3)), 'version' => '0.1.6', 'started_at' => date('Y-m-d H:i:s'), 'last_seen_at' => date('Y-m-d H:i:s')]);
        $this->signIn($this->newUser(true));
        $r = $this->page('/dashboard/operator/webhooks?status=failed&client='.$client->getId()->toRfc4122());
        self::assertSame(200, $r->getStatusCode());
        $html = (string) $r->getContent();
        foreach (['Webhook Co', 'https://ops.example.test/h', 'Attempts exhausted', 'Running'] as $s) {
            self::assertStringContainsString($s, $html);
        }
        self::assertStringNotContainsString($secret, $html);
        self::assertStringNotContainsString('signing_secret', $html);
        self::assertSame(200, $this->page('/dashboard/operator')->getStatusCode());
    }
}
