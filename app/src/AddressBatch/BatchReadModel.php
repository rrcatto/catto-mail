<?php

declare(strict_types=1);

namespace App\AddressBatch;

use App\Domain\DomainRuleViolation;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Read side of the administrator address batches (specification 2.11): batch lists, the
 * counts of every state dimension, validation progress, filtered entries, CSV reports and
 * one address's timeline. Only what is recorded is shown: no event is inferred.
 */
final class BatchReadModel
{
    /** Filter name => SQL condition on the EntryStates columns (a fixed whitelist). */
    public const FILTERS = [
        'all' => 'true',
        'imported' => "outcome = 'imported'", 'duplicate' => "outcome = 'duplicate'", 'malformed' => "outcome = 'malformed'",
        'pending' => "validation_result = 'pending'", 'valid' => "validation_result = 'valid'", 'invalid' => "validation_result = 'invalid'",
        'risky' => "validation_result = 'risky'", 'unknown' => "validation_result = 'unknown'",
        'temporary_failure' => "validation_result = 'temporary_failure'",
        'typo_suspected' => 'suggested_address IS NOT NULL', 'disposable' => 'is_disposable', 'role_account' => 'is_role',
        'accept_all' => 'is_catch_all_or_accept_all',
        'suppressed' => "eligibility IN ('blocked_hard_bounce', 'blocked_complaint', 'blocked_suppressed')",
        'eligible' => "eligibility = 'eligible'", 'pending_review' => "eligibility = 'pending_review'",
        'excluded' => "eligibility = 'excluded'", 'blocked' => "eligibility LIKE 'blocked_%'",
        'unsent_eligible' => "eligibility = 'eligible' AND delivery_state = 'not_sent'",
        'sent' => "delivery_state <> 'not_sent'", 'remote_accepted' => "delivery_state = 'remote_accepted'",
        'deferred' => "delivery_state = 'deferred'", 'soft_bounced' => "delivery_state = 'soft_bounced'",
        'hard_bounced' => "delivery_state = 'hard_bounced'", 'complained' => "delivery_state = 'complained'",
        'unresolved' => "delivery_state IN ('waiting', 'created', 'queued', 'submitted', 'deferred', 'outcome_unknown')",
        'opened' => "engagement IN ('open_observed', 'clicked')", 'clicked' => "engagement = 'clicked'",
        'confirmed' => "consent_state = 'confirmed'", 'unsubscribed' => "consent_state = 'unsubscribed'",
        'global_opt_out' => "consent_state = 'global_opt_out'", 'unconfirmed' => "consent_state = 'unconfirmed'",
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function batches(int $limit = 100): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT b.id::text AS id, b.name, b.purpose, b.created_at, b.data_rows, b.imported_count, b.duplicate_count, b.malformed_count,
                   b.original_filename, c.company_name, c.id::text AS client_id, u.email AS uploaded_by, b.compliance_approved_at,
                   (SELECT count(*) FROM address_batch_sends s WHERE s.batch_id = b.id) AS sends
              FROM address_batches b JOIN clients c ON c.id = b.client_id LEFT JOIN users u ON u.id = b.uploaded_by_user_id
             ORDER BY b.created_at DESC, b.id DESC LIMIT ?
            SQL, [$limit], [ParameterType::INTEGER]);
    }

    /** @return array<string, mixed> */
    public function batch(string $id): array
    {
        $row = \Symfony\Component\Uid\Uuid::isValid($id) ? $this->connection->fetchAssociative(<<<'SQL'
            SELECT b.*, b.id::text AS id, c.company_name, c.id::text AS client_id, c.status AS client_status,
                   u.email AS uploaded_by, a.email AS approved_by
              FROM address_batches b JOIN clients c ON c.id = b.client_id
              LEFT JOIN users u ON u.id = b.uploaded_by_user_id LEFT JOIN users a ON a.id = b.compliance_approved_by_user_id
             WHERE b.id = ?
            SQL, [$id]) : false;
        if (false === $row) {
            throw new DomainRuleViolation('No such batch.');
        }

        return $row;
    }

    /** @return array<string, int> every count of the batch screen */
    public function summary(string $batchId): array
    {
        $sql = 'SELECT count(*) AS entries,'.implode(',', array_map(
            static fn (string $name, string $cond): string => " count(*) FILTER (WHERE $cond) AS \"$name\"",
            array_keys(self::FILTERS), self::FILTERS)).' FROM ('.EntryStates::sql().') s';
        $row = $this->connection->fetchAssociative($sql, ['batch' => $batchId]) ?: [];

        return array_map('intval', $row);
    }

    /** @return array{linked: int, done: int, percent: float, running: bool, counts: array<string, int>} */
    public function progress(string $batchId): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT count(va.id) AS linked, count(va.id) FILTER (WHERE va.processing_state = 'done') AS done,
                   count(*) FILTER (WHERE va.overall_classification IN ('deliverable', 'probably_deliverable')) AS valid,
                   count(*) FILTER (WHERE va.overall_classification = 'undeliverable') AS invalid,
                   count(*) FILTER (WHERE va.overall_classification = 'risky') AS risky,
                   count(*) FILTER (WHERE va.overall_classification = 'unknown') AS unknown,
                   count(*) FILTER (WHERE va.overall_classification = 'temporarily_unverifiable') AS temporary_failure
              FROM address_batch_entries e LEFT JOIN validation_addresses va ON va.id = e.validation_address_id
             WHERE e.batch_id = ? AND e.outcome = 'imported'
            SQL, [$batchId]) ?: [];
        $linked = (int) ($row['linked'] ?? 0);
        $done = (int) ($row['done'] ?? 0);

        return ['linked' => $linked, 'done' => $done, 'percent' => 0 === $linked ? 0.0 : round(100 * $done / $linked, 1),
            'running' => $done < $linked, 'counts' => array_map('intval', array_diff_key($row, ['linked' => 1, 'done' => 1]))];
    }

    /** @return list<array<string, mixed>> */
    public function entries(string $batchId, string $filter, int $offset, int $limit): array
    {
        $cond = self::FILTERS[$filter] ?? throw new DomainRuleViolation('Unknown filter.');
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM ('.EntryStates::sql().") s WHERE $cond ORDER BY row_number, id OFFSET :o LIMIT :l",
            ['batch' => $batchId, 'o' => $offset, 'l' => $limit], ['o' => ParameterType::INTEGER, 'l' => ParameterType::INTEGER]);

        return array_map(self::decorate(...), $rows);
    }

    public function count(string $batchId, string $filter): int
    {
        $cond = self::FILTERS[$filter] ?? throw new DomainRuleViolation('Unknown filter.');

        return (int) $this->connection->fetchOne('SELECT count(*) FROM ('.EntryStates::sql().") s WHERE $cond", ['batch' => $batchId]);
    }

    /** @return list<string> the entry ids matching a filter (bulk review decisions) */
    public function ids(string $batchId, string $filter): array
    {
        $cond = self::FILTERS[$filter] ?? throw new DomainRuleViolation('Unknown filter.');

        return array_map('strval', $this->connection->fetchFirstColumn('SELECT id::text FROM ('.EntryStates::sql().") s WHERE $cond", ['batch' => $batchId]));
    }

    /** CSV report of one filter (UTF-8, header row); formula-like cells are neutralised for spreadsheets. */
    public function csv(string $batchId, string $filter): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['row', 'original', 'address', 'import_outcome', 'validation_result', 'flags', 'suggestion', 'eligibility',
            'consent', 'delivery_state', 'engagement', 'suppression', 'explanation'], ',', '"', '');
        $offset = 0;
        do {
            $rows = $this->entries($batchId, $filter, $offset, 2000);
            foreach ($rows as $r) {
                fputcsv($out, array_map(self::cell(...), [$r['row_number'], $r['original_value'], $r['normalized_address'], $r['outcome'],
                    $r['validation_result'], implode(' ', $r['flags']), $r['suggested_address'], $r['eligibility'], $r['consent_state'],
                    $r['delivery_state'], $r['engagement'], $r['suppression_reason'], $r['explanation']]), ',', '"', '');
            }
            $offset += 2000;
        } while (2000 === \count($rows));
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** @return array{entry: array<string, mixed>, timeline: list<array{at: string, what: string, detail: ?string}>, evidence: list<array<string, mixed>>} */
    public function entry(string $batchId, string $entryId): array
    {
        if (!\Symfony\Component\Uid\Uuid::isValid($entryId)) {
            throw new DomainRuleViolation('No such address.');
        }
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM ('.EntryStates::sql('e.id = :entry').') s', ['batch' => $batchId, 'entry' => $entryId]);
        if ([] === $rows) {
            throw new DomainRuleViolation('No such address.');
        }
        $e = self::decorate($rows[0]);
        $batch = $this->batch($batchId);
        $t = [['at' => (string) $e['created_at'], 'what' => 'Imported', 'detail' => 'Row '.$e['row_number'].' of '.$batch['original_filename'].' ('.$e['outcome'].')']];
        $evidence = [];
        if (null !== $e['validation_address_id']) {
            $job = $this->connection->fetchAssociative('SELECT j.submitted_at, j.started_at FROM validation_jobs j JOIN validation_addresses va ON va.job_id = j.id WHERE va.id = ?', [$e['validation_address_id']]);
            if (false !== $job) {
                $t[] = ['at' => (string) $job['submitted_at'], 'what' => 'Validation requested', 'detail' => null];
            }
            $evidence = $this->connection->fetchAllAssociative('SELECT evidence_type, provider_host, response_code, enhanced_status_code, detail_json, occurred_at
                FROM validation_evidence WHERE validation_address_id = ? ORDER BY occurred_at', [$e['validation_address_id']]);
            if (null !== $e['checked_at']) {
                $t[] = ['at' => (string) $e['checked_at'], 'what' => 'Validation result: '.EntryStates::VALIDATION_RESULTS[$e['validation_result']], 'detail' => $e['explanation']];
            }
        }
        if (null !== $e['send_job_id']) {
            $send = $this->connection->fetchAssociative(<<<'SQL'
                SELECT bs.stage, j.created_at, j.queued_at, j.started_at FROM address_batch_sends bs JOIN send_jobs j ON j.id = bs.send_job_id
                 WHERE bs.send_job_id = ?
                SQL, [$e['send_job_id']]);
            if (false !== $send) {
                $t[] = ['at' => (string) $send['created_at'], 'what' => 'Send job created ('.$send['stage'].' stage)', 'detail' => null];
                if (null !== $send['queued_at']) {
                    $t[] = ['at' => (string) $send['queued_at'], 'what' => 'Send job submitted (queued for the delivery daemon)', 'detail' => null];
                }
                if (null !== $send['started_at']) {
                    $t[] = ['at' => (string) $send['started_at'], 'what' => 'Claimed by the delivery daemon', 'detail' => null];
                }
            }
        }
        if (null !== $e['message_id']) {
            foreach ($this->connection->fetchAllAssociative('SELECT event_type, event_source, occurred_at, smtp_code, enhanced_status_code, remote_host, diagnostic
                FROM message_events WHERE message_id = ? ORDER BY occurred_at, id', [$e['message_id']]) as $ev) {
                $t[] = ['at' => (string) $ev['occurred_at'], 'what' => \App\Dashboard\Labels::label((string) $ev['event_type'], 'event_type'),
                    'detail' => trim(implode(' ', array_filter([$ev['remote_host'], $ev['smtp_code'], $ev['enhanced_status_code'], $ev['diagnostic']]))) ?: null];
            }
        }
        if (null !== $e['consent_changed_at']) {
            $t[] = ['at' => (string) $e['consent_changed_at'], 'what' => 'Recipient answered: '.EntryStates::CONSENT[$e['consent_state']], 'detail' => null];
        }
        if (null !== $e['suppressed_at']) {
            $t[] = ['at' => (string) $e['suppressed_at'], 'what' => 'Suppression in force: '.$e['suppression_reason'], 'detail' => $e['suppression_scope'].' scope'];
        }
        usort($t, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return ['entry' => $e, 'timeline' => $t, 'evidence' => $evidence];
    }

    /** @return list<array<string, mixed>> the batch's sends with their progress */
    public function sends(string $batchId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT bs.id::text AS id, bs.stage, bs.recipient_count, bs.subject, bs.created_at, u.email AS created_by,
                   j.id::text AS send_job_id, j.status, j.summary_counts_json
              FROM address_batch_sends bs JOIN send_jobs j ON j.id = bs.send_job_id LEFT JOIN users u ON u.id = bs.created_by_user_id
             WHERE bs.batch_id = ? ORDER BY bs.created_at
            SQL, [$batchId]);
    }

    /**
     * The lifecycle counts of a batch's sends (or of one send job), from messages and events only.
     *
     * @return array<string, int>
     */
    public function sendReport(string $batchId, ?string $sendJobId = null): array
    {
        $jobs = null === $sendJobId ? 'SELECT send_job_id FROM address_batch_sends WHERE batch_id = :batch'
            : 'SELECT send_job_id FROM address_batch_sends WHERE batch_id = :batch AND send_job_id = :job';
        $row = $this->connection->fetchAssociative(<<<SQL
            WITH r AS (SELECT r.id FROM send_job_recipients r WHERE r.send_job_id IN ({$jobs})),
                 m AS (SELECT m.* FROM messages m WHERE m.send_job_id IN ({$jobs}))
            SELECT (SELECT count(*) FROM r) AS total,
                   (SELECT count(*) FROM r WHERE NOT EXISTS (SELECT 1 FROM m WHERE m.send_job_recipient_id = r.id)) AS waiting,
                   count(*) FILTER (WHERE m.current_status IN ('created', 'queued')) AS queued,
                   count(*) FILTER (WHERE m.current_status = 'submitted') AS submitted,
                   count(*) FILTER (WHERE m.current_status = 'remote_accepted') AS remote_accepted,
                   count(*) FILTER (WHERE m.current_status = 'deferred') AS deferred,
                   count(*) FILTER (WHERE m.current_status = 'soft_bounced') AS soft_bounced,
                   count(*) FILTER (WHERE m.current_status = 'hard_bounced') AS hard_bounced,
                   count(*) FILTER (WHERE m.current_status = 'complained') AS complaints,
                   count(*) FILTER (WHERE m.current_status = 'suppressed') AS suppressed,
                   count(*) FILTER (WHERE m.current_status = 'failed') AS failed,
                   count(*) FILTER (WHERE m.current_status = 'outcome_unknown') AS outcome_unknown,
                   count(*) FILTER (WHERE EXISTS (SELECT 1 FROM message_events e WHERE e.message_id = m.id AND e.event_type = 'open_recorded')) AS open_observed,
                   count(*) FILTER (WHERE EXISTS (SELECT 1 FROM message_events e WHERE e.message_id = m.id AND e.event_type = 'click_recorded')) AS clicked,
                   count(*) FILTER (WHERE m.current_status IN ('created', 'queued', 'submitted', 'deferred', 'outcome_unknown')) AS unresolved
              FROM m
            SQL, array_filter(['batch' => $batchId, 'job' => $sendJobId], static fn ($v) => null !== $v)) ?: [];

        return array_map('intval', $row);
    }

    /** @param array<string, mixed> $r */
    private static function decorate(array $r): array
    {
        foreach (['is_role', 'is_disposable', 'is_catch_all_or_accept_all', 'is_domain_typo_suspected', 'opened', 'clicked'] as $b) {
            $r[$b] = null === $r[$b] ? null : (true === $r[$b] || 't' === $r[$b] || 1 === $r[$b]);
        }
        $r['flags'] = EntryStates::flags($r);
        $r['explanation'] = EntryStates::explain($r);

        return $r;
    }

    private static function cell(mixed $v): string
    {
        $s = (string) $v;

        return '' !== $s && \in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
    }
}
