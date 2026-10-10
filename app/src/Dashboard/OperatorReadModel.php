<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Util\InstallationTime;
use Doctrine\DBAL\Connection;

/**
 * Read-only cross-client queries behind the operator dashboard (Phase 6). Only
 * reachable by users with the global operator role (access_control and the
 * controller both check it).
 *
 * Health is derived from durable database signals only: work queues, leases held
 * by workers, the newest results they wrote and the Postfix-log ingest cursor.
 * Rendering a page never runs a shell command, a Podman/systemd call or a network
 * probe, so the dashboard stays fast and usable while a worker is down. The
 * Postfix queue snapshots are read by the Go daemon only (postfix-integration.md
 * §4); the web container has no access to them, so the dashboard shows the
 * messages Smarthost itself considers to be in Postfix instead of a queue depth.
 */
final class OperatorReadModel
{
    public const RATE_WINDOW_DAYS = 7;

    public const CLIENT_SORTS = [
        'created' => ['c.created_at', 'timestamptz'],
        'name' => ['c.company_name', 'text'],
        'status_changed' => ['c.status_changed_at', 'timestamptz'],
    ];

    /** Phase 9 alert list. */
    public const ALERT_SORTS = ['observed' => ['a.last_observed_at', 'timestamptz']];

    public const DSN_SORTS = ['received' => ['d.received_at', 'timestamptz']];

    public const SUPPRESSION_SORTS = ['created' => ['s.created_at', 'timestamptz']];

    public const AUDIT_SORTS = ['occurred' => ['a.occurred_at', 'timestamptz']];

    public const OUTBOX_SORTS = ['created' => ['w.created_at', 'timestamptz']];

    public const OUTBOX_SORTS_DELIVERIES = ['created' => ['d.created_at', 'timestamptz']];

    public const USER_SORTS = [
        'created' => ['u.created_at', 'timestamptz'],
        'email' => ['lower(u.email)', 'text'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly KeysetQuery $keyset,
        private readonly InstallationTime $time,
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $c = $this->connection;

        return [
            'validation' => $c->fetchAssociative(<<<'SQL'
                SELECT (SELECT count(*) FROM validation_jobs WHERE status IN ('queued', 'processing')) AS active_jobs,
                       (SELECT min(submitted_at) FROM validation_jobs WHERE status IN ('queued', 'processing')) AS oldest_active_job,
                       count(*) FILTER (WHERE processing_state = 'pending') AS pending,
                       count(*) FILTER (WHERE processing_state = 'retry_scheduled') AS retry_scheduled,
                       count(*) FILTER (WHERE processing_state = 'claimed' AND lease_expires_at > now()) AS claimed,
                       count(*) FILTER (WHERE processing_state = 'claimed' AND lease_expires_at <= now()) AS expired_leases,
                       count(DISTINCT claimed_by) FILTER (WHERE processing_state = 'claimed' AND lease_expires_at > now()) AS active_workers
                  FROM validation_addresses WHERE processing_state <> 'done'
                SQL),
            // Newest result of the jobs in progress (bounded: only their addresses), and the newest completed job.
            'validation_last_result' => $c->fetchOne(<<<'SQL'
                SELECT max(va.checked_at) FROM validation_addresses va
                 WHERE va.job_id IN (SELECT id FROM validation_jobs WHERE status IN ('queued', 'processing'))
                SQL),
            'validation_last_completed' => $c->fetchOne("SELECT max(completed_at) FROM validation_jobs WHERE status = 'completed'"),
            'sending' => $c->fetchAssociative(<<<'SQL'
                SELECT count(*) FILTER (WHERE status = 'queued') AS queued_jobs,
                       count(*) FILTER (WHERE status = 'processing') AS processing_jobs,
                       count(*) FILTER (WHERE status = 'dispatched') AS dispatched_jobs,
                       count(*) FILTER (WHERE status IN ('queued', 'processing') AND lease_expires_at > now()) AS leased_jobs,
                       count(DISTINCT claimed_by) FILTER (WHERE status IN ('queued', 'processing') AND lease_expires_at > now()) AS active_workers,
                       min(queued_at) FILTER (WHERE status IN ('queued', 'processing')) AS oldest_queued
                  FROM send_jobs WHERE status IN ('queued', 'processing', 'dispatched')
                SQL),
            // Messages per current status from the counters Go maintains per job (one row per job, no message scan).
            'statuses' => array_map('intval', $c->fetchAllKeyValue(<<<'SQL'
                SELECT k, sum(v::bigint) FROM send_jobs sj, jsonb_each_text(sj.summary_counts_json) AS x(k, v) GROUP BY k
                SQL)),
            'rates' => $this->rates(),
            'ingest' => $c->fetchAllAssociative('SELECT source, updated_at FROM delivery_ingest_cursors ORDER BY source'),
            'suppressions' => $c->fetchAllKeyValue(<<<'SQL'
                SELECT reason, count(*) FROM suppressions
                 WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > now()) GROUP BY reason ORDER BY reason
                SQL),
            'global_suppressions' => (int) $c->fetchOne(<<<'SQL'
                SELECT count(*) FROM suppressions WHERE client_id IS NULL AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > now())
                SQL),
            'unmatched_dsns' => array_map('intval', $c->fetchAllKeyValue(
                'SELECT status, count(*) FROM unmatched_dsns WHERE status IN (\'open\', \'match_requested\') GROUP BY status')),
            'webhooks' => $this->webhookSummary(),
            'delivery' => $this->deliveryStatus(),
            'clients' => array_map('intval', $c->fetchAllKeyValue('SELECT status, count(*) FROM clients GROUP BY status ORDER BY status')),
            'alerts' => array_map('intval', $c->fetchAllKeyValue('SELECT severity, count(*) FROM client_alerts WHERE resolved_at IS NULL GROUP BY severity')),
            'rate_window_days' => self::RATE_WINDOW_DAYS,
        ];
    }

    /**
     * Cross-client transport outcome rates over the window, per client, from the
     * job counters of jobs created in the window (counts messages by current
     * status; a bounce rate is bounced / messages that reached Postfix).
     *
     * @return list<array<string, mixed>>
     */
    public function rates(?\DateTimeImmutable $since = null): array
    {
        $since ??= new \DateTimeImmutable(\sprintf('-%d days', self::RATE_WINDOW_DAYS));

        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.id::text AS client_id, c.company_name, c.status AS client_status, t.*,
                   round(100.0 * t.hard_bounced / NULLIF(t.reached_postfix, 0), 2) AS hard_bounce_rate,
                   round(100.0 * t.soft_bounced / NULLIF(t.reached_postfix, 0), 2) AS soft_bounce_rate,
                   round(100.0 * t.deferred / NULLIF(t.reached_postfix, 0), 2) AS deferral_rate,
                   round(100.0 * t.complained / NULLIF(t.reached_postfix, 0), 2) AS complaint_rate
              FROM (
                SELECT sj.client_id,
                       COALESCE(sum((summary_counts_json->>'remote_accepted')::bigint), 0) AS remote_accepted,
                       COALESCE(sum((summary_counts_json->>'hard_bounced')::bigint), 0) AS hard_bounced,
                       COALESCE(sum((summary_counts_json->>'soft_bounced')::bigint), 0) AS soft_bounced,
                       COALESCE(sum((summary_counts_json->>'deferred')::bigint), 0) AS deferred,
                       COALESCE(sum((summary_counts_json->>'complained')::bigint), 0) AS complained,
                       COALESCE(sum((summary_counts_json->>'outcome_unknown')::bigint), 0) AS outcome_unknown,
                       COALESCE(sum((summary_counts_json->>'suppressed')::bigint), 0) AS suppressed,
                       COALESCE(sum(COALESCE((summary_counts_json->>'submitted')::bigint, 0) + COALESCE((summary_counts_json->>'deferred')::bigint, 0)
                           + COALESCE((summary_counts_json->>'outcome_unknown')::bigint, 0) + COALESCE((summary_counts_json->>'remote_accepted')::bigint, 0)
                           + COALESCE((summary_counts_json->>'soft_bounced')::bigint, 0) + COALESCE((summary_counts_json->>'hard_bounced')::bigint, 0)
                           + COALESCE((summary_counts_json->>'complained')::bigint, 0)), 0) AS reached_postfix
                  FROM send_jobs sj WHERE sj.created_at >= ? GROUP BY sj.client_id
              ) t JOIN clients c ON c.id = t.client_id
             ORDER BY hard_bounce_rate DESC NULLS LAST, complaint_rate DESC NULLS LAST, c.company_name
            SQL, [$since->format(\DATE_ATOM)]);
    }

    /**
     * The operator overview page's current-state figures in one statement (the page has a
     * query budget; overview() above serves `smarthost:ops:status` with the same signals).
     *
     * @return array<string, mixed>
     */
    public function overviewSnapshot(): array
    {
        $r = $this->connection->fetchAssociative(<<<'SQL'
            SELECT v.*, s.*,
                   (SELECT count(*) FROM validation_jobs WHERE status IN ('queued', 'processing')) AS validation_active_jobs,
                   (SELECT min(submitted_at) FROM validation_jobs WHERE status IN ('queued', 'processing')) AS validation_oldest_active_job,
                   (SELECT max(va.checked_at) FROM validation_addresses va
                     WHERE va.job_id IN (SELECT id FROM validation_jobs WHERE status IN ('queued', 'processing'))) AS validation_last_result,
                   (SELECT max(completed_at) FROM validation_jobs WHERE status = 'completed') AS validation_last_completed,
                   (SELECT jsonb_object_agg(k, n) FROM (SELECT k, sum(v::bigint) AS n FROM send_jobs sj, jsonb_each_text(sj.summary_counts_json) AS x(k, v) GROUP BY k) t) AS statuses,
                   (SELECT jsonb_agg(jsonb_build_object('source', source, 'updated_at', updated_at) ORDER BY source) FROM delivery_ingest_cursors) AS ingest,
                   (SELECT jsonb_object_agg(reason, n) FROM (SELECT reason, count(*) AS n FROM suppressions
                     WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > now()) GROUP BY reason) t) AS suppressions,
                   (SELECT count(*) FROM suppressions WHERE client_id IS NULL AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > now())) AS global_suppressions,
                   (SELECT jsonb_object_agg(status, n) FROM (SELECT status, count(*) AS n FROM unmatched_dsns
                     WHERE status IN ('open', 'match_requested') GROUP BY status) t) AS unmatched_dsns,
                   (SELECT count(*) FROM webhook_events WHERE fanned_out_at IS NULL) AS webhook_events_awaiting_fanout,
                   (SELECT jsonb_object_agg(k, n) FROM (
                        SELECT CASE WHEN attempt_count > 0 THEN 'retrying' ELSE 'pending' END AS k, count(*) AS n FROM webhook_deliveries WHERE status = 'pending' GROUP BY 1
                        UNION ALL
                        SELECT status, count(*) FROM webhook_deliveries WHERE status <> 'pending' AND created_at > now() - interval '24 hours' GROUP BY 1) t) AS webhook_deliveries,
                   (SELECT count(*) FROM webhook_worker_heartbeats WHERE stopped_at IS NULL AND last_seen_at > now() - interval '2 minutes') AS webhook_workers,
                   (SELECT jsonb_object_agg(status, n) FROM (SELECT status, count(*) AS n FROM clients GROUP BY status) t) AS clients,
                   (SELECT jsonb_object_agg(severity, n) FROM (SELECT severity, count(*) AS n FROM client_alerts WHERE resolved_at IS NULL GROUP BY severity) t) AS alerts,
                   (SELECT company_name FROM clients WHERE status = 'pending_approval' ORDER BY status_changed_at LIMIT 1) AS first_pending_client
              FROM (SELECT count(*) FILTER (WHERE processing_state = 'pending') AS pending,
                           count(*) FILTER (WHERE processing_state = 'retry_scheduled') AS retry_scheduled,
                           count(*) FILTER (WHERE processing_state = 'claimed' AND lease_expires_at > now()) AS claimed,
                           count(*) FILTER (WHERE processing_state = 'claimed' AND lease_expires_at <= now()) AS expired_leases,
                           count(DISTINCT claimed_by) FILTER (WHERE processing_state = 'claimed' AND lease_expires_at > now()) AS validation_workers
                      FROM validation_addresses WHERE processing_state <> 'done') v,
                   (SELECT count(*) FILTER (WHERE status = 'queued') AS queued_jobs,
                           count(*) FILTER (WHERE status = 'processing') AS processing_jobs,
                           count(*) FILTER (WHERE status = 'dispatched') AS dispatched_jobs,
                           count(*) FILTER (WHERE status IN ('queued', 'processing') AND lease_expires_at > now()) AS leased_jobs,
                           count(DISTINCT claimed_by) FILTER (WHERE status IN ('queued', 'processing') AND lease_expires_at > now()) AS delivery_workers,
                           min(queued_at) FILTER (WHERE status IN ('queued', 'processing')) AS oldest_queued
                      FROM send_jobs WHERE status IN ('queued', 'processing', 'dispatched')) s
            SQL) ?: [];
        $json = static fn (string $key): array => \is_string($r[$key] ?? null) ? (json_decode($r[$key], true) ?: []) : [];
        $ints = static fn (string $key): array => array_map('intval', $json($key));

        return [
            'validation' => [
                'active_jobs' => (int) ($r['validation_active_jobs'] ?? 0), 'oldest_active_job' => $r['validation_oldest_active_job'] ?? null,
                'pending' => (int) ($r['pending'] ?? 0), 'retry_scheduled' => (int) ($r['retry_scheduled'] ?? 0),
                'claimed' => (int) ($r['claimed'] ?? 0), 'expired_leases' => (int) ($r['expired_leases'] ?? 0),
                'active_workers' => (int) ($r['validation_workers'] ?? 0),
                'last_result' => $r['validation_last_result'] ?? null, 'last_completed' => $r['validation_last_completed'] ?? null,
            ],
            'sending' => [
                'queued_jobs' => (int) ($r['queued_jobs'] ?? 0), 'processing_jobs' => (int) ($r['processing_jobs'] ?? 0),
                'dispatched_jobs' => (int) ($r['dispatched_jobs'] ?? 0), 'leased_jobs' => (int) ($r['leased_jobs'] ?? 0),
                'active_workers' => (int) ($r['delivery_workers'] ?? 0), 'oldest_queued' => $r['oldest_queued'] ?? null,
            ],
            'statuses' => $ints('statuses'),
            'ingest' => $json('ingest'),
            'suppressions' => $ints('suppressions'),
            'global_suppressions' => (int) ($r['global_suppressions'] ?? 0),
            'unmatched_dsns' => $ints('unmatched_dsns'),
            'webhooks' => ['events_awaiting_fanout' => (int) ($r['webhook_events_awaiting_fanout'] ?? 0),
                'deliveries' => $ints('webhook_deliveries'), 'workers' => (int) ($r['webhook_workers'] ?? 0)],
            'clients' => $ints('clients'),
            'alerts' => $ints('alerts'),
            'first_pending_client' => $r['first_pending_client'] ?? null,
        ];
    }

    /**
     * Figures of the overview period from the per-job counters the delivery daemon and the
     * validator maintain (no message or address scan): messages per bucket by current status
     * for this and the previous period, send jobs with their hard-bounce rate, validation
     * results, and the per-client rates. Jobs count in the bucket in which they were created.
     *
     * @return array<string, mixed>
     */
    public function trends(OverviewPeriod $p): array
    {
        $sums = TrendCharts::countersSql();
        $series = TrendCharts::bucketise($this->connection->fetchAllAssociative(
            "SELECT to_char(date_trunc(CAST(? AS text), created_at AT TIME ZONE CAST(? AS text)), 'YYYY-MM-DD HH24:00') AS bucket, $sums
               FROM send_jobs WHERE created_at >= ? GROUP BY 1",
            [$p->unit, $p->zone->getName(), $p->previousSince->format(\DATE_ATOM)]), $p);

        $jobs = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT sj.id::text AS id, sj.client_id::text AS client_id, c.company_name, sj.external_reference, sj.created_at,
                   COALESCE((sj.summary_counts_json->>'hard_bounced')::bigint, 0) AS hard_bounced,
                   COALESCE((sj.summary_counts_json->>'submitted')::bigint, 0) + COALESCE((sj.summary_counts_json->>'deferred')::bigint, 0)
                   + COALESCE((sj.summary_counts_json->>'outcome_unknown')::bigint, 0) + COALESCE((sj.summary_counts_json->>'remote_accepted')::bigint, 0)
                   + COALESCE((sj.summary_counts_json->>'soft_bounced')::bigint, 0) + COALESCE((sj.summary_counts_json->>'hard_bounced')::bigint, 0)
                   + COALESCE((sj.summary_counts_json->>'complained')::bigint, 0) AS reached_postfix
              FROM send_jobs sj JOIN clients c ON c.id = sj.client_id
             WHERE sj.created_at >= ? ORDER BY sj.created_at DESC LIMIT 500
            SQL, [$p->since->format(\DATE_ATOM)]);

        $validation = $this->connection->fetchAssociative(<<<'SQL'
            SELECT count(*) AS jobs, COALESCE(sum(processed_count), 0) AS checked,
                   (SELECT jsonb_object_agg(k, n) FROM (SELECT k, sum(v::bigint) AS n FROM validation_jobs vj, jsonb_each_text(vj.classification_counts_json) AS x(k, v)
                     WHERE vj.submitted_at >= ? GROUP BY k) t) AS classes
              FROM validation_jobs WHERE submitted_at >= ?
            SQL, [$p->since->format(\DATE_ATOM), $p->since->format(\DATE_ATOM)]) ?: [];

        return $series + [
            'jobs' => array_values(array_filter($jobs, static fn (array $j): bool => (int) $j['reached_postfix'] > 0)),
            'validation' => ['jobs' => (int) ($validation['jobs'] ?? 0), 'checked' => (int) ($validation['checked'] ?? 0),
                'classes' => array_map('intval', \is_string($validation['classes'] ?? null) ? (json_decode($validation['classes'], true) ?: []) : [])],
            'rates' => $this->rates($p->since),
        ];
    }

    /**
     * Messages submitted to Postfix per minute, summarised per bucket of the period: the typical
     * rate (the median of the minutes with a submission), the peak minute and the total. Reads
     * only the submitted_to_postfix events of the period (message_events_submitted_idx).
     *
     * @return array<string, array{typical: float, peak: int, total: int}> bucket key => figures (buckets without submissions are absent)
     */
    public function submissionRate(OverviewPeriod $p): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT to_char(date_trunc(CAST(? AS text), m AT TIME ZONE CAST(? AS text)), 'YYYY-MM-DD HH24:00') AS bucket,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY n) AS typical, max(n) AS peak, sum(n) AS total
              FROM (SELECT date_trunc('minute', occurred_at) AS m, count(*) AS n
                      FROM message_events WHERE event_type = 'submitted_to_postfix' AND occurred_at >= ?
                     GROUP BY 1) per_minute
             GROUP BY 1
            SQL, [$p->unit, $p->zone->getName(), $p->since->format(\DATE_ATOM)]);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['bucket']] = ['typical' => round((float) $r['typical'], 1), 'peak' => (int) $r['peak'], 'total' => (int) $r['total']];
        }

        return $out;
    }

    /**
     * The newest audit entries for the overview's activity list.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(int $limit = 5): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT a.actor_type, a.actor_id, a.action, a.target_type, a.occurred_at, u.email AS actor_email
              FROM audit_log a LEFT JOIN users u ON a.actor_type = 'user' AND u.id::text = a.actor_id
             ORDER BY a.occurred_at DESC LIMIT ?
            SQL, [$limit], [\Doctrine\DBAL\ParameterType::INTEGER]);
    }

    /**
     * Open reputation alerts, critical first, for the overview's attention list.
     *
     * @return list<array<string, mixed>>
     */
    public function openAlerts(int $limit = 3): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT a.client_id::text AS client_id, c.company_name, a.metric, a.severity, a.value, a.threshold, a.window_hours
              FROM client_alerts a JOIN clients c ON c.id = a.client_id
             WHERE a.resolved_at IS NULL
             ORDER BY a.severity = 'critical' DESC, a.last_observed_at DESC LIMIT ?
            SQL, [$limit], [\Doctrine\DBAL\ParameterType::INTEGER]);
    }

    /**
     * Counts behind the navigation badges (one cheap statement per operator page).
     *
     * @return array{open_alerts: int, pending_clients: int, open_dsns: int, failed_webhooks: int, warn_checks: int, fail_checks: int}
     */
    public function navCounts(): array
    {
        $r = $this->connection->fetchAssociative(<<<'SQL'
            SELECT (SELECT count(*) FROM client_alerts WHERE resolved_at IS NULL) AS open_alerts,
                   (SELECT count(*) FROM clients WHERE status = 'pending_approval') AS pending_clients,
                   (SELECT count(*) FROM unmatched_dsns WHERE status = 'open') AS open_dsns,
                   (SELECT count(*) FILTER (WHERE status = 'failed') FROM webhook_deliveries
                     WHERE status <> 'pending' AND created_at > now() - interval '24 hours') AS failed_webhooks,
                   (SELECT count(*) FROM system_checks WHERE result = 'warn') AS warn_checks,
                   (SELECT count(*) FROM system_checks WHERE result = 'fail') AS fail_checks
            SQL) ?: [];

        return array_map('intval', $r + ['open_alerts' => 0, 'pending_clients' => 0, 'open_dsns' => 0, 'failed_webhooks' => 0, 'warn_checks' => 0, 'fail_checks' => 0]);
    }

    /**
     * @param array{status?: string, q?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function clients(array $filters, Listing $listing): array
    {
        $where = [];
        $params = [];
        if ('' !== ($filters['status'] ?? '')) {
            $where[] = 'c.status = ?';
            $params[] = $filters['status'];
        }
        if ('' !== ($filters['q'] ?? '')) {
            $where[] = "(c.company_name ILIKE ? ESCAPE '\\' OR c.contact_email ILIKE ? ESCAPE '\\')";
            $like = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            array_push($params, $like, $like);
        }

        return $this->keyset->page(<<<'SQL'
            c.id::text AS id, c.company_name, c.contact_email, c.status, c.plan, c.created_at, c.can_submit_global_suppressions,
            c.origin, c.status_changed_at, c.approved_at,
            (SELECT count(*) FROM sending_domains d WHERE d.client_id = c.id AND d.status = 'verified') AS verified_domains,
            (SELECT count(*) FROM client_alerts a WHERE a.client_id = c.id AND a.resolved_at IS NULL) AS open_alerts,
            (SELECT CASE WHEN bool_or(a.severity = 'critical') THEN 'critical' WHEN count(*) > 0 THEN 'warning' END FROM client_alerts a WHERE a.client_id = c.id AND a.resolved_at IS NULL) AS worst_alert
            SQL, 'clients c', $where, $params, $listing, self::CLIENT_SORTS, 'c.id');
    }

    /** @return array<string, mixed>|null */
    public function client(string $clientId): ?array
    {
        if (!ClientReadModel::isUuid($clientId)) {
            return null;
        }
        $c = $this->connection;
        $client = $c->fetchAssociative(<<<'SQL'
            SELECT id::text AS id, company_name, contact_email, status, plan, created_at, can_submit_global_suppressions,
                   origin, billing_contact_email, abuse_contact_email, status_changed_at, approved_at, closed_at, policy_acceptance_required
              FROM clients WHERE id = ?
            SQL, [$clientId]);
        if (false === $client) {
            return null;
        }
        $client['domains'] = $c->fetchAllAssociative(<<<'SQL'
            SELECT id::text AS id, domain, status, verified_at, last_checked_at, last_check_error, dkim_selector, dkim_status
              FROM sending_domains WHERE client_id = ? ORDER BY domain
            SQL, [$clientId]);
        // API key metadata only: never the hash.
        $client['api_keys'] = $c->fetchAllAssociative(<<<'SQL'
            SELECT id::text AS id, key_prefix, name, created_at, last_used_at, revoked_at
              FROM api_keys WHERE client_id = ? ORDER BY created_at DESC LIMIT 100
            SQL, [$clientId]);
        $client['members'] = $c->fetchAllAssociative(<<<'SQL'
            SELECT u.email, u.display_name, u.status, m.role, u.last_login_at
              FROM client_memberships m JOIN users u ON u.id = m.user_id WHERE m.client_id = ? ORDER BY u.email
            SQL, [$clientId]);
        $client['webhook_endpoints'] = $c->fetchAllAssociative(<<<'SQL'
            SELECT id::text AS id, url, status, subscribed_event_types, created_at, updated_at,
                   previous_signing_secret_expires_at IS NOT NULL AND previous_signing_secret_expires_at > now() AS rotation_overlap
              FROM webhook_endpoints WHERE client_id = ? ORDER BY created_at
            SQL, [$clientId]);

        return $client;
    }

    /**
     * Reputation alerts across clients (Phase 9), with the client's current status.
     *
     * @param array{state?: string, severity?: string, metric?: string, client?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function alerts(array $filters, Listing $listing): array
    {
        $where = [match ($filters['state'] ?? '') {
            'resolved' => 'a.resolved_at IS NOT NULL',
            'all' => 'true',
            default => 'a.resolved_at IS NULL',
        }];
        $params = [];
        foreach (['severity' => 'a.severity', 'metric' => 'a.metric'] as $key => $column) {
            if ('' !== ($filters[$key] ?? '')) {
                $where[] = "$column = ?";
                $params[] = $filters[$key];
            }
        }
        if (ClientReadModel::isUuid($filters['client'] ?? '')) {
            $where[] = 'a.client_id = ?';
            $params[] = $filters['client'];
        }

        return $this->keyset->page(<<<'SQL'
            a.id::text AS id, a.client_id::text AS client_id, c.company_name, c.status AS client_status, a.metric, a.window_hours,
            a.severity, a.numerator, a.denominator, a.value, a.threshold, a.first_observed_at, a.last_observed_at, a.resolved_at,
            a.acknowledged_at, a.acknowledgement_note, (SELECT u.email FROM users u WHERE u.id = a.acknowledged_by) AS acknowledged_by
            SQL, 'client_alerts a JOIN clients c ON c.id = a.client_id', $where, $params, $listing, self::ALERT_SORTS, 'a.id');
    }

    /**
     * The newest reputation metrics of every client with traffic in the window, riskiest
     * first (Phase 9).
     *
     * @return list<array<string, mixed>>
     */
    public function reputation(int $windowHours, int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT m.*, m.client_id::text AS client_id, c.company_name, c.status AS client_status,
                   (SELECT count(*) FROM client_alerts a WHERE a.client_id = m.client_id AND a.resolved_at IS NULL) AS open_alerts
              FROM client_reputation_metrics m JOIN clients c ON c.id = m.client_id
             WHERE m.window_hours = ? AND (m.messages_submitted > 0 OR m.validation_addresses > 0 OR m.suppressed > 0 OR m.webhook_failures > 0)
             ORDER BY (m.complaints::numeric / GREATEST(m.messages_submitted, 1)) DESC,
                      (m.hard_bounces::numeric / GREATEST(m.messages_submitted, 1)) DESC, c.company_name
             LIMIT ?
            SQL, [$windowHours, $limit]);
        foreach ($rows as $i => $r) {
            $rows[$i] += ClientAccountReadModel::rates($r);
        }

        return $rows;
    }

    public function reputationComputedAt(): ?string
    {
        $at = $this->connection->fetchOne('SELECT max(computed_at) FROM client_reputation_metrics');

        return false === $at || null === $at ? null : (string) $at;
    }

    /**
     * @param array{status?: string, classification?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function unmatchedDsns(array $filters, Listing $listing): array
    {
        $where = [];
        $params = [];
        foreach (['status' => 'd.status', 'classification' => 'd.classification'] as $key => $column) {
            if ('' !== ($filters[$key] ?? '')) {
                $where[] = "$column = ?";
                $params[] = $filters[$key];
            }
        }

        return $this->keyset->page(<<<'SQL'
            d.id::text AS id, d.received_at, d.classification, d.status, d.original_recipient, d.final_recipient,
            d.postfix_queue_id, d.reporting_mta, d.enhanced_status_code, d.resolved_at,
            jsonb_array_length(COALESCE(d.detail_json->'candidates', '[]'::jsonb)) AS candidates
            SQL, 'unmatched_dsns d', $where, $params, $listing, self::DSN_SORTS, 'd.id');
    }

    /**
     * Candidate messages for an unmatched DSN, as Go recorded them
     * (detail_json.candidates, D-36), enriched with the message's client and job.
     *
     * @param list<mixed> $candidates
     *
     * @return list<array<string, mixed>>
     */
    public function candidateMessages(array $candidates): array
    {
        $ids = [];
        foreach ($candidates as $c) {
            $id = \is_array($c) ? ($c['message_id'] ?? null) : null;
            if (\is_string($id) && ClientReadModel::isUuid($id)) {
                $ids[] = $id;
            }
        }
        if ([] === $ids) {
            return [];
        }

        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT m.id::text AS id, m.recipient_address, m.current_status, m.postfix_queue_id, m.created_at,
                   sj.id::text AS send_job_id, sj.sender_email, c.company_name
              FROM messages m JOIN send_jobs sj ON sj.id = m.send_job_id JOIN clients c ON c.id = sj.client_id
             WHERE m.id IN (?) ORDER BY m.created_at DESC
            SQL, [array_slice(array_values(array_unique($ids)), 0, 50)], [\Doctrine\DBAL\ArrayParameterType::STRING]);
    }

    /** @return array<string, mixed>|null */
    public function message(string $messageId): ?array
    {
        if (!ClientReadModel::isUuid($messageId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT m.id::text AS id, m.recipient_address, m.current_status, m.postfix_queue_id, sj.client_id::text AS client_id
              FROM messages m JOIN send_jobs sj ON sj.id = m.send_job_id WHERE m.id = ?
            SQL, [$messageId]);

        return false === $row ? null : $row;
    }

    /**
     * Global and client-scoped suppressions with operator-level provenance.
     *
     * @param array{address?: string, reason?: string, state?: string, scope?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function suppressions(array $filters, Listing $listing): array
    {
        $where = [];
        $params = [];
        if (null !== ($address = ClientReadModel::normalizedFilter($filters['address'] ?? null))) {
            $where[] = 's.address_or_domain = ?';
            $params[] = $address;
        }
        if ('' !== ($filters['reason'] ?? '')) {
            $where[] = 's.reason = ?';
            $params[] = $filters['reason'];
        }
        $where[] = match ($filters['state'] ?? '') {
            'active' => 's.lifted_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > now())',
            'lifted' => 's.lifted_at IS NOT NULL',
            'expired' => 's.lifted_at IS NULL AND s.expires_at <= now()',
            default => 'true',
        };
        $where[] = match ($filters['scope'] ?? '') {
            'global' => 's.client_id IS NULL',
            'client' => 's.client_id IS NOT NULL',
            default => 'true',
        };

        return $this->keyset->page(<<<'SQL'
            s.id::text AS id, s.address_or_domain, s.scope_type, s.reason, s.created_at, s.expires_at, s.lifted_at,
            s.client_id::text AS client_id, sc.company_name AS scope_client, s.source_client_id::text AS source_client_id,
            src.company_name AS source_client, s.source_message_id::text AS source_message_id, s.external_reference,
            CASE WHEN s.lifted_at IS NOT NULL THEN 'lifted' WHEN s.expires_at IS NOT NULL AND s.expires_at <= now() THEN 'expired' ELSE 'active' END AS state
            SQL, 'suppressions s LEFT JOIN clients sc ON sc.id = s.client_id LEFT JOIN clients src ON src.id = s.source_client_id',
            $where, $params, $listing, self::SUPPRESSION_SORTS, 's.id');
    }

    /**
     * @param array{action?: string, actor?: string, target_type?: string, target_id?: string, from?: string, to?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function audit(array $filters, Listing $listing): array
    {
        $where = [];
        $params = [];
        foreach (['action' => 'a.action', 'actor' => 'a.actor_id', 'target_type' => 'a.target_type', 'target_id' => 'a.target_id', 'actor_type' => 'a.actor_type'] as $key => $column) {
            if ('' !== ($filters[$key] ?? '')) {
                $where[] = "$column = ?";
                $params[] = $filters[$key];
            }
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $day = $filters[$key] ?? '';
            if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $day)) {
                // Whole days in the dashboard's time zone, as the page shows the times.
                $where[] = "a.occurred_at $op ((CAST(? AS date)".('to' === $key ? ' + 1' : '').")::timestamp AT TIME ZONE CAST(? AS text))";
                $params[] = $day;
                $params[] = $this->time->name();
            }
        }
        $page = $this->keyset->page(<<<'SQL'
            a.id::text AS id, a.actor_type, a.actor_id, a.action, a.target_type, a.target_id, a.detail_json, a.occurred_at,
            u.email AS actor_email
            SQL, 'audit_log a LEFT JOIN users u ON a.actor_type = \'user\' AND u.id::text = a.actor_id', $where, $params, $listing, self::AUDIT_SORTS, 'a.id');
        foreach ($page['rows'] as $i => $row) {
            $page['rows'][$i]['detail'] = AuditDetailSanitizer::sanitize(json_decode((string) $row['detail_json'], true) ?: []);
        }

        return $page;
    }

    /**
     * Dashboard users with their roles and number of client memberships.
     *
     * @param array{q?: string, status?: string, role?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function users(array $filters, Listing $listing): array
    {
        $where = [];
        $params = [];
        if ('' !== ($filters['q'] ?? '')) {
            $where[] = "(u.email ILIKE ? ESCAPE '\\' OR u.display_name ILIKE ? ESCAPE '\\')";
            $like = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            array_push($params, $like, $like);
        }
        if (\in_array($filters['status'] ?? '', ['active', 'disabled'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $filters['status'];
        }
        if ('' !== ($filters['role'] ?? '')) {
            $where[] = 'u.id IN (SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.role_key = ?)';
            $params[] = $filters['role'];
        }

        return $this->keyset->page(<<<'SQL'
            u.id::text AS id, u.email, u.display_name, u.status, u.created_at, u.last_login_at,
            (SELECT string_agg(r.role_key, ', ' ORDER BY r.role_key) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
            (SELECT count(*) FROM client_memberships m WHERE m.user_id = u.id) AS memberships
            SQL, 'users u', $where, $params, $listing, self::USER_SORTS, 'u.id');
    }

    /** @return list<array{id: string, company_name: string, status: string}> clients for membership pickers (bounded) */
    public function clientChoices(int $limit = 1000): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id::text AS id, company_name, status FROM clients ORDER BY lower(company_name), id LIMIT '.max(1, min($limit, 5000)));
    }

    /** @return list<array<string, mixed>> a user's client memberships */
    public function memberships(string $userId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.id::text AS client_id, c.company_name, c.status AS client_status, m.role, m.created_at
              FROM client_memberships m JOIN clients c ON c.id = m.client_id WHERE m.user_id = ? ORDER BY lower(c.company_name)
            SQL, [$userId]);
    }

    /**
     * Go delivery daemons (delivery_heartbeats, Phase 8): live/held/paused state, the
     * warm-up ceiling and the Postfix queue depth of the newest queue snapshot, as Go
     * last saw them. Bounded: daemons seen in the last day.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveryStatus(): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT worker_id, version, started_at, last_seen_at, stopped_at, live_delivery, send_work_held, outbound_paused,
                   global_rate_per_minute, queue_snapshot_at, queue_active, queue_deferred, queue_hold, queue_incoming,
                   submitted, temporary_failures, dsns_processed,
                   stopped_at IS NULL AND last_seen_at > now() - interval '2 minutes' AS alive,
                   queue_snapshot_at IS NOT NULL AND queue_snapshot_at > now() - interval '5 minutes' AS snapshot_fresh
              FROM delivery_heartbeats WHERE last_seen_at > now() - interval '1 day' ORDER BY last_seen_at DESC LIMIT 10
            SQL);
    }

    /** @return array{events_awaiting_fanout: int, deliveries: array<string, int>, workers: list<array<string, mixed>>} */
    public function webhookSummary(): array
    {
        // Bounded: every pending delivery (a small set), delivered and failed of the last 24 hours.
        $deliveries = array_map('intval', $this->connection->fetchAllKeyValue(<<<'SQL'
            SELECT CASE WHEN attempt_count > 0 THEN 'retrying' ELSE 'pending' END, count(*) FROM webhook_deliveries WHERE status = 'pending' GROUP BY 1
            UNION ALL
            SELECT status, count(*) FROM webhook_deliveries WHERE status <> 'pending' AND created_at > now() - interval '24 hours' GROUP BY 1
            SQL));

        return [
            'events_awaiting_fanout' => (int) $this->connection->fetchOne('SELECT count(*) FROM webhook_events WHERE fanned_out_at IS NULL'),
            'deliveries' => $deliveries,
            // Live or recently stopped worker processes (bounded).
            'workers' => $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT worker_id, version, started_at, last_seen_at, stopped_at, attempts, delivered, events_fanned_out,
                       stopped_at IS NULL AND last_seen_at > now() - interval '2 minutes' AS alive
                  FROM webhook_worker_heartbeats WHERE last_seen_at > now() - interval '1 day' ORDER BY last_seen_at DESC LIMIT 20
                SQL),
        ];
    }

    /**
     * Webhook deliveries across clients.
     *
     * @param array{status?: string, event_type?: string, client?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function webhookDeliveries(array $filters, Listing $listing): array
    {
        $where = [];
        $params = [];
        ClientReadModel::deliveryStatusFilter($where, $filters['status'] ?? '');
        if ('' !== ($filters['event_type'] ?? '')) {
            $where[] = 'd.event_type = ?';
            $params[] = $filters['event_type'];
        }
        if (ClientReadModel::isUuid($filters['client'] ?? '')) {
            $where[] = 'd.client_id = ?';
            $params[] = $filters['client'];
        }

        return $this->keyset->page(<<<'SQL'
            d.id::text AS id, d.webhook_event_id::text AS event_id, d.event_type, d.status, d.attempt_count, d.next_attempt_at,
            d.last_attempt_at, d.last_response_status, d.last_error, d.delivered_at, d.created_at, e.url, c.company_name, d.client_id::text AS client_id
            SQL, 'webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.webhook_endpoint_id JOIN clients c ON c.id = d.client_id',
            $where, $params, $listing, self::OUTBOX_SORTS_DELIVERIES, 'd.id');
    }

    /** @return list<string> distinct audit actions (for the filter) */
    public function auditActions(): array
    {
        return $this->connection->fetchFirstColumn('SELECT DISTINCT action FROM audit_log ORDER BY action LIMIT 500');
    }

    /**
     * The transactional webhook outbox (Phase 7 delivers it over HTTP; until then
     * these rows are pending outbox state, never proof of delivery).
     *
     * @param array{state?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function webhookEvents(array $filters, Listing $listing): array
    {
        $where = match ($filters['state'] ?? '') {
            'awaiting_fanout' => ['w.fanned_out_at IS NULL'],
            'fanned_out' => ['w.fanned_out_at IS NOT NULL'],
            default => [],
        };

        return $this->keyset->page(<<<'SQL'
            w.id::text AS id, w.event_type, w.subject_type, w.subject_id::text AS subject_id, w.created_at, w.fanned_out_at,
            c.company_name,
            (SELECT count(*) FROM webhook_deliveries d WHERE d.webhook_event_id = w.id) AS deliveries
            SQL, 'webhook_events w JOIN clients c ON c.id = w.client_id', $where, [], $listing, self::OUTBOX_SORTS, 'w.id');
    }
}
