<?php

declare(strict_types=1);

namespace App\Enum;

/** client_quota_usage.metric: admitted work counted against client_limits (specification 2.10). Values: docs/contracts/status-vocabulary.yaml. */
enum QuotaMetric: string
{
    case ValidationJobs = 'validation_jobs';
    case ValidationAddresses = 'validation_addresses';
    case SendJobs = 'send_jobs';
    case SendRecipients = 'send_recipients';
}
