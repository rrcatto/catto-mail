<?php

declare(strict_types=1);

namespace App\Idempotency;

use Doctrine\DBAL\Connection;

/**
 * In-flight detection for Idempotency-Key requests (api.idempotency: "same key
 * while the first request is in flight is rejected (409)").
 *
 * A transaction-scoped PostgreSQL advisory lock on hash(scope); the scope names
 * the endpoint, the client (or send job) and the key. The holder keeps it until
 * its transaction commits or rolls back; a concurrent request with the same scope
 * fails to acquire it and answers 409 instead of waiting. After the first request
 * commits, a retry acquires the lock and finds the stored resource (replay). The
 * unique indexes on the idempotency keys remain the final guarantee against
 * duplicates; a hash collision can only cause a spurious 409.
 */
final class IdempotencyLock
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function tryAcquire(string $scope): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Idempotency locks are transaction-scoped.');
        }

        return (bool) $this->connection->fetchOne('SELECT pg_try_advisory_xact_lock(hashtextextended(:scope, 0))', ['scope' => $scope]);
    }
}
