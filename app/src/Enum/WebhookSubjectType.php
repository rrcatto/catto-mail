<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `webhook_subject_type` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum WebhookSubjectType: string
{
    case ValidationJob = 'validation_job';
    case SendJob = 'send_job';
    case Message = 'message';
    case WebhookEndpoint = 'webhook_endpoint';
}
