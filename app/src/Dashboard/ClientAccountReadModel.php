<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Client\ClientLifecycle;
use App\Client\ClientLimitPolicy;
use App\Client\QuotaEnforcer;
use App\Entity\Client;
use App\Usage\UsagePeriod;
use App\Usage\UsageReporting;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Phase 9 account data of one client for the operator client page and the client's own
 * dashboard: lifecycle, limits and quota usage, API keys, webhook endpoint health,
 * usage periods, policy acceptance, billing statements, and - operator only - private
 * notes, reputation metrics, alerts and the client's audit history.
 *
 * Every query carries the client id explicitly (dashboard queries are not covered by
 * the ORM tenant filter). Operator-only methods are called only by operator pages.
 */
final class ClientAccountReadModel
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClientLimitPolicy $limits,
        private readonly QuotaEnforcer $quotas,
        private readonly UsageReporting $usage,
        private readonly ClientLifecycle $lifecycle,
    ) {
    }

    /**
     * Limits with their source, and the quotas with the current period's admitted work.
     *
     * @return array{limits: list<array<string, mixed>>, quotas: list<array<string, mixed>>}
     */
    public function limits(Client $client): array
    {
        $own = $this->limits->clientLimits($client);
        $effective = $this->limits->effective($client);
        $rows = [];
        foreach ($own as $column => $value) {
            $rows[] = ['column' => $column, 'own' => $value, 'ceiling' => $this->limits->ceiling($column), 'effective' => $effective[$column]];
        }
        $quotas = [];
        foreach ($this->quotas->currentUsage($client) as $key => $used) {
            [$metric, $period] = explode('|', $key);
            $column = ClientLimitPolicy::QUOTAS[$key] ?? null;
            $quotas[] = ['metric' => $metric, 'period' => $period, 'used' => $used, 'limit' => null === $column ? null : $own[$column]];
        }

        return ['limits' => $rows, 'quotas' => $quotas];
    }

    /** @return list<array<string, mixed>> API key metadata only (never the hash), newest first */
    public function apiKeys(string $clientId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id::text AS id, key_prefix, name, created_at, last_used_at, revoked_at, expires_at,
                   revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now()) AS usable
              FROM api_keys WHERE client_id = ? ORDER BY created_at DESC, id DESC LIMIT 200
            SQL, [$clientId]);
    }

    /**
     * Per endpoint: deliveries of the last 7 days by state, the last success and failure,
     * and the failures since the last success (repeated-failure visibility). Every part is
     * a bounded range on webhook_deliveries_endpoint_status_idx (endpoint, status,
     * created_at), never the endpoint's whole history.
     *
     * @return list<array<string, mixed>>
     */
    public function webhookEndpoints(string $clientId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT e.id::text AS id, e.url, e.status, e.subscribed_event_types, e.created_at, e.updated_at,
                   e.previous_signing_secret_expires_at IS NOT NULL AND e.previous_signing_secret_expires_at > now() AS rotation_overlap,
                   (SELECT count(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = e.id AND d.status = 'delivered'
                       AND d.created_at >= now() - interval '7 days') AS delivered_7d,
                   (SELECT count(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = e.id AND d.status = 'failed'
                       AND d.created_at >= now() - interval '7 days') AS failed_7d,
                   (SELECT count(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = e.id AND d.status = 'pending') AS pending,
                   last_ok.delivered_at AS last_delivered_at,
                   (SELECT d.updated_at FROM webhook_deliveries d WHERE d.webhook_endpoint_id = e.id AND d.status = 'failed'
                     ORDER BY d.created_at DESC LIMIT 1) AS last_failed_at,
                   (SELECT count(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = e.id AND d.status = 'failed'
                       AND d.created_at > COALESCE(last_ok.created_at, '-infinity')) AS failures_since_success
              FROM webhook_endpoints e
              -- The newest successful delivery: one backward step on webhook_deliveries_endpoint_status_idx.
              LEFT JOIN LATERAL (
                SELECT d.created_at, d.delivered_at FROM webhook_deliveries d
                 WHERE d.webhook_endpoint_id = e.id AND d.status = 'delivered' ORDER BY d.created_at DESC LIMIT 1
              ) last_ok ON true
             WHERE e.client_id = ? ORDER BY e.created_at
            SQL, [$clientId]);
    }

    /** @return array<string, array<string, array{quantity: int, records: int}>> period label => totals */
    public function usagePeriods(string $clientId): array
    {
        $out = [];
        foreach (['current_day', 'current_month', 'previous_month'] as $name) {
            $out[$name] = $this->usage->clientTotals($clientId, UsagePeriod::named($name));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function policy(Client $client): array
    {
        return $this->lifecycle->policyStatus($client) + ['acceptances' => $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT a.policy_version, a.accepted_at, a.source, a.reference, u.email AS accepted_by
              FROM client_policy_acceptances a LEFT JOIN users u ON u.id = a.accepted_by_user_id
             WHERE a.client_id = ? ORDER BY a.accepted_at DESC
            SQL, [$client->getId()->toRfc4122()])];
    }

    /** @return list<array<string, mixed>> billing statements (the client sees finalized and exported ones only) */
    public function statements(string $clientId, bool $operator): array
    {
        // Fixed SQL text per audience (no user input): drafts and void statements are operator-only.
        $visible = $operator ? '' : " AND s.status IN ('finalized', 'exported')";
        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT s.id::text AS id, s.period_start, s.period_end, s.status, s.reconciliation_status, s.finalized_at, s.exported_at,
                   s.external_reference, s.created_at,
                   (SELECT jsonb_object_agg(l.usage_type, l.quantity) FROM billing_statement_lines l WHERE l.statement_id = s.id) AS totals
              FROM billing_statements s
             WHERE s.client_id = ?{$visible}
             ORDER BY s.period_start DESC, s.created_at DESC LIMIT 36
            SQL, [$clientId]);
        foreach ($rows as $i => $r) {
            $rows[$i]['totals'] = null === $r['totals'] ? [] : json_decode((string) $r['totals'], true, 8, \JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    // ------------------------------------------------------------- operator only

    /** @return list<array<string, mixed>> private operator notes, newest first */
    public function notes(string $clientId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT n.note, n.created_at, u.email AS author FROM client_notes n LEFT JOIN users u ON u.id = n.author_user_id
             WHERE n.client_id = ? ORDER BY n.created_at DESC LIMIT 100
            SQL, [$clientId]);
    }

    /**
     * Reputation metrics of the last evaluation (both windows) with rates, and the
     * client's open alerts.
     *
     * @return array{metrics: list<array<string, mixed>>, alerts: list<array<string, mixed>>}
     */
    public function reputation(string $clientId): array
    {
        $metrics = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT * FROM client_reputation_metrics WHERE client_id = ? ORDER BY window_hours
            SQL, [$clientId]);
        foreach ($metrics as $i => $m) {
            $metrics[$i] += self::rates($m);
        }

        return ['metrics' => $metrics, 'alerts' => $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT a.id::text AS id, a.metric, a.window_hours, a.severity, a.numerator, a.denominator, a.value, a.threshold,
                   a.first_observed_at, a.last_observed_at, a.resolved_at, a.acknowledged_at, a.acknowledgement_note, u.email AS acknowledged_by
              FROM client_alerts a LEFT JOIN users u ON u.id = a.acknowledged_by
             WHERE a.client_id = ? ORDER BY a.resolved_at IS NULL DESC, a.last_observed_at DESC LIMIT 20
            SQL, [$clientId])];
    }

    /**
     * The client's audit history: actions on the client itself and on its API keys,
     * sending domains and webhook endpoints (each served by audit_log_target_idx).
     *
     * @return list<array<string, mixed>>
     */
    public function audit(string $clientId, int $limit = 50): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            -- One newest-first range on audit_log_target_idx per target (the client and each of its keys,
            -- domains and endpoints), merged: never a scan of the whole audit log.
            WITH targets(target_type, target_id) AS (
                SELECT 'client', CAST(:c AS text)
                UNION ALL SELECT 'api_key', id::text FROM api_keys WHERE client_id = CAST(:c AS uuid)
                UNION ALL SELECT 'sending_domain', id::text FROM sending_domains WHERE client_id = CAST(:c AS uuid)
                UNION ALL SELECT 'webhook_endpoint', id::text FROM webhook_endpoints WHERE client_id = CAST(:c AS uuid)
            )
            SELECT a.occurred_at, a.action, a.actor_type, a.actor_id, a.target_type, a.target_id, a.detail_json,
                   u.email AS actor_email
              FROM targets t
              CROSS JOIN LATERAL (
                SELECT * FROM audit_log x WHERE x.target_type = t.target_type AND x.target_id = t.target_id
                 ORDER BY x.occurred_at DESC LIMIT :n
              ) a
              LEFT JOIN users u ON a.actor_type = 'user' AND u.id::text = a.actor_id
             ORDER BY a.occurred_at DESC LIMIT :n
            SQL, ['c' => $clientId, 'n' => $limit], ['n' => ParameterType::INTEGER]);
        foreach ($rows as $i => $row) {
            $rows[$i]['detail'] = AuditDetailSanitizer::sanitize(json_decode((string) $row['detail_json'], true) ?: []);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $m a client_reputation_metrics row
     *
     * @return array<string, ?float> percentages of messages submitted (null without messages)
     */
    public static function rates(array $m): array
    {
        $sent = (int) $m['messages_submitted'];
        $pct = static fn (string $k): ?float => $sent > 0 ? round(100.0 * (int) $m[$k] / $sent, 2) : null;

        return ['hard_bounce_rate' => $pct('hard_bounces'), 'complaint_rate' => $pct('complaints'),
            'deferral_rate' => $pct('deferrals'), 'soft_bounce_rate' => $pct('soft_bounces'),
            'suppression_rate' => ($sent + (int) $m['suppressed']) > 0 ? round(100.0 * (int) $m['suppressed'] / ($sent + (int) $m['suppressed']), 2) : null];
    }
}
