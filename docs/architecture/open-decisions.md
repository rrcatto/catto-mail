# Decision Log

**This file is a log, not a source of architectural authority.** Every approved decision has been
incorporated into the canonical specification (`docs/20260908-1644-smarthost-llm-spec.yaml`,
version 2.5, `revision_history`) and its normative contracts. If this log and the specification
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
| D-12 | `users` and `client_memberships`; global operator role (since specification 2.7: roles and permission keys, passwordless sign-in); API keys never used for browser login. | `security.dashboard_authentication`, `schema.tables` |
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

### Decisions after Phase 2 (resolved 2026-10-03, incorporated in spec 2.2)

| ID | Decision | Incorporated in spec 2.2 |
|---|---|---|
| D-31 | Only `active` and `throttled` clients may create or add work. `POST /v1/validation-jobs`, `POST /v1/send-jobs`, recipient batches and submit answer 403 for `pending_approval` and `suspended` clients, which can still authenticate and read. Closed clients fail authentication. Workers claim only active/throttled clients' work and stop when a suspension becomes effective during a lease. | `api.authentication.client_status_rule`, `sending.throttling_and_suspension`, `validation.work_claiming`, OpenAPI 403 responses |
| D-32 | The D-18 normalisation rule is exact: ASCII-whitespace trim, split at the final `@`, local part byte-for-byte, ASCII domain lower-cased, non-ASCII domain to A-labels via UTS #46 non-transitional processing, no normalised form on failure. Shared vectors: `docs/contracts/address-normalization-vectors.json` (PHP, Python, and Go from Phase 4). | `suppression_and_reputation.address_matching.exact_rule`, `normative_contracts` |
| D-33 | The Python validator meters validation usage: one `validation_address` unit per address that transitions to `done`, in the same transaction as the fenced result; aggregation per job and transaction is allowed; nothing is metered for unprocessed addresses, lost leases, reclaims or retries. | `validation.usage_metering` |
| D-34 | An idempotent replay guarantees no repeated side effect, the same resource, status code and Location, and `Idempotent-Replayed: true`; resource-creation bodies may show the current representation (no stored responses); a recipient-batch replay reproduces its original result. | `api.idempotency.semantics`, OpenAPI `IdempotencyKey` |

### Decision after Phase 3 (owner decision 2026-10-04, incorporated in spec 2.3)

| ID | Decision | Incorporated in spec 2.3 |
|---|---|---|
| D-35 | The development pod and its containers are persistent Podman objects: created once, then started, stopped and restarted as the same objects, including from Podman Desktop (`podman pod stop/start`). A systemd user service starts the existing pod at boot and stops it at shutdown and never removes it. Only `smarthostctl recreate` replaces pod and containers (named volumes kept); `destroy-volumes --yes` is the only data-deleting operation. This replaces the Quadlet `.pod`/`.container` runtime, whose units delete their containers and pod on every stop. | `podman_environment.process_management`, `repository_strategy` |

### Decision before Phase 5 (owner decision 2026-10-04, incorporated in spec 2.4)

| ID | Decision | Incorporated in spec 2.4 |
|---|---|---|
| D-30 | Automatic transport suppressions are **global** across the installation (`client_id` NULL) and apply to every current and future client. They are created only for an authoritative recipient-specific hard bounce (`failure_scope` recipient), a verified (correlated) complaint, the repeated recipient soft-bounce rule (evaluated globally; the suppression expires after the configured window), and an explicit **recipient global opt-out** (`recipient_global_opt_out`) reported by a client an operator authorised (`clients.can_submit_global_suppressions`, default false) through `POST /v1/global-suppressions`. Temporary, provider-policy, reputation, domain, DNS, connection, TLS, infrastructure, ambiguous and `outcome_unknown` outcomes never suppress. Provenance is explicit (`source_message_id`, `source_event_id`, `source_client_id`, audit log). Lifting sets `lifted_at`, never deletes, and never overrides an independent suppression. An ordinary unsubscribe remains client business state. | `suppression_and_reputation.global_suppression_policy`, `api.authentication.capabilities`, `api.endpoints_initial`, `inbound_bounce_handling`, `schema.tables.suppressions`, vocabulary, OpenAPI, reference schema |

### Phase 5 corrections (owner decisions 2026-10-05, incorporated in spec 2.5)

| ID | Decision | Incorporated in spec 2.5 |
|---|---|---|
| D-36 | A DSN or complaint is correlated automatically only through Smarthost-issued identifiers: VERP token, envelope id, Smarthost Message-ID or Postfix queue id. The recipient address, even with a matching returned `From`/sender, is forgeable and, because suppressions are global, never creates a message event or suppression; it is stored with the unmatched DSN as operator candidate information (`detail_json.candidates`). Supersedes the Phase 5 recipient-level correlation. | `inbound_bounce_handling.correlation` |
| D-37 | A client holding `can_submit_global_suppressions` may **create** a `recipient_global_opt_out` while `pending_approval` or `suspended` (a recipient-safety, do-not-contact operation, not work creation); it may **lift** its own opt-outs only while `active` or `throttled`. Closed clients fail authentication. | `api.authentication.client_status_rule`, `api.authentication.capabilities` |
| D-38 | Opt-out request idempotency is durable and independent of the suppression row: `global_suppression_requests` maps (source client, operation, Idempotency-Key) to the canonical request hash, the resulting suppression and the original status. Same key + same request replays the recorded result; same key + different request is 422; concurrent same-key requests have one effect; a later lift never changes what a used key means. Supersedes the Phase 5 behaviour that returned an active opt-out for a new key without storing the key. | `api.idempotency.global_opt_out_mechanism`, `schema.tables.global_suppression_requests` |

ARF `abuse`-only complaints and the D-30 global suppression policy are unchanged.

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

### Phase 2 implementation choices

Made while implementing Phase 2 within the contracts. None changes the architecture; they are
listed for review.

| Choice | Where |
|---|---|
| D-18 IDNA detail: ASCII domains are lower-cased; non-ASCII domains become A-labels via UTS #46 non-transitional processing; trimming covers ASCII whitespace only. Python (Phase 3) and Go must match. | conventions "Email addresses" |
| `content_bytes` / `content_sha256` definitions (what survives the D-14 purge). | conventions "Content and tracking" |
| API key format `shk_` + 256-bit base64url; `key_prefix` = first 12 characters; `last_used_at` is updated at most once a minute. | conventions "API" |
| Idempotency in-flight detection uses a transaction-scoped PostgreSQL advisory lock on (endpoint, client or job, key); the unique indexes stay the final guarantee. The request hash is SHA-256 of the canonically re-encoded JSON body (sorted keys), so whitespace and key order do not matter but adding a field with its default value does. | `app/src/Idempotency`, `app/src/Api/RequestHasher.php` |
| A replay returns the original status code and headers with the resource's **current** representation (no response bodies are stored). A recipient-batch replay reproduces the original body exactly (running total at that time). | `app/src/Validation`, `app/src/Sending` |
| Request bodies are validated against the normative OpenAPI document itself (copied into the image), so the API cannot drift from the contract; unknown fields such as `client_id`, `headers`, `merge_data` or `template_reference` are 422 with a JSON pointer. A non-JSON media type is 400; a malformed resource id is 404, like a foreign one. | `app/src/Api/OpenApiContract.php` |
| Recipient addresses must be structurally usable in an SMTP envelope (host-name domain with at least two labels; local part ≤ 64 octets without whitespace or control characters); subjects, names, list ids and references must not contain control characters (header injection). Deliverability is still never judged. | `app/src/Sending` |
| Client status (superseded by D-31 in 2.2): Phase 2 blocked only send-job creation; since D-31 all four work-creating operations answer 403 for `pending_approval` and `suspended` clients (`App\Api\WorkPermission`). | `app/src/Api/WorkPermission.php` |
| Failed API authentication is limited to 30 per minute per client IP (a code constant; there is no contract variable for it). The per-key limit uses `APP_API_RATE_LIMIT_PER_MINUTE`. Limiter state is local to the PHP-FPM container. | `app/config/packages/framework.yaml` |
| `smarthost:dev:bootstrap` marks the development domain verified without DNS and DKIM active (selector `phase1`); it refuses unless `SMARTHOST_ENV` is `development` or `test`. | `app/src/Command/DevBootstrapCommand.php` |
| Memberships can be added and re-roled but not removed: the grant matrix gives `smarthost_app` no DELETE on `client_memberships`. | `docs/schema/schema.md` §6 |
| Webhook endpoint URLs must be https (http only in development/test); `webhook.test` is not subscribable. Signing secrets are `whsec_` + 256-bit base64url, sealed with libsodium secretbox bound to the endpoint id. | `app/src/Webhook` |
| `APP_ENCRYPTION_KEYS` and `APP_WEBHOOK_SECRET_OVERLAP_HOURS` are first needed in Phase 2 (endpoint/secret model), not Phase 7. | environment contract |
| 12 `meaning` texts in `status-vocabulary.yaml` contained unquoted commas inside YAML flow mappings, which silently truncated them (and Symfony's YAML parser rejects them). They are now quoted; no value changed. | vocabulary |

### Phase 3 implementation choices

Made while implementing the Python validator within specification 2.2. None changes the
architecture; they are listed for review. Details: `validator/README.md`.

| Choice | Where |
|---|---|
| D-32 in Python implements UTS #46 non-transitional processing explicitly on the `idna` package's mapping table, because `idna.encode()` applies IDNA2008 rules that disagree with PHP/ICU. Both languages pass the same 87 vectors. | `validator/smarthost_validator/normalize.py` |
| Internationalised local parts (RFC 6531) are valid syntax. They are probed only when the server offers SMTPUTF8; otherwise `smtp_status = inconclusive` (`smtp.smtputf8_unsupported`). | `syntax.py`, `smtp_probe.py` |
| Address-literal domains (`user@[192.0.2.1]`) are valid syntax but are not looked up or probed; the result is `unknown`, low confidence. | `pipeline.py`, `classify.py` |
| Probes do not negotiate STARTTLS. A server that insists on it yields a non-accepted result (`blocked`/`inconclusive`), never `rejected`. | `smtp_probe.py` |
| No live probing outside production: with `SMARTHOST_ENV` other than `production`, `VALIDATOR_SMTP_PROBE_ENABLED=true` requires `VALIDATOR_SMTP_ROUTE_OVERRIDE`; in production the override must be empty. The worker refuses to start otherwise. | `config.py`, environment contract |
| With probing disabled, an address with usable DNS is `probably_deliverable` with low confidence (`smtp.skipped`). | `classify.py` (rule table in the docstring) |
| Accept-all detection: at most one probe per domain per 24 hours (verdict cached per worker) and at most one per second per worker; other addresses of the domain wait for the verdict. An accept-all domain is `risky`, never `deliverable`. | `limits.py`, `classify.py` |
| Provider back-off: 421, 4.7.x, 5.7.x and policy wording put the MX into an exponential cool-down; affected addresses are rescheduled, and exhaustion during a cool-down is `temporarily_unverifiable`. | `limits.py`, `pipeline.py` |
| A probe takes its domain slot, then its MX slot, then a global slot, so a skewed job waits on its provider without holding global slots. | `limits.py` |
| Disposable detection matches the domain or any parent domain in `disposable_domains`. | `db.py` |
| Typo edit-distance matching uses only provider domains of at least 9 characters (short ones such as `aol.com` are too close to unrelated real domains). | `typo.py` |
| Symfony wakes the validator with `pg_notify('smarthost_validation_work', <job id>)` in the job-creation transaction; the validator `LISTEN`s and also polls, so a lost notification only delays work. | conventions, `ValidationJobService`, `worker.py` |
| `claimed_by` is `<VALIDATOR_WORKER_ID>/<random instance id>`, so a restarted process never treats its predecessor's leases as its own. | `worker.py` |
| The validator's outbox insert is a plain `INSERT` made after winning the job's status transition: `ON CONFLICT` would need SELECT on `webhook_events`, which the role lacks. Python inserts the `validation.completed`/`validation.failed` outbox row only; HTTP delivery remains the Symfony webhook worker's (Phase 7). | conventions, `db.py` |
| A job that can never complete (fewer address rows than `total_addresses`) becomes `failed`, with `validation.failed` in the outbox and the reason in `audit_log`. | `db.py` |
| Development pod DNS has no Internet route, so real domains classify as `undeliverable` (NXDOMAIN) in the development pod. Meaningful validation tests use the fake DNS server of `smarthostctl test phase3`. | `validator/README.md` |

### Phase 4 implementation choices

Made while implementing the Go delivery daemon within specification 2.3. None changes the
architecture; details are in `docs/architecture/postfix-integration.md` §9 and `delivery/README.md`.

| Choice | Where |
|---|---|
| D-32 in Go uses `golang.org/x/net/idna` (UTS #46 non-transitional, no STD3, CheckHyphens/Bidi/Joiners, DNS lengths) plus one explicit check that x/net/idna lacks: an `xn--` label must decode to a non-empty, not-all-ASCII label (shared vector `xn--ss-`). All 87 vectors pass in PHP, Python and Go. | `delivery/internal/address` |
| VERP tokens are 128-bit CSPRNG values in lower-case base32 (26 characters), so a receiver that lower-cases the return path's local part does not break correlation. Tracking tokens are 192-bit base64url (32 characters), never signed. | `delivery/internal/ids` |
| Submission uses STARTTLS without certificate verification (pod-internal hop; no CA contract variable) and never sends AUTH in clear. | `delivery/internal/worker` |
| Per-message retries live inside the job lease (the schema has no per-message lease or `next_attempt_at`): `5 s · 2^(n-1)` capped at `DELIVERY_DEFERRAL_BACKOFF_SECONDS`; failures of the submission service also pause all submissions (up to 2 min). A temporarily failing message keeps the job `processing`. | `delivery/internal/worker` |
| A submission starts only when the whole SMTP transaction fits in the remaining lease (margin and minimum scale with `DELIVERY_LEASE_SECONDS`), so a stale worker never submits after its lease could have expired. | `delivery/internal/worker` |
| An acceptance that cannot be recorded (lease lost, repeated database errors) parks the message instead of retrying the submission; the next lease owner recovers it from the log (§6.3). | `delivery/internal/worker` |
| Ambiguous submissions are resolved from the log within 2 minutes ("committed" = a queue-manager record after the cleanup record); otherwise resubmitted. Recovered queue ids are re-scanned at once so their delivery records are not left to reconciliation. | `delivery/internal/worker`, `postfixlog` |
| Ingestion holds for up to 60 s at the cleanup record of a submission whose queue id is being recorded; it checks those messages before correlating queue ids, so a commit between the two statements cannot let a record slip past both. | `delivery/internal/store/logevents.go` |
| `submission_failed` is keyed as a `delivery_daemon` lifecycle event (`submission_failed:<message_id>`), since the `postfix_submission` key needs a queue id. | `delivery/internal/store` |
| `send_jobs.summary_counts_json` (messages per status, the API's `summary_counts`) is maintained by Go in the transaction of every status change, under the job row lock; transactions lock job rows before message rows. | `delivery/internal/store` |
| Synchronous Postfix bounces in the log (`status=bounced`/`expired`) are recorded as `hard_bounce`/`soft_bounce` (vocabulary source `postfix_log`) with `message.hard_bounced` in the outbox; no suppression is created (superseded in Phase 5: they now feed the D-30 global suppression policy). | `delivery/internal/postfixlog`, `store` |
| Provider pressure: recipient domains with at least 3 messages currently deferred are backed off for `DELIVERY_DEFERRAL_BACKOFF_SECONDS`. | `delivery/internal/worker` |
| Reconciliation does not conclude `outcome_unknown` while an open or match-requested unmatched DSN names the message's VERP token or queue id. | `delivery/internal/reconcile` |
| `POSTFIX_QUEUE_SNAPSHOT_INTERVAL_SECONDS` is also consumed by delivery (snapshot freshness, three intervals). | environment contract |
| Symfony's submit sends `NOTIFY smarthost_send_work, '<job id>'`; the daemon `LISTEN`s and also polls. | `app/src/Sending/SendJobService.php` |
| The delivery binary is `smarthost-delivery` (`run`, `health` and the Phase 1 probes `identity`, `check-db`, `check-observability`, `check-spool`). | `delivery/cmd/smarthost-delivery` |

### Phase 5 implementation choices

Made while implementing Phase 5 within specification 2.4. None changes the architecture; details
are in `docs/architecture/postfix-integration.md` §5 and §10 and `delivery/README.md`.

| Choice | Where |
|---|---|
| One failure-scope classifier (`internal/smtpclass`) serves Postfix log and DSN evidence. It is conservative: policy/reputation/DNS/connection wording wins over the code; only x.1.0/1/3/6 and x.2.0/1/2 (and, without a usable code, unambiguous mailbox wording) are `recipient`; x.4.7 (expired) is `unknown` (Phase 4 called it `connection`; neither counts). | `delivery/internal/smtpclass` |
| The VERP token is read only from the **topmost** `Delivered-To`/`X-Original-To` header (prepended by the receiving Postfix); lower ones may be forged by the sender of the DSN. | `delivery/internal/dsn` |
| ~~Recipient-level correlation~~ (superseded by D-36): recent messages to the reported recipient, with whether their sender matches the returned `From`, are only operator candidates on the unmatched DSN. Identifiers naming different messages are a conflict (unmatched). | `delivery/internal/store/dsn.go` |
| A correlated report that cannot be reconciled (no block for the recipient, a different `Original-Recipient`, no status, malformed ARF) appends `dsn_unmatched` to the message; `Action: delivered/relayed/expanded` and non-`abuse` ARF types record nothing. Only ARF `Feedback-Type: abuse` is a complaint. | `delivery/internal/dsn/interpret.go` |
| A DSN's event time is its `Last-Attempt-Date`, else `Date`, else the Maildir receipt time; an implausible time (before the message existed, or more than 5 minutes after receipt) is replaced by the receipt time. | `delivery/internal/store/dsn.go` |
| Repeated soft bounces count distinct messages; a `remote_accepted` resets the sequence only if the same message was not soft-bounced afterwards (a relay may accept a message whose final mailbox then returns a DSN — observed in the end-to-end run). | `delivery/internal/store/policy.go` |
| Concurrency of the policy: a transaction-scoped advisory lock per address, taken at commit in address order (no deadlock), plus partial unique indexes for hard bounce, complaint and opt-outs. | `delivery/internal/store/policy.go`, migration |
| Go re-checks suppressions immediately before each submission (one indexed query per message), so a suppression created after a job was staged or expanded still prevents the submission. | `delivery/internal/worker` |
| Stale `processing/` claims (older than `DELIVERY_LEASE_SECONDS`) are reclaimed by renaming to `<key>#<worker>-<nonce>`; the key ignores everything after `:` or `#`. Unreadable files go to `failed/`; a transient database error keeps the claim for an in-process retry. | `delivery/internal/dsnspool` |
| The stored `raw_message` is at most 512 KiB, valid UTF-8 without NUL (flags in `detail_json`); the spool file keeps the exact bytes until retention. | `delivery/internal/store/dsn.go` |
| Feedback-loop reports are accepted at the plain base address `<SMARTHOST_VERP_LOCAL_PART>@<bounce domain>` (already accepted by the Phase 1 recipient table) or at a VERP return path; no new variable. | spec `inbound_bounce_handling.feedback_loop_address` |
| Opt-out API: 403 is checked before the body; the representation never shows other suppressions of the address. (Superseded by D-37 and D-38: creation needs only the capability; lifting also needs an active or throttled client; a new key for an already active opt-out returns it with 200 **and** is recorded in `global_suppression_requests`; a reaffirming request is audited as `suppression.global_opt_out_reaffirmed`.) | `app/src/Suppression/GlobalSuppressionService.php` |
| A tenant sees through the ORM only its client-scoped suppressions and the global opt-outs it reported (tightened tenant filter). | `app/src/Tenant/TenantFilter.php` |
| Operator commands (`smarthost:client:global-suppressions`, `smarthost:suppression:{list,create,lift}`, `smarthost:dsn:{list,show,match,dismiss}`) require `--operator=<login email>` of an enabled operator, who is the audit actor (and `resolution_requested_by`); capability changes and lifts require a `--note`. | `app/src/Command` |
| A match request Go cannot apply returns the row to `open` with `detail_json.resolution_failures`. | `delivery/internal/store/dsn.go` |
| Phase 1 verification T16 stops the delivery daemon while it observes raw Maildir delivery (the daemon now consumes the spool), runs the claim probe in a throwaway container, and then proves the running daemon ingests a new DSN. | `infra/tests/phase1-verify.sh` |

### Phase 6 implementation choices

Made while implementing Phase 6; recorded in specification 2.6
(`message_tracking.engagement_tracking.endpoints`, `user_interfaces.phase_6_implementation`).
None changes the architecture.

| Choice | Where |
|---|---|
| Tracking recording rule: a request is always answered, but not recorded when the message had an open in the last 60 s (opens), the message and link had a click in the last 10 s (clicks), the message has 1000 events of that type, or the requesting address exceeded 1200 tracking requests per minute (Symfony rate limiter `tracking`). A per-message transaction-scoped advisory lock makes the check-and-insert atomic. Nothing about the requester is stored. | `app/src/Tracking/TrackingRecorder.php`, `config/packages/framework.yaml` |
| Eligibility: well-formed token (base64url, 32–64 characters), message handed to Postfix (`postfix_queue_id` set), job tracking flag for that kind, not older than `APP_RETENTION_TRACKING_DAYS` when set. A text-only message of a tracked job has a token that never appears in any mail (Go instruments only HTML). | `TrackingRecorder` |
| Responses: one 43-byte GIF for every open request; 302 to exactly the stored target (re-checked: absolute http/https, no whitespace/control characters) or the same plain 404; a low-priority `/t/{rest}` fallback answers every other tracking URL with that 404 instead of a router exception, so malformed URLs (with tokens) are never logged as errors. `no-store`, `no-referrer`, no cookies; HEAD not recorded. | `app/src/Controller/TrackingController.php` |
| Token-free logs: the `request` log channel only records warnings (it logs route parameters at info); nginx logs tracking URLs with the token redacted (`map $request_uri`). | `config/packages/monolog.yaml`, `infra/nginx/templates/smarthost.conf.template` |
| Dashboard read models use DBAL with an explicit client condition in every query (the ORM tenant filter only covers API requests); a client the user may not see is a 404 like a missing one. Changes reuse the existing audited services. | `app/src/Dashboard/` |
| Keyset pagination over (whitelisted sort expression, id); a cursor whose values do not fit the sort type restarts at page 1 (a tampered cursor once produced a cast error, found by the tests). | `app/src/Dashboard/KeysetQuery.php` |
| Overview counters come from `send_jobs.summary_counts_json` / `validation_jobs.classification_counts_json` (one row per job); engagement figures are limited to jobs of the last 30 days (client) and rates to jobs of the last 7 days (operator). | `ClientReadModel`, `OperatorReadModel` |
| `message_events_engagement_idx` (partial, opens/clicks): the load test showed the engagement aggregates scanning all of `message_events`; with the index they read only the job's tracking rows (31–59 ms → 10–12 ms at 165,000 events). No other Phase 6 query needed an index; the deferred reconciliation-candidates index stays deferred (no Phase 6 query uses it). | migration `Version20261006000100` |
| The web role may read `delivery_ingest_cursors` (operator overview: Postfix-log ingest freshness); it still cannot write it. | `infra/postgres/grants.sql`, schema.md §6 |
| AssetMapper serves the dashboard assets through PHP-FPM (nginx serves no files); Stimulus is vendored in `app/assets/vendor/`; the es-module-shims CDN polyfill is disabled. CSP with a per-request nonce for the import map. LiveComponent was not needed. | `config/packages/asset_mapper.yaml`, `app/src/Dashboard/SecurityHeadersSubscriber.php` |
| Validation-result export: CSV with exactly the OpenAPI `ValidationAddress` fields, streamed in keyset batches of 500, formula-like cells prefixed with an apostrophe. | `ClientDashboardController::exportValidationResults` |

### Owner instruction: passwordless sign-in, roles and ACL (specification 2.7, 2026-10-06)

The owner instructed that the dashboard use passwordless sign-in (an emailed link, captured by
Mailpit in development) with an `APP_ADMIN_EMAIL` administrator and roles, permissions and an ACL,
adapted from the owner's other Symfony applications. Owner instructions take precedence over earlier
specification rules; the specification was updated to match.

| Choice | Where |
|---|---|
| Single-use links: 256-bit token, SHA-256 stored, 15-minute default lifetime, atomic `UPDATE ... RETURNING` redeem, HEAD never redeems; 5 requests per address and 20 per client address (keyed hash) per 15 minutes; identical answer whether or not a link was sent. | `app/src/Security/LoginLinkService.php`, `LoginLinkAuthenticator.php` |
| The web application sends this one kind of mail itself, through authenticated Postfix submission with its own SASL account (`APP_MAIL_SUBMISSION_*`), so it is DKIM-signed and captured by Mailpit in development. Bulk/campaign mail still never goes through Symfony. | `config/packages/mailer.yaml`, `postfix/entrypoint.sh` |
| `APP_ADMIN_EMAIL` may always request a link; the account is created on first sign-in and ADMIN is (re)granted at every sign-in, recreating the ADMIN row if it was lost. | `app/src/Access/AccessControl.php` |
| Permission keys in code (`PermissionCatalog`), roles in the database (`roles`, `role_permissions`, `user_roles`); ADMIN holds every key without rows; SYSTEM.* only for ADMIN; OPERATOR seeded with every PLATFORM.* key; permissions re-resolved on every request. | `app/src/Access/` |
| `users.password_hash` and `users.global_role` are dropped; existing operators became OPERATOR holders in the migration. | migration `Version20261006000200` |
| Client memberships can be removed (DELETE grant) so the Users page can manage them. | `infra/postgres/grants.sql` |
| The development `SMARTHOST_PUBLIC_BASE_URL` is `https://localhost:8443` (the published nginx port) so emailed links open in a browser; nginx `server_name` follows. | environment contract |

### Phase 7 implementation choices (specification 2.8, 2026-10-06)

Made while implementing the Smarthost side of Phase 7. They are recorded in specification 2.8
(`webhooks.delivery_contract`) and do not change the architecture.

| Choice | Where |
|---|---|
| The request body is PostgreSQL's `jsonb` text of the payload. `payload_hash` is computed in SQL from those same bytes when the delivery row is inserted, and checked again before every send, so a retry signs and sends identical bytes. | `app/src/Webhook/WebhookDispatcher.php` |
| Fan-out uses `INSERT ... ON CONFLICT` on the (event, endpoint) unique index, so concurrent workers never create a second delivery. The event is marked fanned out in the same transaction. | `WebhookDispatcher::fanOut` |
| A claim increments `attempt_count`, which serves as the fencing token. An outcome is written only `WHERE claimed_by = me AND attempt_count = n AND status = 'pending'`. A delivery whose lease expired at the attempt limit is marked failed ("outcome unknown"). The configuration is rejected when the lease is not longer than the request timeout. | `WebhookDispatcher::claim/finish/failExhaustedLeases` |
| A 4xx other than 408/425/429 fails permanently, and so does any 3xx. Neither can be fixed by retrying the same bytes. Endpoints are never disabled automatically, so a misconfigured receiver cannot silently lose later events. | `WebhookDispatcher::outcome` |
| SSRF: the guard resolves A and AAAA records and refuses the target if any answer is non-public. The connection is pinned through the HTTP client's `resolve` option, with `max_redirects` 0. The development allowlist is by host name (`APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS`) and is a startup error in production. A failed lookup is retried; a refused target fails without retry. | `app/src/Webhook/WebhookTargetGuard.php` |
| A batch is sent concurrently through Symfony HttpClient with no database transaction open. At most 64 KiB of each response is read and 1 KiB of it kept. | `WebhookDispatcher::attempt` |
| The worker has its own Doctrine connection and entity manager as `smarthost_webhook`; its container holds no web-role credentials, and the web container holds none of the worker's. Signing-secret decryption moved to `WebhookSecrets`, so the worker does not depend on the web-side endpoint service. Doctrine's entity argument resolver is disabled: it called `getManagerForClass()`, which built every entity manager and made `/dashboard/login` fail in the web container. Phase 6 e2e found this; `WebProcessIsolationTest` is the regression test. | `config/packages/doctrine.yaml`, `app/src/Webhook/WebhookSecrets.php` |
| Wake-up is `LISTEN smarthost_webhook_work` (notified by `/v1/webhooks/test` and the dashboard), with polling every `APP_WEBHOOK_POLL_INTERVAL_SECONDS` as the guarantee. Producers in Go and Python need no change. | `app/src/Command/WebhookWorkCommand.php` |
| Health: a liveness file touched every loop (the container health check requires it to be newer than 120 s) and a `webhook_worker_heartbeats` row per worker for the dashboards. A worker that is gone is shown as stale, never deleted. | `app/docker/webhook-worker-healthcheck.sh`, migration `Version20261007000100` |
| `webhook.test` names exactly one endpoint. `POST /v1/webhooks/test` requires `webhook_endpoint_id`: a foreign endpoint is 404, a disabled one is 409 `webhook-endpoint-disabled`. A missing id fails validation and never fans out. The fan-out re-checks that the endpoint is still enabled. A database check ties `webhook.test` to the `webhook_endpoint` subject. The client-wide variant briefly present during development was removed by owner decision, with no compatibility layer. | `WebhookTestController`, `WebhookTestService`, migration `Version20261007000100` |
| Dashboard webhook management requires client ADMIN (or PLATFORM.CLIENT.MANAGE) and goes through the audited `WebhookEndpointService`. The raw secret is rendered once in the POST response with `no-store`. | `app/src/Controller/Dashboard/ClientWebhookController.php` |
| The end-to-end proof uses a deterministic external receiver (stdlib Python, its own container, no Smarthost code) and an API-only driver. The harness alone uses the console (endpoint registration) and the Phase 5 tool (DSN injection, delivery-state checks). | `tests/webhook-receiver/`, `infra/tests/phase7-e2e.sh` |
| Query-plan review (`WebhookQueryPlanTest`; 180,000 deliveries, 182,000 events). The worker's statements already used the existing indexes: the fan-out pending index, the partial due index and the primary keys. They take at most 6 ms; claiming 50 of 180 due deliveries takes 4–13 ms. The dashboard statements did not: per-endpoint delivery counts and the cross-client delivery and outbox lists scanned and sorted whole tables, taking 190–670 ms. Three indexes were added: `(webhook_endpoint_id, status, created_at)`, `webhook_deliveries (created_at, id)` and `webhook_events (created_at, id)`. Delivered and failed counts are now time-bounded: 7 days per endpoint, 24 hours in the operator summary; pending counts stay total. Every statement now takes ≤ 16 ms with no whole-table scan, and the test asserts this. | migration `Version20261007000200`, `ClientReadModel`, `OperatorReadModel` |

### Phase 7 closeout decisions (owner, specification 2.8, 2026-10-06)

| Decision | Where recorded |
|---|---|
| `POST /v1/webhooks/test` targets exactly one endpoint; `webhook_endpoint_id` is required. | OpenAPI, `webhooks.delivery_contract.fan_out`, `check-contracts.py` |
| No public `/v1` webhook-endpoint management (CRUD). Setup stays in the client dashboard and the audited console; runtime integration uses the public API and received webhooks. | `webhooks.delivery_contract.management` |
| No suppression-lookup API. Smarthost is authoritative for transport suppression and enforces it at send time; clients learn outcomes from their message state and events. Global suppression state is never an address-query oracle. | `suppression...no_address_lookup` |
| Webhook delivery retention: `APP_RETENTION_WEBHOOK_DELIVERIES_DAYS`. Empty means no automatic deletion until a production policy sets a period; no deletion machinery is built now. | `compliance.retention`, environment contract |
| Production `APP_WEBHOOK_*` values are chosen in Phase 8. These are later hardening, not needed for correctness now: a distinct connect timeout, a response-header bound, public-certificate webhook testing and several live worker containers. | `webhooks.delivery_contract.production_settings` |

### Phase 8 implementation choices (specification 2.9, 2026-10-06)

Made while implementing repository-side production readiness. They are recorded in specification
2.9 (`podman_environment.production_topology`, `production_operations`). The development pod
(D-35) is unchanged.

| Choice | Where |
|---|---|
| Production is eight standalone persistent containers on an internal and an egress network, not a pod: pod members share one network namespace, so a pod cannot give components different connectivity. Only Postfix, the validator, the webhook worker and the application join the egress network. (The networking closeout below replaced the published 443/25 ports with a socket-activated ingress and added the ingress network.) | `infra/podman/smarthost-production.sh.in`, `docs/production/README.md` |
| Fixed internal addresses (.10–.17) and `/etc/hosts` entries for the service names. A probe on Podman 6 showed both resolvers answer, but Ubuntu 24.04 ships Podman 4.9 with an older aardvark-dns; `/etc/hosts` is deterministic. The egress network is listed first for dual-homed containers. | production topology |
| Production tooling is a separate command family (`smarthostctl prod`, `infra/bin/smarthostctl-prod`). It requires `SMARTHOST_ENV=production`, and the development commands refuse a production `.env`. The same renderer validates and renders both. `@TOPOLOGY@` points the systemd units at the right script. | `infra/bin/`, `infra/lib/smarthost_render.py` |
| The production template is generated from a *Production profile* table in the environment contract, which stays the only variable list. `prod init-env` generates the secrets on the host. A rehearsal (`SMARTHOST_EGRESS_ENABLED=false`, no Internet at all) may use reserved names; anything with egress may not. | environment contract, renderer |
| Held mode: production with live delivery off has no relayhost, and `retry(8)` is the default transport. Postfix therefore refuses outbound recipients with a temporary 450 at submission (observed in the rehearsal), instead of queueing them. The sign-in sender keeps `smtp`, so operators can sign in before activation. Go claims no send jobs. | `postfix/entrypoint.sh`, `delivery/internal/config` |
| Emergency pause: a durable flag in the observability volume. Postfix (writer) applies `defer_transports=smtp`; Go (read-only) stops claiming and submitting. The pause survives restarts and needs no new database table. | `postfix/control.sh`, `delivery/internal/worker` |
| Installation-wide delivery controls (live-enable/disable, pause/resume) need the new `SYSTEM.DELIVERY.CONTROL` key (ADMIN only, no migration: ADMIN holds every key). They are audited with operator and note *before* acting (`smarthost:ops:record`). | `app/src/Command/OpsRecordCommand.php` |
| Warm-up ceiling `DELIVERY_GLOBAL_RATE_PER_MINUTE`: a hard per-daemon spacing in the pacing limiter, checked with the per-domain limits. Production runs one delivery daemon, so it is installation-wide. The `throttled` client status, which existed but had no effect, now paces that client at `DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE`, refreshed at every lease renewal. | `delivery/internal/pacing` |
| Postfix queue depth for the dashboard: Go reads the newest queue snapshot it already reads for reconciliation and records it in `delivery_heartbeats`, with the delivery state and warm-up ceiling. No web request runs a Postfix command. | migration `Version20261008000100` |
| Submission sender ownership (`reject_sender_login_mismatch`) in every environment. The Phase 1 verification client now uses a VERP envelope sender with the same header From. | `postfix/entrypoint.sh`, `infra/tests/phase1_client.py` |
| Production DKIM keys are generated with `openssl genpkey` (PKCS#8; OpenDKIM signing verified) inside OpenDKIM. Generation never overwrites, a new key does not sign until activated, and the active selector cannot be retired. | `opendkim/dkim-key.sh` |
| Webhooks: a distinct connect timeout through Symfony's standard `max_connect_duration`, and a 16 KiB response-header bound checked on the first chunk (libcurl's own cap is 300 KB). Both fit the existing worker unchanged. | `WebhookDispatcher` |
| The preflight is stdlib Python running on the host, with its own small DNS client and SPF evaluator (no macros, `ptr` not relied on) and `openssl` for certificates, so the host needs no extra packages. Host, DNS and TLS are injectable for tests. | `infra/lib/smarthost_preflight.py` |
| Backups are pg_dump plus volume exports plus the `.env`. Restore drops and recreates the database in the existing topology. Rollback restores the pre-upgrade database, because released migrations are not reversed in place. | `smarthostctl prod backup/restore/upgrade/rollback` |

### Phase 8 networking closeout (owner instruction, specification 2.9, 2026-10-06)

The owner required, for production:
- the real remote address at nginx/Symfony and at Postfix;
- an explicit, verified host setting for the rootless binding of 25 and 443.

The owner also accepted the remaining Phase 8 review items as launch decisions.

| Decision | Where |
|---|---|
| **Rootless low ports:** the host sets `net.ipv4.ip_unprivileged_port_start=25` in `/etc/sysctl.d/60-catto-mail-ports.conf`. It is system-wide: any local user may bind 25–1023, and 1–24 stay privileged. Accepted for a dedicated single-purpose host. `prod preflight --section host` checks the running value against the configured binds and that a `sysctl.d` file persists it. `install`/`create`/`start`/`recreate`/`replace`/`upgrade` refuse to proceed and print the remediation. | runbook §1, `docs/production/README.md` §2.2 |
| **Client addresses: socket activation.** The service user's systemd binds `PROXY_HTTPS_BIND` and `POSTFIX_SMTP_BIND` (`<instance>-ingress.socket`), and `<instance>-ingress.service` starts the persistent nginx container with them (`podman start --attach`, `LISTEN_FDS`, `NGINX=3;4;`). nginx sees every client address. HTTPS reaches Symfony as `REMOTE_ADDR`. SMTP goes to Postfix over a new internal `<instance>-ingress` network (nginx and Postfix only) with the PROXY protocol (`smtpd_upstream_proxy_protocol=haproxy` on `postfix-ingress:25`). No container publishes a port. | `infra/systemd/production/`, `infra/nginx/production/`, `postfix/entrypoint.sh`, README §2.1 |
| Chosen on measured behaviour, on the development machine (Podman 6.0, the engine the rehearsal runs on): published ports (rootlessport) showed two loopback clients as one address (the container's own); pasta did the same for loopback-originated clients. A pasta container cannot also join a Podman network, and host networking would expose nginx to the whole host. Socket activation kept 127.0.0.7/127.0.0.9 distinct, survived an nginx crash (systemd restarts it with the same sockets) and stops cleanly. A restart by Podman alone loses the sockets, so nginx has no Podman restart policy and is started only through its unit. | README §2.1 |
| `TRUSTED_PROXIES` is empty in production (a configuration rule). No proxy stands in front of nginx, so Symfony's client address is `REMOTE_ADDR`, and client-sent `X-Forwarded-For` is ignored. Under the earlier published-port design the client address seen by nginx lay inside the internal subnet that `TRUSTED_PROXIES` had to equal, so forwarded headers from any client would have been trusted. | renderer, environment contract |
| The production nginx HTTPS template is derived at image build time from the development one, with only the `listen` line changed (the build fails otherwise). The development configuration renders byte-identically. | `infra/nginx/Containerfile` |
| Proof: `smarthostctl prod ingress-check` (= `preflight --section ingress`, part of `--activation`). It connects from two random loopback sources through each bind and requires both addresses, distinct, in the nginx access log and the Postfix log. One shared address is a FAIL. Loopback sources prove the general case because no proxy process sits in the path. | `infra/lib/smarthost_preflight.py` |
| **IPv4-only initial production.** IPv6 can be added later as a separate sending identity with its own forward DNS, PTR, SPF authorisation and reputation monitoring. | README §5 |
| **Validator SMTP RCPT probing stays disabled.** Validation runs its other stages. Enabling it later is an explicit operator decision, because it creates outbound SMTP activity from the sending host that can affect its reputation. | README §5 |
| **Retention stays unset:** empty means no automatic deletion, until an operational and compliance decision. No new deletion machinery. | contract, runbook §16 |

### Phase 9 implementation choices (specification 2.10, 2026-10-07)

Made while implementing public SaaS hardening on the repository side. They are recorded in
specification 2.10 (`saas_operations`). The owner's instructions fixed four things:
- extend the existing client model;
- keep public onboarding disabled with no public route;
- choose no billing provider;
- take no automatic action on alerts.

| Choice | Where |
|---|---|
| Lifecycle transitions are a fixed table with a permission per transition: approval and reactivation of a suspended client need `PLATFORM.CLIENT.APPROVE`; throttling and suspension need `PLATFORM.CLIENT.RESTRICT`. Reactivation is the stronger right because it reverses a safety decision. Nothing returns to `pending_approval`; `closed` is final. A reason is mandatory. The rule is checked before the transaction and again under the row lock. | `ClientStatusTransitions`, `ClientLifecycle` |
| The only path that could ever create a public applicant is `ClientLifecycle::apply`, gated by `APP_PUBLIC_ONBOARDING_ENABLED` and fixed to `pending_approval`. No route calls it. | `ClientLifecycle` |
| Policy acceptance stores versions only, never text. New clients require it. Clients created active by an operator, and every client that existed before the migration, are exempt (`policy_acceptance_required = false`), so Phase 9 changes nothing for them. | migration `Version20261009000100` |
| Quotas are per-period counters (`client_quota_usage`), not sums over `usage_records`. A sum cannot be made concurrency-safe without locking the client, while `INSERT … ON CONFLICT DO UPDATE … RETURNING` in the admitting transaction is atomic and rolls back with it. Proven by an 8-process race. Counters measure admitted work (recipients when added); `usage_records` stays the billing truth (units for accepted messages). | `QuotaEnforcer`, `QuotaTest` |
| A refused quota is `429 quota-exceeded`, a new problem type distinct from `rate-limited`, with `Retry-After` and a `quota` member. Clients can tell "slow down" from "wait for the next period". | OpenAPI 1.0.0-draft.8 |
| Count limits (keys, endpoints, domains) are re-checked under `SELECT … FOR UPDATE` on the client row. Rare, so serialising them per client costs nothing. | `ApiKeyManager`, `WebhookEndpointService`, `SendingDomainService` |
| Installation ceilings are environment variables. A client limit can only lower them; volume quotas have no ceiling. | `ClientLimitPolicy` |
| At most one `message_submitted` unit per message (unique partial index). The Go daemon already wrote one unit per accepted message; the index makes double billing impossible. The `(client_id, occurred_at)` usage index now covers `usage_type` and `quantity`: the query-plan review measured period sums as heap-bound (831 ms for the evaluation at 1,000,000 records; 309 ms after the change, index-only). | migration, `Phase9QueryPlanTest` |
| Billing statements freeze quantities of an ended period (draft → finalized → exported, or void). Finalizing reconciles first and refuses stale totals. There are no prices, so any billing provider can consume the export later. | `BillingStatementService` |
| Reputation metrics are recomputed by a periodic evaluation (a production systemd timer every 15 minutes, advisory-locked), not on each page view. Each kind of event counts a message once. Rates need a minimum sample. Alerts are rows updated in place, resolved on recovery and re-opened as new rows; an escalation clears an acknowledgement. | `ReputationEvaluator`, `infra/systemd/production/reputation-evaluate.*` |
| Production per-instance units moved from `infra/systemd/ingress/` to `infra/systemd/production/`. The machine helper installs every `<instance>-*` unit (`instance-install`). | `smarthost-machine-helper.sh` |
| The public API documentation is served by Symfony at `/docs/api`, from the same files as the contract, without authentication or session. No documentation generator was added. | `ApiDocumentationController` |
| Console commands that change Phase 9 state name the acting operator (`--operator`) and check the same permission keys as the dashboard. `smarthost:client:set-status` now requires `--operator` and `--note` (incompatible with Phase 8 usage; `prod client-status` passes them through). | `app/src/Command/` |

### Business decisions left to the owner (Phase 9)

None of these blocks the repository work; the defaults are safe.

- **Plans, prices and quotas:** which limits each plan gets. The schema holds limits per client;
  plans are only a label (`clients.plan`).
- **Billing system:** which system consumes the usage export and statements, and how payment and
  dunning work.
- **Service-policy text and version:** what clients accept. `APP_ACCEPTABLE_USE_POLICY_VERSION`
  is empty until then, so approval needs no acceptance.
- **Thresholds:** the `APP_REPUTATION_*` defaults are conservative starting points, to be
  calibrated on live traffic.
- **Retention:** of usage records, statements, alerts and notes (unset like the rest; no
  deletion).
- **Opening public onboarding:** the criteria are in `docs/production/onboarding.md`.

### Operator self-service (owner instruction, specification 2.11, 2026-10-07)

| Choice | Where |
|---|---|
| **The web application never runs host commands.** A small host agent (stdlib Python, a systemd user service of the service user) does host-side checks and carries out recorded requests through the existing production CLI. The web application records each request, checked and audited; the agent claims it through a console command. The privilege boundary is unchanged: the web role still cannot reach the host. | `infra/lib/smarthost_agent.py`, `system_requests` |
| **The emergency stop is a database flag the delivery daemon reads** (with the existing pause flag file, every few seconds), so the web button stops submissions immediately without host access. The Postfix queue hold follows through the agent within a minute. `prod pause` sets both. | `delivery_controls`, `worker.checkPause` |
| Bootstrap sign-in is a host-printed single-use link (ADMIN-only, ≤ 15 minutes, audited), not a password or a setup token in the web: whoever can run it already holds every secret. | `LoginLinkService::issueHostLink` |
| The installer is one bash script at the repository root, from Ubuntu packages only, installing a release tag. It generates but does not apply the firewall unless asked, so it cannot lock the operator out. Certificates: Let's Encrypt when configured, otherwise a self-signed bootstrap certificate (the TLS checks fail until it is replaced, so live activation is refused). | `install-catto-mail` |
| Let's Encrypt uses DNS-01 with Ubuntu's `lego` (many DNS providers through one credentials file, and a manual mode), never port 80. Credentials stay outside `infra/.env` and are read as `NAME=value` lines, never executed. | `prod tls acme` |
| Backups: encryption with `openssl enc -aes-256-cbc -pbkdf2` and a passphrase file (no new dependency); off-host copies with rsync to any SSH target or mounted path (no provider chosen); the restore rehearsal uses a temporary database in the same PostgreSQL. Only paths the tooling created (timestamp names, `restore.XXXXXX`) are ever removed. | `smarthostctl-prod` |
| Check history keeps requested runs, changes of result and the first result per check and day, so the history stays readable at hourly runs. | `SystemChecks` |
| Address batches reuse ordinary validation jobs and send jobs of a chosen client, so quotas, sending-domain rules and the delivery daemon's suppression check apply unchanged. No single address "status": five derived dimensions. | `app/src/AddressBatch/` |
| **Re-permission answers are stored as evidence on the batch entry and forwarded to the client** (`repermission.responded`). An unsubscribe from the list remains the client's state and never a suppression (D-30); a global opt-out becomes the existing `recipient_global_opt_out` suppression reported for the batch's client. The page answers only on POST (link scanners GET). | `RepermissionService` |
| The operator-facing validation result maps the validator's classification without new classes: deliverable and probably deliverable are *valid*; *temporarily unverifiable* is *temporary failure*. Flags come from the stored evidence. | `EntryStates` |
| ctnlist is not modified from this repository; the outstanding ctnlist work is specified for its own maintainers. | `docs/integration/ctnlist.md` |
| **GitHub Releases (owner decision, v0.1.9):** each release gets a release page with its CHANGELOG notes and the source as `catto-mail-vX.Y.Z.zip`/`.tar.gz` (made with `git archive` from the tag) plus `SHA256SUMS`, next to GitHub's automatic archives. The installer and upgrades keep using the immutable Git tag. | `VPS-INSTALL.md` §12 |

### Development on a local Linux engine (owner instruction, v0.2.0, 2026-10-08)

The specification is unchanged: it already allows a Linux host or a WSL Podman machine for
development and requires Podman 4.9 or later.

| Choice | Where |
|---|---|
| **The development minimum is Podman 4.9, the specification's own minimum** (owner instruction). The README and the development guide had said 5.1. | `README.md`, `docs/development-environment.md` §1 |
| Podman 4.9 can report `cgroup: Unit user-libpod_pod_<id>.slice not loaded` after a pod stop or removal that worked, and always does for a pod that is already stopped. The pod script judges the result instead: a stop succeeds when nothing runs, a removal when the pod is gone. Volumes are never involved. | `infra/podman/smarthost-pod.sh.in` |
| aardvark-dns 1.4 (Ubuntu 24.04's Podman 4.9) forwards public names from the `Internal=true` network and briefly fails alias lookups while containers join or leave it. The development topology is not changed for this: the network still has no route out, and steady-state use is unaffected. Verify T06 and T13 are documented as expected failures on such engines. | `docs/development-environment.md` §1 |
| The pinned PostgreSQL and Mailpit images are pulled once per engine by hand (documented), because the pod creates containers with `--pull never`; `smarthostctl build` does not fetch them. | `docs/development-environment.md` §1 |
| The owner asked for release "v0.2". Release tags are `vX.Y.Z` (the installer accepts nothing else), so it is `v0.2.0`. | `CHANGELOG.md` |

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

None. D-31 to D-34 were resolved on 2026-10-03, D-35 and D-30 on 2026-10-04, D-36 to D-38 on
2026-10-05 (see §1).

Points to confirm with live traffic (not blocking):
- a CA file variable so Go can verify Postfix's submission certificate;
- an index for reconciliation candidates at production volume (no query-plan review up to Phase
  9 found a query that needs it);
- an index for the per-client usage reconciliation of accepted messages, if a client's monthly
  volume makes it slow (458 ms at 60,000 messages among 260,000 in the Phase 9 review);
- an index on `suppressions (source_event_id)` if a future retention job deletes message events
  (production never deletes them today);
- retention commands for the `APP_RETENTION_*` periods (empty, so no deletion, until the
  compliance policy sets them; tracking expiry is already enforced by the endpoints);
- an index on `suppressions (created_at, id)` if the unfiltered operator suppression list grows
  large (a sorted scan, 33 ms at 20,000 rows in the Phase 6 load test);
- whether browser-based validation upload is wanted (the API covers it).

Resolved since: the Postfix queue depth is in `delivery_heartbeats` (Phase 8); the production
webhook settings are in the production profile (Phase 8); API keys are managed in both
dashboards (Phase 9). The Phase 9 business decisions are listed in §2 (*Business decisions left
to the owner*).
