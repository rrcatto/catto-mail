<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tests\Schema\SchemaFixtures;
use Doctrine\DBAL\Connection;

/**
 * Bulk rows for the Phase 6 tracking and dashboard tests, written with the owner
 * connection as the Python validator and the Go delivery daemon would have
 * written them. Large sets are generated inside PostgreSQL (generate_series), so
 * a 10,000-row job costs one statement, not 10,000 round trips.
 */
final class DashboardFixtures
{
    /**
     * A sealed send job (dispatched) with $n messages handed to Postfix.
     *
     * @param array{track_opens?: bool, track_clicks?: bool, status?: string, created_at?: string, external_reference?: string} $options
     *
     * @return array{job: string, messages: list<string>}
     */
    public static function sendJob(Connection $o, string $clientId, string $domainId, int $n, array $options = []): array
    {
        $created = $options['created_at'] ?? date('Y-m-d H:i:s');
        $job = SchemaFixtures::sendJob($o, $clientId, $domainId, [
            'external_reference' => $options['external_reference'] ?? 'job-'.bin2hex(random_bytes(3)),
            'status' => $options['status'] ?? 'dispatched', 'queued_at' => $created, 'dispatch_completed_at' => $created,
            'created_at' => $created, 'total_recipients' => $n,
            'track_opens' => ($options['track_opens'] ?? false) ? 'true' : 'false',
            'track_clicks' => ($options['track_clicks'] ?? false) ? 'true' : 'false',
        ]);
        // Recipient batches hold at most 500 recipients (the API limit), as when submitted.
        $batches = [];
        for ($done = 0; $done < $n; $done += 500) {
            $batches[] = SchemaFixtures::batch($o, $job, min(500, $n - $done));
        }
        $tag = bin2hex(random_bytes(4));
        $o->executeStatement(<<<'SQL'
            INSERT INTO send_job_recipients (id, send_job_id, batch_id, external_recipient_reference, email_address, normalized_address,
                                             content_bytes, content_sha256, content_purged_at, created_at)
            SELECT gen_random_uuid(), ?, (string_to_array(?, ',')::uuid[])[(i - 1) / 500 + 1], 'ref-' || i,
                   'user' || i || '.' || ? || '@example.test', 'user' || i || '.' || ? || '@example.test',
                   100, repeat('0', 64), CAST(? AS timestamptz), CAST(? AS timestamptz)
              FROM generate_series(1, ?) AS i
            SQL, [$job, implode(',', $batches), $tag, $tag, $created, $created, $n]);
        $tracked = ($options['track_opens'] ?? false) || ($options['track_clicks'] ?? false);
        $o->executeStatement(<<<'SQL'
            INSERT INTO messages (id, send_job_id, send_job_recipient_id, external_recipient_reference, recipient_address, verp_token,
                                  return_path, tracking_token, postfix_queue_id, current_status, created_at)
            SELECT gen_random_uuid(), r.send_job_id, r.id, r.external_recipient_reference, r.normalized_address, 'v' || md5(r.id::text),
                   'bounce+v' || md5(r.id::text) || '@bounce.example.test',
                   CASE WHEN ? THEN translate(encode(decode(md5(random()::text) || md5(r.id::text), 'hex'), 'base64'), '+/=', '-_') END,
                   'Q' || upper(substr(md5(r.id::text), 1, 10)), 'remote_accepted', r.created_at
              FROM send_job_recipients r WHERE r.send_job_id = ?
            SQL, [$tracked ? 'true' : 'false', $job]);
        $counts = json_encode(['created' => 0, 'queued' => 0, 'submitted' => 0, 'deferred' => 0, 'outcome_unknown' => 0, 'remote_accepted' => $n,
            'soft_bounced' => 0, 'hard_bounced' => 0, 'complained' => 0, 'failed' => 0, 'suppressed' => 0], \JSON_THROW_ON_ERROR);
        $o->executeStatement('UPDATE send_jobs SET summary_counts_json = CAST(? AS jsonb) WHERE id = ?', [$counts, $job]);

        return ['job' => $job, 'messages' => $o->fetchFirstColumn('SELECT id FROM messages WHERE send_job_id = ? ORDER BY created_at, id', [$job])];
    }

    /** Transport history for every message of a job (created, queued, submitted, remote accepted: 4 events each). */
    public static function transportEvents(Connection $o, string $jobId): void
    {
        $o->executeStatement(<<<'SQL'
            INSERT INTO message_events (id, message_id, event_type, event_source, source_event_key, occurred_at)
            SELECT gen_random_uuid(), m.id, e.t, e.s, e.t || ':' || m.id, m.created_at + e.o * interval '1 second'
              FROM messages m
              CROSS JOIN (VALUES ('message_created', 'delivery_daemon', 0), ('message_queued', 'delivery_daemon', 1),
                                 ('submitted_to_postfix', 'postfix_submission', 2), ('remote_accepted', 'postfix_log', 3)) AS e(t, s, o)
             WHERE m.send_job_id = ?
            SQL, [$jobId]);
    }

    public static function trackingEvent(Connection $o, string $messageId, string $type, string $occurredAt, ?int $linkIndex = null): void
    {
        $o->insert('message_events', ['id' => SchemaFixtures::id(), 'message_id' => $messageId, 'event_type' => $type,
            'event_source' => 'tracking_endpoint', 'occurred_at' => $occurredAt,
            'metadata_json' => null === $linkIndex ? '{}' : json_encode(['link_index' => $linkIndex])]);
    }

    public static function link(Connection $o, string $messageId, int $index, string $target): void
    {
        $o->insert('message_links', ['id' => SchemaFixtures::id(), 'message_id' => $messageId, 'link_index' => $index, 'target_url' => $target]);
    }

    /** A completed validation job with $n checked addresses in a fixed mix of results. */
    public static function validationJob(Connection $o, string $clientId, int $n, ?string $externalReference = null): string
    {
        $job = SchemaFixtures::id();
        $o->insert('validation_jobs', ['id' => $job, 'client_id' => $clientId, 'external_reference' => $externalReference,
            'idempotency_key' => SchemaFixtures::id(), 'request_hash' => 'h', 'status' => 'completed', 'started_at' => date('Y-m-d H:i:s'),
            'completed_at' => date('Y-m-d H:i:s'), 'total_addresses' => $n, 'processed_count' => $n]);
        $o->executeStatement(<<<'SQL'
            INSERT INTO validation_addresses (id, job_id, original_address, normalized_address, syntax_status, domain_status, smtp_status,
                is_role, is_disposable, is_catch_all_or_accept_all, is_domain_typo_suspected, suggested_address, suggestion_reason_code,
                suggestion_confidence, overall_classification, confidence, diagnostic_code, checked_at, processing_state)
            SELECT gen_random_uuid(), ?, 'person' || i || '@example.test', 'person' || i || '@example.test', 'valid', 'mx',
                   CASE WHEN i % 10 = 0 THEN 'rejected' ELSE 'accepted' END, i % 7 = 0, i % 11 = 0, false, i % 13 = 0,
                   CASE WHEN i % 13 = 0 THEN 'person' || i || '@example.com' END, CASE WHEN i % 13 = 0 THEN 'tld_typo' END,
                   CASE WHEN i % 13 = 0 THEN 'medium' END,
                   CASE WHEN i % 10 = 0 THEN 'undeliverable' WHEN i % 11 = 0 THEN 'risky' ELSE 'deliverable' END,
                   CASE WHEN i % 10 = 0 THEN 'high' ELSE 'medium' END, CASE WHEN i % 10 = 0 THEN 'smtp_550' END, now(), 'done'
              FROM generate_series(1, ?) AS i
            SQL, [$job, $n]);
        $o->executeStatement(<<<'SQL'
            UPDATE validation_jobs SET classification_counts_json = (
                SELECT jsonb_object_agg(overall_classification, n) FROM (
                    SELECT overall_classification, count(*) AS n FROM validation_addresses WHERE job_id = ? GROUP BY 1) t)
             WHERE id = ?
            SQL, [$job, $job]);

        return $job;
    }

    public static function usage(Connection $o, string $clientId, string $type, int $quantity, string $referenceType, string $referenceId): void
    {
        $o->insert('usage_records', ['id' => SchemaFixtures::id(), 'client_id' => $clientId, 'usage_type' => $type, 'quantity' => $quantity,
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'occurred_at' => date('Y-m-d H:i:s')]);
    }
}
