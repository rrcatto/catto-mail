<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `failure_scope` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum FailureScope: string
{
    case Recipient = 'recipient';
    case Domain = 'domain';
    case ProviderPolicy = 'provider_policy';
    case Connection = 'connection';
    case Dns = 'dns';
    case Infrastructure = 'infrastructure';
    case Unknown = 'unknown';
}
