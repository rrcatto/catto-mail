<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `usage_type` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum UsageType: string
{
    case ValidationAddress = 'validation_address';
    case MessageSubmitted = 'message_submitted';
}
