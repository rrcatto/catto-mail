<?php

declare(strict_types=1);

namespace App\Enum;

/** address_batch_entries.typo_decision (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum TypoDecision: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
