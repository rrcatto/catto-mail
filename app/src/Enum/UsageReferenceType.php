<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `usage_reference_type` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum UsageReferenceType: string
{
    case ValidationJob = 'validation_job';
    case SendJob = 'send_job';
    case Message = 'message';
}
