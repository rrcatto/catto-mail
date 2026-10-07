<?php

declare(strict_types=1);

namespace App\Usage;

use Doctrine\DBAL\Connection;

/**
 * Reconciles a client's metered usage of a period against the authoritative jobs and
 * messages (Phase 9; spec phase 9 acceptance "per-client usage metering reconciles
 * against jobs/messages").
 *
 * Validation (D-33): every validation job metered in the period must carry, over all of
 * its usage rows, exactly as many validation_address units as it has addresses in
 * `done`, all attributed to the job's own client and referencing the job.
 *
 * Sending (D-14): every message_submitted row of the period must reference a message of
 * the same client with exactly one unit and a submitted_to_postfix event; and every
 * message of the client whose submitted_to_postfix event falls in the period must have
 * its usage row (a message is metered at most once: usage_records_message_once_uq).
 *
 * The result is deterministic (findings in a fixed order) and bounded (at most
 * MAX_FINDINGS listed, with the total).
 */
final class UsageReconciliation
{
    public const MAX_FINDINGS = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{client_id: string, period: array{start: string, end: string}, status: string,
     *               checked: array<string, int>, findings_total: int, findings: list<array<string, mixed>>}
     */
    public function reconcile(string $clientId, UsagePeriod $period): array
    {
        $p = ['c' => $clientId, 's' => $period->startSql(), 'e' => $period->endSql()];
        $findings = [];

        // Validation: per job metered in the period, all usage of the job against its done addresses.
        $jobs = $this->connection->fetchAllAssociative(<<<'SQL'
            WITH jobs AS (
                SELECT DISTINCT reference_id FROM usage_records
                 WHERE client_id = :c AND usage_type = 'validation_address' AND reference_type = 'validation_job'
                   AND occurred_at >= :s AND occurred_at < :e)
            SELECT j.reference_id::text AS job_id, vj.client_id::text AS job_client,
                   (SELECT COALESCE(sum(quantity), 0) FROM usage_records u WHERE u.reference_type = 'validation_job'
                       AND u.reference_id = j.reference_id AND u.usage_type = 'validation_address') AS metered,
                   (SELECT COALESCE(sum(quantity), 0) FROM usage_records u WHERE u.reference_type = 'validation_job'
                       AND u.reference_id = j.reference_id AND u.usage_type = 'validation_address' AND u.client_id <> :c) AS metered_elsewhere,
                   (SELECT count(*) FROM validation_addresses va WHERE va.job_id = j.reference_id AND va.processing_state = 'done') AS done
              FROM jobs j LEFT JOIN validation_jobs vj ON vj.id = j.reference_id
             ORDER BY j.reference_id
            SQL, $p);
        foreach ($jobs as $j) {
            if (null === $j['job_client']) {
                $findings[] = ['type' => 'validation_usage_without_job', 'job_id' => $j['job_id'], 'metered' => (int) $j['metered']];
            } elseif ($j['job_client'] !== $clientId || (int) $j['metered_elsewhere'] > 0) {
                $findings[] = ['type' => 'validation_usage_wrong_client', 'job_id' => $j['job_id']];
            } elseif ((int) $j['metered'] !== (int) $j['done']) {
                $findings[] = ['type' => 'validation_usage_mismatch', 'job_id' => $j['job_id'],
                    'metered' => (int) $j['metered'], 'done_addresses' => (int) $j['done']];
            }
        }
        foreach ($this->connection->fetchFirstColumn(<<<'SQL'
            SELECT id::text FROM usage_records WHERE client_id = :c AND occurred_at >= :s AND occurred_at < :e
               AND ((usage_type = 'validation_address' AND reference_type <> 'validation_job')
                 OR (usage_type = 'message_submitted' AND (reference_type <> 'message' OR quantity <> 1)))
             ORDER BY id LIMIT 101
            SQL, $p) as $usageId) {
            $findings[] = ['type' => 'usage_wrong_reference', 'usage_id' => $usageId];
        }

        // Sending, usage -> message.
        $metered = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT count(*) FROM usage_records WHERE client_id = :c AND usage_type = 'message_submitted'
               AND occurred_at >= :s AND occurred_at < :e
            SQL, $p);
        foreach ($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT u.id::text AS usage_id, u.reference_id::text AS message_id,
                   CASE WHEN m.id IS NULL THEN 'missing_message'
                        WHEN sj.client_id <> u.client_id THEN 'other_client'
                        ELSE 'not_submitted' END AS reason
              FROM usage_records u
              LEFT JOIN messages m ON m.id = u.reference_id
              LEFT JOIN send_jobs sj ON sj.id = m.send_job_id
             WHERE u.client_id = :c AND u.usage_type = 'message_submitted' AND u.reference_type = 'message'
               AND u.occurred_at >= :s AND u.occurred_at < :e
               AND (m.id IS NULL OR sj.client_id <> u.client_id
                    OR NOT EXISTS (SELECT 1 FROM message_events e WHERE e.message_id = m.id AND e.event_type = 'submitted_to_postfix'))
             ORDER BY u.id LIMIT 101
            SQL, $p) as $row) {
            $findings[] = ['type' => 'message_usage_invalid'] + $row;
        }

        // Sending, message -> usage: accepted into Postfix in the period but not metered.
        $accepted = (int) $this->connection->fetchOne(self::acceptedSql('count(*)'), $p);
        foreach ($this->connection->fetchFirstColumn(self::acceptedSql('m.id::text').<<<'SQL'
               AND NOT EXISTS (SELECT 1 FROM usage_records u WHERE u.reference_id = m.id
                                  AND u.usage_type = 'message_submitted' AND u.reference_type = 'message')
             ORDER BY m.id LIMIT 101
            SQL, $p) as $messageId) {
            $findings[] = ['type' => 'message_without_usage', 'message_id' => $messageId];
        }

        return [
            'client_id' => $clientId,
            'period' => $period->asArray(),
            'status' => [] === $findings ? 'consistent' : 'inconsistent',
            'checked' => ['validation_jobs' => \count($jobs), 'message_usage_records' => $metered, 'messages_accepted' => $accepted],
            'findings_total' => \count($findings),
            'findings' => \array_slice($findings, 0, self::MAX_FINDINGS),
        ];
    }

    /** Messages of the client whose submitted_to_postfix event lies in the period. */
    private static function acceptedSql(string $select): string
    {
        return <<<SQL
            SELECT $select FROM send_jobs sj
              JOIN messages m ON m.send_job_id = sj.id
              JOIN message_events e ON e.message_id = m.id AND e.event_type = 'submitted_to_postfix'
                                   AND e.occurred_at >= :s AND e.occurred_at < :e
             WHERE sj.client_id = :c AND sj.created_at < :e
               AND sj.status <> 'collecting' AND (sj.dispatch_completed_at IS NULL OR sj.dispatch_completed_at >= :s)

            SQL;
    }
}
