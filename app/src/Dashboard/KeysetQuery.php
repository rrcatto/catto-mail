<?php

declare(strict_types=1);

namespace App\Dashboard;

use Doctrine\DBAL\Connection;

/**
 * Runs a keyset-paginated SELECT. A list declares its sorts as
 * name => [SQL expression, PostgreSQL type]; the expression must never be NULL
 * (use COALESCE) and the id expression must be unique, so (sort, id) is a total
 * order. The query fetches limit + 1 rows to know whether a next page exists.
 */
final class KeysetQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, array{0: string, 1: string}> $sorts
     * @param list<string>                                $where  SQL conditions (no request text)
     * @param list<mixed>                                 $params positional parameters of $where
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function page(string $select, string $from, array $where, array $params, Listing $listing, array $sorts, string $idExpr): array
    {
        [$expr, $type] = $sorts[$listing->sort];
        $op = 'asc' === $listing->dir ? '>' : '<';
        // A cursor is opaque but client-supplied: values that are not of the sort's
        // type (tampered, or from another sort) start again at the first page.
        if (null !== $listing->after && self::castable($listing->after[0], $type) && self::castable($listing->after[1], 'uuid')) {
            $where[] = "($expr, $idExpr) $op (CAST(? AS $type), CAST(? AS uuid))";
            array_push($params, $listing->after[0], $listing->after[1]);
        }
        $dir = 'asc' === $listing->dir ? 'ASC' : 'DESC';
        $sql = "SELECT $select, ($expr)::text AS _sort_value, ($idExpr)::text AS _sort_id FROM $from"
            .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
            ." ORDER BY $expr $dir, $idExpr $dir LIMIT ".($listing->limit + 1);
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $next = null;
        if (\count($rows) > $listing->limit) {
            array_pop($rows);
            $last = end($rows);
            $next = Listing::encode([(string) $last['_sort_value'], (string) $last['_sort_id']]);
        }

        return ['rows' => $rows, 'next' => $next];
    }

    private static function castable(string $value, string $type): bool
    {
        return match ($type) {
            'uuid' => 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value),
            'integer' => 1 === preg_match('/^-?[0-9]{1,9}$/', $value),
            // PostgreSQL's own text output of timestamptz, e.g. 2026-10-05 12:34:56.123456+00
            'timestamptz' => 1 === preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2}){0,2}$/', $value),
            'text' => !str_contains($value, "\0"),
            default => false,
        };
    }
}
