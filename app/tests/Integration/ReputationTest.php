<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Reputation\AlertAdministration;
use App\Reputation\ReputationEvaluator;
use App\Reputation\ReputationThresholds;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;
use App\Tests\Support\Phase9Fixtures;

/**
 * Phase 9 abuse and reputation monitoring (specification 2.10 reputation_monitoring):
 * per-client transport facts per window, rate alerts with numerator and denominator,
 * the volume-increase alert, resolution when a metric recovers, escalation clearing an
 * acknowledgement, and that alerts never change the client.
 */
final class ReputationTest extends DashboardTestCase
{
    private function evaluator(int $minMessages = 10): ReputationEvaluator
    {
        $thresholds = new ReputationThresholds($minMessages, [
            'hard_bounce_rate' => ['warning' => 2.0, 'critical' => 5.0],
            'complaint_rate' => ['warning' => 0.1, 'critical' => 0.3],
            'deferral_rate' => ['warning' => 15.0, 'critical' => 30.0],
            'volume_increase' => ['warning' => 3.0, 'critical' => 10.0],
        ]);

        return new ReputationEvaluator(Db::owner(), $thresholds);
    }

    /** @return array<string, array<string, mixed>> metric|window => open alert */
    private static function openAlerts(string $clientId): array
    {
        $out = [];
        foreach (Db::owner()->fetchAllAssociative('SELECT * FROM client_alerts WHERE client_id = ? AND resolved_at IS NULL', [$clientId]) as $a) {
            $out[$a['metric'].'|'.$a['window_hours']] = $a;
        }

        return $out;
    }

    public function testMetricsAlertsAndResolution(): void
    {
        $client = $this->newClient();
        $id = $client->getId()->toRfc4122();
        $domain = $this->verifiedDomain($client)->getId()->toRfc4122();
        $o = Db::owner();
        $recent = gmdate('Y-m-d H:i:s', time() - 3600);
        $send = Phase9Fixtures::meteredSendJob($o, $id, $domain, 100, $recent);
        Phase9Fixtures::events($o, \array_slice($send['messages'], 0, 6), 'hard_bounce', $recent, 'recipient');
        Phase9Fixtures::events($o, \array_slice($send['messages'], 6, 1), 'complaint', $recent);
        Phase9Fixtures::events($o, \array_slice($send['messages'], 7, 8), 'deferred', $recent, 'provider_policy');
        Phase9Fixtures::events($o, \array_slice($send['messages'], 7, 2), 'deferred', $recent, 'provider_policy'); // repeated deferrals count once
        Phase9Fixtures::meteredValidationJob($o, $id, 40, $recent);

        $result = $this->evaluator()->evaluate();
        self::assertFalse($result['skipped']);
        $m = $o->fetchAssociative('SELECT * FROM client_reputation_metrics WHERE client_id = ? AND window_hours = 24', [$id]);
        self::assertSame(['messages_submitted' => 100, 'validation_addresses' => 40, 'hard_bounces' => 6, 'soft_bounces' => 0, 'deferrals' => 8,
            'provider_policy_failures' => 8, 'complaints' => 1, 'suppressed' => 0, 'outcome_unknown' => 0],
            array_map('intval', array_intersect_key($m, array_flip(['messages_submitted', 'validation_addresses', 'hard_bounces', 'complaints', 'deferrals',
                'provider_policy_failures', 'soft_bounces', 'suppressed', 'outcome_unknown']))));

        $open = self::openAlerts($id);
        // 6/100 = 6 % hard bounces (critical >= 5), 1/100 = 1 % complaints (critical >= 0.3), 8 % deferrals (below 15), new sender volume.
        self::assertSame('critical', $open['hard_bounce_rate|24']['severity']);
        self::assertSame(['6.00', '100.00', '6.0000', '5.0000'], [$open['hard_bounce_rate|24']['numerator'], $open['hard_bounce_rate|24']['denominator'],
            $open['hard_bounce_rate|24']['value'], $open['hard_bounce_rate|24']['threshold']]);
        self::assertSame('critical', $open['complaint_rate|168']['severity']);
        self::assertArrayNotHasKey('deferral_rate|24', $open);
        self::assertSame('critical', $open['volume_increase|24']['severity'], 'a first day of 100 messages against no history');
        self::assertSame('active', $o->fetchOne('SELECT status FROM clients WHERE id = ?', [$id]), 'alerts never act on the client');

        // Acknowledged, then the same evaluation updates the open alert (still the same row).
        $operator = $this->newUser(true);
        $this->container()->get(AlertAdministration::class)->acknowledge($open['hard_bounce_rate|24']['id'], $this->reload($operator), 'throttled; asked to clean the list');
        $this->evaluator()->evaluate();
        $again = self::openAlerts($id)['hard_bounce_rate|24'];
        self::assertSame($open['hard_bounce_rate|24']['id'], $again['id']);
        self::assertNotNull($again['acknowledged_at']);
        self::assertSame('client_alert.acknowledged', $o->fetchOne("SELECT action FROM audit_log WHERE target_id = ? AND action = 'client_alert.acknowledged'", [$id]));

        // Events older than 24 hours leave the window: the 24-hour alerts resolve, the 7-day ones stay.
        $o->executeStatement("UPDATE message_events SET occurred_at = now() - interval '2 days' WHERE message_id IN (SELECT id FROM messages WHERE send_job_id = ?)", [$send['job']]);
        $o->executeStatement("UPDATE usage_records SET occurred_at = now() - interval '2 days' WHERE client_id = ?", [$id]);
        $r = $this->evaluator()->evaluate();
        self::assertGreaterThanOrEqual(3, $r['resolved']);
        $open = self::openAlerts($id);
        self::assertArrayNotHasKey('hard_bounce_rate|24', $open);
        self::assertArrayHasKey('hard_bounce_rate|168', $open);
        self::assertSame(1, (int) $o->fetchOne("SELECT count(*) FROM client_alerts WHERE client_id = ? AND metric = 'hard_bounce_rate' AND window_hours = 24 AND resolved_at IS NOT NULL", [$id]));
    }

    public function testMinimumSampleEscalationAndAcknowledgement(): void
    {
        $client = $this->newClient();
        $id = $client->getId()->toRfc4122();
        $domain = $this->verifiedDomain($client)->getId()->toRfc4122();
        $o = Db::owner();
        $recent = gmdate('Y-m-d H:i:s', time() - 600);
        $small = Phase9Fixtures::meteredSendJob($o, $id, $domain, 5, $recent);
        Phase9Fixtures::events($o, $small['messages'], 'hard_bounce', $recent, 'recipient');
        $this->evaluator()->evaluate();
        self::assertSame([], self::openAlerts($id), '5 of 5 bounced, but below APP_REPUTATION_MIN_MESSAGES');

        // 100 more messages: the 5 hard bounces are 4.8 % of 105, a warning (>= 2, < 5).
        $big = Phase9Fixtures::meteredSendJob($o, $id, $domain, 100, $recent);
        $this->evaluator(100)->evaluate();
        $warn = self::openAlerts($id)['hard_bounce_rate|24'];
        self::assertSame('warning', $warn['severity']);
        $operator = $this->newUser(true);
        $this->container()->get(AlertAdministration::class)->acknowledge($warn['id'], $this->reload($operator), 'watching');
        Phase9Fixtures::events($o, \array_slice($big['messages'], 0, 10), 'hard_bounce', $recent, 'recipient');
        $this->evaluator(100)->evaluate();
        $critical = self::openAlerts($id)['hard_bounce_rate|24'];
        self::assertSame([$warn['id'], 'critical', null], [$critical['id'], $critical['severity'], $critical['acknowledged_at']], 'an escalation clears the acknowledgement');
    }
}
