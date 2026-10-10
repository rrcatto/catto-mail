<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dashboard redesign (owner decision 2026-10-10): the submission-rate chart counts the
 * messages submitted to Postfix per minute over the chosen period. A partial index of the
 * submitted_to_postfix events by time keeps that an index-only scan instead of a scan of
 * every message event.
 *
 * Reproduces docs/schema/reference-schema.sql.
 */
final class Version20261011000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dashboard: index of submitted_to_postfix events by time (submission-rate chart)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX message_events_submitted_idx ON message_events (occurred_at) WHERE event_type = 'submitted_to_postfix'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX message_events_submitted_idx');
    }
}
