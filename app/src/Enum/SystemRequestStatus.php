<?php

declare(strict_types=1);

namespace App\Enum;

/** system_requests.status (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum SystemRequestStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
