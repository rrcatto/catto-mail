<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Sending\AddressNormalizer;
use App\Util\InstallationTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Read-only queries behind the client dashboard (Phase 6).
 *
 * Every query takes the client id as its first argument and carries its own
 * client condition (raw DBAL is not covered by the ORM tenant filter): a row of
 * another client is indistinguishable from a missing one. The controller obtains
 * the client id only after the ClientVoter granted access.
 *
 * Nothing here loads a whole job or table into PHP: lists are keyset pages
 * (KeysetQuery), statistics are aggregated by PostgreSQL. Overview counters come
 * from the per-job counters that Go maintains transactionally
 * (send_jobs.summary_counts_json, validation_jobs.classification_counts_json), so
 * they cost one row per job, not one per message.
 */
final class ClientReadModel
{
    public const VALIDATION_JOB_SORTS = [
        'submitted' => ['vj.submitted_at', 'timestamptz'],
        'total' => ['vj.total_addresses', 'integer'],
    ];

    public const ADDRESS_SORTS = [
        'input' => ['va.id', 'uuid'],
        'address' => ['va.original_address', 'text'],
        'classification' => ["COALESCE(va.overall_classification, '')", 'text'],
    ];

    public const SEND_JOB_SORTS = [
        'created' => ['sj.created_at', 'timestamptz'],
        'recipients' => ['sj.total_recipients', 'integer'],
    ];

    public const MESSAGE_SORTS = [
        'created' => ['m.created_at', 'timestamptz'],
        'recipient' => ['m.recipient_address', 'text'],
        'status' => ['m.current_status', 'text'],
    ];

    public const SUPPRESSED_SORTS = ['created' => ['m.created_at', 'timestamptz']];

    public const EVENT_SORTS = ['occurred' => ['e.occurred_at', 'timestamptz']];

    public const USAGE_SORTS = ['occurred' => ['u.occurred_at', 'timestamptz']];

    public const DELIVERY_SORTS = ['created' => ['d.created_at', 'timestamptz']];

    /** Columns of the validation result export: exactly the OpenAPI ValidationAddress fields. */
    public const EXPORT_COLUMNS = [
        'id', 'external_address_reference', 'original_address', 'normalized_address', 'syntax_status', 'domain_status',
        'smtp_status', 'is_role', 'is_disposable', 'is_catch_all_or_accept_all', 'is_domain_typo_suspected',
        'suggested_address', 'suggestion_reason_code', 'suggestion_confidence', 'overall_classification', 'confidence',
        'diagnostic_code', 'diagnostic_text', 'checked_at',
    ];

    private const ADDRESS_COLUMNS = 'va.id::text AS id, va.external_address_reference, va.original_address, va.normalized_address,
        va.syntax_status, va.domain_status, va.smtp_status, va.is_role, va.is_disposable, va.is_catch_all_or_accept_all,
        va.is_domain_typo_suspected, va.suggested_address, va.suggestion_reason_code, va.suggestion_confidence,
        va.overall_classification, va.confidence, va.diagnostic_code, va.diagnostic_text, va.checked_at, va.processing_state';

    public function __construct(
        private readonly Connection $connection,
        private readonly KeysetQuery $keyset,
        private readonly InstallationTime $time,
    ) {
    }

    /**
     * The client overview for a period: messages per bucket by current status against the
     * previous period, validation results, engagement of the period's send jobs, sending
     * domains, work in progress and the newest jobs. Every query carries the client id.
     *
     * @return array<string, mixed>
     */
    public function overview(string $clientId, OverviewPeriod $p): array
    {
        $c = $this->connection;
        $since = $p->since->format(\DATE_ATOM);
        $sums = TrendCharts::countersSql();
        // Messages per bucket by current status, this period and the previous one (job counters, no message scan).
        $series = TrendCharts::bucketise($c->fetchAllAssociative(
            "SELECT to_char(date_trunc(CAST(? AS text), created_at AT TIME ZONE CAST(? AS text)), 'YYYY-MM-DD HH24:00') AS bucket, $sums
               FROM send_jobs WHERE client_id = ? AND created_at >= ? GROUP BY 1",
            [$p->unit, $p->zone->getName(), $clientId, $p->previousSince->format(\DATE_ATOM)]), $p);
        $validation = $c->fetchAssociative(<<<'SQL'
            SELECT count(*) AS jobs, COALESCE(sum(total_addresses), 0) AS addresses, COALESCE(sum(processed_count), 0) AS checked,
                   (SELECT count(*) FROM validation_jobs WHERE client_id = ? AND status IN ('queued', 'processing')) AS active_jobs,
                   (SELECT count(*) FROM send_jobs WHERE client_id = ? AND status IN ('collecting', 'queued', 'processing', 'dispatched')) AS active_send_jobs,
                   (SELECT jsonb_object_agg(k, n) FROM (SELECT k, sum(v::bigint) AS n FROM validation_jobs vj, jsonb_each_text(vj.classification_counts_json) AS x(k, v)
                     WHERE vj.client_id = ? AND vj.submitted_at >= ? GROUP BY k) t) AS classes
              FROM validation_jobs WHERE client_id = ? AND submitted_at >= ?
            SQL, [$clientId, $clientId, $clientId, $since, $clientId, $since]) ?: [];
        $engagement = $c->fetchAssociative(<<<'SQL'
            SELECT count(*) FILTER (WHERE e.event_type = 'open_recorded') AS open_events,
                   count(DISTINCT e.message_id) FILTER (WHERE e.event_type = 'open_recorded') AS opened_messages,
                   count(*) FILTER (WHERE e.event_type = 'click_recorded') AS click_events,
                   count(DISTINCT e.message_id) FILTER (WHERE e.event_type = 'click_recorded') AS clicked_messages
              FROM send_jobs sj JOIN messages m ON m.send_job_id = sj.id JOIN message_events e ON e.message_id = m.id
             WHERE sj.client_id = ? AND sj.created_at >= ?
               AND e.event_type IN ('open_recorded', 'click_recorded')
            SQL, [$clientId, $since]);
        $domains = $c->fetchAllAssociative(
            'SELECT domain, status, dkim_status FROM sending_domains WHERE client_id = ? ORDER BY domain', [$clientId]);

        return [
            'trends' => $series + ['validation' => ['jobs' => (int) ($validation['jobs'] ?? 0), 'checked' => (int) ($validation['checked'] ?? 0),
                'classes' => array_map('intval', \is_string($validation['classes'] ?? null) ? (json_decode($validation['classes'], true) ?: []) : [])]],
            'validation' => ['jobs' => (int) ($validation['jobs'] ?? 0), 'addresses' => (int) ($validation['addresses'] ?? 0),
                'checked' => (int) ($validation['checked'] ?? 0), 'active_jobs' => (int) ($validation['active_jobs'] ?? 0)],
            'active_send_jobs' => (int) ($validation['active_send_jobs'] ?? 0),
            'engagement' => $engagement,
            'domains' => $domains,
            'recent_validation_jobs' => $this->validationJobs($clientId, [], Listing::first('submitted', 'desc', 5))['rows'],
            'recent_send_jobs' => $this->sendJobs($clientId, [], Listing::first('created', 'desc', 5))['rows'],
        ];
    }

    /**
     * @param array{status?: string, external_reference?: string, from?: string, to?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function validationJobs(string $clientId, array $filters, Listing $listing): array
    {
        $where = ['vj.client_id = ?'];
        $params = [$clientId];
        self::filterEquals($where, $params, 'vj.status', $filters['status'] ?? null);
        self::filterEquals($where, $params, 'vj.external_reference', $filters['external_reference'] ?? null);
        $this->filterRange($where, $params, 'vj.submitted_at', $filters);

        return $this->keyset->page(<<<'SQL'
            vj.id::text AS id, vj.external_reference, vj.status, vj.submitted_at, vj.started_at, vj.completed_at,
            vj.total_addresses, vj.processed_count, vj.classification_counts_json
            SQL, 'validation_jobs vj', $where, $params, $listing, self::VALIDATION_JOB_SORTS, 'vj.id');
    }

    /** @return array<string, mixed>|null */
    public function validationJob(string $clientId, string $jobId): ?array
    {
        if (!self::isUuid($jobId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT id::text AS id, external_reference, status, submitted_at, started_at, completed_at, total_addresses,
                   processed_count, classification_counts_json
              FROM validation_jobs WHERE id = ? AND client_id = ?
            SQL, [$jobId, $clientId]);
        if (false === $row) {
            return null;
        }
        // Work still outstanding, only while the job runs (a finished job has none; this avoids a whole-job aggregate).
        $row['processing'] = \in_array($row['status'], ['queued', 'processing'], true) ? $this->connection->fetchAllKeyValue(
            'SELECT processing_state, count(*) FROM validation_addresses WHERE job_id = ? GROUP BY processing_state ORDER BY 1', [$jobId]) : [];

        return $row;
    }

    /**
     * Result rows of a job the caller already resolved for this client.
     *
     * @param array<string, string> $filters see addressFilter()
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function validationAddresses(string $clientId, string $jobId, array $filters, Listing $listing): array
    {
        [$where, $params] = $this->addressFilter($clientId, $jobId, $filters);

        return $this->keyset->page(self::ADDRESS_COLUMNS, 'validation_addresses va', $where, $params, $listing, self::ADDRESS_SORTS, 'va.id');
    }

    /**
     * Streams every filtered result row of a job in input order, in bounded
     * keyset batches (never the whole job in memory).
     *
     * @param array<string, string> $filters
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function exportValidationAddresses(string $clientId, string $jobId, array $filters, int $batch = 500): \Generator
    {
        $listing = Listing::first('input', 'asc', $batch);
        do {
            [$where, $params] = $this->addressFilter($clientId, $jobId, $filters);
            $page = $this->keyset->page(self::ADDRESS_COLUMNS, 'validation_addresses va', $where, $params, $listing, self::ADDRESS_SORTS, 'va.id');
            foreach ($page['rows'] as $row) {
                yield $row;
            }
            $last = end($page['rows']);
            $listing = $listing->withAfter(false === $last ? null : [(string) $last['_sort_value'], (string) $last['_sort_id']]);
        } while (null !== $page['next']);
    }

    /**
     * @param array{status?: string, external_reference?: string, from?: string, to?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function sendJobs(string $clientId, array $filters, Listing $listing): array
    {
        $where = ['sj.client_id = ?'];
        $params = [$clientId];
        self::filterEquals($where, $params, 'sj.status', $filters['status'] ?? null);
        self::filterEquals($where, $params, 'sj.external_reference', $filters['external_reference'] ?? null);
        $this->filterRange($where, $params, 'sj.created_at', $filters);

        return $this->keyset->page(<<<'SQL'
            sj.id::text AS id, sj.external_reference, sj.message_class, sj.status, sj.created_at, sj.queued_at,
            sj.dispatch_completed_at, sj.completed_at, sj.total_recipients, sj.summary_counts_json, sj.track_opens, sj.track_clicks
            SQL, 'send_jobs sj', $where, $params, $listing, self::SEND_JOB_SORTS, 'sj.id');
    }

    /** @return array<string, mixed>|null */
    public function sendJob(string $clientId, string $jobId): ?array
    {
        if (!self::isUuid($jobId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT sj.id::text AS id, sj.external_reference, sj.message_class, sj.list_id, sj.sender_email, sj.sender_name,
                   sj.reply_to_email, sj.track_opens, sj.track_clicks, sj.status, sj.created_at, sj.queued_at, sj.started_at,
                   sj.dispatch_completed_at, sj.completed_at, sj.total_recipients, sj.summary_counts_json, d.domain AS sending_domain
              FROM send_jobs sj JOIN sending_domains d ON d.id = sj.sending_domain_id
             WHERE sj.id = ? AND sj.client_id = ?
            SQL, [$jobId, $clientId]);
        if (false === $row) {
            return null;
        }
        $row['engagement'] = $this->jobEngagement($jobId);

        return $row;
    }

    /**
     * Recorded opens/clicks of one job. "Messages with a recorded open" counts
     * messages with at least one open_recorded event; a click is never counted as
     * an open.
     *
     * @return array<string, mixed>
     */
    public function jobEngagement(string $jobId): array
    {
        $totals = $this->connection->fetchAssociative(<<<'SQL'
            SELECT count(*) FILTER (WHERE e.event_type = 'open_recorded') AS open_events,
                   count(DISTINCT e.message_id) FILTER (WHERE e.event_type = 'open_recorded') AS opened_messages,
                   min(e.occurred_at) FILTER (WHERE e.event_type = 'open_recorded') AS first_open,
                   max(e.occurred_at) FILTER (WHERE e.event_type = 'open_recorded') AS last_open,
                   count(*) FILTER (WHERE e.event_type = 'click_recorded') AS click_events,
                   count(DISTINCT e.message_id) FILTER (WHERE e.event_type = 'click_recorded') AS clicked_messages,
                   min(e.occurred_at) FILTER (WHERE e.event_type = 'click_recorded') AS first_click,
                   max(e.occurred_at) FILTER (WHERE e.event_type = 'click_recorded') AS last_click
              FROM messages m JOIN message_events e ON e.message_id = m.id
             WHERE m.send_job_id = ? AND e.event_type IN ('open_recorded', 'click_recorded')
            SQL, [$jobId]);
        $links = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT (e.metadata_json->>'link_index')::int AS link_index, count(*) AS clicks, count(DISTINCT e.message_id) AS messages,
                   min(l.target_url) AS target_url, count(DISTINCT l.target_url) AS targets
              FROM messages m JOIN message_events e ON e.message_id = m.id
              LEFT JOIN message_links l ON l.message_id = m.id AND l.link_index = (e.metadata_json->>'link_index')::int
             WHERE m.send_job_id = ? AND e.event_type = 'click_recorded'
             GROUP BY 1 ORDER BY clicks DESC, link_index LIMIT 50
            SQL, [$jobId]);

        return $totals + ['links' => $links];
    }

    /**
     * Messages of one job (the caller resolved the job for this client).
     *
     * @param array{status?: string, recipient?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function messages(string $clientId, string $jobId, array $filters, Listing $listing): array
    {
        $where = ['m.send_job_id = ?', 'm.send_job_id IN (SELECT id FROM send_jobs WHERE client_id = ?)'];
        $params = [$jobId, $clientId];
        self::filterEquals($where, $params, 'm.current_status', $filters['status'] ?? null);
        self::filterEquals($where, $params, 'm.recipient_address', self::normalizedFilter($filters['recipient'] ?? null));
        $page = $this->keyset->page(<<<'SQL'
            m.id::text AS id, m.recipient_address, m.external_recipient_reference, m.current_status, m.postfix_queue_id,
            m.created_at, m.resolved_at
            SQL, 'messages m', $where, $params, $listing, self::MESSAGE_SORTS, 'm.id');
        $page['rows'] = $this->withMessageStats($page['rows']);

        return $page;
    }

    /** @return array<string, mixed>|null the message with its job, or null when it is not this client's */
    public function message(string $clientId, string $messageId): ?array
    {
        if (!self::isUuid($messageId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT m.id::text AS id, m.send_job_id::text AS send_job_id, sj.external_reference AS job_reference,
                   m.recipient_address, m.external_recipient_reference, m.current_status, m.postfix_queue_id,
                   m.created_at, m.resolved_at, sj.track_opens, sj.track_clicks
              FROM messages m JOIN send_jobs sj ON sj.id = m.send_job_id
             WHERE m.id = ? AND sj.client_id = ?
            SQL, [$messageId, $clientId]);

        return false === $row ? null : $this->withMessageStats([$row])[0];
    }

    /**
     * The append-only event timeline of a message the caller resolved for this
     * client, oldest first. Only interpreted fields are returned: no tracking
     * token, no source keys, and from metadata only the suppression reason and the
     * clicked link index.
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function events(string $clientId, string $messageId, Listing $listing): array
    {
        return $this->keyset->page(<<<'SQL'
            e.id::text AS id, e.event_type, e.event_source, e.failure_scope, e.smtp_code, e.enhanced_status_code, e.remote_host,
            e.diagnostic, e.occurred_at, e.recorded_at, e.metadata_json->>'reason' AS suppression_reason,
            (e.metadata_json->>'link_index')::int AS link_index
            SQL, 'message_events e', ['e.message_id = ?', 'e.message_id IN (SELECT m.id FROM messages m JOIN send_jobs sj ON sj.id = m.send_job_id WHERE sj.client_id = ?)'],
            [$messageId, $clientId], $listing, self::EVENT_SORTS, 'e.id');
    }

    /**
     * Suppressions this client may see in full (D-30, schema.md §3): its own
     * client-scoped rows and the global opt-outs it reported itself. Global rows
     * caused by other clients or the system are never listed here.
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function suppressions(string $clientId, ?string $address, bool $activeOnly, Listing $listing): array
    {
        $where = ['(s.client_id = ? OR (s.client_id IS NULL AND s.source_client_id = ?))'];
        $params = [$clientId, $clientId];
        self::filterEquals($where, $params, 's.address_or_domain', self::normalizedFilter($address));
        if ($activeOnly) {
            $where[] = 's.lifted_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > now())';
        }

        return $this->keyset->page(<<<'SQL'
            s.id::text AS id, s.address_or_domain, s.scope_type, s.reason, s.created_at, s.expires_at, s.lifted_at,
            (s.client_id IS NULL) AS is_global, s.external_reference
            SQL, 'suppressions s', $where, $params, $listing, ['created' => ['s.created_at', 'timestamptz']], 's.id');
    }

    /**
     * Messages of this client that were not submitted because a suppression
     * matched, with only the broad reason (from the message_suppressed event).
     * Which client or message caused a global suppression is never shown.
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function suppressedMessages(string $clientId, ?string $recipient, Listing $listing): array
    {
        $where = ['m.send_job_id IN (SELECT id FROM send_jobs WHERE client_id = ?)', "m.current_status = 'suppressed'"];
        $params = [$clientId];
        self::filterEquals($where, $params, 'm.recipient_address', self::normalizedFilter($recipient));

        return $this->keyset->page(<<<'SQL'
            m.id::text AS id, m.send_job_id::text AS send_job_id, m.recipient_address, m.created_at,
            (SELECT e.metadata_json->>'reason' FROM message_events e WHERE e.message_id = m.id AND e.event_type = 'message_suppressed'
              ORDER BY e.occurred_at LIMIT 1) AS reason
            SQL, 'messages m', $where, $params, $listing, self::SUPPRESSED_SORTS, 'm.id');
    }

    /** @return list<array<string, mixed>> */
    public function sendingDomains(string $clientId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id::text AS id, domain, status, verification_token, verified_at, last_checked_at, last_check_error,
                   dkim_selector, dkim_status, created_at
              FROM sending_domains WHERE client_id = ? ORDER BY domain
            SQL, [$clientId]);
    }

    /**
     * The client's webhook endpoints with delivery counts (never secrets).
     *
     * @return list<array<string, mixed>>
     */
    public function webhookEndpoints(string $clientId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT e.id::text AS id, e.url, e.status, e.subscribed_event_types, e.signing_secret_created_at, e.created_at, e.updated_at,
                   e.previous_signing_secret_expires_at IS NOT NULL AND e.previous_signing_secret_expires_at > now() AS rotation_overlap,
                   e.previous_signing_secret_expires_at,
                   (SELECT count(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = e.id AND d.status = 'delivered'
                                                              AND d.created_at > now() - interval '7 days') AS delivered,
                   (SELECT count(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = e.id AND d.status = 'pending') AS pending,
                   (SELECT count(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = e.id AND d.status = 'failed'
                                                              AND d.created_at > now() - interval '7 days') AS failed,
                   last_ok.delivered_at AS last_delivered_at,
                   -- Phase 9: repeated failures (permanently failed deliveries since the newest successful one).
                   (SELECT count(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = e.id AND d.status = 'failed'
                       AND d.created_at > COALESCE(last_ok.created_at, '-infinity')) AS failures_since_success
              FROM webhook_endpoints e
              LEFT JOIN LATERAL (SELECT d.created_at, d.delivered_at FROM webhook_deliveries d
                                  WHERE d.webhook_endpoint_id = e.id AND d.status = 'delivered' ORDER BY d.created_at DESC LIMIT 1) last_ok ON true
             WHERE e.client_id = ? ORDER BY e.created_at
            SQL, [$clientId]);
    }

    /**
     * This client's webhook deliveries (outbox state and HTTP attempts).
     *
     * @param array{status?: string, endpoint?: string} $filters status: pending, retrying, delivered, failed
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function webhookDeliveries(string $clientId, array $filters, Listing $listing): array
    {
        $where = ['d.client_id = ?'];
        $params = [$clientId];
        self::deliveryStatusFilter($where, $filters['status'] ?? '');
        if (self::isUuid($filters['endpoint'] ?? '')) {
            $where[] = 'd.webhook_endpoint_id = ?';
            $params[] = $filters['endpoint'];
        }

        return $this->keyset->page(<<<'SQL'
            d.id::text AS id, d.webhook_event_id::text AS event_id, d.event_type, d.status, d.attempt_count, d.next_attempt_at,
            d.last_attempt_at, d.last_response_status, d.last_error, d.delivered_at, d.created_at, e.url
            SQL, 'webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.webhook_endpoint_id', $where, $params, $listing, self::DELIVERY_SORTS, 'd.id');
    }

    /** @param list<string> $where */
    public static function deliveryStatusFilter(array &$where, string $status): void
    {
        $where[] = match ($status) {
            'pending' => "d.status = 'pending' AND d.attempt_count = 0",
            'retrying' => "d.status = 'pending' AND d.attempt_count > 0",
            'delivered' => "d.status = 'delivered'",
            'failed' => "d.status = 'failed'",
            default => 'true',
        };
    }

    /**
     * Usage per month and type (bounded: at most $months x 2 rows) and a keyset
     * page of the individual records.
     *
     * @return array{months: list<array<string, mixed>>, records: array{rows: list<array<string, mixed>>, next: ?string}}
     */
    public function usage(string $clientId, ?string $type, Listing $listing, int $months = 12): array
    {
        $monthsRows = $this->connection->fetchAllAssociative(<<<'SQL'
            -- One index-only range sum per month (usage_records_client_occurred_idx covers the
            -- columns), so no sort of the client's records (Phase 9 query-plan review).
            SELECT to_char(m, 'YYYY-MM') AS month, u.validation_addresses, u.messages_submitted
              FROM generate_series(date_trunc('month', now()) - make_interval(months => ?), date_trunc('month', now()), interval '1 month') AS m
              CROSS JOIN LATERAL (
                SELECT count(*) AS records,
                       sum(quantity) FILTER (WHERE usage_type = 'validation_address') AS validation_addresses,
                       sum(quantity) FILTER (WHERE usage_type = 'message_submitted') AS messages_submitted
                  FROM usage_records WHERE client_id = ? AND occurred_at >= m AND occurred_at < m + interval '1 month'
              ) u
             WHERE u.records > 0
             ORDER BY m DESC
            SQL, [$months - 1, $clientId]);
        $where = ['u.client_id = ?'];
        $params = [$clientId];
        self::filterEquals($where, $params, 'u.usage_type', $type);
        $records = $this->keyset->page(
            'u.id::text AS id, u.usage_type, u.quantity, u.reference_type, u.reference_id::text AS reference_id, u.occurred_at',
            'usage_records u', $where, $params, $listing, self::USAGE_SORTS, 'u.id');

        return ['months' => $monthsRows, 'records' => $records];
    }

    /**
     * Per-message statistics for one page of messages, in one grouped query over
     * the (message_id, occurred_at, id) index - never for the whole job.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withMessageStats(array $rows): array
    {
        if ([] === $rows) {
            return $rows;
        }
        $stats = $this->connection->fetchAllAssociativeIndexed(<<<'SQL'
            SELECT message_id::text AS message_id, count(*) AS events,
                   min(occurred_at) FILTER (WHERE event_type = 'submitted_to_postfix') AS submitted_at,
                   count(*) FILTER (WHERE event_type = 'open_recorded') AS opens,
                   min(occurred_at) FILTER (WHERE event_type = 'open_recorded') AS first_open,
                   max(occurred_at) FILTER (WHERE event_type = 'open_recorded') AS last_open,
                   count(*) FILTER (WHERE event_type = 'click_recorded') AS clicks,
                   min(occurred_at) FILTER (WHERE event_type = 'click_recorded') AS first_click,
                   max(occurred_at) FILTER (WHERE event_type = 'click_recorded') AS last_click
              FROM message_events WHERE message_id IN (?) GROUP BY message_id
            SQL, [array_column($rows, 'id')], [ArrayParameterType::STRING]);
        $empty = ['events' => 0, 'submitted_at' => null, 'opens' => 0, 'first_open' => null, 'last_open' => null,
            'clicks' => 0, 'first_click' => null, 'last_click' => null];
        foreach ($rows as $i => $row) {
            $rows[$i] += $stats[$row['id']] ?? $empty;
        }

        return $rows;
    }

    /**
     * @param array<string, string> $filters classification, syntax, domain, smtp, confidence, role, disposable, typo, catch_all ('yes'/'no')
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function addressFilter(string $clientId, string $jobId, array $filters): array
    {
        $where = ['va.job_id = ?', 'va.job_id IN (SELECT id FROM validation_jobs WHERE client_id = ?)'];
        $params = [$jobId, $clientId];
        foreach (['classification' => 'va.overall_classification', 'syntax' => 'va.syntax_status', 'domain' => 'va.domain_status',
            'smtp' => 'va.smtp_status', 'confidence' => 'va.confidence'] as $key => $column) {
            self::filterEquals($where, $params, $column, $filters[$key] ?? null);
        }
        foreach (['role' => 'va.is_role', 'disposable' => 'va.is_disposable', 'typo' => 'va.is_domain_typo_suspected',
            'catch_all' => 'va.is_catch_all_or_accept_all'] as $key => $column) {
            $value = $filters[$key] ?? '';
            if ('yes' === $value || 'no' === $value) {
                $where[] = $column.('yes' === $value ? ' IS TRUE' : ' IS NOT TRUE');
            }
        }

        return [$where, $params];
    }

    /**
     * @param list<string> $where
     * @param list<mixed>  $params
     */
    private static function filterEquals(array &$where, array &$params, string $column, ?string $value): void
    {
        if (null !== $value && '' !== $value) {
            $where[] = "$column = ?";
            $params[] = $value;
        }
    }

    /**
     * @param list<string>          $where
     * @param list<mixed>           $params
     * @param array<string, string> $filters from/to as YYYY-MM-DD (days in the installation's time zone, inclusive)
     */
    private function filterRange(array &$where, array &$params, string $column, array $filters): void
    {
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $day = $filters[$key] ?? '';
            if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $this->time->zone)) {
                $where[] = "$column $op ((CAST(? AS date)".('to' === $key ? ' + 1' : '').")::timestamp AT TIME ZONE CAST(? AS text))";
                $params[] = $day;
                $params[] = $this->time->name();
            }
        }
    }

    /**
     * An address or domain filter in the stored (D-32 normalised) form, so that
     * "Reader@Example.COM" finds "Reader@example.com". Unusable input is kept as
     * typed (it then simply matches nothing).
     */
    public static function normalizedFilter(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $value = trim($value);

        return (str_contains($value, '@') ? AddressNormalizer::normalize($value) : AddressNormalizer::normalizeDomain(rtrim($value, '.'))) ?? $value;
    }

    public static function isUuid(string $id): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }
}
