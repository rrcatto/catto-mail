<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sending domains set up in the client dashboard (owner decision 2026-10-11): a client admin adds
 * a domain, proves it with the TXT challenge, and the host agent generates its DKIM key inside
 * OpenDKIM. sending_domains.dkim_public_key keeps the public half of the domain's key (the p=
 * value of the DKIM record, public DNS material) so the dashboard can show the record to publish
 * and compare it with DNS before it asks the host agent to activate the selector.
 *
 * Reproduces docs/schema/reference-schema.sql.
 */
final class Version20261013000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sending domains in the client dashboard: sending_domains.dkim_public_key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE sending_domains ADD COLUMN dkim_public_key text NULL
    CHECK (dkim_public_key IS NULL OR (dkim_public_key ~ '^[A-Za-z0-9+/]+={0,2}$' AND length(dkim_public_key) <= 2000))
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sending_domains DROP COLUMN dkim_public_key');
    }
}
