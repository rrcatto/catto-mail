# Project Guide: Structure, Files and Workflows

This guide explains how the repository is organised, what every file does, and how the main
workflows run. Architecture authority is the specification
(`docs/20260908-1644-smarthost-llm-spec.yaml`); this document only describes it.

Generated and secret material is **not** in the repository:
- `infra/.env` holds development secrets.
- `infra/.generated/` holds the rendered env files, rendered units and development TLS keys.

Both are gitignored.

---

## 1. Directory structure

```
.
├── AGENTS.md                      Rules for coding agents (read first)
├── CLAUDE.md                      Claude Code specifics on top of AGENTS.md
├── README.md                      Project overview and quick start
├── CHANGELOG.md                   Version history
├── LICENSE                        MIT licence
├── .gitignore                     Excludes secrets and generated files
├── .containerignore               Build context filter for the app and validator images (repository root)
├── app/                           Symfony 8.1 application (PHP-FPM)
│   ├── Containerfile              Multi-stage: base, tools, test, vendor, runtime
│   ├── README.md
│   ├── composer.json, composer.lock, symfony.lock
│   ├── phpunit.dist.xml
│   ├── bin/console, bin/phpunit
│   ├── public/index.php           The only front controller (nginx → FastCGI)
│   ├── config/                    Framework, Doctrine, security, monolog, routes, services
│   ├── migrations/                7 Doctrine migrations (the schema authority)
│   ├── src/
│   │   ├── Api/                   Problems, OpenAPI validation, idempotency key, cursors, representations, work permission (D-31)
│   │   ├── Audit/                 Audit log writer
│   │   ├── Client/                Client, user and membership administration
│   │   ├── Command/               Console commands (administration and dev bootstrap)
│   │   ├── Config/                Fail-closed configuration, `_FILE` secrets, limits
│   │   ├── Controller/            /v1 API, /healthz, minimal dashboard login
│   │   ├── Crypto/                Encryption keyring (webhook signing secrets)
│   │   ├── Doctrine/Type/         timestamptz and jsonb_map types
│   │   ├── Domain/                Sending domains and DNS TXT verification
│   │   ├── Dsn/                   Unmatched-DSN operator workflow (match request, dismissal)
│   │   ├── Entity/                25 entities, one per table
│   │   ├── Enum/                  33 vocabulary enums
│   │   ├── Idempotency/           In-flight lock
│   │   ├── Logging/               Contract log format
│   │   ├── Security/              API-key and dashboard authentication, voter
│   │   ├── Sending/               Send-job lifecycle, D-18 normalisation, content fingerprint
│   │   ├── Suppression/           Recipient global opt-out API service, operator suppression administration (D-30)
│   │   ├── Tenant/                Tenant scope and Doctrine tenant filter
│   │   ├── Validation/            Validation-job creation
│   │   ├── Webhook/               Endpoint/secret model and transactional outbox
│   │   ├── Util/Clock.php
│   │   └── Kernel.php
│   ├── tests/                     PHPUnit: Unit, Contract, Schema, Integration, Support, bin
│   ├── docker/
│   │   ├── fpm-healthcheck.sh
│   │   ├── php.ini
│   │   └── zz-smarthost-fpm.conf
│   └── phase1-probe/
│       ├── db-check.php
│       └── webhook-worker.php
├── validator/                     Python validation worker (Phase 3)
│   ├── Containerfile              Stages: base, test, runtime
│   ├── README.md
│   ├── requirements.txt, requirements-test.txt, pytest.ini
│   ├── smarthost_validator/       The worker package (pipeline stages, leases, limits, SQL)
│   └── tests/                     pytest: unit, database, worker; fakes/ (fake DNS); e2e/zone.json
├── delivery/                      Go delivery daemon (Phases 4 and 5)
│   ├── Containerfile              Stages: deps, test, build, runtime
│   ├── README.md
│   ├── go.mod, go.sum
│   ├── cmd/smarthost-delivery/    main.go (run, health, normalize), probe.go (Phase 1 probes)
│   └── internal/                  address, config, dsn (+ testdata fixtures), dsnspool, ids, ingest,
│                                  integration (tests), logx, mimemsg, pacing, postfixlog, reconcile,
│                                  smtpclass, smtpsub, snapshot, status, store, testsmtp, tracking, worker
├── postfix/                       Postfix image
│   ├── Containerfile
│   ├── README.md
│   ├── entrypoint.sh
│   ├── healthcheck.sh
│   ├── queue-snapshot.sh
│   └── logrotate.sh
├── opendkim/                      OpenDKIM milter image
│   ├── Containerfile
│   ├── README.md
│   ├── entrypoint.sh
│   └── dev-key.sh
├── infra/                         Environment, units and tooling
│   ├── .env.example
│   ├── README.md
│   ├── bin/
│   │   ├── smarthostctl
│   │   └── smarthost-machine-helper.sh
│   ├── lib/smarthost_render.py
│   ├── nginx/
│   │   ├── Containerfile
│   │   └── templates/smarthost.conf.template
│   ├── postgres/
│   │   ├── bootstrap.sh           Role bootstrap
│   │   ├── grants.sh              Applies grants.sql after migrations
│   │   └── grants.sql             The schema.md §6 privilege matrix
│   ├── podman/smarthost-pod.sh.in Persistent pod: network, volumes, pod, 10 containers, DB tasks
│   ├── systemd/*.in               boot service + timers
│   └── tests/
│       ├── Containerfile          Verification tool image
│       ├── requirements.txt
│       ├── phase1_client.py
│       ├── phase1-verify.sh       Phase 1 verification suite
│       ├── testpod.sh             Shared throwaway network-less test pod (sourced)
│       ├── phase2-test.sh         Phase 2 test harness (PHPUnit)
│       ├── phase3-test.sh         Phase 3 test harness (pytest + end to end)
│       ├── phase4-test.sh         Phase 4/5 harness: Go unit + PostgreSQL integration (throwaway pod)
│       ├── phase4-e2e.sh          Phase 4 end to end against the running pod
│       ├── phase4_e2e.py          Phase 4 end-to-end driver (API, Mailpit, DKIM, DB, Postfix log)
│       ├── phase5-e2e.sh          Phase 5 end to end against the running pod (DSNs via port 25)
│       └── phase5_e2e.py          Phase 5 end-to-end driver (API, DSN/ARF injection, DB)
├── tests/fake-smtp/               Deterministic SMTP test server
│   ├── Containerfile
│   ├── README.md
│   └── fake_smtp.py
├── scripts/
│   ├── README.md
│   └── check-contracts.py
└── docs/                          Specifications, contracts, architecture
    ├── README.md
    ├── PROJECT.md                 (this file)
    ├── development-environment.md
    ├── 20260908-1644-smarthost-llm-spec.yaml
    ├── 20260908-1644-smarthost-human-specification.md
    ├── api/openapi.v1.yaml
    ├── architecture/
    │   ├── overview.md
    │   ├── conventions.md
    │   ├── postfix-integration.md
    │   └── open-decisions.md
    ├── contracts/
    │   ├── environment.md
    │   ├── status-vocabulary.yaml
    │   └── address-normalization-vectors.json
    └── schema/
        ├── reference-schema.sql
        └── schema.md
```

---

## 2. Files and what they do

### Root

| File | Purpose |
|---|---|
| `AGENTS.md` | Mandatory instructions for any coding agent: read the specs first; Podman only; no commits without instruction; what to report. |
| `CLAUDE.md` | Claude Code notes: authority order, current phase, WSL Podman-machine handling, protection of other projects' containers, required checks. |
| `README.md` | What Smarthost is, an architecture diagram, layout, quick start, status. |
| `CHANGELOG.md` | Version history: 0.1 (Phase 0 complete), 0.1.1 (Phase 1 complete, `smarthost` pod), 0.1.2 (Phase 2 complete: Symfony foundation, schema, API primitives) 0.1.3 (Phase 3 complete: Python validation engine), 0.1.4 (Phase 4 complete: Go/Postfix delivery pipeline; persistent pod lifecycle) and 0.1.5 (Phase 5 complete: inbound DSN, complaint and global suppression processing, D-30). |
| `LICENSE` | MIT licence. |
| `.gitignore` | Keeps `infra/.env`, `infra/.generated/`, keys and certificates out of git. |
| `.containerignore` | The app and validator images build from the repository root so that they can copy the normative contracts; this admits only `app/` (without `vendor/`, `var/`), `validator/` (without caches), those contract files, the D-32 vectors and the fake SMTP server (validator test stage). |

### `docs/`: specifications and contracts

| File | Purpose |
|---|---|
| `20260908-1644-smarthost-llm-spec.yaml` | **Canonical specification 2.3.** It covers architecture, ownership, services, API, sending, tracking, schema, security, compliance and phases. |
| `20260908-1644-smarthost-human-specification.md` | Human-readable companion that mirrors the YAML. |
| `README.md` | Index of the documentation. |
| `PROJECT.md` | This guide. |
| `development-environment.md` | How to run the Podman environment: topology, ports, volumes, mail safety, facts established in Phase 1, the verification suite, and the Phase 2 application (migrations, console, tests). |
| `api/openapi.v1.yaml` | OpenAPI 3.1 contract for `/v1`: validation jobs, batched send jobs (create → recipients → submit), messages, events and webhooks. |
| `contracts/status-vocabulary.yaml` | Every allowed status, event, event source, failure scope and classification value, with transitions and ranks. |
| `contracts/environment.md` | The only list of permitted environment variables, with consumers, secrecy and phase. |
| `contracts/address-normalization-vectors.json` | Shared D-32 address-normalisation test vectors (87). PHP and Python test against this file; Go must from Phase 4. |
| `schema/reference-schema.sql` | Reference PostgreSQL 16 DDL (24 tables). This is not a migration. |
| `schema/schema.md` | ERD, tenant ownership, required indexes, CHECK rules and the database grant matrix. |
| `architecture/overview.md` | One-page map from the specification to the contracts and flows. |
| `architecture/conventions.md` | Cross-language rules: IDs, time, leases, API, webhooks, logging, wording. |
| `architecture/postfix-integration.md` | Postfix/OpenDKIM/Go channels, cursors, reconciliation and the verified V-1…V-7 results. |
| `architecture/open-decisions.md` | Decision log (D-01…D-35). Not a source of authority. |

### `infra/`: environment, units and tooling

| File | Purpose |
|---|---|
| `.env.example` | Safe template of every contract variable, with secrets empty. Generated from `docs/contracts/environment.md`. |
| `README.md` | What lives in `infra/`. |
| `bin/smarthostctl` | Developer CLI: init-env, render, build, secrets, install (units + pod if missing), create, dkim-dev-key, start/stop/restart (same objects), recreate (new pod and containers, volumes kept), remove, status, logs, systemctl pass-through, uninstall, destroy-volumes, verify, test (Phase 2/3/4 suites and the Phase 4 end-to-end run), console (Symfony console in the app container), migrate (migration and grant tasks). |
| `bin/smarthost-machine-helper.sh` | Runs inside the Podman engine's systemd namespace. It installs and uninstalls the systemd units (including the `default.target.wants` boot link; it removes the pre-D-35 Quadlet units once) and passes commands through to `systemctl --user` and `journalctl --user`. It never creates or removes Podman objects. |
| `lib/smarthost_render.py` | Regenerates `.env.example` and creates `infra/.env` with random development secrets. It renders per-consumer env files, the pod script (values shell-quoted) and the systemd templates, and refuses non-contract variables. |
| `nginx/Containerfile` | nginx 1.28 image without the default site. |
| `nginx/templates/smarthost.conf.template` | HTTPS server. Every request goes to the Symfony front controller over FastCGI, with request-time DNS resolution. Bodies above 12 MiB get a JSON 413 from nginx; the application enforces the 10 MiB API limit itself. Also a loopback-only health server. |
| `postgres/bootstrap.sh` | Idempotent role bootstrap: five least-privilege roles; owner of the database and schema; runtime roles get CONNECT and USAGE only. |
| `postgres/grants.sql` | The table and column privileges of `docs/schema/schema.md` §6, exactly: revoke everything from the runtime roles, then grant the matrix, in one transaction. |
| `postgres/grants.sh` | Runs `grants.sql` with the administrative connection. Idempotent; re-run after every migration run. |
| `podman/smarthost-pod.sh.in` | The single definition of the persistent topology (D-35), rendered to `.generated/podman/smarthost-pod.sh`: the `Internal=true` network `smarthost-internal` (`10.89.20.0/24`, no Internet route), the six named volumes, the `smarthost` pod (network attachment, service aliases, the two loopback host ports) and its ten service containers with their images, env files, mounts, secrets, identities, health checks and `--restart on-failure`; plus the ordered one-off DB tasks `db-bootstrap`, `db-migrate` (as `smarthost_owner`) and `db-grants`. Actions: `create`, `start` (PostgreSQL → tasks → pod → health), `stop` (pod stop; objects kept), `remove` (pod and containers; volumes kept), `tasks`, `wait-healthy`, `postfix-exec` (timers). |
| `systemd/smarthost.service.in` | Starts the existing pod at boot (ordered start) and stops it at shutdown (`podman pod stop`); wanted by `default.target`. No `ExecStopPost`: it never removes objects. Uses the user's Podman API socket like Podman Desktop. |
| `systemd/smarthost-postfix-queue-snapshot.{service,timer}.in` | Periodic `smarthost-queue-snapshot` in the Postfix container (skipped while it is not running). |
| `systemd/smarthost-postfix-logrotate.{service,timer}.in` | Daily `smarthost-postfix-logrotate` in the Postfix container (skipped while it is not running). |
| `tests/phase1-verify.sh` | Phase 1 verification suite behind `smarthostctl verify [--clean]`. It runs 25 test groups and writes an evidence log to `infra/.generated/verify/`. The groups cover images, units, clean create and start, readiness, ports, network isolation, the live-mode guard, nginx→FPM, PostgreSQL roles, mail capture, DKIM, the milter-failure policy, V-1…V-7, the DSN spool, fake SMTP, the persistent lifecycle (restart, stop/start, Podman-level stop/start, boot path and recreate with pod/container ID and data checks) and untouched neighbours. |
| `tests/testpod.sh` | Sourced by the Phase 2 and 3 harnesses. It builds the app `test` image and starts a throwaway pod **without any network** (`--network none`, label `project=smarthost`) with PostgreSQL 16.15, then runs the real role bootstrap, the Doctrine migrations on the empty database and the grants. Random credentials; the pod is removed afterwards. |
| `tests/phase2-test.sh` | Phase 2 harness behind `smarthostctl test phase2`: the test pod, plus the reference schema loaded into a separate database for comparison, then PHPUnit as the application role. |
| `tests/phase3-test.sh` | Phase 3 harness behind `smarthostctl test phase3`: in the test pod, pytest (unit, database and worker tests as the validator role), then an end-to-end run with the fake DNS and fake SMTP servers: Symfony creates jobs through `/v1` (including 10,000 addresses), the real worker is SIGKILLed mid-job and restarted, and Symfony verifies results, usage and the outbox. Fails if the fake SMTP server ever received `DATA`. `smarthostctl test` runs both harnesses. |
| `tests/phase4-test.sh` | Phase 4 harness behind `smarthostctl test phase4`: builds the delivery `test` image (gofmt and go vet run during the build), runs the Go unit tests without network, then the integration tests against PostgreSQL in the throwaway test pod as `smarthost_delivery` (claiming, fencing, exactly-once expansion, suppressions, submission outcomes, purge and metering, ambiguity and crash recovery, log ingestion with rotation and the hold rule, reconciliation, pacing, headers and tracking). |
| `tests/phase5-e2e.sh`, `tests/phase5_e2e.py` | Phase 5 end to end behind `smarthostctl test phase5-e2e`, against the running pod: messages through `/v1` to Mailpit, then the fixtures of `delivery/internal/dsn/testdata` sent to Postfix port 25 (VERP, postmaster or the feedback-loop address). Scenarios A (cross-client hard bounce), B (opt-out API and lift), C (correlation), D (ARF complaint), E (excluded scopes, repeated soft bounces), F (operator workflow), G (crash, reclaim, retention). |
| `tests/phase4-e2e.sh`, `tests/phase4_e2e.py` | Phase 4 end to end behind `smarthostctl test phase4-e2e`, against the running pod: jobs through `/v1`, the dev daemon or throwaway worker containers, real Postfix/OpenDKIM/Mailpit. Scenarios A (headers, VERP, tracking, DKIM, events, purge, usage, completion), B (OpenDKIM down), C (deferral), D (SIGKILL and reclaim), E (10,000 recipients with a log rotation); duplicates are checked against the Postfix log. |
| `tests/phase1_client.py` | Test client run inside the tool image on the internal network: submission (with queue ID), port-25 DSN injection, Mailpit lookup, cryptographic DKIM verification, fake-SMTP scenarios, PostgreSQL role/privilege probes and inotify watching. |
| `tests/Containerfile`, `tests/requirements.txt` | Verification tool image (`localhost/smarthost-testtools:dev`): Python with dkimpy, psycopg and inotify_simple, plus the Phase 4 end-to-end driver. Test-only; never a service. |

### Service images

| File | Purpose |
|---|---|
| `postfix/Containerfile` | Debian trixie with Postfix 3.10, Cyrus SASL, OpenSSL and netcat. |
| `postfix/entrypoint.sh` | Enforces the capture/live **safety switch** and builds `main.cf`: relayhost, VERP bounce domain to the Maildir as the delivery UID, logging to the observability volume at 0640. Configures submission on 587 (TLS, SASL, OpenDKIM milter, tempfail) and the sasldb, then starts Postfix in the foreground. |
| `postfix/healthcheck.sh` | Readiness: Postfix master running, and a 220 greeting on 25 and 587. |
| `postfix/queue-snapshot.sh` | Atomic `postqueue -j` JSON-Lines snapshot (tmp file, then rename) with retention. |
| `postfix/logrotate.sh` | `postfix logrotate` plus deletion of rotated files older than the retention period. |
| `opendkim/Containerfile` | Debian trixie with OpenDKIM, opendkim-tools, openssl and busybox. |
| `opendkim/entrypoint.sh` | Writes `opendkim.conf`: sign-only, signs for `MTA ORIGINATING` (Postfix submission) using KeyTable/SigningTable. Logs through busybox syslogd to stdout. |
| `opendkim/dev-key.sh` | Generates a **disposable** development key inside the keys volume, registers it, and prints the public TXT record. Refuses outside development/test. |
| `app/Containerfile` | PHP 8.5-FPM with `pdo_pgsql`, `intl`, `pcntl` and `cgi-fcgi`. Stages: `tools` (Composer), `test` (dev dependencies and test-only contract copies), `runtime` (default; no dev dependencies, no tests). Built from the repository root so that the normative OpenAPI and vocabulary files are copied into `config/contracts/`. No PHP HTTP server. |
| `app/docker/php.ini` | UTC, no `expose_php`, `post_max_size` 12M (the application decides the 10 MiB limit), memory and OPcache settings. |
| `app/docker/zz-smarthost-fpm.conf` | Pool overrides: listen 9000, ping path, `clear_env = no`. |
| `app/docker/fpm-healthcheck.sh` | FastCGI ping readiness check. |
| `app/phase1-probe/db-check.php` | CLI check: connect as the app, webhook or owner role and verify the CREATE privilege boundary (used by the Phase 1 suite). |
| `app/phase1-probe/webhook-worker.php` | Placeholder long-running worker with a heartbeat and database check. Delivers no webhooks (Phase 7). |
| `validator/Containerfile` | Python 3.14 slim. Stages: `test` (pytest, tests, fake DNS/SMTP, shared vectors) and `runtime` (default; the worker as unprivileged uid 10001). Built from the repository root. |
| `validator/requirements.txt` | Pinned runtime dependencies: psycopg 3, psycopg-pool, dnspython, idna. `requirements-test.txt`: pytest, pytest-asyncio. |
| `validator/smarthost_validator/` | The worker (see `validator/README.md`): `__main__` (`run`, `health`, `check-db`, `normalize`), `config`, `worker` (claiming, renewal, chunks, LISTEN/poll), `pipeline`, `normalize` (D-32), `syntax`, `typo` + `typo_data`, `dns_check`, `roles`, `smtp_probe` (never DATA), `limits` (global/domain/MX slots, back-off, accept-all policy), `classify` (rule table), `db` (every SQL statement, fenced), `models`, `logs`. |
| `validator/tests/` | pytest suites: normalisation vectors, syntax, typos, roles, config, DNS (with a fake DNS server), SMTP (with the fake SMTP server), limits, classification, pipeline, database leases/fencing/metering, worker end to end. `fakes/fake_dns.py` is a deterministic UDP/TCP DNS server driven by a zone file. |
| `delivery/Containerfile` | Go 1.26, built from the repository root. Stages: `deps` (modules), `test` (sources, shared D-32 vectors; `gofmt` and `go vet` run during the build; `go test` runs offline), `build` (static binary), `runtime` (default; Debian slim, `USER 5001:5000`). |
| `delivery/cmd/smarthost-delivery/` | The daemon (`run`: job worker, log ingestion, reconciliation, heartbeat, stats), `health`, `normalize`, and the Phase 1 probes `identity`, `check-db`, `check-observability`, `check-spool [--claim]`. |
| `delivery/internal/` | See `delivery/README.md`: `config` (environment contract), `logx` (contract logs), `address` (D-32), `ids` (UUIDv7, VERP, tracking tokens, Message-ID), `mimemsg` (MIME and header contract), `tracking` (pixel, click rewriting), `smtpsub` (submission client), `pacing`, `store` (all SQL: leases, expansion, acceptance with purge and metering, projection, counts, outbox, log batches, reconciliation), `worker` (job processing), `postfixlog` (parser, generations, searches), `ingest` (cursor-following ingestion), `snapshot` and `reconcile` (D-27), `status` (vocabulary ranks), `testsmtp` (scripted submission server for tests), `integration` (PostgreSQL integration tests, build tag `integration`; `phase5_test.go` covers DSNs and the suppression policy). Phase 5: `smtpclass` (the one failure-scope classifier), `dsn` (bounded DSN/ARF parser, interpretation, correlation evidence; `testdata/` holds the realistic fixtures), `dsnspool` (spool claim/reclaim/retention and the match-request resolver), `store/dsn.go` (ingestion, correlation, unmatched rows, resolution) and `store/policy.go` (global suppression policy, pre-submission suppression). |
| `delivery/go.mod`, `go.sum` | Module definition: pgx v5 and `golang.org/x/net` (IDNA, HTML tokenizer). |
| `tests/fake-smtp/fake_smtp.py` | Stdlib asyncio SMTP server whose reply is chosen by the recipient prefix (`reject-550`, `tempfail-450/451`, `unavailable-421`, `timeout`, otherwise accept) plus the validator scenarios: `accept-all`/`block-all` domain labels and `throttle-421`, `block-554` and accept-all-probe local parts. It logs every command as JSON so tests can prove `DATA` never arrives; DATA is discarded. |
| `tests/fake-smtp/Containerfile` | Python 3.14 slim, uid 10003. |

### `app/`: the Symfony application (Phase 2)

| Path | Purpose |
|---|---|
| `composer.json`, `composer.lock`, `symfony.lock` | Symfony 8.1, Doctrine ORM 3 / DBAL 4 / Migrations, Security, Validator, Serializer, Rate Limiter, Lock, Monolog, Uid, opis/json-schema; PHPUnit 13 and Symfony test tools as dev dependencies. No Dotenv: configuration comes only from the contract variables. |
| `config/packages/framework.yaml` | Secret, trusted proxies, session (dashboard only), rate limiters (per API key, per IP for failed API authentication). |
| `config/packages/doctrine.yaml` | Two lazy connections: `default` as `smarthost_app` (runtime) and `owner` as `smarthost_owner` (migrations only); custom types; the tenant filter. |
| `config/packages/doctrine_migrations.yaml` | Migrations run on the `owner` connection, transactional. |
| `config/packages/security.yaml` | Stateless `/v1` firewall (API keys only) and a separate `/dashboard` form-login firewall (users only). |
| `config/packages/monolog.yaml` | JSON lines to stderr in the contract format; Doctrine only at warning. |
| `config/services.yaml` | Parameters from the environment contract; the test DNS stub. |
| `migrations/Version20261003000100…000500.php` | Tenancy; validation; sending; suppression/reputation; metering, webhooks and audit. |
| `migrations/Version20261004000100.php` | Phase 5 / D-30: client capability, suppression provenance, `recipient_global_opt_out`, partial unique indexes, `messages_recipient_address_idx`, dismissal reason. |
| `migrations/Version20261005000100.php` | Spec 2.5 / D-38: `global_suppression_requests` (durable opt-out request idempotency), backfilled from existing opt-outs; removes the per-row key columns from `suppressions`. With the six above it reproduces `docs/schema/reference-schema.sql`; each has a `down()`. |
| `src/Kernel.php` | Runs the fail-closed safety guard on every boot. |
| `src/Config/` | `SafetyGuard` (SMARTHOST_ENV values; unverified domains forbidden in production), `SecretEnvVarProcessor` (`X` or `X_FILE`, never both), `Limits` (configuration may lower, never raise, the contract ceilings). |
| `src/Doctrine/Type/` | `timestamptz` (microseconds, UTC) and `jsonb_map` (`{}` stays an object). |
| `src/Entity/` | One entity per table (25; `GlobalSuppressionRequest` since D-38). Tables written only by Python or Go are mapped read-only. |
| `src/Enum/` | The 33 vocabularies of `status-vocabulary.yaml` as PHP enums. |
| `src/Security/` | `ApiKeyManager` (raw key `shk_…`, 256 bits, shown once; SHA-256 stored), `ApiKeyAuthenticator` (Bearer; revoked keys and closed clients rejected; last-used tracking; failure rate limit), `ApiClientUser`, dashboard user provider and checker, login listener, `ClientVoter`. |
| `src/Tenant/` | `TenantScope` (the only way API code loads tenant resources; foreign = missing), `TenantFilter` (Doctrine SQL filter, deny-by-default per table; a client sees only its own suppressions and the global opt-outs it reported), and the listener that enables it for the authenticated client. |
| `src/Suppression/` | `GlobalSuppressionService` (D-30 opt-out API: capability check, creation allowed in any authenticated status and lifting only when active/throttled (D-37), normalisation, durable request idempotency in `global_suppression_requests` (D-38), per-address lock, audited create, reaffirm and lift) and `SuppressionAdministration` (operator capability changes, operator blocks, audited lifting, search). |
| `src/Dsn/UnmatchedDsnAdministration.php` | Operator match requests (status `match_requested`, NOTIFY `smarthost_unmatched_dsn_work`; Go applies them) and dismissals with a reason; audited. |
| `src/Api/` | `WorkPermission` (D-31: 403 for work-creating operations of `pending_approval`/`suspended` clients), RFC 9457 problems and their renderer, exception mapping for `/v1`, body-size and per-key rate limits, `OpenApiContract` (validates requests against the normative OpenAPI), `JsonRequest`, `RequestHasher` (canonical idempotency hash), `IdempotencyKey`, `Cursor`, `Presenter`. |
| `src/Idempotency/IdempotencyLock.php` | Transaction-scoped advisory lock that turns a concurrent retry into 409. |
| `src/Validation/ValidationJobService.php` | Creates the job and its pending address rows and wakes the validator with `pg_notify`; validates nothing. |
| `src/Sending/` | `SendJobService` (create, batch, submit with row locking), `AddressNormalizer` (D-18), `RecipientContent` (size and SHA-256 fingerprint kept after purge). |
| `src/Domain/` | `SendingDomainService` (registration, TXT verification, re-checks, enable/disable, DKIM status) and the DNS TXT resolver boundary. |
| `src/Crypto/Keyring.php` | `APP_ENCRYPTION_KEYS` keyring (libsodium secretbox, purpose-bound). |
| `src/Webhook/` | `WebhookEndpointService` (encrypted secrets, rotation with overlap) and `WebhookOutbox` (transactional outbox writer). |
| `src/Audit/` | `AuditLogger` and `AuditActor`. |
| `src/Client/AccountAdministration.php` | Clients, users (case-insensitive login), passwords, operator role, memberships. |
| `src/Command/` | `smarthost:client:*` (including `client:global-suppressions`), `smarthost:api-key:*`, `smarthost:user:*`, `smarthost:membership:set`, `smarthost:domain:*`, `smarthost:webhook:*`, `smarthost:suppression:{list,create,lift}`, `smarthost:dsn:{list,show,match,dismiss}`, `smarthost:dev:bootstrap`. Operator actions require `--operator=<login email>`. |
| `src/Controller/` | `/v1` controllers (validation jobs, send jobs, messages, global suppressions, `webhooks/test` = 501), `/healthz`, minimal `/dashboard` login. |
| `src/Logging/ContractFormatter.php` | `ts`, `level`, `service`, `msg` plus context. |
| `tests/Unit/` | D-18 normalisation (including the shared D-32 vectors), request hashing, keyring, configuration rules, log format. |
| `tests/Contract/` | `/v1` routes equal the OpenAPI operations; OpenAPI enums equal the PHP enums. |
| `tests/Schema/` | Reference-schema equivalence, migration up/down/up, ORM mapping vs database, vocabulary CHECKs, the grant matrix parsed from `schema.md`, least privilege, critical constraints. |
| `tests/Integration/` | Authentication, client status (D-31), tenant isolation, idempotency (including multi-process concurrency), validation jobs, send jobs, sending domains, dashboard users, webhooks, console commands, health and audit, the global opt-out API (`GlobalSuppressionApiTest`) and the Phase 5 operator commands (`Phase5OperatorCommandTest`). Every response is validated against the OpenAPI contract. |
| `tests/Phase3/e2e.php` | Phase 3 end-to-end driver (`prepare`, `verify`): creates jobs through `/v1` and checks results, counters, usage and the outbox after the worker run. |
| `tests/Support/`, `tests/bin/request.php` | Test base class, direct database connections, catalog comparison, DNS stub, and the concurrent-request worker. |

### `scripts/`

| File | Purpose |
|---|---|
| `check-contracts.py` | Read-only consistency check across the specification, human specification, vocabulary, OpenAPI, DDL, schema docs and environment contract/template, plus a stale-term scan. |
| `README.md` | How to run it. |

The per-directory `README.md` files describe each component's role and limits.

---

## 3. Workflow diagrams

### 3.1 Service topology

```mermaid
flowchart LR
    subgraph host["Workstation / VPS (rootless Podman)"]
        direction LR
        subgraph net["pod smarthost on smarthost-internal (Internal=true, no Internet route)"]
            nginx["nginx :443"] -- FastCGI --> fpm["symfony-app<br/>PHP-FPM :9000"]
            fpm --> pg[(postgres)]
            ww["webhook-worker"] --> pg
            val["validator"] --> pg
            del["delivery"] --> pg
            del -- "SMTP 587 AUTH" --> pf["postfix"]
            pf -- "milter :8891" --> dk["opendkim"]
            pf -- "capture relay :1025" --> mp["mailpit"]
            val -. "dev probes" .-> fs["fake-smtp"]
            boot["db-bootstrap → db-migrate → db-grants (ordered tasks of start)"] --> pg
        end
        obs[("observability vol<br/>log/ + queue/")]
        spool[("dsn-spool vol<br/>Maildir")]
        pf --> obs
        pf --> spool
        obs -. "read-only" .-> del
        spool -. "claim by rename" .-> del
    end
    user((Developer)) -- "127.0.0.1:8443" --> nginx
    user -- "127.0.0.1:8026" --> mp
```

### 3.2 Send workflow (Phase 4; tracking endpoints Phase 6, webhook delivery Phase 7)

```mermaid
sequenceDiagram
    participant C as Client app
    participant A as Symfony API
    participant DB as PostgreSQL
    participant G as Go delivery
    participant P as Postfix
    participant K as OpenDKIM
    participant W as Webhook worker
    C->>A: POST /v1/send-jobs (metadata)
    A->>DB: send_jobs (collecting)
    loop batches of at most 500 rendered recipients
        C->>A: POST /v1/send-jobs/{id}/recipients (Idempotency-Key)
        A->>DB: send_job_recipient_batches + send_job_recipients
    end
    C->>A: POST /v1/send-jobs/{id}/submit
    A->>DB: seal, then status queued (+ NOTIFY)
    G->>DB: claim job (lease), processing
    loop each recipient
        G->>DB: messages(created), suppression check, queued
        G->>P: SMTP 587 (VERP, ENVID, NOTIFY)
        P->>K: milter: sign (tempfail on error)
        P-->>G: 250 queued as QID
        G->>DB: submitted + purge rendered content
    end
    G->>DB: dispatched
    P-->>G: log events / DSNs / queue snapshots
    G->>DB: append events, terminal states, completed + webhook_events
    W->>DB: claim outbox
    W->>C: signed webhook send.completed
```

### 3.3 Validation workflow (Phase 3)

```mermaid
flowchart LR
    A["POST /v1/validation-jobs"] --> B["validation_jobs (queued)<br/>validation_addresses (pending)"]
    B --> C["Python claims chunk (lease)"]
    C --> D["normalise → syntax → typo (suggest only)<br/>→ DNS/MX → disposable → role<br/>→ SMTP RCPT (no DATA) → classify"]
    D -- temporary --> E["retry_scheduled (backoff)"] --> C
    D -- final --> F["done + evidence"]
    F --> G{"all done?"}
    G -- yes --> H["completed + webhook_events(validation.completed)"]
```

### 3.4 Inbound DSN, global suppression and reconciliation (Phases 4–5)

```mermaid
flowchart TD
    R["Remote MTA / feedback loop"] -- "port 25 (no milter)" --> P["Postfix: bounce domain only"]
    P -- "virtual(8) as delivery UID" --> M["Maildir inbound/new"]
    M -- "rename (atomic claim) to processing/" --> G["Go: DSN / ARF parser, correlation"]
    G -- "matched (one transaction)" --> E["message_events (dsn_spool key), projection, outbox"]
    E --> SP["global suppression policy (D-30): recipient hard bounce, complaint, repeated soft bounce"]
    L2["Postfix log hard/soft bounces"] --> SP
    API["POST /v1/global-suppressions (authorised client)"] --> SUP[("suppressions, client_id NULL")]
    SP --> SUP
    SUP -- "checked at message creation and before submission" --> W["Go worker: message_suppressed"]
    G -- unmatched --> U["unmatched_dsns (open)"]
    U -- "smarthost:dsn:match (operator)" --> Q["match_requested"] --> G
    G -- "after commit" --> D["done/ (deleted after retention)"]
    S["queue snapshots (postqueue -j)"] --> RC["Go reconciliation"]
    L["Postfix log (generation + position cursor)"] --> RC
    RC -- "absent ≥2 snapshots, no outcome found" --> OU["transport_outcome_unknown"]
```

### 3.5 Contract governance workflow

```mermaid
flowchart LR
    D["Owner decision"] --> S["Spec YAML (authoritative)"]
    S --> H["Human spec"]
    S --> V["vocabulary · OpenAPI · DDL · env contract · postfix-integration"]
    V --> X["infra/.env.example (generated)"]
    S & H & V & X --> CK["scripts/check-contracts.py"]
    CK -- pass --> OK["Ready to implement"]
    D -. "recorded in" .-> LOG["open-decisions.md (log only)"]
```

### 3.6 API request pipeline (Phase 2)

```mermaid
flowchart TD
    R["HTTPS request"] --> N["nginx (≤ 12 MiB)"] --> F["PHP-FPM → Symfony front controller"]
    F --> S{"body > APP_API_MAX_REQUEST_BYTES?"}
    S -- yes --> P413["413 problem"]
    S -- no --> A["/v1 firewall: Bearer API key<br/>sha256 lookup, revoked/closed → 401"]
    A --> T["tenant = key's client<br/>Doctrine tenant filter on"]
    T --> L{"per-key rate limit"}
    L -- exceeded --> P429["429 + Retry-After"]
    L -- ok --> C["controller"]
    C --> V["OpenAPI schema validation → 422 with JSON pointers"]
    V --> I["transaction: advisory lock on (scope, Idempotency-Key)<br/>busy → 409; stored key → replay or 422"]
    I --> W["write job / batch (job row locked) / seal"]
    W --> J["JSON or RFC 9457 problem"]
```

### 3.7 Schema and grants at start-up

```mermaid
flowchart LR
    PG["postgres (healthy)"] --> B["db-bootstrap<br/>roles, owner, CONNECT/USAGE"]
    B --> M["db-migrate<br/>Doctrine migrations as smarthost_owner"]
    M --> G["db-grants<br/>schema.md §6 matrix (admin)"]
    G --> APP["symfony-app · webhook-worker · validator · delivery"]
```

### 3.8 Development environment lifecycle

See `docs/development-environment.md` §2.
