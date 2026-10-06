<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 6: partial index for the recorded-open/click aggregates of the dashboards.
 *
 * Measured with the Phase 6 load test (tests/Integration/DashboardLargeDatasetTest.php):
 * without it, the per-job and per-client engagement figures scanned every row of
 * message_events (all clients' transport history) to find the few tracking
 * events of one job, so their cost grew with the whole installation. The index
 * holds only open_recorded / click_recorded rows, keyed like the tracking
 * endpoint's per-message interval check.
 *
 * Reproduces docs/schema/reference-schema.sql (specification 2.6). No table
 * change, so the grant matrix is unchanged.
 */
final class Version20261006000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 6 engagement index on message_events (recorded opens and clicks)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE INDEX message_events_engagement_idx ON message_events (message_id, occurred_at)
    WHERE event_type IN ('open_recorded', 'click_recorded')
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX message_events_engagement_idx');
    }
}
