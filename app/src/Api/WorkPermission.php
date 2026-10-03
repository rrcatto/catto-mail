<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Client;

/**
 * D-31 at the API: work-creating operations answer 403 for clients that are not
 * active or throttled (POST /v1/validation-jobs, /v1/send-jobs,
 * /v1/send-jobs/{id}/recipients, /v1/send-jobs/{id}/submit). Checked before any
 * idempotency handling, so no side effect and no replay happens for them.
 */
final class WorkPermission
{
    public static function assertMayCreateWork(Client $client): void
    {
        if (!$client->mayCreateWork()) {
            throw ApiProblem::forbidden(\sprintf('A client in status "%s" may not create or add work.', $client->getStatus()->value));
        }
    }
}
