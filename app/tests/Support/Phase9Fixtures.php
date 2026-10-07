<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tests\Schema\SchemaFixtures;
use Doctrine\DBAL\Connection;

/**
 * Phase 9 rows written as the validator and the delivery daemon write them: metered
 * validation jobs and send jobs (usage_records in the same shape as D-33 and D-14), and
 * reputation-relevant transport events at chosen times.
 */
final class Phase9Fixtures
{
    /** A completed validation job of $n done addresses metered at $at (one usage row). */
    public static function meteredValidationJob(Connection $o, string $clientId, int $n, string $at): string
    {
        $job = DashboardFixtures::validationJob($o, $clientId, $n);
        $o->insert('usage_records', ['id' => SchemaFixtures::id(), 'client_id' => $clientId, 'usage_type' => 'validation_address',
            'quantity' => $n, 'reference_type' => 'validation_job', 'reference_id' => $job, 'occurred_at' => $at]);

        return $job;
    }

    /**
     * A dispatched send job of $n messages accepted by Postfix at $at: transport events
     * (submitted_to_postfix at $at + 2 s) and one message_submitted unit per message at
     * the event time, as Go's acceptance transaction writes them.
     *
     * @return array{job: string, messages: list<string>}
     */
    public static function meteredSendJob(Connection $o, string $clientId, string $domainId, int $n, string $at): array
    {
        $r = DashboardFixtures::sendJob($o, $clientId, $domainId, $n, ['created_at' => $at]);
        DashboardFixtures::transportEvents($o, $r['job']);
        $o->executeStatement(<<<'SQL'
            INSERT INTO usage_records (id, client_id, usage_type, quantity, reference_type, reference_id, occurred_at)
            SELECT gen_random_uuid(), ?, 'message_submitted', 1, 'message', e.message_id, e.occurred_at
              FROM message_events e JOIN messages m ON m.id = e.message_id
             WHERE m.send_job_id = ? AND e.event_type = 'submitted_to_postfix'
            SQL, [$clientId, $r['job']]);

        return $r;
    }

    /** One transport event per message (e.g. hard_bounce, complaint, deferred) at $at. */
    public static function events(Connection $o, array $messageIds, string $type, string $at, ?string $failureScope = null): void
    {
        $source = 'complaint' === $type ? 'dsn_spool' : 'postfix_log';
        foreach ($messageIds as $id) {
            $o->insert('message_events', ['id' => SchemaFixtures::id(), 'message_id' => $id, 'event_type' => $type, 'event_source' => $source,
                'source_event_key' => $type.':'.$id.':'.bin2hex(random_bytes(3)), 'failure_scope' => $failureScope, 'occurred_at' => $at]);
        }
    }
}
