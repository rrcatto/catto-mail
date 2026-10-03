<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `message_class` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum MessageClass: string
{
    case Transactional = 'transactional';
    case Subscription = 'subscription';
}
