<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `validation_processing_state` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum ValidationProcessingState: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case RetryScheduled = 'retry_scheduled';
    case Done = 'done';
}
