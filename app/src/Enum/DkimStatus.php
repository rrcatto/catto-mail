<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `dkim_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum DkimStatus: string
{
    case NotConfigured = 'not_configured';
    case PendingDns = 'pending_dns';
    case Active = 'active';
    case Disabled = 'disabled';
}
