<?php

declare(strict_types=1);

namespace App\Enum;

/** billing_statements.reconciliation_status (specification 2.10). Values: docs/contracts/status-vocabulary.yaml. */
enum BillingReconciliationStatus: string
{
    case Consistent = 'consistent';
    case Inconsistent = 'inconsistent';
}
