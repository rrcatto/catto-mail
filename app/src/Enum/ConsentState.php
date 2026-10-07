<?php

declare(strict_types=1);

namespace App\Enum;

/** address_batch_entries.consent_state (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum ConsentState: string
{
    case Unknown = 'unknown';
    case Unconfirmed = 'unconfirmed';
    case Confirmed = 'confirmed';
    case Unsubscribed = 'unsubscribed';
    case GlobalOptOut = 'global_opt_out';
}
