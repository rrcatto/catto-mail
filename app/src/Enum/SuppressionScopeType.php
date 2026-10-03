<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `suppression_scope_type` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum SuppressionScopeType: string
{
    case Address = 'address';
    case Domain = 'domain';
}
