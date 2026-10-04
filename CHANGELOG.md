# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Project versions are independent of
the *specification* version, which is 2.2.

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
