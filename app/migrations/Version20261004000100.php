<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 5 / D-30: global suppressions with provenance, the recipient global opt-out
 * and its operator-granted client capability, the global soft-bounce evaluation
 * index and the unmatched-DSN dismissal reason.
 *
 * Reproduces the specification 2.4 changes of docs/schema/reference-schema.sql;
 * tests/Schema/ReferenceSchemaEquivalenceTest.php proves the resulting catalog is
 * structurally identical (new columns are appended, so column order matches).
 * No existing client receives the capability (DEFAULT false). No new table, so
 * the grant matrix (infra/postgres/grants.sql) is unchanged.
 */
final class Version20261004000100 extends AbstractMigration
{
    private const REASONS_2_3 = "'hard_bounce', 'complaint', 'repeated_soft_bounce', 'operator_block', 'client_abuse_block'";

    public function getDescription(): string
    {
        return 'D-30 global suppressions: provenance, recipient_global_opt_out, client capability, indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clients ADD COLUMN can_submit_global_suppressions boolean NOT NULL DEFAULT false');

        $this->addSql('CREATE INDEX messages_recipient_address_idx ON messages (recipient_address)');

        $this->addSql('ALTER TABLE unmatched_dsns DROP CONSTRAINT unmatched_dsns_dismissed_resolved');
        $this->addSql(<<<'SQL'
ALTER TABLE unmatched_dsns ADD CONSTRAINT unmatched_dsns_dismissed_resolved CHECK (
    status <> 'dismissed' OR (resolved_at IS NOT NULL AND resolution_note IS NOT NULL))
SQL);

        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_reason_check');
        $this->addSql('ALTER TABLE suppressions ADD CONSTRAINT suppressions_reason_check CHECK (reason IN ('
            .self::REASONS_2_3.", 'recipient_global_opt_out'))");
        $this->addSql('ALTER TABLE suppressions ADD COLUMN source_event_id uuid NULL REFERENCES message_events (id)');
        $this->addSql('ALTER TABLE suppressions ADD COLUMN source_client_id uuid NULL REFERENCES clients (id)');
        $this->addSql('ALTER TABLE suppressions ADD COLUMN external_reference text NULL');
        $this->addSql('ALTER TABLE suppressions ADD COLUMN idempotency_key text NULL');
        $this->addSql('ALTER TABLE suppressions ADD COLUMN request_hash text NULL');
        $this->addSql(<<<'SQL'
ALTER TABLE suppressions ADD CONSTRAINT suppressions_opt_out_provenance CHECK (
    (reason = 'recipient_global_opt_out') = (source_client_id IS NOT NULL)
    AND (source_client_id IS NULL) = (idempotency_key IS NULL)
    AND (idempotency_key IS NULL) = (request_hash IS NULL)
    AND (external_reference IS NULL OR source_client_id IS NOT NULL)
    AND (reason <> 'recipient_global_opt_out' OR (client_id IS NULL AND scope_type = 'address')))
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE suppressions ADD CONSTRAINT suppressions_indefinite_reasons CHECK (
    reason NOT IN ('hard_bounce', 'complaint', 'recipient_global_opt_out') OR expires_at IS NULL)
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE suppressions ADD CONSTRAINT suppressions_source_event_has_message CHECK (source_event_id IS NULL OR source_message_id IS NOT NULL)
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX suppressions_global_address_reason_active_uq ON suppressions (address_or_domain, reason)
    WHERE client_id IS NULL AND scope_type = 'address' AND lifted_at IS NULL AND reason IN ('hard_bounce', 'complaint')
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX suppressions_source_client_idempotency_uq ON suppressions (source_client_id, idempotency_key)
    WHERE source_client_id IS NOT NULL
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX suppressions_source_client_address_active_uq ON suppressions (source_client_id, address_or_domain)
    WHERE reason = 'recipient_global_opt_out' AND lifted_at IS NULL
SQL);
        $this->addSql('CREATE INDEX suppressions_source_message_idx ON suppressions (source_message_id) WHERE source_message_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX suppressions_source_message_idx');
        $this->addSql('DROP INDEX suppressions_source_client_address_active_uq');
        $this->addSql('DROP INDEX suppressions_source_client_idempotency_uq');
        $this->addSql('DROP INDEX suppressions_global_address_reason_active_uq');
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_source_event_has_message');
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_indefinite_reasons');
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_opt_out_provenance');
        $this->addSql('ALTER TABLE suppressions DROP COLUMN request_hash');
        $this->addSql('ALTER TABLE suppressions DROP COLUMN idempotency_key');
        $this->addSql('ALTER TABLE suppressions DROP COLUMN external_reference');
        $this->addSql('ALTER TABLE suppressions DROP COLUMN source_client_id');
        $this->addSql('ALTER TABLE suppressions DROP COLUMN source_event_id');
        // Fails (by design) while recipient_global_opt_out rows exist: history is never deleted by a rollback.
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_reason_check');
        $this->addSql('ALTER TABLE suppressions ADD CONSTRAINT suppressions_reason_check CHECK (reason IN ('.self::REASONS_2_3.'))');

        $this->addSql('ALTER TABLE unmatched_dsns DROP CONSTRAINT unmatched_dsns_dismissed_resolved');
        $this->addSql("ALTER TABLE unmatched_dsns ADD CONSTRAINT unmatched_dsns_dismissed_resolved CHECK (status <> 'dismissed' OR resolved_at IS NOT NULL)");

        $this->addSql('DROP INDEX messages_recipient_address_idx');
        $this->addSql('ALTER TABLE clients DROP COLUMN can_submit_global_suppressions');
    }
}
