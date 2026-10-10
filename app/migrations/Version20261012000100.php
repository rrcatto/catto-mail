<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Settings in the dashboard (owner decision 2026-10-10): an administrator may change the
 * operational settings of docs/contracts/settings.json in System > Settings. A change is a row
 * of setting_overrides, which takes precedence over infra/.env without changing the file; the
 * host agent applies it (system request settings.apply: the configuration is rendered again
 * with the overrides and the affected services restart).
 *
 * Reproduces docs/schema/reference-schema.sql.
 */
final class Version20261012000100 extends AbstractMigration
{
    private const ACTIONS = "'diagnostics.run', 'delivery.pause', 'delivery.resume', 'delivery.live_enable', 'delivery.live_disable',"
        ." 'backup.run', 'backup.restore_rehearsal', 'dkim.generate', 'dkim.activate', 'tls.renew'";

    public function getDescription(): string
    {
        return 'Settings in the dashboard: setting_overrides and the settings.apply host request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE setting_overrides (
    name                text        PRIMARY KEY CHECK (name ~ '^[A-Z][A-Z0-9_]{1,63}$'),
    value               text        NOT NULL CHECK (length(value) <= 200),
    reason              text        NOT NULL CHECK (length(reason) BETWEEN 3 AND 1000),
    updated_at          timestamptz NOT NULL,
    updated_by_user_id  uuid        NULL REFERENCES users (id)
)
SQL);
        $this->addSql('ALTER TABLE system_requests DROP CONSTRAINT system_requests_action_check');
        $this->addSql('ALTER TABLE system_requests ADD CONSTRAINT system_requests_action_check CHECK (action IN ('.self::ACTIONS.", 'settings.apply'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM system_requests WHERE action = 'settings.apply'");
        $this->addSql('ALTER TABLE system_requests DROP CONSTRAINT system_requests_action_check');
        $this->addSql('ALTER TABLE system_requests ADD CONSTRAINT system_requests_action_check CHECK (action IN ('.self::ACTIONS.'))');
        $this->addSql('DROP TABLE setting_overrides');
    }
}
