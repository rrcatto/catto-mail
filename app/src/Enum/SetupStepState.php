<?php

declare(strict_types=1);

namespace App\Enum;

/** setup_steps.state (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum SetupStepState: string
{
    case Done = 'done';
    case Skipped = 'skipped';
}
