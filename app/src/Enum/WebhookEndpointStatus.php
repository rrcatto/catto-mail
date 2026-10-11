<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `webhook_endpoint_status` from docs/contracts/status-vocabulary.yaml (kept in sync by
 * tests/Schema/VocabularyConstraintTest.php, which also compares the database CHECK constraints).
 * `removed` is terminal: the endpoint of an ended application connection (specification 2.12).
 */
enum WebhookEndpointStatus: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Removed = 'removed';
}
