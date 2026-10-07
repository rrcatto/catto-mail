<?php

declare(strict_types=1);

namespace App\Enum;

/** client_quota_usage.period: UTC calendar day or month (specification 2.10). Values: docs/contracts/status-vocabulary.yaml. */
enum QuotaPeriod: string
{
    case Day = 'day';
    case Month = 'month';
}
