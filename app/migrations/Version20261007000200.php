<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 7 query-plan review (WebhookQueryPlanTest, 180,000 deliveries): without
 * these, the per-endpoint delivery counts of the client Webhooks page, the
 * cross-client delivery and outbox lists of the operator Webhooks page and the
 * bounded delivery summary scanned and sorted whole tables (190-670 ms).
 *
 * Reproduces docs/schema/reference-schema.sql.
 */
final class Version20261007000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 7 webhook dashboard indexes (endpoint counts, cross-client delivery and outbox lists)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX webhook_deliveries_endpoint_status_idx ON webhook_deliveries (webhook_endpoint_id, status, created_at)');
        $this->addSql('CREATE INDEX webhook_deliveries_created_idx ON webhook_deliveries (created_at, id)');
        $this->addSql('CREATE INDEX webhook_events_created_idx ON webhook_events (created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX webhook_events_created_idx');
        $this->addSql('DROP INDEX webhook_deliveries_created_idx');
        $this->addSql('DROP INDEX webhook_deliveries_endpoint_status_idx');
    }
}
