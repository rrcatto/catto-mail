<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `send_job_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum SendJobStatus: string
{
    case Collecting = 'collecting';
    case Queued = 'queued';
    case Processing = 'processing';
    case Dispatched = 'dispatched';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
