<?php

declare(strict_types=1);

namespace App\Enum;

/** address_batch_entries.outcome (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum AddressBatchEntryOutcome: string
{
    case Imported = 'imported';
    case Duplicate = 'duplicate';
    case Malformed = 'malformed';
}
