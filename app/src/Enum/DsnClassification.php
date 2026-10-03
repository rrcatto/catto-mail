<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `dsn_classification` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum DsnClassification: string
{
    case HardBounce = 'hard_bounce';
    case SoftBounce = 'soft_bounce';
    case Deferred = 'deferred';
    case ConnectionFailure = 'connection_failure';
    case Complaint = 'complaint';
    case MalformedOrUnmatchedDsn = 'malformed_or_unmatched_dsn';
}
