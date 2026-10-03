<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `smtp_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum SmtpStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case TemporaryFailure = 'temporary_failure';
    case Blocked = 'blocked';
    case Timeout = 'timeout';
    case ConnectionFailed = 'connection_failed';
    case Inconclusive = 'inconclusive';
    case Skipped = 'skipped';
}
