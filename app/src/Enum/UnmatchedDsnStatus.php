<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `unmatched_dsn_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum UnmatchedDsnStatus: string
{
    case Open = 'open';
    case MatchRequested = 'match_requested';
    case Matched = 'matched';
    case Dismissed = 'dismissed';
}
