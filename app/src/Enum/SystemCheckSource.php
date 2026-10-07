<?php

declare(strict_types=1);

namespace App\Enum;

/** system_checks.source and system_check_runs.source (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum SystemCheckSource: string
{
    case Agent = 'agent';
    case Application = 'application';
}
