<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Public sign-up (owner decision 2026-10-11): the verification link of a sign-up is a
 * passwordless link of the existing mechanism (auth_login_tokens), with purpose sign_up and the
 * organisation the person named. Nothing else exists until the link is used: no client and no
 * user. A sign-up link never belongs to a user, and a sign-in link never carries an organisation.
 *
 * Reproduces docs/schema/reference-schema.sql.
 */
final class Version20261014000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Public sign-up: auth_login_tokens.purpose and organisation_name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE auth_login_tokens ADD COLUMN purpose text NOT NULL DEFAULT 'sign_in' CHECK (purpose IN ('sign_in', 'sign_up'))");
        $this->addSql('ALTER TABLE auth_login_tokens ADD COLUMN organisation_name text NULL CHECK (organisation_name IS NULL OR length(organisation_name) BETWEEN 1 AND 200)');
        $this->addSql(<<<'SQL'
ALTER TABLE auth_login_tokens ADD CONSTRAINT auth_login_tokens_sign_up_fields CHECK (
    (purpose = 'sign_in' AND organisation_name IS NULL)
 OR (purpose = 'sign_up' AND organisation_name IS NOT NULL AND user_id IS NULL))
SQL);
        $this->addSql('CREATE INDEX auth_login_tokens_purpose_ip_created_idx ON auth_login_tokens (purpose, requested_ip_hash, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM auth_login_tokens WHERE purpose = 'sign_up'");
        $this->addSql('DROP INDEX auth_login_tokens_purpose_ip_created_idx');
        $this->addSql('ALTER TABLE auth_login_tokens DROP CONSTRAINT auth_login_tokens_sign_up_fields');
        $this->addSql('ALTER TABLE auth_login_tokens DROP COLUMN organisation_name');
        $this->addSql('ALTER TABLE auth_login_tokens DROP COLUMN purpose');
    }
}
