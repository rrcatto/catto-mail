<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 7: the Symfony webhook worker (specification 2.8).
 *
 *  - webhook_deliveries: updated_at, last_attempt_at and a bounded
 *    last_response_excerpt for operator diagnostics; claimed_by and
 *    lease_expires_at are set and cleared together (a claim is a lease; the
 *    attempt_count incremented by the claim is the fencing token of the attempt);
 *  - webhook_events: a `webhook.test` is always addressed to exactly one endpoint
 *    (subject_type `webhook_endpoint`), and only `webhook.test` has that subject;
 *  - webhook_worker_heartbeats: one row per worker process (liveness, counters)
 *    for the operator dashboard; the container health check uses a local file.
 *
 * Reproduces docs/schema/reference-schema.sql. Grants: infra/postgres/grants.sql.
 */
final class Version20261007000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 7 webhook worker: delivery diagnostics and lease consistency, single-endpoint webhook.test, worker heartbeats';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_deliveries ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now()');
        $this->addSql('ALTER TABLE webhook_deliveries ADD COLUMN last_attempt_at timestamptz NULL');
        $this->addSql('ALTER TABLE webhook_deliveries ADD COLUMN last_response_excerpt text NULL CHECK (length(last_response_excerpt) <= 1024)');
        $this->addSql('ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_lease_complete CHECK ((claimed_by IS NULL) = (lease_expires_at IS NULL))');
        $this->addSql("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_final_unclaimed CHECK (status = 'pending' OR claimed_by IS NULL)");

        $this->addSql(<<<'SQL'
ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_test_subject CHECK (
    (event_type = 'webhook.test') = (subject_type = 'webhook_endpoint'))
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE webhook_worker_heartbeats (
    worker_id             text        PRIMARY KEY,
    version               text        NOT NULL,
    started_at            timestamptz NOT NULL,
    last_seen_at          timestamptz NOT NULL,
    stopped_at            timestamptz NULL,
    attempts              bigint      NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    delivered             bigint      NOT NULL DEFAULT 0 CHECK (delivered >= 0),
    events_fanned_out     bigint      NOT NULL DEFAULT 0 CHECK (events_fanned_out >= 0)
)
SQL);
        $this->addSql('CREATE INDEX webhook_worker_heartbeats_seen_idx ON webhook_worker_heartbeats (last_seen_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_worker_heartbeats');
        $this->addSql('ALTER TABLE webhook_events DROP CONSTRAINT webhook_events_test_subject');
        $this->addSql('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_final_unclaimed');
        $this->addSql('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_lease_complete');
        $this->addSql('ALTER TABLE webhook_deliveries DROP COLUMN last_response_excerpt');
        $this->addSql('ALTER TABLE webhook_deliveries DROP COLUMN last_attempt_at');
        $this->addSql('ALTER TABLE webhook_deliveries DROP COLUMN updated_at');
    }
}
