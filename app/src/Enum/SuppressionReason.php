<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `suppression_reason` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum SuppressionReason: string
{
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case RepeatedSoftBounce = 'repeated_soft_bounce';
    case OperatorBlock = 'operator_block';
    case ClientAbuseBlock = 'client_abuse_block';
}
