<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `audit_actor_type` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum AuditActorType: string
{
    case User = 'user';
    case ApiKey = 'api_key';
    case System = 'system';
}
