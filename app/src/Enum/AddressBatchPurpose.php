<?php

declare(strict_types=1);

namespace App\Enum;

/** address_batches.purpose (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum AddressBatchPurpose: string
{
    case Validation = 'validation';
    case Repermission = 'repermission';
}
