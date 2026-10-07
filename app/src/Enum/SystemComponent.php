<?php

declare(strict_types=1);

namespace App\Enum;

/** system_checks.component and system_check_runs.component (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum SystemComponent: string
{
    case Host = 'host';
    case Boot = 'boot';
    case Database = 'database';
    case Web = 'web';
    case Validator = 'validator';
    case Delivery = 'delivery';
    case Postfix = 'postfix';
    case Opendkim = 'opendkim';
    case Dns = 'dns';
    case Tls = 'tls';
    case Nginx = 'nginx';
    case Tracking = 'tracking';
    case Webhook = 'webhook';
    case Bounce = 'bounce';
    case Backup = 'backup';
    case Security = 'security';
}
