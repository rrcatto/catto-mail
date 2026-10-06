<?php

declare(strict_types=1);

namespace App\Dashboard;

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
    ];

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
            'clients' => array_map('intval', $c->fetchAllKeyValue('SELECT status, count(*) FROM clients GROUP BY status ORDER BY status')),
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
    public function rates(): array
    {
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
                  FROM send_jobs sj WHERE sj.created_at >= now() - make_interval(days => ?) GROUP BY sj.client_id
              ) t JOIN clients c ON c.id = t.client_id
             ORDER BY hard_bounce_rate DESC NULLS LAST, complaint_rate DESC NULLS LAST, c.company_name
            SQL, [self::RATE_WINDOW_DAYS]);
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
            (SELECT count(*) FROM sending_domains d WHERE d.client_id = c.id AND d.status = 'verified') AS verified_domains
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
            SELECT id::text AS id, company_name, contact_email, status, plan, created_at, can_submit_global_suppressions
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
                $where[] = "a.occurred_at $op CAST(? AS date)".('to' === $key ? ' + 1' : '');
                $params[] = $day;
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
