<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `domain_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum DomainStatus: string
{
    case Mx = 'mx';
    case NullMx = 'null_mx';
    case AddressFallback = 'address_fallback';
    case NoMailHost = 'no_mail_host';
    case Nxdomain = 'nxdomain';
    case TemporaryFailure = 'temporary_failure';
    case InvalidResponse = 'invalid_response';
    case Skipped = 'skipped';
}
