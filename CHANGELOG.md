# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Project versions are independent of
the *specification* version, which is 2.8.

## [0.1.7] - 2026-10-06

Phase 7, Smarthost side (specification 2.8): the real webhook worker and its delivery contract, a
single-endpoint `POST /v1/webhooks/test`, and dashboard webhook management. The client workflow is
proven with a deterministic external test client. Integrating the real first client application,
in its own repository, is outstanding. Validator version 0.1.7.

### Added
- **Webhook worker** (`smarthost:webhook:work`, container `smarthost-webhook-worker`). It replaces
  the Phase 1 placeholder (`app/phase1-probe/webhook-worker.php`, removed).
  - Fans out the transactional outbox to the client's enabled, subscribed endpoints; one delivery
    per (event, endpoint).
  - Signs requests: `Smarthost-Signature: t=..,v1=..` is HMAC-SHA256 over `<t>.<body>`, with a
    second `v1` during a rotation overlap. Also sends `Smarthost-Event-Id`, `-Event-Type`,
    `-Delivery-Id`, `-Delivery-Attempt` and `User-Agent: Catto-Mail-Smarthost/0.1.6`.
  - Claims with `FOR UPDATE SKIP LOCKED` and leases, with the attempt count as the fencing token.
    Requests are sent concurrently with no transaction open; at-least-once.
  - Retries: 408/425/429/5xx and transport errors retry with exponential backoff, ±10% jitter and
    `Retry-After`. Other 4xx and 3xx fail permanently; endpoints are never disabled automatically.
  - SSRF: non-public destinations are refused (private, loopback, link-local/metadata, CGNAT,
    multicast, reserved, documentation, ULA, IPv4-embedded). The checked address is pinned and
    redirects are never followed. `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS` is for development fixtures
    only and is a startup error in production.
  - Limits: request timeout; at most 64 KiB of the response is read and 1 KiB kept.
  - Runtime: `LISTEN smarthost_webhook_work` plus polling; SIGTERM stops new claims; a liveness
    health check; `webhook_worker_heartbeats`. Logs carry `service: webhook-worker`.
- `POST /v1/webhooks/test` records a `webhook.test` event for exactly one endpoint:
  `webhook_endpoint_id` is required. It returns 202 with `webhook_event_id` and never calls the
  endpoint itself. A foreign endpoint answers 404, a disabled one 409 `webhook-endpoint-disabled`,
  and a missing id fails validation without fanning out.
- **Client dashboard → Webhooks** (client admins): create, edit and enable/disable endpoints, choose
  their events, rotate the secret (shown once, `no-store`), send a test event, and browse deliveries
  with status filters. **Operator → Webhooks**: deliveries (pending/retrying/delivered/failed,
  client, endpoint and event filters, last response and error), outbox events and worker
  heartbeats. The overview card shows retrying and failed deliveries and live workers.
- Migration `Version20261007000100`:
  - `webhook_deliveries`: `updated_at`, `last_attempt_at`, `last_response_excerpt`, plus claim
    consistency checks;
  - a check tying `webhook.test` to the single-endpoint `webhook_endpoint` subject;
  - the `webhook_worker_heartbeats` table.
- Migration `Version20261007000200` adds the indexes `webhook_deliveries (webhook_endpoint_id, status,
  created_at)`, `webhook_deliveries (created_at, id)` and `webhook_events (created_at, id)`. The
  query-plan review (`WebhookQueryPlanTest`, 180,000 deliveries) showed the webhook dashboard
  statements taking 190–670 ms on whole-table scans; they now take ≤ 16 ms. The worker statements
  already used indexes, at ≤ 13 ms. Dashboard delivered and failed counts are now time-bounded:
  7 days per endpoint and 24 hours in the operator summary.
- Tests:
  - `WebhookWorkerTest`: contract body and signature, fan-out rules, permanent and retryable
    responses, retry then success or exhaustion, crash reclaim and fencing, concurrent fan-out,
    rotation overlap, SSRF, development allowlist, oversized response, disabled endpoint, message
    payloads, test events through the API;
  - `WebhookDashboardTest`, `WebhookQueryPlanTest` and `WebProcessIsolationTest`;
  - an external receiver fixture (`tests/webhook-receiver/`, with unit tests);
  - `smarthostctl test phase7-e2e`.

### Changed
- Specification 2.8 (`webhooks.delivery_contract`); OpenAPI 1.0.0-draft.7.
- Decisions recorded (specification 2.8):
  - no public webhook-endpoint management API and no suppression-lookup API;
  - `APP_RETENTION_WEBHOOK_DELIVERIES_DAYS` (empty: no automatic deletion);
  - production `APP_WEBHOOK_*` values are deferred to Phase 8.
- The Symfony image adds `symfony/http-client`. The worker uses its own Doctrine connection as
  `smarthost_webhook`, and signing-secret decryption moved to `App\Webhook\WebhookSecrets`.
  Doctrine's entity argument resolver is disabled (controllers take ids), so the web process never
  builds the worker-only entity manager (`WebProcessIsolationTest`).
- README, CHANGELOG and phase6-e2e wording cleaned up for specification 2.7 (no "form login").

## [0.1.6] - 2026-10-06

Phase 6 complete: tracking and dashboards (specification 2.6), with passwordless dashboard sign-in,
roles, permissions and an ACL (specification 2.7), and the Phase 5 corrections (specification 2.5,
decisions D-36 to D-38).

### Changed (specification 2.7, by owner instruction)
- Dashboard sign-in is passwordless: `/dashboard/login` asks for an email address and emails a
  single-use link (256-bit token, only its SHA-256 stored, `APP_LOGIN_LINK_TTL_SECONDS` lifetime,
  atomic redeem, HEAD never redeems; 5 requests per address and 20 per client address per 15
  minutes; the same answer whether or not a link was sent; the link is built from
  `SMARTHOST_PUBLIC_BASE_URL`). Form login, password hashing, `smarthost:user:set-password` and
  `--password-stdin` are gone; `users.password_hash` is dropped.
- The web application sends the sign-in email itself through authenticated Postfix submission with
  its own SASL account (Symfony Mailer); Postfix creates both SASL accounts; OpenDKIM signs; Mailpit
  captures it in development.
- `APP_ADMIN_EMAIL` can always request a link; the account is created on first sign-in and receives
  ADMIN (every permission) at every sign-in.
- Roles, permissions and ACL: permission keys (`App\Access\PermissionCatalog`), tables `roles`,
  `role_permissions`, `user_roles`, `auth_login_tokens` (migration `Version20261006000200`); built-in
  ADMIN (fixed, every key, SYSTEM.* only for it) and OPERATOR (every PLATFORM.* key, editable);
  custom roles. `users.global_role` and the `global_role` vocabulary are replaced by OPERATOR
  (vocabulary 2.4.0); operator pages require permission keys; `ClientVoter` grants every client to
  `PLATFORM.CLIENT.VIEW`/`MANAGE`. `smarthost:user:set-operator` is replaced by
  `smarthost:user:role <email> <ROLE> grant|revoke`; `smarthost:user:create --role`.
- New operator pages: Users (create, enable/disable, email a sign-in link, roles, add/change/remove
  client memberships; `smarthost_app` may now delete memberships) and Roles & permissions (ADMIN).
- New settings `APP_ADMIN_EMAIL`, `APP_MAIL_FROM`, `APP_LOGIN_LINK_TTL_SECONDS`,
  `APP_MAIL_SUBMISSION_HOST`/`PORT`/`USERNAME`/`PASSWORD`; the development `SMARTHOST_PUBLIC_BASE_URL`
  is `https://localhost:8443` and `PROXY_SERVER_NAME` `localhost`, so emailed links open in a browser.
- Tests: `DashboardUserTest` (rewritten for links), `AccessControlTest`; the dashboard test helpers
  sign in through the real emailed link; `phase6-e2e` takes the link from Mailpit (DKIM-signed,
  single use, admin bootstrap, ACL). The schema catalog comparison uses visible column order.

### Verified (v0.1.6, final)
- `smarthostctl test phase2`: 225 tests, 3,306 assertions; `test phase3`: Ruff and mypy clean,
  295 pytest tests, end to end PASS; `test phase4` (Go 4/5 suite): gofmt and go vet clean, 14 unit
  packages, 29 integration tests.
- `test phase6-e2e` 75/75 (sign-in links from Mailpit), `test phase4-e2e` 21/21 (incl. 10,000
  recipients), `test phase5-e2e` 50/50 against the rebuilt, recreated pod; `smarthostctl verify`
  176/176 (persistent lifecycle T19–T23, neighbours untouched); dashboard and Mailpit reachable from
  the Windows host.
- Contract checks 1966/1966; OpenAPI 3.1 valid; yamllint clean; shellcheck clean at warning;
  `composer validate --strict`; privacy and secrets scans clean.

### Added (Phase 6)
- Public tracking endpoints `GET /t/o/{token}.gif` and `GET /t/c/{token}/{link_index}` on the
  opaque Go tokens (`App\Tracking\TrackingRecorder`, `App\Controller\TrackingController`). A token
  must be well formed, name a message handed to Postfix whose job enables that tracking kind and be
  within `APP_RETENTION_TRACKING_DAYS` (now consumed; empty = no expiry). Opens always get the same
  43-byte GIF; clicks get a 302 to exactly the stored `message_links` target (re-checked as an
  absolute http/https URL without control characters) or the same plain 404; a `/t/...` fallback
  route answers every other tracking URL with that 404 instead of a logged routing error.
  `no-store`, `no-referrer`, no cookies, HEAD never recorded, nothing about the requester stored.
- Bounded recording rule: a request is answered but not recorded within 60 s of the message's last
  recorded open (opens) or 10 s of the message and link's last recorded click (clicks), beyond 1000
  events of a type per message, or above 1200 tracking requests per minute from one address
  (`tracking` rate limiter); a per-message advisory lock makes it atomic.
- Client dashboard under `/dashboard/c/{client}`: overview, validation jobs and job results
  (filters, keyset pagination, sorting, CSV export with exactly the API's `ValidationAddress`
  fields, streamed in batches), send jobs and job detail (transport outcomes, recorded engagement,
  clicks per link, messages), message event timeline, suppressions (own rows and reported
  opt-outs; this client's suppressed messages with only the broad reason of a global suppression),
  sending domains (DNS check through `SendingDomainService::verify`), usage. A client the user may not
  see is a 404; every query carries the client id.
- Operator dashboard under `/dashboard/operator` (each page guarded by a permission key since specification 2.7): system overview from durable
  database signals (queues, leases, newest results, Postfix-log ingest cursor, messages per status,
  per-client rates over 7 days, suppressions, unmatched DSNs, webhook outbox), clients (status and
  opt-out capability through the audited services), the unmatched-DSN workflow (match request and
  dismissal through `UnmatchedDsnAdministration`; Go applies matches), suppressions (operator
  provenance, operator blocks, lifting with a required note), audit log (filters; secret-like keys
  redacted on display), webhook outbox (labelled as outbox state until Phase 7).
- Twig templates, AssetMapper (served through PHP-FPM; Stimulus vendored, no CDN, es-module-shims
  polyfill disabled), Symfony UX StimulusBundle with one `confirm` controller; `symfony/twig-bundle`,
  `symfony/asset`, `symfony/asset-mapper`, `symfony/stimulus-bundle`. Generic production error page.
- Dashboard HTTP security: CSP with a per-request nonce for the import map, `frame-ancestors 'none'`,
  `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, `no-store`; every change a POST
  with a CSRF token; sign-out is a POST with a CSRF token (GET no longer signs out).
- Migration `Version20261006000100`: partial `message_events_engagement_idx` (opens/clicks), added
  because the load test showed the engagement aggregates scanning all of `message_events`.
- Grant: `smarthost_app` may read (not write) `delivery_ingest_cursors` (schema.md §6 footnote 4).
- Tests: `TrackingTest`, `TrackingStatisticsTest`, `ClientDashboardTest`, `OperatorDashboardTest`,
  `DashboardLargeDatasetTest` (10,000-address and 10,000-message jobs with 45,000 events plus other
  tenants' data; query counts, memory and EXPLAIN ANALYZE of every page query, reported in
  `infra/.generated/test-output/`), the test-only `QueryRecorder` DBAL middleware; Go
  `TestTextOnlyMessageOfTrackedJobIsNotInstrumented`; `infra/tests/phase6-e2e.sh` and
  `smarthostctl test phase6-e2e`.

### Changed (Phase 6)
- Specification 2.6 (YAML and human specification): tracking endpoint eligibility, responses,
  recording rule, aggregates and privacy; the Phase 6 dashboards' scope, access model, pagination
  and HTTP security; what is not in Phase 6 (browser upload, API-key/webhook management, Postfix
  queue depth in the web UI, LiveComponent). Contract documents re-labelled 2.6; OpenAPI routes
  unchanged.
- The `request` log channel records only warnings (it logged route parameters, i.e. tracking
  tokens, at info), in tests too; nginx writes tracking URLs to its access log with the token
  redacted.
- The minimal inline-HTML login page is replaced by the Twig dashboard; the dashboard home sends
  operators to the operator overview and single-client users to their client.
- `infra/tests/testpod.sh` accepts extra application-container arguments; `phase2-test.sh` mounts
  `infra/.generated/test-output/`.

### Verified (Phase 6, before specification 2.7)
- `smarthostctl test phase2`: 216 tests, 2,796 assertions; `test phase3`: Ruff and mypy clean,
  295 pytest tests, end to end PASS; `test phase4` (Go 4/5 suite): gofmt and go vet clean, 14 unit
  packages, 29 integration tests.
- `test phase6-e2e` 68/68, `test phase4-e2e` 21/21, `test phase5-e2e` 50/50 against the rebuilt,
  recreated pod; `smarthostctl verify` 176/176 (persistent lifecycle T19–T23, neighbours untouched).
- Load test (measured client 10,000 addresses / 10,000 messages / 45,000 events, plus 30,000
  addresses, 30,000 messages and 120,000 events of another client, 20,000 suppressions, 20,000 audit
  rows): at most 13 queries and 1.2 MiB of PHP memory per page, slowest statement 33 ms; the
  10,000-row CSV export in 24 bounded queries.
- Contract checks 1915/1915; OpenAPI 3.1 valid; yamllint clean; shellcheck clean at warning;
  `composer validate --strict`; privacy and secrets scans clean.

### Changed (Phase 5 corrections, specification 2.5)
- D-36: inbound DSNs and complaints are correlated automatically only through Smarthost-issued
  identifiers (VERP token, envelope id, Smarthost Message-ID, Postfix queue id). Recipient address
  plus a matching returned sender no longer correlates (with global suppressions it is forgeable);
  such reports stay open unmatched DSNs whose recent messages to the recipient are stored as
  operator candidates (`detail_json.candidates`, with `sender_matches_returned_from`).
- D-37: a client holding `can_submit_global_suppressions` may create a recipient global opt-out
  while `pending_approval` or `suspended` (a do-not-contact operation, not work creation); lifting
  requires an `active` or `throttled` client. Closed clients still fail authentication.
- D-38: durable opt-out request idempotency. New table `global_suppression_requests` (migration
  `Version20261005000100`, backfilled from existing opt-outs) maps (source client, operation,
  Idempotency-Key) to the canonical request hash, the resulting suppression and the original status;
  the per-row `suppressions.idempotency_key`/`request_hash` columns are removed. A key that found an
  active opt-out (200) is now recorded, so retrying it after a lift replays its result instead of
  creating a suppression; such a request is audited as `suppression.global_opt_out_reaffirmed`.
  Vocabulary 2.3.0 (`global_suppression_request_operation`), OpenAPI 1.0.0-draft.6, grant matrix
  (`smarthost_app`: S I D), tenant filter.
- Unchanged: only confidently correlated ARF `abuse` reports are complaints; the D-30 global policy.

## [0.1.5] - 2026-10-05

Phase 5 complete: inbound DSN, complaint and global suppression processing (D-30, specification
2.4).

### Added
- Specification 2.4 resolves D-30: automatic transport suppressions are global (`client_id`
  NULL) and apply to every client. They are created only for a recipient-specific hard bounce
  (`failure_scope` recipient), a correlated ARF complaint, and repeated recipient soft bounces
  (evaluated across all clients, counted once per message; a temporary suppression that expires
  after `DELIVERY_SOFT_BOUNCE_SUPPRESSION_WINDOW_DAYS`). Provider-policy, reputation, domain, DNS,
  connection, TLS, infrastructure, ambiguous and `outcome_unknown` outcomes never suppress.
- Recipient global opt-out: the `recipient_global_opt_out` reason and `POST /v1/global-suppressions`,
  `GET /v1/global-suppressions/{id}` and `POST /v1/global-suppressions/{id}/lift` for clients an
  operator granted `can_submit_global_suppressions` (default false; 403 otherwise). Address-only,
  normalised (D-18/D-32), idempotent and concurrency safe; the reporter is kept in
  `source_client_id`; creation and lifting are audited; lifting never overrides an independent
  suppression. Ordinary unsubscribes remain client state.
- Migration `Version20261004000100`: `clients.can_submit_global_suppressions`; suppression
  provenance (`source_event_id`, `source_client_id`, `external_reference`, `idempotency_key`,
  `request_hash`), constraints and partial unique indexes; `messages_recipient_address_idx`; a
  dismissed unmatched DSN requires a written reason. Reference schema and `schema.md` updated; no
  grant change.
- Go: the DSN spool processor (atomic claim by rename, inotify with polling fallback, reclaim of
  stale claims, `failed/` for unreadable files, retention of `done/` for `DELIVERY_DSN_RETENTION_DAYS`);
  an RFC 3464/6533 DSN and RFC 5965 ARF parser with bounds; correlation by VERP token, ENVID,
  Smarthost Message-ID, Postfix queue id and corroborated recipient (never by address alone);
  conservative classification; `dsn_unmatched` for unreconcilable reports; `unmatched_dsns` rows
  with raw and parsed evidence; the operator match-request resolver
  (`NOTIFY smarthost_unmatched_dsn_work`); `message.complained` in the outbox; the global
  suppression policy applied to every newly appended authoritative event, whatever its source;
  a suppression re-check immediately before each submission.
- Symfony console commands: `smarthost:client:global-suppressions`, `smarthost:suppression:list`,
  `create`, `lift`, `smarthost:dsn:list`, `show`, `match`, `dismiss` (operator identity required,
  audited). Development bootstrap enables the capability for the disposable development client only.
- Tests: `smarthostctl test phase5` (alias of the Go suite: parser fixtures, classifier, spool,
  14 Phase 5 PostgreSQL integration tests) and `smarthostctl test phase5-e2e` (DSNs and ARF reports
  through Postfix port 25 and the real spool, cross-client suppression, opt-out API, correlation,
  operator workflow, crash and retention). PHPUnit: `GlobalSuppressionApiTest`,
  `Phase5OperatorCommandTest`, schema constraint tests.

### Changed
- One shared failure-scope classifier (`delivery/internal/smtpclass`) for log and DSN evidence; it
  is stricter (e.g. x.1.2 is `domain`, x.4.7 expiry is `unknown`, policy wording wins).
- Vocabulary 2.2.0: `deferred` and `connection_failure` may come from `dsn_spool` and
  `unmatched_dsn_resolution`; OpenAPI 1.0.0-draft.5.
- The tenant filter shows a client only its client-scoped suppressions and the opt-outs it reported.
- Phase 1 verification T16 stops the delivery daemon while it observes raw Maildir delivery, then
  proves the running daemon ingests a new DSN.
- `smarthostctl test` targets `phase5` (the Go suite) and `phase5-e2e`.

### Fixed
- `phase4-e2e.sh` B: the log check no longer fails spuriously under `pipefail` (SIGPIPE).
- Stale documentation: the Postfix and OpenDKIM READMEs still said "Empty until Phase 1"; the app
  README listed send usage metering (done by Go since Phase 4) as later work; test counts, versions
  and status lines across README, `CLAUDE.md` and `docs/`.

### Verified
- `smarthostctl test phase5` (Go): gofmt and go vet clean; unit tests in 13 packages pass (DSN/ARF
  fixtures, classifier, spool, D-32 vectors); 28 PostgreSQL integration tests pass as
  `smarthost_delivery` (14 Phase 4, 14 Phase 5: cross-client hard bounce, every correlation level,
  excluded scopes from log and DSN, repeated soft bounces with reset and relay-accepted-then-bounced
  messages, concurrent evidence, complaints, late events, crash/reclaim/duplicate idempotency,
  retention, operator resolution, every suppression reason, suppression after staging).
- `smarthostctl test phase5-e2e`: 44/44 through Postfix port 25 and the real DSN spool (A–G).
- `smarthostctl test phase4-e2e`: 21/21 (the 10,000-recipient job, now with the per-message
  pre-submission suppression check, completed in 185 s; peak RSS 26.6 MiB; at most 20 concurrent
  submissions, 2 per domain).
- `smarthostctl test phase2`: 177 tests, 1,838 assertions. `smarthostctl test phase3`: Ruff and
  mypy clean, 295 pytest tests and the 10,000-address run pass.
- `smarthostctl verify`: 176/176 (v0.1.4's 180 with `--clean` minus the five T03 clean-state
  checks, plus the new T16 check that the running daemon ingests a DSN), including the lifecycle
  groups T19–T23 through systemd. A first run failed one T20 check because the Windows WSL service
  transiently refused the `wsl.exe` call that runs `systemctl stop`; the re-run passed.
- Contract checks 1898/1898; OpenAPI 3.1 valid; yamllint clean; shellcheck clean at warning
  severity; `composer validate --strict` and `composer check-platform-reqs` pass.

## [0.1.4] - 2026-10-04

Phase 4 complete: Go/Postfix delivery pipeline. Also the persistent development pod lifecycle
(D-35, specification 2.3) and Python static checks.

### Added
- `delivery/`: the Go 1.26 delivery daemon (`smarthost-delivery run`) replaces the Phase 1 probe.
  It leases sealed send jobs (fenced writes, renewal, reclaim, D-31 client status), creates exactly
  one message per staged recipient (UUIDv7, 128-bit VERP token and return path, 192-bit tracking
  token), honours existing suppressions, builds MIME from the rendered content under the header
  contract (RFC 2047, header-injection rejection, RFC 8058 `List-Unsubscribe`/`List-Id` for
  subscription mail), instruments HTML (open pixel, click rewriting via `message_links`; never
  plain text, `mailto:`, fragments, other schemes or the unsubscribe URL) and submits one message
  per transaction to Postfix 587 (STARTTLS, SASL, `RET`/`ENVID`/`NOTIFY`/`ORCPT`).
- One transaction records a Postfix acceptance: queue id, `submitted_to_postfix`, content purge and
  one `message_submitted` usage unit. Temporary failures (including OpenDKIM tempfail) keep the
  content and retry with bounded backoff; permanent refusals are `submission_failed`; ambiguous
  submissions and reclaimed jobs are resolved from the Postfix log before any resubmission.
- Postfix log ingestion with the persistent `delivery_ingest_cursors` cursor (first-record
  fingerprint generations, `.gz` rotation, complete records only, hold rule), append-only events with
  rank-based projection, `send_jobs.summary_counts_json`, dispatch/completion with `send.completed`
  (and `message.hard_bounced`) in the transactional outbox; D-27 reconciliation against fresh queue
  snapshots producing `transport_outcome_unknown`; global/per-domain concurrency, per-domain rate and
  deferral back-off.
- D-32 in Go, passing the shared 87 vectors (PHP, Python and Go agree).
- Tests: `smarthostctl test phase4` (gofmt, go vet, Go unit tests, PostgreSQL integration tests as
  `smarthost_delivery` in a throwaway pod) and `smarthostctl test phase4-e2e` (the running pod's
  Postfix, OpenDKIM and Mailpit: headers and DKIM, OpenDKIM down, deferral, SIGKILL/reclaim,
  10,000 recipients with a log rotation).
- Persistent pod lifecycle (D-35, spec 2.3): `infra/podman/smarthost-pod.sh.in` defines the
  network, volumes, pod and containers once; `smarthostctl create`, `start`, `stop`, `restart`
  keep the same objects; `recreate` replaces pod and containers (volumes kept); `remove`;
  `smarthost.service` starts the existing pod at boot and never removes it. Phase 1 verification
  T19–T23 prove ID persistence across restart, stop/start, `podman pod stop/start` and the boot path,
  and replacement by `recreate` with data intact.
- Python static checks for the validator: Ruff and mypy (`validator/pyproject.toml`), run by
  `smarthostctl test phase3`; `.gitignore` excludes Python bytecode and tool caches.

### Changed
- Specification 2.3 (D-35) replaces the Quadlet `.pod`/`.container` runtime, which removed the pod and
  containers on every stop. The DB bootstrap, migrations and grants are ordered tasks of `start`.
- `POSTFIX_QUEUE_SNAPSHOT_INTERVAL_SECONDS` is also consumed by delivery (snapshot freshness).
- Symfony's send-job submit sends `NOTIFY smarthost_send_work`.
- The webhook worker container stops with SIGTERM and fake SMTP runs with `--init`, so a pod stop no
  longer waits 30 s for SIGKILL.

### Fixed
- Validator typing gaps found by mypy (no behaviour change).

### Verified
- `smarthostctl test phase4`: gofmt and go vet clean; Go unit tests in 10 packages and 14
  PostgreSQL integration tests (as `smarthost_delivery`) pass. D-32: all 87 shared vectors pass in
  Go, as in PHP and Python.
- `smarthostctl test phase4-e2e`: A (headers, VERP, tracking, DKIM verified with dkimpy, events,
  purge, usage, completion), B (OpenDKIM down: no unsigned mail, no usage, retry then signed
  delivery), C (deferral then delivery), D (SIGKILL after 267 of 600 submissions; reclaim; every
  message reached Postfix exactly once), E (10,000 recipients over 50 domains completed in 124 s
  with a log rotation; peak RSS 24.8 MiB; at most 20 concurrent submissions, 2 per domain).
- `smarthostctl verify --clean`: 180/180 checks, including the lifecycle groups T19–T23.
- `smarthostctl test phase3`: Ruff and mypy clean; 295 pytest tests and the 10,000-address run pass
  (no DATA). `smarthostctl test phase2`: 163 tests, 1,578 assertions.
- Contract checks 1870/1870; OpenAPI 3.1 valid; yamllint clean; shellcheck clean at warning
  severity; `composer validate --strict` and `composer check-platform-reqs` pass.

## [0.1.3] - 2026-10-04

Phase 3 complete: Python validation engine.

### Added
- `validator/`: the asynchronous Python 3.14 validation worker replaces the Phase 1 probe. It claims
  addresses under leases (`FOR UPDATE SKIP LOCKED`, renewal, fencing, reclaim), and runs D-32
  normalisation, syntax analysis, typo suggestions, DNS/MX/Null-MX/fallback analysis, disposable and
  role flags, SMTP RCPT probing (never `DATA`), accept-all detection and a deterministic classifier.
  It records evidence, maintains job counters and status, writes `validation.completed` /
  `validation.failed` to the transactional `webhook_events` outbox (no HTTP delivery), and meters
  usage per D-33. Global, per-domain and per-MX concurrency limits, provider back-off and bounded
  exponential retries are enforced. Runtime dependencies: psycopg 3.3.6, psycopg-pool 3.3.3,
  dnspython 2.8.0, idna 3.20.
- `docs/contracts/address-normalization-vectors.json`: 87 shared D-32 vectors, tested by PHP and
  Python (and by Go from Phase 4).
- `App\Api\WorkPermission` (D-31) and `ClientStatusTest`.
- Phase 3 test harness (`infra/tests/phase3-test.sh`, `smarthostctl test phase3`) with a
  deterministic fake DNS server, extended fake SMTP scenarios (accept-all, probe blocking,
  throttling) and a command log; `infra/tests/testpod.sh` is the throwaway test pod shared with
  Phase 2.
- `smarthost.target` starts at boot (linked into `default.target.wants` by `smarthostctl install`),
  so the `smarthost` pod comes up whenever the Podman machine starts.

### Changed
- Specification 2.2 incorporates D-31 (work-creating operations are 403 for `pending_approval` and
  `suspended` clients; workers claim only active/throttled clients' work), D-32 (exact
  normalisation rule and shared vectors), D-33 (validation usage metered by the validator per
  address that reaches `done`) and D-34 (idempotent-replay semantics without stored response
  bodies). OpenAPI `1.0.0-draft.4` declares the new 403 responses. The decisions are marked
  resolved in the decision log, which also lists the Phase 3 implementation choices.
- Validation-job creation wakes the validator with `pg_notify`; the validator also polls.
- `app/composer.json` requires `ext-intl` (used by `AddressNormalizer`); both app image stages run
  `composer check-platform-reqs`.
- The validator image builds from the repository root (stages `test` and `runtime`); its unit's
  health check reports the worker heartbeat.

### Fixed
- The Doctrine tenant filter is reset at the start of every main request, so a long-lived kernel
  never carries one client's filter into the next request's authentication.

### Verified
- `smarthostctl test phase3`: 295 pytest tests pass. End to end: the 10,000-address job completed
  (`processed_count` 10,000; classification counts total 10,000; usage quantity 10,000; exactly one
  `validation.completed` outbox row per job). The worker was killed with SIGKILL after 3,710
  addresses and a restarted worker finished after the leases expired, with no duplicate result or
  usage. Limits held (per domain 2/2, per MX 2/2, global 7/20; claim size 250); peak worker RSS
  about 52 MiB. The fake SMTP server received EHLO, MAIL, RCPT and QUIT, and no `DATA`.
- `smarthostctl test phase2`: 163 tests and 1,578 assertions pass (unit 37, contract 2, schema 33,
  integration 91).
- `smarthostctl verify --clean`: 167/167 checks.
- Contract checks 1873/1873; OpenAPI 3.1 valid; yamllint clean; shellcheck clean at warning
  severity; `composer validate --strict` and `composer check-platform-reqs` pass.

## [0.1.2] - 2026-10-03

Phase 2 complete: database and Symfony foundation.

### Added
- Symfony 8.1 application in `app/` (PHP 8.5-FPM behind the existing nginx; no PHP HTTP server),
  replacing the Phase 1 `/healthz` probe with the real front controller.
- Doctrine ORM entities for all 24 tables and five Doctrine migrations that reproduce
  `docs/schema/reference-schema.sql` exactly; they run as `smarthost_owner` in the new
  `smarthost-db-migrate` oneshot. The new `smarthost-db-grants` oneshot then applies the
  `schema.md` §6 grant matrix (`infra/postgres/grants.sql`) with the administrative connection.
- `/v1` API: validation-job creation and reads, staged send jobs (create, recipient batches,
  submit/seal), message and event reads, `POST /v1/webhooks/test` (501 until Phase 7). Requests
  are validated against the OpenAPI contract; errors are RFC 9457 problems.
- API-key authentication (Bearer; SHA-256 only; revocation; last-used tracking; rate limits),
  tenant isolation (tenant scope plus a deny-by-default Doctrine filter), durable idempotency with
  in-flight 409.
- Dashboard user foundation (users, memberships, operator role, CSRF-protected form login that
  never accepts API keys), sending-domain registration and TXT verification, DKIM status records,
  webhook endpoints with encrypted rotating secrets, the transactional outbox writer, and the
  audit log.
- Console commands `smarthost:*` for administration and `smarthost:dev:bootstrap` for development
  data; `smarthostctl test`, `console` and `migrate`.
- Phase 2 test harness (`infra/tests/phase2-test.sh`): a throwaway, network-less pod with
  PostgreSQL 16; 156 tests and 1,471 assertions, all passing (unit 36, contract 2, schema 33, integration 85).

### Changed
- The app image builds from the repository root (`.containerignore`) so the normative OpenAPI and
  vocabulary files are part of it; nginx forwards every request to Symfony and answers oversize
  bodies with a JSON 413.
- `APP_ENCRYPTION_KEYS` and `APP_WEBHOOK_SECRET_OVERLAP_HOURS` are first used in Phase 2.
- The D-18 IDNA rule, content fingerprint and API-key format are now defined in
  `docs/architecture/conventions.md`; Phase 2 implementation choices and new questions (D-31…D-34)
  are logged in `docs/architecture/open-decisions.md`.

### Fixed
- `status-vocabulary.yaml`: 12 `meaning` texts with unquoted commas in YAML flow mappings were
  silently truncated by YAML parsers; they are now quoted (no value changed).
- The Phase 1 check "no private key material in the repository" matched its own search pattern
  once the suite was committed; it now uses a pattern that cannot match itself and also scans
  untracked files.

### Verified
- `smarthostctl verify --clean`: 166/166 checks on the Phase 2 topology.
- `smarthostctl test`: 156 tests and 1,471 assertions pass (unit 36, contract 2, schema 33, integration 85),
  including reference-schema equivalence, migration rollback, grants, tenant isolation and
  concurrent idempotent retries.
- Contract checks 1691/1691; OpenAPI 3.1 valid; shellcheck clean.

## [0.1.1] - 2026-10-03

Phase 1 complete: verified rootless Podman development environment, with all services in one pod.

### Changed
- All Smarthost containers now run in one Podman pod, `smarthost` (`infra/quadlet/smarthost.pod.in`).
  The pod joins `smarthost-internal`, carries the service aliases and publishes the only host
  ports. Containers have no network, alias or port keys of their own.
- Postfix port 25 drops `permit_mynetworks`. Pod members share loopback, so no client address is
  trusted for relaying.

### Added
- Phase 1 verification suite `infra/tests/phase1-verify.sh` (`smarthostctl verify [--clean]`):
  22 test groups and 162 checks, with an evidence log in `infra/.generated/verify/`.
- Verification tool image (`infra/tests/Containerfile`): dkimpy, psycopg, inotify_simple.
- `smarthostctl systemctl` pass-through.
- A licence check in `scripts/check-contracts.py`: the OpenAPI licence must match `LICENSE`.
- The public-repository content rule in `CLAUDE.md`.

### Fixed
- OpenAPI licence metadata is now `MIT` (it was `Proprietary`).
- Postfix SASL on 587: added `cyrus_sasl_config_path`; `smtpd.conf` is now readable by the
  `postfix` user.
- Postfix `virtual(8)` could not read the bounce-recipient table. The entrypoint now uses umask
  `022` for configuration files.
- `smarthostctl stop` and `restart` are deterministic: they name every member unit.
- `dkim-dev-key` creates the OpenDKIM volumes with the project label, and `destroy-volumes`
  removes the six Smarthost volumes by name.
- The PostgreSQL health check names its user and database (no more `role "root"` log noise).

### Verified
- Phase 1 is complete; `smarthostctl verify --clean` passes 162/162 checks.
- V-1…V-7 are recorded as observed facts in `docs/architecture/postfix-integration.md` §8. Log
  generation identity is now a first-record fingerprint, because compressed generations get a new
  inode.

## [0.1] - 2026-10-02

First published snapshot: Phase 0 is complete and Phase 1 is in progress.

### Added: Phase 0 (architecture and contracts)
- **Canonical specification 2.1** (`docs/20260908-1644-smarthost-llm-spec.yaml`) and its
  human-readable companion. It incorporates decisions D-01 to D-29, including:
  - fully rendered recipient content from client applications, with no mail merge;
  - staged recipient upload (`collecting` → submit);
  - the webhook transactional outbox and Symfony webhook worker;
  - nginx with PHP-FPM;
  - the OpenDKIM milter;
  - opaque random tracking tokens;
  - RFC 8058 one-click unsubscribe;
  - sending-domain verification;
  - transient rendered-content retention;
  - Postfix queue-snapshot reconciliation with `outcome_unknown`;
  - leased work claiming;
  - origin-aware event de-duplication;
  - the database role bootstrap;
  - the live-sending compliance gate.
- **Normative contracts:**
  - status/event vocabulary;
  - OpenAPI 3.1 `/v1` contract;
  - reference PostgreSQL 16 schema (24 tables) with ERD and grant matrix;
  - environment-variable contract and `infra/.env.example`;
  - the Postfix integration contract.
- **Architecture docs:** overview, conventions and decision log.
- **`scripts/check-contracts.py`:** cross-artifact consistency checker.

### Added: Phase 1 (rootless Podman development environment, in progress)
- **Container images:**
  - Postfix 3.10 (Debian trixie), with a capture/live safety switch, SASL submission on 587,
    a milter on submission only, the DSN Maildir spool, and atomic `postqueue -j` snapshots;
  - OpenDKIM 2.11 with a disposable dev-key tool;
  - nginx 1.28 to PHP-FPM 8.5 over FastCGI;
  - a Symfony runtime image with Phase 1 probes and a webhook-worker placeholder;
  - Python 3.14 validator and Go 1.25 delivery probe images;
  - a deterministic fake SMTP server.
- **Quadlet and systemd:**
  - one `Internal=true` network and six named volumes;
  - eleven containers with readiness health checks (`Notify=healthy`);
  - `smarthost.target` and systemd timers for queue snapshots and log rotation.
- **`infra/bin/smarthostctl`** (init-env, render, build, secrets, install, dkim-dev-key,
  start/stop/restart/status/logs), with support for a WSL Podman machine through its `enterns`
  helper.
- **`infra/lib/smarthost_render.py`:** contract-driven, per-service least-privilege env files and
  unit rendering.
- **Idempotent PostgreSQL role bootstrap** (`infra/postgres/bootstrap.sh`).

### Changed: environment contract (from Phase 1 findings)
- Added `SMARTHOST_DELIVERY_UID`, because DSN files must be written as the Go delivery identity.
- `MAILPIT_UI_BIND` now defaults to `127.0.0.1:8026` (8025 is commonly taken by other local Mailpit instances), and
  `TRUSTED_PROXIES` to `10.89.20.0/24`.
- Documented the `/var` restriction on the Postfix log path and the production-only live mode.
- OpenDKIM now consumes `SMARTHOST_ENV`.

### Known incomplete
- The Phase 1 verification suite (`smarthostctl verify`) is not yet written.
- The V-1…V-7 Postfix/OpenDKIM verification items are only partly observed.
- End-to-end mail, DKIM-signature, milter-failure, DSN-spool, snapshot and persistence tests
  are still pending.
