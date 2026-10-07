<?php

declare(strict_types=1);

namespace App\Usage;

use App\Enum\UsageType;
use Doctrine\DBAL\Connection;

/**
 * Period usage summaries from usage_records (Phase 9). Units are kept separate (one
 * figure per usage type, never one combined number). The sums run over
 * usage_records_client_occurred_idx (client_id, occurred_at); the representative-scale
 * plan review is in tests/Integration/Phase9QueryPlanTest.
 */
final class UsageReporting
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, array{quantity: int, records: int}> usage type => totals, every type present */
    public function clientTotals(string $clientId, UsagePeriod $period): array
    {
        $rows = $this->connection->fetchAllAssociativeIndexed(<<<'SQL'
            SELECT usage_type, sum(quantity) AS quantity, count(*) AS records FROM usage_records
             WHERE client_id = ? AND occurred_at >= ? AND occurred_at < ? GROUP BY usage_type
            SQL, [$clientId, $period->startSql(), $period->endSql()]);

        return self::complete($rows);
    }

    /**
     * Per-client totals of a period for the operator (keyset over the client name).
     *
     * @return list<array{client_id: string, company_name: string, status: string, validation_address: int, message_submitted: int}>
     */
    public function allClients(UsagePeriod $period, int $limit = 200, ?string $afterName = null): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.id::text AS client_id, c.company_name, c.status,
                   COALESCE(u.validation_address, 0) AS validation_address, COALESCE(u.message_submitted, 0) AS message_submitted
              FROM clients c
              LEFT JOIN LATERAL (
                SELECT sum(quantity) FILTER (WHERE usage_type = 'validation_address') AS validation_address,
                       sum(quantity) FILTER (WHERE usage_type = 'message_submitted') AS message_submitted
                  FROM usage_records WHERE client_id = c.id AND occurred_at >= ? AND occurred_at < ?
              ) u ON true
             WHERE (?::text IS NULL OR c.company_name > ?)
             ORDER BY c.company_name, c.id LIMIT ?
            SQL, [$period->startSql(), $period->endSql(), $afterName, $afterName, $limit]);

        return array_map(static fn (array $r): array => ['validation_address' => (int) $r['validation_address'],
            'message_submitted' => (int) $r['message_submitted']] + $r, $rows);
    }

    /** @return list<array{day: string, validation_address: int, message_submitted: int}> per UTC day of the period */
    public function daily(string $clientId, UsagePeriod $period): array
    {
        return array_map(static fn (array $r): array => ['day' => $r['day'], 'validation_address' => (int) $r['validation_address'],
            'message_submitted' => (int) $r['message_submitted']], $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT to_char(date_trunc('day', occurred_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS day,
                   COALESCE(sum(quantity) FILTER (WHERE usage_type = 'validation_address'), 0) AS validation_address,
                   COALESCE(sum(quantity) FILTER (WHERE usage_type = 'message_submitted'), 0) AS message_submitted
              FROM usage_records WHERE client_id = ? AND occurred_at >= ? AND occurred_at < ?
             GROUP BY 1 ORDER BY 1
            SQL, [$clientId, $period->startSql(), $period->endSql()]));
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     *
     * @return array<string, array{quantity: int, records: int}>
     */
    private static function complete(array $rows): array
    {
        $out = [];
        foreach (UsageType::cases() as $type) {
            $out[$type->value] = ['quantity' => (int) ($rows[$type->value]['quantity'] ?? 0), 'records' => (int) ($rows[$type->value]['records'] ?? 0)];
        }

        return $out;
    }
}
