<?php

declare(strict_types=1);

namespace App\Enum;

/** system_requests.action (specification 2.11). Values: docs/contracts/status-vocabulary.yaml. */
enum SystemRequestAction: string
{
    case DiagnosticsRun = 'diagnostics.run';
    case DeliveryPause = 'delivery.pause';
    case DeliveryResume = 'delivery.resume';
    case DeliveryLiveEnable = 'delivery.live_enable';
    case DeliveryLiveDisable = 'delivery.live_disable';
    case BackupRun = 'backup.run';
    case BackupRestoreRehearsal = 'backup.restore_rehearsal';
    case DkimGenerate = 'dkim.generate';
    case DkimActivate = 'dkim.activate';
    case TlsRenew = 'tls.renew';
    case SettingsApply = 'settings.apply';
}
