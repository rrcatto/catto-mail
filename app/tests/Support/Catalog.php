<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Structural description of the public schema from the PostgreSQL catalog, in a
 * form that can be compared between two databases: tables, columns (type,
 * nullability, default), constraints (by name, pg_get_constraintdef incl. FK
 * actions) and indexes (by name, pg_get_indexdef). Doctrine's own bookkeeping
 * table is excluded.
 */
final class Catalog
{
    /** @return array{tables: list<string>, columns: array<string, string>, constraints: array<string, string>, indexes: array<string, string>} */
    public static function describe(Connection $c): array
    {
        $exclude = "('doctrine_migration_versions')";
        $tables = $c->fetchFirstColumn("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname NOT IN $exclude ORDER BY 1");
        $columns = [];
        foreach ($c->fetchAllAssociative("SELECT c.relname AS t, a.attname AS col, format_type(a.atttypid, a.atttypmod) AS type,
                a.attnotnull AS notnull, pg_get_expr(d.adbin, d.adrelid) AS def, a.attnum
            FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            WHERE n.nspname = 'public' AND c.relkind = 'r' AND a.attnum > 0 AND NOT a.attisdropped AND c.relname NOT IN $exclude
            ORDER BY c.relname, a.attnum") as $r) {
            $columns["{$r['t']}.{$r['col']}"] = \sprintf('#%d %s %s default=%s', $r['attnum'], $r['type'], $r['notnull'] ? 'NOT NULL' : 'NULL', $r['def'] ?? '-');
        }
        $constraints = [];
        foreach ($c->fetchAllAssociative("SELECT cl.relname AS t, co.conname AS name, pg_get_constraintdef(co.oid, true) AS def
            FROM pg_constraint co JOIN pg_class cl ON cl.oid = co.conrelid JOIN pg_namespace n ON n.oid = cl.relnamespace
            WHERE n.nspname = 'public' AND cl.relname NOT IN $exclude ORDER BY 1, 2") as $r) {
            $constraints["{$r['t']}.{$r['name']}"] = $r['def'];
        }
        $indexes = [];
        foreach ($c->fetchAllAssociative("SELECT tablename AS t, indexname AS name, indexdef AS def FROM pg_indexes
            WHERE schemaname = 'public' AND tablename NOT IN $exclude ORDER BY 1, 2") as $r) {
            $indexes["{$r['t']}.{$r['name']}"] = $r['def'];
        }

        return ['tables' => $tables, 'columns' => $columns, 'constraints' => $constraints, 'indexes' => $indexes];
    }

    /** @return list<string> human-readable differences (empty when equivalent) */
    public static function diff(array $expected, array $actual): array
    {
        $out = [];
        foreach (['tables', 'columns', 'constraints', 'indexes'] as $part) {
            $e = 'tables' === $part ? array_fill_keys($expected[$part], true) : $expected[$part];
            $a = 'tables' === $part ? array_fill_keys($actual[$part], true) : $actual[$part];
            foreach (array_diff_key($e, $a) as $k => $v) {
                $out[] = "$part: missing $k".(true === $v ? '' : " ($v)");
            }
            foreach (array_diff_key($a, $e) as $k => $v) {
                $out[] = "$part: unexpected $k".(true === $v ? '' : " ($v)");
            }
            foreach (array_intersect_key($e, $a) as $k => $v) {
                if ($v !== $a[$k]) {
                    $out[] = "$part: $k differs: expected [$v] actual [{$a[$k]}]";
                }
            }
        }

        return $out;
    }
}
