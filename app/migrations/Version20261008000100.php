<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 8 (specification 2.9): delivery_heartbeats, one row per Go delivery
 * daemon. It is the durable production signal the operator dashboard reads
 * instead of running Postfix commands while rendering a page:
 *
 *  - the delivery state: live delivery, send work held (production before live
 *    activation) and the operator's emergency pause;
 *  - the installation-wide warm-up ceiling in force;
 *  - the Postfix queue depth (active, deferred, hold, incoming) of the newest
 *    queue snapshot and that snapshot's time (freshness);
 *  - counters (submitted, temporary failures, DSNs processed).
 *
 * Written only by the delivery role; the web role reads it. Reproduces
 * docs/schema/reference-schema.sql. Grants: infra/postgres/grants.sql.
 */
final class Version20261008000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8 delivery heartbeats: delivery state, warm-up ceiling and Postfix queue depth';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE delivery_heartbeats (
    worker_id               text        PRIMARY KEY,
    version                 text        NOT NULL,
    started_at              timestamptz NOT NULL,
    last_seen_at            timestamptz NOT NULL,
    stopped_at              timestamptz NULL,
    live_delivery           boolean     NOT NULL,
    send_work_held          boolean     NOT NULL,
    outbound_paused         boolean     NOT NULL,
    global_rate_per_minute  integer     NOT NULL CHECK (global_rate_per_minute >= 0),
    queue_snapshot_at       timestamptz NULL,
    queue_active            integer     NULL CHECK (queue_active >= 0),
    queue_deferred          integer     NULL CHECK (queue_deferred >= 0),
    queue_hold              integer     NULL CHECK (queue_hold >= 0),
    queue_incoming          integer     NULL CHECK (queue_incoming >= 0),
    submitted               bigint      NOT NULL DEFAULT 0 CHECK (submitted >= 0),
    temporary_failures      bigint      NOT NULL DEFAULT 0 CHECK (temporary_failures >= 0),
    dsns_processed          bigint      NOT NULL DEFAULT 0 CHECK (dsns_processed >= 0),
    CONSTRAINT delivery_heartbeats_queue_complete CHECK (
        (queue_snapshot_at IS NULL) = (queue_active IS NULL)
        AND (queue_active IS NULL) = (queue_deferred IS NULL)
        AND (queue_deferred IS NULL) = (queue_hold IS NULL)
        AND (queue_hold IS NULL) = (queue_incoming IS NULL))
)
SQL);
        $this->addSql('CREATE INDEX delivery_heartbeats_seen_idx ON delivery_heartbeats (last_seen_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE delivery_heartbeats');
    }
}
