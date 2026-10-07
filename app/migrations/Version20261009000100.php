<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 9 (specification 2.10), public SaaS hardening:
 *
 *  - clients: origin, billing and abuse contacts, lifecycle timestamps (status changed,
 *    approved, closed) and whether approval needs a policy acceptance. Existing clients
 *    are operator-created internal clients: they keep working, are marked approved at
 *    their creation time when they already operate, and are exempt from the policy
 *    acceptance (new clients require it by default);
 *  - client_limits (per-client limits below the installation ceilings) and
 *    client_quota_usage (admitted-work counters per UTC day and month);
 *  - client_notes (private operator notes) and client_policy_acceptances (policy
 *    version accepted, by whom, how);
 *  - api_keys.expires_at;
 *  - usage_records: a message is metered at most once (unique partial index); the
 *    (client_id, occurred_at) index covers usage_type and quantity (index-only period sums);
 *  - message_events_reputation_idx: the reputation evaluation's time-window scan;
 *  - billing_statements and billing_statement_lines: the provider-neutral billing boundary;
 *  - client_reputation_metrics and client_alerts: reputation monitoring and operator warnings;
 *  - the new PLATFORM.* permission keys for the built-in OPERATOR role (which holds every
 *    PLATFORM.* key); roles that could change a client's status through
 *    PLATFORM.CLIENT.MANAGE keep that ability through PLATFORM.CLIENT.APPROVE and
 *    PLATFORM.CLIENT.RESTRICT.
 *
 * Reproduces docs/schema/reference-schema.sql. Grants: infra/postgres/grants.sql.
 */
final class Version20261009000100 extends AbstractMigration
{
    private const NEW_PERMISSIONS = [
        'PLATFORM.CLIENT.APPROVE', 'PLATFORM.CLIENT.RESTRICT', 'PLATFORM.CLIENT_LIMIT.MANAGE', 'PLATFORM.CLIENT_KEY.MANAGE',
        'PLATFORM.USAGE.VIEW', 'PLATFORM.USAGE.EXPORT', 'PLATFORM.ABUSE.VIEW', 'PLATFORM.ABUSE.MANAGE',
    ];

    public function getDescription(): string
    {
        return 'Phase 9: client lifecycle, limits and quotas, notes, policy acceptance, key expiry, billing boundary, reputation and alerts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE clients
    ADD COLUMN origin text NOT NULL DEFAULT 'operator' CHECK (origin IN ('operator', 'public_application')),
    ADD COLUMN billing_contact_email text NULL,
    ADD COLUMN abuse_contact_email text NULL,
    ADD COLUMN status_changed_at timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN approved_at timestamptz NULL,
    ADD COLUMN closed_at timestamptz NULL,
    ADD COLUMN policy_acceptance_required boolean NOT NULL DEFAULT false
SQL);
        // Existing clients: their history starts at creation; operating ones count as approved then.
        $this->addSql('UPDATE clients SET status_changed_at = created_at');
        $this->addSql("UPDATE clients SET approved_at = created_at WHERE status IN ('active', 'throttled', 'suspended')");
        $this->addSql("UPDATE clients SET closed_at = created_at WHERE status = 'closed'");
        $this->addSql('ALTER TABLE clients ALTER COLUMN policy_acceptance_required SET DEFAULT true');
        $this->addSql("ALTER TABLE clients ADD CONSTRAINT clients_closed_has_time CHECK (status <> 'closed' OR closed_at IS NOT NULL)");
        $this->addSql('CREATE INDEX clients_status_changed_idx ON clients (status, status_changed_at)');

        $this->addSql(<<<'SQL'
CREATE TABLE client_limits (
    client_id                     uuid        PRIMARY KEY REFERENCES clients (id),
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
    updated_at                    timestamptz NOT NULL DEFAULT now()
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE client_quota_usage (
    client_id     uuid        NOT NULL REFERENCES clients (id),
    metric        text        NOT NULL CHECK (metric IN ('validation_jobs', 'validation_addresses', 'send_jobs', 'send_recipients')),
    period        text        NOT NULL CHECK (period IN ('day', 'month')),
    period_start  date        NOT NULL,
    used          bigint      NOT NULL CHECK (used >= 0),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (client_id, metric, period, period_start),
    CONSTRAINT client_quota_usage_month_start CHECK (period <> 'month' OR extract(day FROM period_start) = 1)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE client_notes (
    id              uuid        PRIMARY KEY,
    client_id       uuid        NOT NULL REFERENCES clients (id),
    author_user_id  uuid        NULL REFERENCES users (id),
    note            text        NOT NULL CHECK (length(note) BETWEEN 1 AND 4000),
    created_at      timestamptz NOT NULL DEFAULT now()
)
SQL);
        $this->addSql('CREATE INDEX client_notes_client_created_idx ON client_notes (client_id, created_at)');
        $this->addSql(<<<'SQL'
CREATE TABLE client_policy_acceptances (
    id                   uuid        PRIMARY KEY,
    client_id            uuid        NOT NULL REFERENCES clients (id),
    policy_version       text        NOT NULL CHECK (policy_version ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$'),
    accepted_at          timestamptz NOT NULL DEFAULT now(),
    accepted_by_user_id  uuid        NULL REFERENCES users (id),
    source               text        NOT NULL CHECK (source IN ('client_dashboard', 'operator_recorded')),
    reference            text        NULL CHECK (length(reference) <= 255)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX client_policy_acceptances_client_version_uq ON client_policy_acceptances (client_id, policy_version)');

        $this->addSql('ALTER TABLE api_keys ADD COLUMN expires_at timestamptz NULL');
        $this->addSql('ALTER TABLE api_keys ADD CONSTRAINT api_keys_expiry_after_creation CHECK (expires_at IS NULL OR expires_at > created_at)');

        $this->addSql(<<<'SQL'
CREATE INDEX message_events_reputation_idx ON message_events (occurred_at)
    WHERE event_type IN ('hard_bounce', 'soft_bounce', 'deferred', 'complaint', 'transport_outcome_unknown', 'message_suppressed')
SQL);
        // Period usage sums per client become index-only scans (Phase9QueryPlanTest measured the heap fetches).
        $this->addSql('DROP INDEX usage_records_client_occurred_idx');
        $this->addSql('CREATE INDEX usage_records_client_occurred_idx ON usage_records (client_id, occurred_at) INCLUDE (usage_type, quantity)');
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX usage_records_message_once_uq ON usage_records (reference_id)
    WHERE usage_type = 'message_submitted' AND reference_type = 'message'
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE billing_statements (
    id                     uuid        PRIMARY KEY,
    client_id              uuid        NOT NULL REFERENCES clients (id),
    period_start           date        NOT NULL,
    period_end             date        NOT NULL,
    status                 text        NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'finalized', 'exported', 'void')),
    reconciliation_status  text        NOT NULL CHECK (reconciliation_status IN ('consistent', 'inconsistent')),
    reconciliation_json    jsonb       NOT NULL DEFAULT '{}'::jsonb,
    reconciled_at          timestamptz NOT NULL,
    created_at             timestamptz NOT NULL DEFAULT now(),
    finalized_at           timestamptz NULL,
    exported_at            timestamptz NULL,
    external_reference     text        NULL CHECK (length(external_reference) <= 255),
    voided_at              timestamptz NULL,
    CONSTRAINT billing_statements_period CHECK (period_end > period_start),
    CONSTRAINT billing_statements_finalized_has_time CHECK (status NOT IN ('finalized', 'exported') OR finalized_at IS NOT NULL),
    CONSTRAINT billing_statements_exported_complete CHECK (status <> 'exported' OR (exported_at IS NOT NULL AND external_reference IS NOT NULL)),
    CONSTRAINT billing_statements_void_has_time CHECK (status <> 'void' OR voided_at IS NOT NULL)
)
SQL);
        $this->addSql("CREATE UNIQUE INDEX billing_statements_client_period_uq ON billing_statements (client_id, period_start, period_end) WHERE status <> 'void'");
        $this->addSql('CREATE INDEX billing_statements_period_idx ON billing_statements (period_start, status)');
        $this->addSql(<<<'SQL'
CREATE TABLE billing_statement_lines (
    id            uuid        PRIMARY KEY,
    statement_id  uuid        NOT NULL REFERENCES billing_statements (id),
    usage_type    text        NOT NULL CHECK (usage_type IN ('validation_address', 'message_submitted')),
    quantity      bigint      NOT NULL CHECK (quantity >= 0)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX billing_statement_lines_statement_type_uq ON billing_statement_lines (statement_id, usage_type)');

        $this->addSql(<<<'SQL'
CREATE TABLE client_reputation_metrics (
    client_id                 uuid          NOT NULL REFERENCES clients (id),
    window_hours              smallint      NOT NULL CHECK (window_hours IN (24, 168)),
    computed_at               timestamptz   NOT NULL,
    messages_submitted        bigint        NOT NULL CHECK (messages_submitted >= 0),
    validation_addresses      bigint        NOT NULL CHECK (validation_addresses >= 0),
    hard_bounces              bigint        NOT NULL CHECK (hard_bounces >= 0),
    soft_bounces              bigint        NOT NULL CHECK (soft_bounces >= 0),
    deferrals                 bigint        NOT NULL CHECK (deferrals >= 0),
    provider_policy_failures  bigint        NOT NULL CHECK (provider_policy_failures >= 0),
    complaints                bigint        NOT NULL CHECK (complaints >= 0),
    suppressed                bigint        NOT NULL CHECK (suppressed >= 0),
    outcome_unknown           bigint        NOT NULL CHECK (outcome_unknown >= 0),
    webhook_failures          bigint        NOT NULL CHECK (webhook_failures >= 0),
    previous_daily_average    numeric(14,2) NULL,
    PRIMARY KEY (client_id, window_hours)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE client_alerts (
    id                    uuid          PRIMARY KEY,
    client_id             uuid          NOT NULL REFERENCES clients (id),
    metric                text          NOT NULL CHECK (metric IN ('hard_bounce_rate', 'complaint_rate', 'deferral_rate', 'volume_increase')),
    window_hours          smallint      NOT NULL CHECK (window_hours IN (24, 168)),
    severity              text          NOT NULL CHECK (severity IN ('warning', 'critical')),
    numerator             numeric(18,2) NOT NULL CHECK (numerator >= 0),
    denominator           numeric(18,2) NOT NULL CHECK (denominator >= 0),
    value                 numeric(14,4) NOT NULL,
    threshold             numeric(14,4) NOT NULL,
    first_observed_at     timestamptz   NOT NULL,
    last_observed_at      timestamptz   NOT NULL,
    resolved_at           timestamptz   NULL,
    acknowledged_at       timestamptz   NULL,
    acknowledged_by       uuid          NULL REFERENCES users (id),
    acknowledgement_note  text          NULL,
    CONSTRAINT client_alerts_observed_order CHECK (last_observed_at >= first_observed_at),
    CONSTRAINT client_alerts_ack_complete CHECK ((acknowledged_at IS NULL) = (acknowledgement_note IS NULL))
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX client_alerts_open_uq ON client_alerts (client_id, metric, window_hours) WHERE resolved_at IS NULL');
        $this->addSql('CREATE INDEX client_alerts_observed_idx ON client_alerts (last_observed_at, id)');
        $this->addSql('CREATE INDEX client_alerts_client_idx ON client_alerts (client_id, last_observed_at)');

        foreach (self::NEW_PERMISSIONS as $key) {
            $this->addSql("INSERT INTO role_permissions (id, role_id, permission_key) SELECT ?, id, ? FROM roles WHERE role_key = 'OPERATOR'",
                [Uuid::v7()->toRfc4122(), $key]);
        }
        // Continuity: a role that could change client status (PLATFORM.CLIENT.MANAGE) keeps that ability.
        foreach (['PLATFORM.CLIENT.APPROVE', 'PLATFORM.CLIENT.RESTRICT'] as $key) {
            $this->addSql(<<<'SQL'
INSERT INTO role_permissions (id, role_id, permission_key)
SELECT gen_random_uuid(), rp.role_id, ? FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
 WHERE rp.permission_key = 'PLATFORM.CLIENT.MANAGE' AND r.role_key <> 'OPERATOR'
ON CONFLICT (role_id, permission_key) DO NOTHING
SQL, [$key]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM role_permissions WHERE permission_key IN (?)', [self::NEW_PERMISSIONS],
            [\Doctrine\DBAL\ArrayParameterType::STRING]);
        $this->addSql('DROP TABLE client_alerts');
        $this->addSql('DROP TABLE client_reputation_metrics');
        $this->addSql('DROP TABLE billing_statement_lines');
        $this->addSql('DROP TABLE billing_statements');
        $this->addSql('DROP INDEX usage_records_message_once_uq');
        $this->addSql('DROP INDEX usage_records_client_occurred_idx');
        $this->addSql('CREATE INDEX usage_records_client_occurred_idx ON usage_records (client_id, occurred_at)');
        $this->addSql('DROP INDEX message_events_reputation_idx');
        $this->addSql('ALTER TABLE api_keys DROP CONSTRAINT api_keys_expiry_after_creation');
        $this->addSql('ALTER TABLE api_keys DROP COLUMN expires_at');
        $this->addSql('DROP TABLE client_policy_acceptances');
        $this->addSql('DROP TABLE client_notes');
        $this->addSql('DROP TABLE client_quota_usage');
        $this->addSql('DROP TABLE client_limits');
        $this->addSql('DROP INDEX clients_status_changed_idx');
        $this->addSql('ALTER TABLE clients DROP CONSTRAINT clients_closed_has_time');
        $this->addSql('ALTER TABLE clients DROP COLUMN origin, DROP COLUMN billing_contact_email, DROP COLUMN abuse_contact_email,
            DROP COLUMN status_changed_at, DROP COLUMN approved_at, DROP COLUMN closed_at, DROP COLUMN policy_acceptance_required');
    }
}
