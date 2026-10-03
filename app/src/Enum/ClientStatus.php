<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `client_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum ClientStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Throttled = 'throttled';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
