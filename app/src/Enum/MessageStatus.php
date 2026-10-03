<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `message_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum MessageStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Submitted = 'submitted';
    case Deferred = 'deferred';
    case OutcomeUnknown = 'outcome_unknown';
    case RemoteAccepted = 'remote_accepted';
    case SoftBounced = 'soft_bounced';
    case HardBounced = 'hard_bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
}
