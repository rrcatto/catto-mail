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
    case RecipientGlobalOptOut = 'recipient_global_opt_out';

    /** Reasons only an operator may create (console); D-30 system reasons are Go's, the opt-out a trusted client's. */
    public function isOperatorReason(): bool
    {
        return self::OperatorBlock === $this || self::ClientAbuseBlock === $this;
    }
}
