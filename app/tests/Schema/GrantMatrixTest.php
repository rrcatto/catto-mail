<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use App\Tests\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * The privileges applied by infra/postgres/grants.sql equal the matrix in
 * docs/schema/schema.md §6 exactly (parsed from the document), for all four
 * runtime roles, including the column-restricted UPDATE on send_job_recipients.
 */
final class GrantMatrixTest extends TestCase
{
    private const ROLES = ['smarthost_app' => 'APP_DB_USER', 'smarthost_webhook' => 'APP_WEBHOOK_DB_USER',
        'smarthost_validator' => 'VALIDATOR_DB_USER', 'smarthost_delivery' => 'DELIVERY_DB_USER'];
    private const PRIVS = ['S' => 'SELECT', 'I' => 'INSERT', 'U' => 'UPDATE', 'D' => 'DELETE'];

    /** @return array<string, array<string, list<string>>> table => role => privilege letters (U² = column update) */
    private static function matrix(): array
    {
        $doc = file_get_contents(\dirname(__DIR__).'/contracts/schema.md');
        $section = substr($doc, strpos($doc, '## 6.'));
        $out = [];
        foreach (explode("\n", $section) as $line) {
            if (!preg_match('/^\| ([a-z_, ]+) \|(.+)\|$/', $line, $m) || str_contains($line, '---')) {
                continue;
            }
            $cells = array_map('trim', explode('|', $m[2]));
            if (4 !== \count($cells)) {
                continue;
            }
            foreach (array_map('trim', explode(',', $m[1])) as $table) {
                foreach (array_keys(self::ROLES) as $i => $role) {
                    $out[$table][$role] = '–' === $cells[$i] ? [] : preg_split('/\s+/', preg_replace('/[¹³⁴]/u', '', $cells[$i]));
                }
            }
        }

        return $out;
    }

    public function testTableAndColumnPrivilegesEqualTheContract(): void
    {
        $matrix = self::matrix();
        self::assertCount(30, $matrix, 'schema.md §6 should list all 30 tables');
        $c = Db::owner();
        $problems = [];
        foreach ($matrix as $table => $roles) {
            foreach ($roles as $role => $letters) {
                $name = Db::env(self::ROLES[$role]);
                foreach (self::PRIVS as $letter => $priv) {
                    $columnUpdate = 'U' === $letter && \in_array('U²', $letters, true);
                    $expected = \in_array($letter, $letters, true);
                    $actual = (bool) $c->fetchOne('SELECT has_table_privilege(:r, :t, :p)', ['r' => $name, 't' => $table, 'p' => $priv]);
                    if ($expected !== $actual) {
                        $problems[] = "$role $priv on $table: expected ".($expected ? 'yes' : 'no');
                    }
                    if ($columnUpdate) {
                        foreach (['subject', 'html_body', 'text_body', 'content_purged_at'] as $col) {
                            if (!$c->fetchOne('SELECT has_column_privilege(:r, :t, :c, \'UPDATE\')', ['r' => $name, 't' => $table, 'c' => $col])) {
                                $problems[] = "$role UPDATE($col) on $table missing";
                            }
                        }
                        foreach (['email_address', 'normalized_address', 'send_job_id', 'unsubscribe_url', 'content_sha256'] as $col) {
                            if ($c->fetchOne('SELECT has_column_privilege(:r, :t, :c, \'UPDATE\')', ['r' => $name, 't' => $table, 'c' => $col])) {
                                $problems[] = "$role must not UPDATE($col) on $table";
                            }
                        }
                    }
                }
                foreach (['TRUNCATE', 'REFERENCES', 'TRIGGER'] as $priv) {
                    if ($c->fetchOne('SELECT has_table_privilege(:r, :t, :p)', ['r' => $name, 't' => $table, 'p' => $priv])) {
                        $problems[] = "$role must not have $priv on $table";
                    }
                }
            }
        }
        self::assertSame([], $problems);
    }

    public function testRuntimeRolesCannotTouchSchemaOrMigrationBookkeeping(): void
    {
        $c = Db::owner();
        foreach (self::ROLES as $env) {
            $role = Db::env($env);
            self::assertFalse((bool) $c->fetchOne("SELECT has_schema_privilege(:r, 'public', 'CREATE')", ['r' => $role]));
            self::assertFalse((bool) $c->fetchOne("SELECT has_database_privilege(:r, current_database(), 'CREATE')", ['r' => $role]));
            self::assertFalse((bool) $c->fetchOne("SELECT has_table_privilege(:r, 'doctrine_migration_versions', 'SELECT')", ['r' => $role]));
            self::assertFalse((bool) $c->fetchOne('SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolbypassrls FROM pg_roles WHERE rolname = :r', ['r' => $role]));
        }
    }
}
