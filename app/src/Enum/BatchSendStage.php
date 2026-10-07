<?php

declare(strict_types=1);

namespace App\Enum;

/** address_batch_sends.stage (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum BatchSendStage: string
{
    case Seed = 'seed';
    case Controlled = 'controlled';
    case Rollout = 'rollout';
    case Full = 'full';
}
