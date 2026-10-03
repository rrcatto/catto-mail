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
        self::assertTrue($this->denied('DELETE FROM client_memberships'));
        self::assertTrue($this->denied('UPDATE messages SET current_status = current_status'));
        self::assertTrue($this->denied('UPDATE message_events SET diagnostic = diagnostic'));
        self::assertTrue($this->denied('UPDATE validation_addresses SET overall_classification = overall_classification'));
        self::assertTrue($this->denied('INSERT INTO messages (id) VALUES (gen_random_uuid())'));
        self::assertTrue($this->denied('SELECT * FROM delivery_ingest_cursors'));
        self::assertTrue($this->denied('UPDATE webhook_deliveries SET status = status'));
        self::assertTrue($this->denied('UPDATE audit_log SET action = action'));
    }
}
