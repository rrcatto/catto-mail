<?php

declare(strict_types=1);

namespace App\Enum;

/** system_check_runs.run_trigger (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum SystemCheckTrigger: string
{
    case Scheduled = 'scheduled';
    case Requested = 'requested';
    case Changed = 'changed';
}
