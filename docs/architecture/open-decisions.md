# Decision Log

**This file is a log, not a source of architectural authority.** Every approved decision has been
incorporated into the canonical specification (`docs/20260908-1644-smarthost-llm-spec.yaml`,
version 2.1, `revision_history`) and its normative contracts. If this log and the specification
ever differ, the specification wins.

## 1. Decisions (all resolved, 2026-10-02)

| ID | Decision | Incorporated in spec 2.1 |
|---|---|---|
| D-01 | Symfony persists staged recipients in `send_job_recipients`; Go creates `messages`. Merge data is not stored. | `go_delivery`, `schema.tables` |
| D-02 | `suppressed` status; explicit `message_queued`, `message_suppressed` and `submission_failed` events. | `message_tracking` |
| D-03 | Leased work claiming (`claimed_by`, `lease_expires_at`, `attempt_count`, `next_attempt_at`, `last_error`), with renewal, fencing and reclaim. | `architecture.work_claiming` |
| D-04 | Typo reason codes (including `other_domain_typo_rule`) and categorical `low`/`medium`/`high` confidence. A suggestion is never applied automatically. | `validation.typo_suggestions` |
| D-05 | `unmatched_dsns`. The operator identifies the candidate message, the resolution is queued for Go, and Go creates the transport event. The record is retained with who, when and which message. | `inbound_bounce_handling.unmatched_dsns` |
| D-06 | Origin-aware de-duplication: unique `(event_source, source_event_key)`, never derived purely from content. | `message_tracking.event_model.deduplication` |
| D-07 / D-29 | Send jobs: `collecting`, `queued`, `processing`, `dispatched`, `completed`, `failed`, `cancelled`. Validation jobs: `queued`, `processing`, `completed`, `failed`, `cancelled`. `running` is removed. | `sending.send_job_statuses`, `validation.job_statuses` |
| D-08 | No mail merge or template rendering. The client application submits fully rendered recipient content. `merge_data_json` is omitted. | `sending.content_ownership` |
| D-09 | Delivery-time tracking instrumentation, only when enabled. Open pixel in HTML only. Click rewriting of eligible HTML HTTP(S) links only. | `sending.tracking_instrumentation` |
| D-10 | `webhook_endpoints` with encrypted signing secrets and rotation; deliveries reference the endpoint. | `api.webhooks`, `schema.tables` |
| D-11 | 10,000 recipients per job, configuration-driven; rejected cleanly above the limit. | `sending.ingestion.max_recipients_per_job` |
| D-12 | `users` and `client_memberships`; global operator role; API keys never used for browser login. | `security.dashboard_authentication`, `schema.tables` |
| D-13 | `sending_domains` (several per client), random DNS TXT verification token, `verified_at`, DKIM selector/status. Unverified domains are rejected for live sending. | `sending.sending_domains` |
| D-14 | Rendered content is transient: purged on Postfix acceptance, otherwise cleaned up after 7 days by default. Other retention periods are configurable and not hard-coded. | `sending.rendered_content_retention`, `compliance.retention` |
| D-15 | Roles are created by infrastructure bootstrap under `infra/`. Doctrine owns schema objects. Separate identities for owner, app, webhook worker, validator and delivery. | `postgres.roles_and_privileges` |
| D-16 | Filesystem hand-off: SMTP submission, shared observability volume (read-only for Go), shared DSN Maildir spool. Ingestion cursors in PostgreSQL by generation and position. | `go_delivery.initial_integration_strategy` |
| D-17 / D-28 | nginx reverse proxy with PHP-FPM via FastCGI. No separate PHP HTTP application server. | `service_topology.reverse_proxy`, `symfony_app.runtime` |
| D-18 | Normalisation preserves the local part and lower-cases the domain. Duplicates in a send job are rejected. Repeated soft-bounce suppression after 3 consecutive recipient-scope soft bounces in 30 days, reset by `remote_accepted`. Both values are configurable. | `suppression_and_reputation`, `sending.duplicate_recipients` |
| D-19 | `external_reference` everywhere; `client_id` only from the credential. | `api.authentication.client_identity` |
| D-20 | `transactional` and `subscription` classes. Subscription requires `list_id` and a per-recipient `unsubscribe_url`. RFC 8058 headers. The client application owns unsubscribe. | `sending.list_unsubscribe` |
| D-21 | Optional per-address `external_address_reference` on validation input. | `validation.input` |
| D-22 | Webhook transactional outbox (`webhook_events`). Only the Symfony webhook worker delivers. | `api.webhooks` |
| D-23 | `infra/.env.example`, with no secrets. | `normative_contracts.environment` |
| D-24 | Staged recipient upload: create (`collecting`) → batches of ≤ 500 → submit (seal). Request limit stays at 10 MiB. | `sending.ingestion` |
| D-25 | No arbitrary headers in v1. Optional job-level `Reply-To`. Other headers come from the delivery contract. | `sending.header_contract` |
| D-26 | OpenDKIM milter (an explicit justified exception to the no-milter preference). Keys held only by OpenDKIM. Tempfail on milter failure. | `service_topology.opendkim` |
| D-27 | Queue-snapshot reconciliation. `outcome_unknown` after grace and more than one snapshot; it is not success, and can be superseded. | `transport_reconciliation` |
| Tracking tokens | Opaque random tokens of ≥ 192 bits, not signed. `APP_TRACKING_TOKEN_KEY` is removed. | `message_tracking.engagement_tracking.tokens` |
| `unsubscribe_signal` | Removed from the vocabulary; Smarthost has no producer for it. | `message_tracking.event_model` |
| Spec version | 2.1, dated 2026-10-02. This is a documentation revision, not a software version. | `project`, `revision_history` |
| Compliance gate | Consent-dependent live sending is gated on the operator's approved compliance specification. Technical and Mailpit work may proceed. | `compliance.live_sending_compliance_gate` |

## 2. Implementation choices made while incorporating the decisions

These follow from the decisions above and are recorded in the specification or contracts. They
are listed here so they are visible for review.

| Choice | Where |
|---|---|
| `paused` is removed from send jobs, because D-29 lists the complete set without it. Operator throttling and suspension act on the client, and the per-job emergency stop is `cancelled`. | spec `sending.throttling_and_suspension` |
| The reconciliation event is named `transport_outcome_unknown`. | vocabulary |
| `event_source` values and key rules: `delivery_daemon`, `postfix_submission`, `postfix_log`, `dsn_spool`, `unmatched_dsn_resolution`, `queue_reconciliation`, `tracking_endpoint`. Tracking events are unkeyed because repeats are legitimate. | spec, vocabulary |
| `failure_scope` (`recipient`, `domain`, `provider_policy`, `connection`, `dns`, `infrastructure`, `unknown`) implements the D-18 counting rule. | spec, vocabulary |
| The DSN ingestion key is the Maildir unique file name. The content hash is diagnostic only. | postfix-integration §5 |
| Table grants are applied by the same infra tooling, idempotently, after each migration. | spec `postgres.roles_and_privileges` |
| In production, submit also requires `dkim_status = active`, so OpenDKIM is never asked to sign for an unknown domain. | spec `opendkim.failure_policy` |
| The sender domain must be registered with the client when the job is created. Verification is enforced at submit. | OpenAPI |
| Abandoned `collecting` jobs are cancelled when staged content is cleaned up. | spec `sending.rendered_content_retention` |
| Expiry of tracking tokens and links follows `APP_RETENTION_TRACKING_DAYS`. Empty means no expiry. | environment |
| HTTP status codes: create send job → 201; add batch → 201; submit → 202, and is idempotent on an already sealed job; batch upload to a non-`collecting` job → 409. | OpenAPI |
| Verification TXT record: `_smarthost-verification.<domain>` = `smarthost-verification=<token>`. | spec `sending.sending_domains` |
| A queue snapshot is stale after three intervals, and reconciliation draws no conclusions from stale snapshots. Only one Go instance reconciles at a time (advisory lock). | postfix-integration §4, §7 |
| Processed DSN spool files are kept for a transient 7 days. Unmatched DSNs are kept in the database. | environment |
| An `opendkim/` directory is added to the repository layout. | spec `repository_strategy` |

## 3. Verification tasks (not architecture decisions)

All seven were resolved in Phase 1 against the actual container images. The observed results are
recorded in `docs/architecture/postfix-integration.md` §8 and re-proved by
`infra/bin/smarthostctl verify`:

* **V-1:** log-generation identity — a first-record fingerprint, because compressed generations
  get a new inode;
* **V-2:** `postlogd` ownership and `postfix logrotate` behaviour;
* **V-3:** `postqueue -j` format;
* **V-4:** shared identity under rootless Podman;
* **V-5:** Maildir delivery semantics;
* **V-6:** milter scope and tempfail;
* **V-7:** inotify on the shared volumes.

None of them required an architectural change.

## 4. Remaining open decisions

| ID | Topic | Blocks |
|---|---|---|
| D-30 | Whether automatically created suppressions (hard bounce, complaint, repeated soft bounce) are client-scoped or global. The schema supports both. | Phase 5 only |

None blocks Phase 1 or Phase 2.
