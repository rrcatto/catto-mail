<?php

declare(strict_types=1);

namespace App\Enum;

/** clients.origin (specification 2.10). Values: docs/contracts/status-vocabulary.yaml. */
enum ClientOrigin: string
{
    case Operator = 'operator';
    case PublicApplication = 'public_application';
    /** Opened with an operator's invitation link (2026-10-27). */
    case Invitation = 'invitation';
    /** The installation's own self-test client (App\System\ApplicationDiagnostics). */
    case System = 'system';
}
