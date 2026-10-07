<?php

declare(strict_types=1);

namespace App\Enum;

/** address_batch_entries.review_decision (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum BatchReviewDecision: string
{
    case Include = 'include';
    case Exclude = 'exclude';
}
