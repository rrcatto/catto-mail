<?php

declare(strict_types=1);

namespace App\Enum;

/** client_alerts.metric (specification 2.10). Values: docs/contracts/status-vocabulary.yaml. */
enum ClientAlertMetric: string
{
    case HardBounceRate = 'hard_bounce_rate';
    case ComplaintRate = 'complaint_rate';
    case DeferralRate = 'deferral_rate';
    case VolumeIncrease = 'volume_increase';
}
