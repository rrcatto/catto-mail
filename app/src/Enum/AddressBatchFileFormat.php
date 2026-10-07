<?php

declare(strict_types=1);

namespace App\Enum;

/** address_batches.file_format (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum AddressBatchFileFormat: string
{
    case Txt = 'txt';
    case Csv = 'csv';
}
