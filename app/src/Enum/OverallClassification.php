<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `overall_classification` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum OverallClassification: string
{
    case Deliverable = 'deliverable';
    case ProbablyDeliverable = 'probably_deliverable';
    case Undeliverable = 'undeliverable';
    case TemporarilyUnverifiable = 'temporarily_unverifiable';
    case Unknown = 'unknown';
    case Risky = 'risky';
}
