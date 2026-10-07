<?php

declare(strict_types=1);

namespace App\Enum;

/** billing_statements.status (specification 2.10). Values: docs/contracts/status-vocabulary.yaml. */
enum BillingStatementStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Exported = 'exported';
    case Void = 'void';
}
