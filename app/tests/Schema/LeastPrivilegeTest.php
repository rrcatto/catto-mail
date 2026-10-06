<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use App\Tests\Support\Db;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the runtime application role (the identity of PHP-FPM and the
 * console): DML only, inside the matrix; no DDL, no TRUNCATE, no rewriting of
 * staged recipients, no deleting history outside retention grants.
 */
final class LeastPrivilegeTest extends TestCase
{
    private Connection $app;

    protected function setUp(): void
    {
        $this->app = Db::app();
        $this->app->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->app->isTransactionActive()) {
            $this->app->rollBack();
        }
    }

    private function denied(string $sql, array $params = []): bool
    {
        $this->app->executeStatement('SAVEPOINT p');
        try {
            $this->app->executeStatement($sql, $params);
            $this->app->executeStatement('RELEASE SAVEPOINT p');

            return false;
        } catch (DriverException $e) {
            $this->app->executeStatement('ROLLBACK TO SAVEPOINT p');

            return '42501' === $e->getSQLState(); // insufficient_privilege
        }
    }

    public function testConnectsAsTheApplicationRole(): void
    {
        self::assertSame(Db::env('APP_DB_USER'), $this->app->fetchOne('SELECT current_user'));
    }

    public function testNoDdl(): void
    {
        self::assertTrue($this->denied('CREATE TABLE p2_probe (id int)'));
        self::assertTrue($this->denied('ALTER TABLE send_jobs ADD COLUMN x int'));
        self::assertTrue($this->denied('DROP TABLE audit_log'));
        self::assertTrue($this->denied('CREATE INDEX p2_idx ON clients (plan)'));
        self::assertTrue($this->denied('TRUNCATE send_job_recipients'));
    }

    public function testCannotRewriteStagedRecipientsOrDeleteJobs(): void
    {
        self::assertTrue($this->denied("UPDATE send_job_recipients SET email_address = 'x@y.example'"));
        self::assertTrue($this->denied("UPDATE send_job_recipients SET normalized_address = 'x@y.example'"));
        self::assertFalse($this->denied('UPDATE send_job_recipients SET subject = subject WHERE false'), 'content purge columns stay updatable');
        self::assertTrue($this->denied('DELETE FROM send_jobs'));
        self::assertTrue($this->denied('DELETE FROM clients'));
        self::assertFalse($this->denied('DELETE FROM client_memberships WHERE false'), 'memberships can be removed (specification 2.7)');
        self::assertTrue($this->denied('UPDATE user_roles SET assigned_at = assigned_at'), 'role grants are inserted and deleted, never rewritten');
        self::assertTrue($this->denied('UPDATE messages SET current_status = current_status'));
        self::assertTrue($this->denied('UPDATE message_events SET diagnostic = diagnostic'));
        self::assertTrue($this->denied('UPDATE validation_addresses SET overall_classification = overall_classification'));
        self::assertTrue($this->denied('INSERT INTO messages (id) VALUES (gen_random_uuid())'));
        // Phase 6: readable for the operator dashboard's ingest freshness, never writable.
        self::assertFalse($this->denied('SELECT source, updated_at FROM delivery_ingest_cursors'));
        self::assertTrue($this->denied("UPDATE delivery_ingest_cursors SET position = position"));
        self::assertTrue($this->denied("INSERT INTO delivery_ingest_cursors (source, generation_id, position) VALUES ('x', 'y', 0)"));
        self::assertTrue($this->denied('DELETE FROM delivery_ingest_cursors'));
        self::assertTrue($this->denied('UPDATE webhook_deliveries SET status = status'));
        self::assertTrue($this->denied('UPDATE audit_log SET action = action'));
    }
}
