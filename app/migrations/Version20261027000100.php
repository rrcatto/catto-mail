<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guided account setup (owner decisions 2026-10-27):
 *
 *   plans                named sets of client limits, defined once: a client on a plan has its
 *                        limits; clients.plan names one. Starter (the limits public sign-ups had
 *                        from APP_STARTER_*, at their defaults) and Standard (no client limits:
 *                        the installation's ceilings) are built in. Earlier free-text plans other
 *                        than these two become Standard.
 *   client_invitations   a single-use link an operator makes; whoever opens it names their
 *                        organisation and opens an account on the invitation's plan, also while
 *                        public sign-up is closed. Only the SHA-256 of the token is stored.
 *   clients              origin gains invitation and system (the installation's own self-test
 *                        client, until now told apart by its name); the billing and abuse contact
 *                        addresses go, nothing used them; opens_on_policy_acceptance: a client an
 *                        operator created for a manager who must accept the service policy opens
 *                        when they accept it.
 *   auth_login_tokens    invitation_id: a sign-up link that came from an invitation.
 *
 * Reproduces docs/schema/reference-schema.sql.
 */
final class Version20261027000100 extends AbstractMigration
{
    private const LIMITS = <<<'SQL'
    api_requests_per_minute       integer     NULL CHECK (api_requests_per_minute >= 1),
    validation_jobs_per_day       integer     NULL CHECK (validation_jobs_per_day >= 0),
    validation_addresses_per_day  bigint      NULL CHECK (validation_addresses_per_day >= 0),
    validation_addresses_per_month bigint     NULL CHECK (validation_addresses_per_month >= 0),
    send_jobs_per_day             integer     NULL CHECK (send_jobs_per_day >= 0),
    send_recipients_per_day       bigint      NULL CHECK (send_recipients_per_day >= 0),
    send_recipients_per_month     bigint      NULL CHECK (send_recipients_per_month >= 0),
    max_recipients_per_send_job   integer     NULL CHECK (max_recipients_per_send_job BETWEEN 1 AND 10000),
    max_api_keys                  integer     NULL CHECK (max_api_keys >= 0),
    max_webhook_endpoints         integer     NULL CHECK (max_webhook_endpoints >= 0),
    max_sending_domains           integer     NULL CHECK (max_sending_domains >= 0),
SQL;

    public function getDescription(): string
    {
        return 'Guided account setup: plans, client_invitations, client origins invitation and system, contact addresses removed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE plans (
    id                            text        PRIMARY KEY CHECK (id ~ '^[a-z][a-z0-9-]{1,39}$'),
    name                          text        NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
".self::LIMITS."
    created_at                    timestamptz NOT NULL DEFAULT now(),
    updated_at                    timestamptz NOT NULL DEFAULT now()
)");
        $this->addSql('CREATE UNIQUE INDEX plans_name_uq ON plans (lower(name))');
        $this->addSql("INSERT INTO plans (id, name) VALUES ('standard', 'Standard')");
        $this->addSql("INSERT INTO plans (id, name, send_recipients_per_day, send_recipients_per_month, validation_addresses_per_day,
            max_api_keys, max_webhook_endpoints, max_sending_domains) VALUES ('starter', 'Starter', 200, 3000, 1000, 2, 2, 2)");
        // Starter limits an operator set in the dashboard (System › Settings) become the Starter plan's;
        // the APP_STARTER_* settings are gone. Values in infra/.env cannot be read here: env-migrate drops them.
        foreach (['send_recipients_per_day' => 'APP_STARTER_SEND_RECIPIENTS_PER_DAY', 'send_recipients_per_month' => 'APP_STARTER_SEND_RECIPIENTS_PER_MONTH',
            'validation_addresses_per_day' => 'APP_STARTER_VALIDATION_ADDRESSES_PER_DAY', 'max_api_keys' => 'APP_STARTER_API_KEY_LIMIT',
            'max_webhook_endpoints' => 'APP_STARTER_WEBHOOK_ENDPOINT_LIMIT', 'max_sending_domains' => 'APP_STARTER_SENDING_DOMAIN_LIMIT'] as $column => $name) {
            $this->addSql("UPDATE plans SET $column = o.value::bigint FROM setting_overrides o WHERE plans.id = 'starter' AND o.name = '$name' AND o.value ~ '^[0-9]{1,9}$'");
        }
        $this->addSql("DELETE FROM setting_overrides WHERE name LIKE 'APP\\_STARTER\\_%'");
        $this->addSql("UPDATE clients SET plan = CASE WHEN lower(plan) IN ('starter', 'standard') THEN lower(plan) ELSE 'standard' END");
        $this->addSql('ALTER TABLE clients ADD CONSTRAINT clients_plan_fkey FOREIGN KEY (plan) REFERENCES plans (id)');

        $this->addSql('ALTER TABLE clients DROP CONSTRAINT clients_origin_check');
        $this->addSql("ALTER TABLE clients ADD CONSTRAINT clients_origin_check CHECK (origin IN ('operator', 'public_application', 'invitation', 'system'))");
        // The self-test client was created by ApplicationDiagnostics with this name and address.
        $this->addSql("UPDATE clients SET origin = 'system' WHERE company_name = 'catto-mail system tests' AND contact_email = 'postmaster@localhost.invalid'");
        $this->addSql('ALTER TABLE clients DROP COLUMN billing_contact_email, DROP COLUMN abuse_contact_email');
        $this->addSql('ALTER TABLE clients ADD COLUMN opens_on_policy_acceptance boolean NOT NULL DEFAULT false');

        $this->addSql(<<<'SQL'
CREATE TABLE client_invitations (
    id                          uuid        PRIMARY KEY,
    token_hash                  text        NOT NULL CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    plan                        text        NOT NULL REFERENCES plans (id),
    policy_acceptance_required  boolean     NOT NULL,
    note                        text        NULL CHECK (note IS NULL OR length(note) BETWEEN 1 AND 200),
    created_by_user_id          uuid        NOT NULL REFERENCES users (id),
    created_at                  timestamptz NOT NULL DEFAULT now(),
    expires_at                  timestamptz NOT NULL,
    used_at                     timestamptz NULL,
    client_id                   uuid        NULL REFERENCES clients (id),
    revoked_at                  timestamptz NULL,
    CONSTRAINT client_invitations_expiry_after_creation CHECK (expires_at > created_at),
    CONSTRAINT client_invitations_used_has_client CHECK ((used_at IS NULL) = (client_id IS NULL)),
    CONSTRAINT client_invitations_used_or_revoked CHECK (used_at IS NULL OR revoked_at IS NULL)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX client_invitations_token_hash_uq ON client_invitations (token_hash)');
        $this->addSql('CREATE INDEX client_invitations_created_idx ON client_invitations (created_at)');

        $this->addSql('ALTER TABLE auth_login_tokens ADD COLUMN invitation_id uuid NULL REFERENCES client_invitations (id)');
        $this->addSql("ALTER TABLE auth_login_tokens ADD CONSTRAINT auth_login_tokens_invitation_sign_up CHECK (invitation_id IS NULL OR purpose = 'sign_up')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_login_tokens DROP CONSTRAINT auth_login_tokens_invitation_sign_up');
        $this->addSql('ALTER TABLE auth_login_tokens DROP COLUMN invitation_id');
        $this->addSql('DROP TABLE client_invitations');
        $this->addSql('ALTER TABLE clients DROP COLUMN opens_on_policy_acceptance');
        $this->addSql('ALTER TABLE clients ADD COLUMN billing_contact_email text NULL, ADD COLUMN abuse_contact_email text NULL');
        $this->addSql("UPDATE clients SET origin = 'operator' WHERE origin IN ('invitation', 'system')");
        $this->addSql('ALTER TABLE clients DROP CONSTRAINT clients_origin_check');
        $this->addSql("ALTER TABLE clients ADD CONSTRAINT clients_origin_check CHECK (origin IN ('operator', 'public_application'))");
        $this->addSql('ALTER TABLE clients DROP CONSTRAINT clients_plan_fkey');
        $this->addSql('DROP TABLE plans');
    }
}
