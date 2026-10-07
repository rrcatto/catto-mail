<?php

declare(strict_types=1);

namespace App\Enum;

/** clients.origin (specification 2.10). Values: docs/contracts/status-vocabulary.yaml. */
enum ClientOrigin: string
{
    case Operator = 'operator';
    case PublicApplication = 'public_application';
}
