<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Enum\AuditActorType;

/** Who performed an audited action (audit_log.actor_type / actor_id). */
final class AuditActor
{
    private function __construct(public readonly AuditActorType $type, public readonly ?string $id)
    {
    }

    public static function user(User $user): self
    {
        return new self(AuditActorType::User, $user->getId()->toRfc4122());
    }

    public static function apiKey(ApiKey $key): self
    {
        return new self(AuditActorType::ApiKey, $key->getId()->toRfc4122());
    }

    /** Console commands and scheduled jobs; $id names the command. */
    public static function system(string $id): self
    {
        return new self(AuditActorType::System, $id);
    }
}
