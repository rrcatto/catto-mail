<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 5 correction (D-38): durable idempotency for recipient global opt-out
 * requests, independent of the suppression row.
 *
 * global_suppression_requests records, per (source client, operation,
 * Idempotency-Key), the canonical request hash, the resulting suppression and the
 * original HTTP status, so a key keeps its historical meaning after the
 * suppression is lifted. Existing opt-outs are backfilled (one 201 request each,
 * id = the suppression id, which is unique and UUIDv7). The per-row
 * suppressions.idempotency_key / request_hash columns are then removed.
 *
 * Reproduces the specification 2.5 changes of docs/schema/reference-schema.sql
 * (tests/Schema/ReferenceSchemaEquivalenceTest.php). Grants: infra/postgres/grants.sql.
 */
final class Version20261005000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-38 durable global opt-out request idempotency (global_suppression_requests)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE global_suppression_requests (
    id                  uuid        PRIMARY KEY,
    client_id           uuid        NOT NULL REFERENCES clients (id),
    operation           text        NOT NULL CHECK (operation IN ('create_global_opt_out')),
    idempotency_key     text        NOT NULL,
    request_hash        text        NOT NULL,
    suppression_id      uuid        NOT NULL REFERENCES suppressions (id),
    response_status     smallint    NOT NULL CHECK (response_status IN (200, 201)),
    external_reference  text        NULL,
    created_at          timestamptz NOT NULL DEFAULT now()
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX global_suppression_requests_client_operation_key_uq ON global_suppression_requests (client_id, operation, idempotency_key)');
        $this->addSql('CREATE INDEX global_suppression_requests_suppression_idx ON global_suppression_requests (suppression_id)');
        $this->addSql(<<<'SQL'
INSERT INTO global_suppression_requests (id, client_id, operation, idempotency_key, request_hash, suppression_id,
                                         response_status, external_reference, created_at)
SELECT id, source_client_id, 'create_global_opt_out', idempotency_key, request_hash, id, 201, external_reference, created_at
  FROM suppressions WHERE reason = 'recipient_global_opt_out'
SQL);
        $this->addSql('DROP INDEX suppressions_source_client_idempotency_uq');
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_opt_out_provenance');
        $this->addSql('ALTER TABLE suppressions DROP COLUMN request_hash');
        $this->addSql('ALTER TABLE suppressions DROP COLUMN idempotency_key');
        $this->addSql(<<<'SQL'
ALTER TABLE suppressions ADD CONSTRAINT suppressions_opt_out_provenance CHECK (
    (reason = 'recipient_global_opt_out') = (source_client_id IS NOT NULL)
    AND (external_reference IS NULL OR source_client_id IS NOT NULL)
    AND (reason <> 'recipient_global_opt_out' OR (client_id IS NULL AND scope_type = 'address')))
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE suppressions DROP CONSTRAINT suppressions_opt_out_provenance');
        $this->addSql('ALTER TABLE suppressions ADD COLUMN idempotency_key text NULL');
        $this->addSql('ALTER TABLE suppressions ADD COLUMN request_hash text NULL');
        // The request that created each opt-out (201) restores its key; later requests (200) cannot be kept.
        $this->addSql(<<<'SQL'
UPDATE suppressions s SET idempotency_key = r.idempotency_key, request_hash = r.request_hash
  FROM global_suppression_requests r WHERE r.suppression_id = s.id AND r.response_status = 201
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE suppressions ADD CONSTRAINT suppressions_opt_out_provenance CHECK (
    (reason = 'recipient_global_opt_out') = (source_client_id IS NOT NULL)
    AND (source_client_id IS NULL) = (idempotency_key IS NULL)
    AND (idempotency_key IS NULL) = (request_hash IS NULL)
    AND (external_reference IS NULL OR source_client_id IS NOT NULL)
    AND (reason <> 'recipient_global_opt_out' OR (client_id IS NULL AND scope_type = 'address')))
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX suppressions_source_client_idempotency_uq ON suppressions (source_client_id, idempotency_key)
    WHERE source_client_id IS NOT NULL
SQL);
        $this->addSql('DROP TABLE global_suppression_requests');
    }
}
