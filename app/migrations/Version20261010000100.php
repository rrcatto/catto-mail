<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Specification 2.11 (owner instruction 2026-10-07): operator self-service installation,
 * setup and diagnostics, and administrator address batches with re-permission.
 *
 *  - system_requests: work the web application asks of the host agent (diagnostics,
 *    pause/resume, live enable/disable, backup, restore rehearsal, DKIM, TLS renewal);
 *  - system_checks and system_check_runs: the latest result of every system check and its
 *    meaningful history;
 *  - system_state: the host agent's reports (host facts, containers, backups, boot recovery);
 *  - setup_steps: the administrator setup wizard's progress (resumable);
 *  - delivery_controls: the web emergency stop, read by the delivery daemon;
 *  - address_batches, address_batch_entries, address_batch_sends: uploaded address lists,
 *    their validation, review, staged sends and re-permission responses;
 *  - webhook events: repermission.responded (subject address_batch_entry);
 *  - PLATFORM.SYSTEM.VIEW and PLATFORM.HELP.VIEW for the built-in OPERATOR role (the new
 *    SYSTEM.* keys belong to ADMIN, which holds every key).
 *
 * Reproduces docs/schema/reference-schema.sql. Grants: infra/postgres/grants.sql.
 */
final class Version20261010000100 extends AbstractMigration
{
    private const NEW_OPERATOR_PERMISSIONS = ['PLATFORM.SYSTEM.VIEW', 'PLATFORM.HELP.VIEW'];

    public function getDescription(): string
    {
        return 'Specification 2.11: setup wizard, diagnostics, host agent requests, emergency stop, address batches and re-permission';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE system_requests (
    id                    uuid        PRIMARY KEY,
    action                text        NOT NULL CHECK (action IN (
        'diagnostics.run', 'delivery.pause', 'delivery.resume', 'delivery.live_enable', 'delivery.live_disable',
        'backup.run', 'backup.restore_rehearsal', 'dkim.generate', 'dkim.activate', 'tls.renew')),
    params_json           jsonb       NOT NULL DEFAULT '{}'::jsonb,
    status                text        NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'running', 'succeeded', 'failed', 'cancelled')),
    requested_by_user_id  uuid        NULL REFERENCES users (id),
    note                  text        NULL CHECK (note IS NULL OR length(note) BETWEEN 1 AND 1000),
    requested_at          timestamptz NOT NULL DEFAULT now(),
    started_at            timestamptz NULL,
    finished_at           timestamptz NULL,
    result_summary        text        NULL CHECK (result_summary IS NULL OR length(result_summary) <= 2000),
    result_json           jsonb       NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT system_requests_lifecycle CHECK (
        (status = 'pending' AND started_at IS NULL AND finished_at IS NULL)
     OR (status = 'running' AND started_at IS NOT NULL AND finished_at IS NULL)
     OR (status IN ('succeeded', 'failed') AND started_at IS NOT NULL AND finished_at IS NOT NULL)
     OR (status = 'cancelled' AND finished_at IS NOT NULL))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX system_requests_pending_idx ON system_requests (requested_at) WHERE status IN ('pending', 'running')
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX system_requests_requested_idx ON system_requests (requested_at, id)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE system_checks (
    check_key    text        PRIMARY KEY CHECK (check_key ~ '^[a-z0-9][a-z0-9_.-]{0,99}$'),
    component    text        NOT NULL CHECK (component IN (
        'host', 'boot', 'database', 'web', 'validator', 'delivery', 'postfix', 'opendkim', 'dns', 'tls',
        'nginx', 'tracking', 'webhook', 'bounce', 'backup', 'security')),
    title        text        NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
    result       text        NOT NULL CHECK (result IN ('pass', 'warn', 'fail', 'skipped', 'info')),
    summary      text        NOT NULL CHECK (length(summary) <= 2000),
    detail_json  jsonb       NOT NULL DEFAULT '{}'::jsonb,
    duration_ms  integer     NULL CHECK (duration_ms >= 0),
    source       text        NOT NULL CHECK (source IN ('agent', 'application')),
    ran_at       timestamptz NOT NULL,
    changed_at   timestamptz NOT NULL                              -- when the result last changed
)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX system_checks_component_idx ON system_checks (component, check_key)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE system_check_runs (
    id           uuid        PRIMARY KEY,
    check_key    text        NOT NULL CHECK (check_key ~ '^[a-z0-9][a-z0-9_.-]{0,99}$'),
    component    text        NOT NULL CHECK (component IN (
        'host', 'boot', 'database', 'web', 'validator', 'delivery', 'postfix', 'opendkim', 'dns', 'tls',
        'nginx', 'tracking', 'webhook', 'bounce', 'backup', 'security')),
    title        text        NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
    result       text        NOT NULL CHECK (result IN ('pass', 'warn', 'fail', 'skipped', 'info')),
    summary      text        NOT NULL CHECK (length(summary) <= 2000),
    duration_ms  integer     NULL CHECK (duration_ms >= 0),
    source       text        NOT NULL CHECK (source IN ('agent', 'application')),
    run_trigger  text        NOT NULL CHECK (run_trigger IN ('scheduled', 'requested', 'changed')),
    ran_at       timestamptz NOT NULL
)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX system_check_runs_key_ran_idx ON system_check_runs (check_key, ran_at)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX system_check_runs_ran_idx ON system_check_runs (ran_at, id)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE system_state (
    state_key   text        PRIMARY KEY CHECK (state_key ~ '^[a-z][a-z0-9_]{0,63}$'),
    value_json  jsonb       NOT NULL,
    updated_at  timestamptz NOT NULL
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE setup_steps (
    step_key            text        PRIMARY KEY CHECK (step_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    state               text        NOT NULL CHECK (state IN ('done', 'skipped')),
    note                text        NULL CHECK (note IS NULL OR length(note) <= 1000),
    updated_at          timestamptz NOT NULL,
    updated_by_user_id  uuid        NULL REFERENCES users (id)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE delivery_controls (
    id                  smallint    PRIMARY KEY CHECK (id = 1),
    emergency_stop      boolean     NOT NULL,
    changed_at          timestamptz NOT NULL,
    changed_by_user_id  uuid        NULL REFERENCES users (id),
    note                text        NULL CHECK (note IS NULL OR length(note) <= 1000)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE address_batches (
    id                              uuid        PRIMARY KEY,
    client_id                       uuid        NOT NULL REFERENCES clients (id),
    name                            text        NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    description                     text        NULL CHECK (description IS NULL OR length(description) <= 2000),
    source                          text        NULL CHECK (source IS NULL OR length(source) <= 500),
    purpose                         text        NOT NULL CHECK (purpose IN ('validation', 'repermission')),
    original_filename               text        NOT NULL CHECK (length(original_filename) BETWEEN 1 AND 255),
    file_format                     text        NOT NULL CHECK (file_format IN ('txt', 'csv')),
    email_column                    text        NULL,                -- CSV only: the chosen column header
    file_sha256                     text        NOT NULL CHECK (file_sha256 ~ '^[0-9a-f]{64}$'),
    uploaded_by_user_id             uuid        NULL REFERENCES users (id),
    created_at                      timestamptz NOT NULL DEFAULT now(),
    data_rows                       integer     NOT NULL CHECK (data_rows BETWEEN 0 AND 10000),
    imported_count                  integer     NOT NULL CHECK (imported_count >= 0),
    duplicate_count                 integer     NOT NULL CHECK (duplicate_count >= 0),
    malformed_count                 integer     NOT NULL CHECK (malformed_count >= 0),
    blank_count                     integer     NOT NULL CHECK (blank_count >= 0),
    list_id                         text        NULL CHECK (list_id IS NULL OR list_id ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$'),
    compliance_approved_at          timestamptz NULL,               -- the live-sending compliance gate for this batch
    compliance_approved_by_user_id  uuid        NULL REFERENCES users (id),
    compliance_reference            text        NULL CHECK (compliance_reference IS NULL OR length(compliance_reference) BETWEEN 3 AND 1000),
    CONSTRAINT address_batches_counts CHECK (imported_count + duplicate_count + malformed_count = data_rows),
    CONSTRAINT address_batches_approval_complete CHECK ((compliance_approved_at IS NULL) = (compliance_reference IS NULL)),
    CONSTRAINT address_batches_repermission_list CHECK (purpose <> 'repermission' OR list_id IS NOT NULL),
    CONSTRAINT address_batches_column_by_format CHECK ((file_format = 'csv') = (email_column IS NOT NULL))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX address_batches_created_idx ON address_batches (created_at, id)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX address_batches_client_idx ON address_batches (client_id, created_at)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE address_batch_entries (
    id                         uuid        PRIMARY KEY,
    batch_id                   uuid        NOT NULL REFERENCES address_batches (id),
    row_number                 integer     NOT NULL CHECK (row_number >= 1),  -- the file row (the corrected row for corrections)
    original_value             text        NOT NULL CHECK (length(original_value) <= 1000),
    normalized_address         text        NULL,                -- D-18 form; NULL for malformed input
    outcome                    text        NOT NULL CHECK (outcome IN ('imported', 'duplicate', 'malformed')),
    outcome_detail             text        NULL CHECK (outcome_detail IS NULL OR length(outcome_detail) <= 500),
    duplicate_of_entry_id      uuid        NULL REFERENCES address_batch_entries (id),
    corrects_entry_id          uuid        NULL REFERENCES address_batch_entries (id),
    validation_address_id      uuid        NULL REFERENCES validation_addresses (id),
    review_decision            text        NULL CHECK (review_decision IN ('include', 'exclude')),
    typo_decision              text        NULL CHECK (typo_decision IN ('accepted', 'rejected')),
    consent_state              text        NOT NULL DEFAULT 'unknown'
        CHECK (consent_state IN ('unknown', 'unconfirmed', 'confirmed', 'unsubscribed', 'global_opt_out')),
    consent_changed_at         timestamptz NULL,
    response_token_hash        text        NULL CHECK (response_token_hash ~ '^[0-9a-f]{64}$'),
    response_token_expires_at  timestamptz NULL,
    created_at                 timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT address_batch_entries_address_by_outcome CHECK ((outcome = 'malformed') = (normalized_address IS NULL)),
    CONSTRAINT address_batch_entries_duplicate_link CHECK ((outcome = 'duplicate') = (duplicate_of_entry_id IS NOT NULL)),
    CONSTRAINT address_batch_entries_imported_only CHECK (
        outcome = 'imported' OR (validation_address_id IS NULL AND review_decision IS NULL AND typo_decision IS NULL
                                 AND corrects_entry_id IS NULL AND response_token_hash IS NULL)),
    CONSTRAINT address_batch_entries_token_complete CHECK ((response_token_hash IS NULL) = (response_token_expires_at IS NULL))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX address_batch_entries_batch_row_idx ON address_batch_entries (batch_id, row_number, id)
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX address_batch_entries_address_uq ON address_batch_entries (batch_id, normalized_address) WHERE outcome = 'imported'
SQL);
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX address_batch_entries_token_uq ON address_batch_entries (response_token_hash) WHERE response_token_hash IS NOT NULL
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX address_batch_entries_validation_idx ON address_batch_entries (validation_address_id) WHERE validation_address_id IS NOT NULL
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE address_batch_sends (
    id                  uuid        PRIMARY KEY,
    batch_id            uuid        NOT NULL REFERENCES address_batches (id),
    send_job_id         uuid        NOT NULL UNIQUE REFERENCES send_jobs (id),
    stage               text        NOT NULL CHECK (stage IN ('seed', 'controlled', 'rollout', 'full')),
    recipient_count     integer     NOT NULL CHECK (recipient_count BETWEEN 1 AND 10000),
    subject             text        NOT NULL CHECK (length(subject) BETWEEN 1 AND 998),
    created_by_user_id  uuid        NULL REFERENCES users (id),
    created_at          timestamptz NOT NULL DEFAULT now()
)
SQL);
        $this->addSql(<<<'SQL'
CREATE INDEX address_batch_sends_batch_idx ON address_batch_sends (batch_id, created_at)
SQL);
        $this->addSql('ALTER TABLE webhook_events DROP CONSTRAINT webhook_events_event_type_check');
        $this->addSql("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_event_type_check CHECK (event_type IN (
        'validation.completed', 'validation.failed', 'send.completed', 'send.failed',
        'message.hard_bounced', 'message.complained', 'webhook.test', 'repermission.responded'))");
        $this->addSql('ALTER TABLE webhook_events DROP CONSTRAINT webhook_events_subject_type_check');
        $this->addSql("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_subject_type_check CHECK (subject_type IN (
        'validation_job', 'send_job', 'message', 'webhook_endpoint', 'address_batch_entry'))");
        $this->addSql('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_event_type_check');
        $this->addSql("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_type_check CHECK (event_type IN (
        'validation.completed', 'validation.failed', 'send.completed', 'send.failed',
        'message.hard_bounced', 'message.complained', 'webhook.test', 'repermission.responded'))");
        foreach (self::NEW_OPERATOR_PERMISSIONS as $key) {
            $this->addSql("INSERT INTO role_permissions (id, role_id, permission_key) SELECT ?, id, ? FROM roles WHERE role_key = 'OPERATOR'",
                [Uuid::v7()->toRfc4122(), $key]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM role_permissions WHERE permission_key IN (?)', [array_merge(self::NEW_OPERATOR_PERMISSIONS,
            ['SYSTEM.SETUP.MANAGE', 'SYSTEM.DIAGNOSTICS.RUN', 'SYSTEM.ADDRESS_BATCH.MANAGE'])],
            [\Doctrine\DBAL\ArrayParameterType::STRING]);
        $this->addSql("DELETE FROM webhook_deliveries WHERE event_type = 'repermission.responded'");
        $this->addSql("DELETE FROM webhook_events WHERE event_type = 'repermission.responded'");
        $this->addSql('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_event_type_check');
        $this->addSql("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_type_check CHECK (event_type IN (
        'validation.completed', 'validation.failed', 'send.completed', 'send.failed',
        'message.hard_bounced', 'message.complained', 'webhook.test'))");
        $this->addSql('ALTER TABLE webhook_events DROP CONSTRAINT webhook_events_subject_type_check');
        $this->addSql("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_subject_type_check CHECK (subject_type IN (
        'validation_job', 'send_job', 'message', 'webhook_endpoint'))");
        $this->addSql('ALTER TABLE webhook_events DROP CONSTRAINT webhook_events_event_type_check');
        $this->addSql("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_event_type_check CHECK (event_type IN (
        'validation.completed', 'validation.failed', 'send.completed', 'send.failed',
        'message.hard_bounced', 'message.complained', 'webhook.test'))");
        $this->addSql('DROP TABLE address_batch_sends');
        $this->addSql('DROP TABLE address_batch_entries');
        $this->addSql('DROP TABLE address_batches');
        $this->addSql('DROP TABLE delivery_controls');
        $this->addSql('DROP TABLE setup_steps');
        $this->addSql('DROP TABLE system_state');
        $this->addSql('DROP TABLE system_check_runs');
        $this->addSql('DROP TABLE system_checks');
        $this->addSql('DROP TABLE system_requests');
    }
}
