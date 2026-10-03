<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `webhook_endpoint_status` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum WebhookEndpointStatus: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
}
