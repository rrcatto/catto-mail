<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `syntax_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum SyntaxStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
}
