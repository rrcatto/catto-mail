<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Application connections (specification 2.12): an application is connected from the connect
 * page in an authorisation-code flow with PKCE instead of someone copying an API key and a
 * webhook secret by hand. The approval of a client admin stores only the hash of a single-use
 * code and the application's code challenge; the API key and the webhook endpoint are created
 * when the application exchanges the code. Ending a connection revokes its key and removes its
 * endpoint: webhook_endpoints.status gains the terminal value removed.
 *
 * Reproduces docs/schema/reference-schema.sql.
 */
final class Version20261015000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Application connections: application_connections, webhook_endpoints.status removed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_endpoints DROP CONSTRAINT webhook_endpoints_status_check');
        $this->addSql("ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_status_check CHECK (status IN ('enabled', 'disabled', 'removed'))");
        $this->addSql(<<<'SQL'
CREATE TABLE application_connections (
    id                   uuid        PRIMARY KEY,
    client_id            uuid        NOT NULL REFERENCES clients (id),
    application_name     text        NOT NULL CHECK (length(application_name) BETWEEN 1 AND 100),
    redirect_uri         text        NOT NULL CHECK (redirect_uri ~* '^https?://' AND length(redirect_uri) <= 2000),
    webhook_url          text        NOT NULL CHECK (webhook_url ~* '^https?://' AND length(webhook_url) <= 2000),
    status               text        NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'active', 'revoked')),
    approved_by_user_id  uuid        NOT NULL REFERENCES users (id),
    code_hash            text        NOT NULL CHECK (code_hash ~ '^[0-9a-f]{64}$'),
    code_challenge       text        NOT NULL CHECK (code_challenge ~ '^[A-Za-z0-9_-]{43}$'),
    code_expires_at      timestamptz NOT NULL,
    exchanged_at         timestamptz NULL,
    api_key_id           uuid        NULL REFERENCES api_keys (id),
    webhook_endpoint_id  uuid        NULL REFERENCES webhook_endpoints (id),
    revoked_at           timestamptz NULL,
    created_at           timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT application_connections_code_expiry_after_creation CHECK (code_expires_at > created_at),
    CONSTRAINT application_connections_exchange_complete CHECK (
        (exchanged_at IS NULL) = (api_key_id IS NULL) AND (exchanged_at IS NULL) = (webhook_endpoint_id IS NULL)),
    CONSTRAINT application_connections_pending_unexchanged CHECK (status <> 'pending' OR exchanged_at IS NULL),
    CONSTRAINT application_connections_active_exchanged CHECK (status <> 'active' OR exchanged_at IS NOT NULL),
    CONSTRAINT application_connections_revoked_has_time CHECK ((status = 'revoked') = (revoked_at IS NOT NULL))
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX application_connections_code_hash_uq ON application_connections (code_hash)');
        $this->addSql('CREATE INDEX application_connections_client_idx ON application_connections (client_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX application_connections_api_key_uq ON application_connections (api_key_id) WHERE api_key_id IS NOT NULL');
        $this->addSql("CREATE UNIQUE INDEX application_connections_active_webhook_uq ON application_connections (client_id, webhook_url) WHERE status = 'active'");
        $this->addSql("CREATE INDEX application_connections_pending_expiry_idx ON application_connections (code_expires_at) WHERE status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE application_connections');
        // A removed endpoint receives nothing; disabled is the closest earlier state.
        $this->addSql("UPDATE webhook_endpoints SET status = 'disabled' WHERE status = 'removed'");
        $this->addSql('ALTER TABLE webhook_endpoints DROP CONSTRAINT webhook_endpoints_status_check');
        $this->addSql("ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_status_check CHECK (status IN ('enabled', 'disabled'))");
    }
}
