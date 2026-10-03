<?php

declare(strict_types=1);

namespace App\Audit;

use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Application audit log for security-sensitive changes (spec: application-level
 * audit logging). Writes through the current connection so the record commits or
 * rolls back with the change it describes. It is not an application log: callers
 * pass identifiers and non-secret facts only - never raw keys or secrets.
 */
final class AuditLogger
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<string, mixed> $detail */
    public function record(AuditActor $actor, string $action, string $targetType, ?string $targetId, array $detail = []): Uuid
    {
        $id = Uuid::v7();
        $this->connection->insert('audit_log', [
            'id' => $id->toRfc4122(),
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'detail_json' => json_encode((object) $detail, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'occurred_at' => Clock::now()->format('Y-m-d H:i:s.uP'),
        ]);

        return $id;
    }
}
