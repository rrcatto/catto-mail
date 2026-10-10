<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Phase 8 operations: the audited installation-wide delivery controls
 * (smarthost:ops:record, SYSTEM.DELIVERY.CONTROL), the production health summary
 * (smarthost:ops:status) and the delivery-state card fed by delivery_heartbeats.
 */
final class OpsCommandTest extends DashboardTestCase
{
    private function console(string $name, array $input): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find($name));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function heartbeat(bool $held, bool $paused, ?int $deferred): string
    {
        $id = 'ops-test-'.bin2hex(random_bytes(4));
        Db::owner()->executeStatement('UPDATE delivery_heartbeats SET stopped_at = now() WHERE stopped_at IS NULL');
        Db::owner()->insert('delivery_heartbeats', [
            'worker_id' => $id, 'version' => '0.1.7', 'started_at' => date('Y-m-d H:i:s'), 'last_seen_at' => date('Y-m-d H:i:s'),
            'live_delivery' => $held ? 'false' : 'true', 'send_work_held' => $held ? 'true' : 'false', 'outbound_paused' => $paused ? 'true' : 'false',
            'global_rate_per_minute' => 5, 'queue_snapshot_at' => null === $deferred ? null : date('Y-m-d H:i:s'),
            'queue_active' => null === $deferred ? null : 1, 'queue_deferred' => $deferred, 'queue_hold' => null === $deferred ? null : 0,
            'queue_incoming' => null === $deferred ? null : 0,
        ]);

        return $id;
    }

    public function testDeliveryControlsAreAuditedAndNeedSystemDeliveryControl(): void
    {
        static::bootKernel();
        $t = $this->console('smarthost:ops:record', ['action' => 'delivery.outbound_paused', '--note' => 'x']);
        self::assertSame(1, $t->getStatusCode(), 'an operator is required');
        $operator = $this->newUser(true);   // OPERATOR: every PLATFORM.* key, no SYSTEM.* key
        $t = $this->console('smarthost:ops:record', ['action' => 'delivery.outbound_paused', '--operator' => $operator->getEmail(), '--note' => 'x']);
        self::assertSame(1, $t->getStatusCode());
        self::assertStringContainsString('SYSTEM.DELIVERY.CONTROL', $t->getDisplay());
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $t = $this->console('smarthost:ops:record', ['action' => 'delivery.live_enabled', '--operator' => $admin->getEmail(), '--note' => '']);
        self::assertSame(1, $t->getStatusCode(), 'a note is required');
        $t = $this->console('smarthost:ops:record', ['action' => 'delivery.something_else', '--operator' => $admin->getEmail(), '--note' => 'x']);
        self::assertSame(1, $t->getStatusCode(), 'only the known actions');
        $t = $this->console('smarthost:ops:record', ['action' => 'delivery.live_enabled', '--operator' => $admin->getEmail(), '--note' => 'activation after preflight']);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        $row = Db::owner()->fetchAssociative("SELECT actor_type, actor_id, target_type, detail_json::text AS d FROM audit_log WHERE action = 'delivery.live_enabled' ORDER BY occurred_at DESC LIMIT 1");
        self::assertSame(['user', $admin->getId()->toRfc4122(), 'installation'], [$row['actor_type'], $row['actor_id'], $row['target_type']]);
        self::assertStringContainsString('activation after preflight', $row['d']);
    }

    public function testOpsStatusReportsTheDurableSignals(): void
    {
        static::bootKernel();
        $this->heartbeat(true, false, 3);
        $t = $this->console('smarthost:ops:status', ['--json' => true]);
        self::assertSame(0, $t->getStatusCode());
        $s = json_decode($t->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        foreach (['validation', 'sending', 'messages_by_status', 'outcome_unknown', 'delivery', 'rates_by_client', 'global_suppressions',
            'unmatched_dsns', 'webhooks', 'clients'] as $key) {
            self::assertArrayHasKey($key, $s);
        }
        self::assertSame('held', $s['delivery']['state']);
        self::assertSame(3, $s['delivery']['postfix_queue']['deferred']);
        self::assertSame(5, $s['delivery']['global_rate_per_minute']);
        $this->heartbeat(false, true, null);
        $s = json_decode($this->console('smarthost:ops:status', ['--json' => true])->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('paused', $s['delivery']['state']);
        self::assertNull($s['delivery']['postfix_queue']);
        $text = $this->console('smarthost:ops:status', [])->getDisplay();
        self::assertStringContainsString('outcome_unknown', $text);
        self::assertStringContainsString('"state":"paused"', $text);
        Db::owner()->executeStatement("DELETE FROM delivery_heartbeats WHERE worker_id LIKE 'ops-test-%'");
    }

    public function testOverviewShowsDeliveryStateAndQueueDepth(): void
    {
        $this->heartbeat(true, false, 7);
        $this->signIn($this->newUser(true));
        $html = self::text($this->page('/dashboard/operator'));
        self::assertStringContainsString('Delivery is HELD', $html);
        self::assertStringContainsString('Live delivery has not been activated', $html);
        self::assertStringContainsString('rate ceiling 5 messages per minute', $html);
        self::assertStringContainsString('1 active · 7 deferred · 0 held · 0 incoming', $html, 'the Postfix queue from the heartbeat');
        Db::owner()->executeStatement('UPDATE delivery_heartbeats SET stopped_at = now()');
        $stopped = self::text($this->page('/dashboard/operator'));
        self::assertStringContainsString('Delivery is STOPPED', $stopped);
        self::assertStringContainsString('The delivery daemon is not running', $stopped);
        Db::owner()->executeStatement("DELETE FROM delivery_heartbeats WHERE worker_id LIKE 'ops-test-%'");
    }
}
