<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `webhook_event_type` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum WebhookEventType: string
{
    case ValidationCompleted = 'validation.completed';
    case ValidationFailed = 'validation.failed';
    case SendCompleted = 'send.completed';
    case SendFailed = 'send.failed';
    case MessageHardBounced = 'message.hard_bounced';
    case MessageComplained = 'message.complained';
    case WebhookTest = 'webhook.test';
    case RepermissionResponded = 'repermission.responded';
}
