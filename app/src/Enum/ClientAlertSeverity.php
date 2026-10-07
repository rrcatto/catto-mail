<?php

declare(strict_types=1);

namespace App\Enum;

/** client_alerts.severity (specification 2.10). Values: docs/contracts/status-vocabulary.yaml. */
enum ClientAlertSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';
}
