<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `client_membership_role` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum ClientMembershipRole: string
{
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';
}
