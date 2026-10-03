<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `message_event_type` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum MessageEventType: string
{
    case MessageCreated = 'message_created';
    case MessageQueued = 'message_queued';
    case MessageSuppressed = 'message_suppressed';
    case SubmissionFailed = 'submission_failed';
    case SubmittedToPostfix = 'submitted_to_postfix';
    case PostfixQueued = 'postfix_queued';
    case DeliveryAttempt = 'delivery_attempt';
    case Deferred = 'deferred';
    case ConnectionFailure = 'connection_failure';
    case RemoteAccepted = 'remote_accepted';
    case SoftBounce = 'soft_bounce';
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case TransportOutcomeUnknown = 'transport_outcome_unknown';
    case OpenRecorded = 'open_recorded';
    case ClickRecorded = 'click_recorded';
    case DsnUnmatched = 'dsn_unmatched';
}
