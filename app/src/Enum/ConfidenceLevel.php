<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `confidence_level` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum ConfidenceLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
