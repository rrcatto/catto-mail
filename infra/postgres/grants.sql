-- Smarthost table grants (docs/schema/schema.md §6, D-15).
--
-- Infrastructure, not schema: applied by infra/postgres/grants.sh with the
-- administrative connection after every Doctrine migration run. Idempotent and
-- exact: every runtime role first loses all table privileges, then receives
-- precisely the matrix below, in one transaction. A missing table (migrations
-- not yet run) aborts the whole run. Runtime roles never get DDL or TRUNCATE.
-- psql variables: app, webhook, validator, delivery (role names).
\set ON_ERROR_STOP on
BEGIN;

REVOKE ALL ON ALL TABLES IN SCHEMA public FROM :"app", :"webhook", :"validator", :"delivery";

-- Tenancy, people, domains and authentication
GRANT SELECT, INSERT, UPDATE ON clients TO :"app";
GRANT SELECT ON clients TO :"webhook", :"validator", :"delivery";
GRANT SELECT, INSERT, UPDATE ON users TO :"app";
-- Memberships can be removed from the dashboard (specification 2.7).
GRANT SELECT, INSERT, UPDATE, DELETE ON client_memberships TO :"app";
-- Roles, permissions and passwordless sign-in links (specification 2.7).
GRANT SELECT, INSERT, UPDATE, DELETE ON roles TO :"app";
GRANT SELECT, INSERT, DELETE ON role_permissions, user_roles TO :"app";
GRANT SELECT, INSERT, UPDATE, DELETE ON auth_login_tokens TO :"app";
GRANT SELECT, INSERT, UPDATE ON sending_domains TO :"app";
GRANT SELECT ON sending_domains TO :"delivery";
GRANT SELECT, INSERT, UPDATE ON api_keys TO :"app";

-- Validation
GRANT SELECT, INSERT, UPDATE ON validation_jobs TO :"app";
GRANT SELECT ON validation_jobs TO :"webhook";
GRANT SELECT, UPDATE ON validation_jobs TO :"validator";
GRANT SELECT, INSERT, DELETE ON validation_addresses TO :"app";
GRANT SELECT, UPDATE ON validation_addresses TO :"validator";
GRANT SELECT, DELETE ON validation_evidence TO :"app";
GRANT SELECT, INSERT ON validation_evidence TO :"validator";
GRANT SELECT, INSERT, UPDATE, DELETE ON disposable_domains TO :"app";
GRANT SELECT ON disposable_domains TO :"validator";

-- Sending
GRANT SELECT, INSERT, UPDATE ON send_jobs TO :"app";
GRANT SELECT ON send_jobs TO :"webhook";
GRANT SELECT, UPDATE ON send_jobs TO :"delivery";
GRANT SELECT, INSERT ON send_job_recipient_batches TO :"app";
GRANT SELECT, INSERT, DELETE ON send_job_recipients TO :"app";
GRANT SELECT ON send_job_recipients TO :"delivery";
-- Content purge only (D-14): UPDATE is column-restricted for both writers.
GRANT UPDATE (subject, html_body, text_body, content_purged_at) ON send_job_recipients TO :"app", :"delivery";
GRANT SELECT, DELETE ON messages TO :"app";
GRANT SELECT ON messages TO :"webhook";
GRANT SELECT, INSERT, UPDATE ON messages TO :"delivery";
GRANT SELECT, DELETE ON message_links TO :"app";
GRANT SELECT, INSERT ON message_links TO :"delivery";
GRANT SELECT, INSERT, DELETE ON message_events TO :"app";
GRANT SELECT ON message_events TO :"webhook";
GRANT SELECT, INSERT ON message_events TO :"delivery";
GRANT SELECT, UPDATE, DELETE ON unmatched_dsns TO :"app";
GRANT SELECT, INSERT, UPDATE ON unmatched_dsns TO :"delivery";
GRANT SELECT, INSERT, UPDATE ON delivery_ingest_cursors TO :"delivery";
-- Read-only, for the operator dashboard's Postfix-log ingest freshness (Phase 6).
GRANT SELECT ON delivery_ingest_cursors TO :"app";

-- Suppression and reputation
GRANT SELECT, INSERT, UPDATE, DELETE ON suppressions TO :"app";
GRANT SELECT, INSERT ON suppressions TO :"delivery";
-- D-38: durable opt-out request idempotency (Symfony only; DELETE for configured retention).
GRANT SELECT, INSERT, DELETE ON global_suppression_requests TO :"app";
GRANT SELECT ON domain_reputation TO :"app";
GRANT SELECT, INSERT, UPDATE ON domain_reputation TO :"delivery";

-- Metering, webhooks, audit
GRANT SELECT, INSERT, DELETE ON usage_records TO :"app";
GRANT INSERT ON usage_records TO :"validator", :"delivery";
GRANT SELECT, INSERT, UPDATE ON webhook_endpoints TO :"app";
GRANT SELECT ON webhook_endpoints TO :"webhook";
GRANT SELECT, INSERT ON webhook_events TO :"app";
GRANT SELECT, UPDATE ON webhook_events TO :"webhook";
GRANT INSERT ON webhook_events TO :"validator", :"delivery";
GRANT SELECT ON webhook_deliveries TO :"app";
GRANT SELECT, INSERT, UPDATE ON webhook_deliveries TO :"webhook";
-- Webhook worker liveness (Phase 7): written by the worker, read by the operator dashboard.
GRANT SELECT, INSERT, UPDATE ON webhook_worker_heartbeats TO :"webhook";
GRANT SELECT ON webhook_worker_heartbeats TO :"app";
GRANT SELECT, INSERT, DELETE ON audit_log TO :"app";
GRANT INSERT ON audit_log TO :"webhook", :"validator", :"delivery";

COMMIT;
