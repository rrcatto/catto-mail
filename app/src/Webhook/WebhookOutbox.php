<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Enum\WebhookEventType;
use App\Enum\WebhookSubjectType;
use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Transactional-outbox producer (D-22). Call it inside the transaction that makes
 * the underlying state change, so the event exists if and only if the change
 * commits. Each (event_type, subject_id) is recorded once (webhook_events_once_uq);
 * `webhook.test` may repeat. Python and Go insert the same rows from their own
 * transactions; only the Symfony webhook worker (Phase 7) consumes them.
 */
final class WebhookOutbox
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return Uuid|null the new event id, or null when the event was already recorded */
    public function record(Uuid $clientId, WebhookEventType $type, WebhookSubjectType $subjectType, Uuid $subjectId): ?Uuid
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Outbox events must be written in the transaction of the state change.');
        }
        $id = Uuid::v7();
        $sql = 'INSERT INTO webhook_events (id, client_id, event_type, subject_type, subject_id, created_at)
                VALUES (:id, :client, :type, :subject_type, :subject, :at)';
        if (WebhookEventType::WebhookTest !== $type) {
            $sql .= " ON CONFLICT (event_type, subject_id) WHERE event_type <> 'webhook.test' DO NOTHING";
        }
        $inserted = $this->connection->executeStatement($sql, [
            'id' => $id->toRfc4122(), 'client' => $clientId->toRfc4122(), 'type' => $type->value,
            'subject_type' => $subjectType->value, 'subject' => $subjectId->toRfc4122(),
            'at' => Clock::now()->format('Y-m-d H:i:s.uP'),
        ]);

        return 1 === $inserted ? $id : null;
    }
}
