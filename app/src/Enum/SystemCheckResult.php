<?php

declare(strict_types=1);

namespace App\Enum;

/** system_checks.result and system_check_runs.result (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum SystemCheckResult: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skipped = 'skipped';
    case Info = 'info';
}
