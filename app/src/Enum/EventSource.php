<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `event_source` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum EventSource: string
{
    case DeliveryDaemon = 'delivery_daemon';
    case PostfixSubmission = 'postfix_submission';
    case PostfixLog = 'postfix_log';
    case DsnSpool = 'dsn_spool';
    case UnmatchedDsnResolution = 'unmatched_dsn_resolution';
    case QueueReconciliation = 'queue_reconciliation';
    case TrackingEndpoint = 'tracking_endpoint';
}
