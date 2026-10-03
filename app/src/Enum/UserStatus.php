<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `user_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
