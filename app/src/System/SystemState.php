<?php

declare(strict_types=1);

namespace App\System;

use App\Util\Clock;
use Doctrine\DBAL\Connection;

/**
 * The host agent's reports (system_state, specification 2.11): host facts, container
 * states, backups, the boot-recovery record and the agent's own heartbeat. Each key holds
 * the newest report only; the history lives in system_check_runs.
 */
final class SystemState
{
    public const HOST = 'host_report';
    public const BACKUP = 'backup_status';
    public const BOOT = 'boot_report';
    public const AGENT = 'agent_heartbeat';
    public const SEED_TESTS = 'seed_tests';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<mixed> $value */
    public function put(string $key, array $value): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
            throw new \InvalidArgumentException("Bad state key $key.");
        }
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO system_state (state_key, value_json, updated_at) VALUES (?, ?, ?)
            ON CONFLICT (state_key) DO UPDATE SET value_json = EXCLUDED.value_json, updated_at = EXCLUDED.updated_at
            SQL, [$key, json_encode(Redactor::data($value), \JSON_THROW_ON_ERROR), Clock::now()->format('Y-m-d H:i:s.uP')]);
    }

    /** @return array{value: array<mixed>, updated_at: string}|null */
    public function get(string $key): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT value_json, updated_at FROM system_state WHERE state_key = ?', [$key]);

        return false === $row ? null : ['value' => json_decode((string) $row['value_json'], true) ?: [], 'updated_at' => (string) $row['updated_at']];
    }

    /** Seconds since the host agent last reported, or null if it never did. */
    public function agentAge(): ?int
    {
        $age = $this->connection->fetchOne("SELECT extract(epoch FROM now() - updated_at)::bigint FROM system_state WHERE state_key = 'agent_heartbeat'");

        return false === $age || null === $age ? null : (int) $age;
    }
}
