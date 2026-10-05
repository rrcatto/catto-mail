<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `global_suppression_request_operation` from docs/contracts/status-vocabulary.yaml
 * (kept in sync by tests/Schema/VocabularyConstraintTest.php).
 */
enum GlobalSuppressionRequestOperation: string
{
    case CreateGlobalOptOut = 'create_global_opt_out';
}
