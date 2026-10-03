<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `global_role` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum GlobalRole: string
{
    case Operator = 'operator';
}
