<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Account blocks (owner decision 2026-10-27, specification 2.13): an account blocks an
 * address or a whole domain for its own mail, in the dashboard or through POST /v1/blocks.
 *
 *   suppressions                 reason gains account_block: always the account's own row
 *                                (client_id set, never global), no expiry, may carry the
 *                                creating request's external_reference; one active block per
 *                                account, scope and value.
 *   global_suppression_requests  operation gains create_account_block (the same durable
 *                                Idempotency-Key records as the opt-outs, D-38).
 *
 * Reproduces docs/schema/reference-schema.sql.
 */
final class Version20261027000200 extends AbstractMigration
{
    private const REASONS = "'hard_bounce', 'complaint', 'repeated_soft_bounce', 'operator_block', 'client_abuse_block', 'recipient_global_opt_out'";

    public function getDescription(): string
    {
        return 'Account blocks: suppression reason account_block and request operation create_account_block';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_reason_check');
        $this->addSql('ALTER TABLE suppressions ADD CONSTRAINT suppressions_reason_check CHECK (reason IN ('.self::REASONS.", 'account_block'))");
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_opt_out_provenance');
        $this->addSql(<<<'SQL'
ALTER TABLE suppressions ADD CONSTRAINT suppressions_opt_out_provenance CHECK (
    (reason = 'recipient_global_opt_out') = (source_client_id IS NOT NULL)
    AND (external_reference IS NULL OR source_client_id IS NOT NULL OR reason = 'account_block')
    AND (reason <> 'recipient_global_opt_out' OR (client_id IS NULL AND scope_type = 'address')))
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE suppressions ADD CONSTRAINT suppressions_account_block_scoped CHECK (
    reason <> 'account_block' OR (client_id IS NOT NULL AND expires_at IS NULL))
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX suppressions_account_block_active_uq ON suppressions (client_id, scope_type, address_or_domain)
    WHERE reason = 'account_block' AND lifted_at IS NULL
SQL);
        $this->addSql('ALTER TABLE global_suppression_requests DROP CONSTRAINT global_suppression_requests_operation_check');
        $this->addSql("ALTER TABLE global_suppression_requests ADD CONSTRAINT global_suppression_requests_operation_check CHECK (operation IN ('create_global_opt_out', 'create_account_block'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM global_suppression_requests WHERE operation = 'create_account_block'");
        $this->addSql('ALTER TABLE global_suppression_requests DROP CONSTRAINT global_suppression_requests_operation_check');
        $this->addSql("ALTER TABLE global_suppression_requests ADD CONSTRAINT global_suppression_requests_operation_check CHECK (operation IN ('create_global_opt_out'))");
        // An account's blocks have no earlier form: they go with the reason.
        $this->addSql("DELETE FROM suppressions WHERE reason = 'account_block'");
        $this->addSql('DROP INDEX suppressions_account_block_active_uq');
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_account_block_scoped');
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_opt_out_provenance');
        $this->addSql(<<<'SQL'
ALTER TABLE suppressions ADD CONSTRAINT suppressions_opt_out_provenance CHECK (
    (reason = 'recipient_global_opt_out') = (source_client_id IS NOT NULL)
    AND (external_reference IS NULL OR source_client_id IS NOT NULL)
    AND (reason <> 'recipient_global_opt_out' OR (client_id IS NULL AND scope_type = 'address')))
SQL);
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_reason_check');
        $this->addSql('ALTER TABLE suppressions ADD CONSTRAINT suppressions_reason_check CHECK (reason IN ('.self::REASONS.'))');
    }
}
