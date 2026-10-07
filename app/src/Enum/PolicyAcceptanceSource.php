<?php

declare(strict_types=1);

namespace App\Enum;

/** client_policy_acceptances.source (specification 2.10). Values: docs/contracts/status-vocabulary.yaml. */
enum PolicyAcceptanceSource: string
{
    case ClientDashboard = 'client_dashboard';
    case OperatorRecorded = 'operator_recorded';
}
