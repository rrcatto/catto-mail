<?php

declare(strict_types=1);

namespace App\Reputation;

use App\Enum\ClientAlertMetric;
use App\Enum\ClientAlertSeverity;
use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Per-client abuse and reputation monitoring (Phase 9, specification 2.10
 * `reputation_monitoring`). smarthost:reputation:evaluate runs it (a systemd timer every
 * 15 minutes in production; on demand from the operator dashboard).
 *
 * Transport facts, per client and bounded window (24 hours, 7 days), counted by event
 * time: messages submitted (usage_records message_submitted), validation addresses
 * processed, distinct messages with a hard bounce, soft bounce, deferral, provider-policy
 * failure (deferral or soft bounce with failure_scope provider_policy), complaint,
 * suppression instead of sending, outcome_unknown, and webhook deliveries created in the
 * window that have failed. They are stored in client_reputation_metrics (one row per
 * client and window), which the dashboards read.
 *
 * Inferred risk, from those facts: hard-bounce, complaint and deferral rates (percent of
 * the messages submitted in the window, only with at least APP_REPUTATION_MIN_MESSAGES)
 * and the volume increase (last 24 hours over the daily average of the 7 days before,
 * at least 1). Crossing a configured threshold opens an alert, or updates the open one;
 * falling below the warning threshold resolves it. An escalation to critical clears an
 * earlier acknowledgement. Alerts never act on the client: throttling and suspension
 * stay explicit operator actions.
 */
final class ReputationEvaluator
{
    public const WINDOWS = [24, 168];
    private const LOCK = 'smarthost:reputation:evaluate';

    public function __construct(
        private readonly Connection $connection,
        private readonly ReputationThresholds $thresholds,
    ) {
    }

    /** @return array{evaluated_clients: int, opened: int, updated: int, resolved: int, skipped: bool, computed_at: string} */
    public function evaluate(?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? Clock::now())->setTimezone(Clock::zone());
        $result = ['evaluated_clients' => 0, 'opened' => 0, 'updated' => 0, 'resolved' => 0, 'skipped' => false,
            'computed_at' => (string) Clock::rfc3339($now)];

        return $this->connection->transactional(function () use ($now, $result): array {
            if (!$this->connection->fetchOne('SELECT pg_try_advisory_xact_lock(hashtext(?))', [self::LOCK])) {
                return ['skipped' => true] + $result;
            }
            foreach (self::WINDOWS as $hours) {
                $rows = $this->facts($now, $hours);
                foreach ($rows as $r) {
                    $this->store($r, $hours, $now);
                    foreach ($this->signals($r, $hours) as $metric => [$numerator, $denominator, $value, $applicable]) {
                        $change = $this->alert($r['client_id'], ClientAlertMetric::from($metric), $hours,
                            $applicable ? $this->thresholds->severity(ClientAlertMetric::from($metric), $value) : null,
                            $numerator, $denominator, $value, $now);
                        if (null !== $change) {
                            ++$result[$change];
                        }
                    }
                }
                $result['evaluated_clients'] = max($result['evaluated_clients'], \count($rows));
            }

            return $result;
        });
    }

    /** @return list<array<string, mixed>> */
    private function facts(\DateTimeImmutable $now, int $hours): array
    {
        $since = $now->modify("-$hours hours")->format('Y-m-d H:i:s.uP');
        $nowSql = $now->format('Y-m-d H:i:s.uP');

        return $this->connection->fetchAllAssociative(<<<'SQL'
            WITH ev AS (
                SELECT sj.client_id, e.message_id, e.event_type, e.failure_scope
                  FROM message_events e
                  JOIN messages m ON m.id = e.message_id
                  JOIN send_jobs sj ON sj.id = m.send_job_id
                 WHERE e.occurred_at >= :since AND e.occurred_at < :now
                   AND e.event_type IN ('hard_bounce', 'soft_bounce', 'deferred', 'complaint', 'transport_outcome_unknown', 'message_suppressed')
            ), per_client AS (
                SELECT client_id,
                       count(DISTINCT message_id) FILTER (WHERE event_type = 'hard_bounce') AS hard_bounces,
                       count(DISTINCT message_id) FILTER (WHERE event_type = 'soft_bounce') AS soft_bounces,
                       count(DISTINCT message_id) FILTER (WHERE event_type = 'deferred') AS deferrals,
                       count(DISTINCT message_id) FILTER (WHERE event_type IN ('deferred', 'soft_bounce')) AS deferred_or_soft,
                       count(DISTINCT message_id) FILTER (WHERE event_type IN ('deferred', 'soft_bounce') AND failure_scope = 'provider_policy') AS provider_policy_failures,
                       count(DISTINCT message_id) FILTER (WHERE event_type = 'complaint') AS complaints,
                       count(DISTINCT message_id) FILTER (WHERE event_type = 'message_suppressed') AS suppressed,
                       count(DISTINCT message_id) FILTER (WHERE event_type = 'transport_outcome_unknown') AS outcome_unknown
                  FROM ev GROUP BY client_id
            )
            SELECT c.id::text AS client_id,
                   COALESCE(u.messages_submitted, 0) AS messages_submitted,
                   COALESCE(u.validation_addresses, 0) AS validation_addresses,
                   COALESCE(p.hard_bounces, 0) AS hard_bounces, COALESCE(p.soft_bounces, 0) AS soft_bounces,
                   COALESCE(p.deferrals, 0) AS deferrals, COALESCE(p.deferred_or_soft, 0) AS deferred_or_soft,
                   COALESCE(p.provider_policy_failures, 0) AS provider_policy_failures, COALESCE(p.complaints, 0) AS complaints,
                   COALESCE(p.suppressed, 0) AS suppressed, COALESCE(p.outcome_unknown, 0) AS outcome_unknown,
                   COALESCE(w.failed, 0) AS webhook_failures, b.previous_daily_average
              FROM clients c
              LEFT JOIN per_client p ON p.client_id = c.id
              LEFT JOIN LATERAL (
                SELECT sum(quantity) FILTER (WHERE usage_type = 'message_submitted') AS messages_submitted,
                       sum(quantity) FILTER (WHERE usage_type = 'validation_address') AS validation_addresses
                  FROM usage_records WHERE client_id = c.id AND occurred_at >= :since AND occurred_at < :now
              ) u ON true
              LEFT JOIN LATERAL (
                SELECT count(*) AS failed FROM webhook_deliveries
                 WHERE client_id = c.id AND created_at >= :since AND created_at < :now AND status = 'failed'
              ) w ON true
              LEFT JOIN LATERAL (
                SELECT round(COALESCE(sum(quantity), 0) / 7.0, 2) AS previous_daily_average FROM usage_records
                 WHERE client_id = c.id AND usage_type = 'message_submitted'
                   AND occurred_at >= CAST(:now AS timestamptz) - interval '8 days' AND occurred_at < CAST(:now AS timestamptz) - interval '1 day'
              ) b ON :hours = 24
             WHERE c.status <> 'closed' OR p.client_id IS NOT NULL OR u.messages_submitted IS NOT NULL
             ORDER BY c.id
            SQL, ['since' => $since, 'now' => $nowSql, 'hours' => $hours]);
    }

    /** @param array<string, mixed> $r */
    private function store(array $r, int $hours, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO client_reputation_metrics (client_id, window_hours, computed_at, messages_submitted, validation_addresses,
                hard_bounces, soft_bounces, deferrals, provider_policy_failures, complaints, suppressed, outcome_unknown,
                webhook_failures, previous_daily_average)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (client_id, window_hours) DO UPDATE SET computed_at = EXCLUDED.computed_at,
                messages_submitted = EXCLUDED.messages_submitted, validation_addresses = EXCLUDED.validation_addresses,
                hard_bounces = EXCLUDED.hard_bounces, soft_bounces = EXCLUDED.soft_bounces, deferrals = EXCLUDED.deferrals,
                provider_policy_failures = EXCLUDED.provider_policy_failures, complaints = EXCLUDED.complaints,
                suppressed = EXCLUDED.suppressed, outcome_unknown = EXCLUDED.outcome_unknown,
                webhook_failures = EXCLUDED.webhook_failures, previous_daily_average = EXCLUDED.previous_daily_average
            SQL, [$r['client_id'], $hours, $now->format('Y-m-d H:i:s.uP'), $r['messages_submitted'], $r['validation_addresses'],
            $r['hard_bounces'], $r['soft_bounces'], $r['deferrals'], $r['provider_policy_failures'], $r['complaints'],
            $r['suppressed'], $r['outcome_unknown'], $r['webhook_failures'], $r['previous_daily_average']]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: bool}> metric => [numerator, denominator, value, applicable]
     */
    private function signals(array $r, int $hours): array
    {
        $sent = (float) $r['messages_submitted'];
        $enough = $sent >= $this->thresholds->minMessages;
        $rate = static fn (float $n): float => $sent > 0 ? round(100.0 * $n / $sent, 4) : 0.0;
        $out = [
            'hard_bounce_rate' => [(float) $r['hard_bounces'], $sent, $rate((float) $r['hard_bounces']), $enough],
            'complaint_rate' => [(float) $r['complaints'], $sent, $rate((float) $r['complaints']), $enough],
            'deferral_rate' => [(float) $r['deferred_or_soft'], $sent, $rate((float) $r['deferred_or_soft']), $enough],
        ];
        if (24 === $hours) {
            $baseline = max(1.0, (float) ($r['previous_daily_average'] ?? 0));
            $out['volume_increase'] = [$sent, $baseline, round($sent / $baseline, 4), $enough];
        }

        return $out;
    }

    /** @return 'opened'|'updated'|'resolved'|null */
    private function alert(string $clientId, ClientAlertMetric $metric, int $hours, ?ClientAlertSeverity $severity,
        float $numerator, float $denominator, float $value, \DateTimeImmutable $now): ?string
    {
        $open = $this->connection->fetchAssociative(
            'SELECT id, severity FROM client_alerts WHERE client_id = ? AND metric = ? AND window_hours = ? AND resolved_at IS NULL FOR UPDATE',
            [$clientId, $metric->value, $hours]);
        $at = $now->format('Y-m-d H:i:s.uP');
        if (null === $severity) {
            if (false === $open) {
                return null;
            }
            $this->connection->executeStatement('UPDATE client_alerts SET resolved_at = ? WHERE id = ?', [$at, $open['id']]);

            return 'resolved';
        }
        $threshold = $this->thresholds->of($metric)[$severity->value];
        if (false === $open) {
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO client_alerts (id, client_id, metric, window_hours, severity, numerator, denominator, value, threshold,
                    first_observed_at, last_observed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL, [Uuid::v7()->toRfc4122(), $clientId, $metric->value, $hours, $severity->value, $numerator, $denominator,
                $value, $threshold, $at, $at]);

            return 'opened';
        }
        $escalated = ClientAlertSeverity::Critical === $severity && ClientAlertSeverity::Warning->value === $open['severity'];
        $this->connection->executeStatement(<<<'SQL'
            UPDATE client_alerts SET severity = ?, numerator = ?, denominator = ?, value = ?, threshold = ?, last_observed_at = ?,
                   acknowledged_at = CASE WHEN ? THEN NULL ELSE acknowledged_at END,
                   acknowledged_by = CASE WHEN ? THEN NULL ELSE acknowledged_by END,
                   acknowledgement_note = CASE WHEN ? THEN NULL ELSE acknowledgement_note END
             WHERE id = ?
            SQL, [$severity->value, $numerator, $denominator, $value, $threshold, $at, $escalated, $escalated, $escalated, $open['id']],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
             ParameterType::BOOLEAN, ParameterType::BOOLEAN, ParameterType::BOOLEAN, ParameterType::STRING]);

        return 'updated';
    }
}
