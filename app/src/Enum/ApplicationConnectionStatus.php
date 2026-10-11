<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `application_connection_status` from docs/contracts/status-vocabulary.yaml (kept in sync by
 * tests/Schema/VocabularyConstraintTest.php, which also compares the database CHECK constraints).
 */
enum ApplicationConnectionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';
}
