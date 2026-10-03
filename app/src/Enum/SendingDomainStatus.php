<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `sending_domain_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum SendingDomainStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Disabled = 'disabled';
}
