# Development Environment

**Status:** Phases 1–4 complete (Phase 4: Go/Postfix delivery pipeline, v0.1.4).
`smarthostctl verify --clean` passes all 180 checks starting from destroyed volumes (§6), and
`smarthostctl test` passes the Phase 2, 3 and 4 suites, and `smarthostctl test phase4-e2e` the
Phase 4 end-to-end run (§7).

## 1. Requirements

- Rootless Podman 5.1 or later. Verified with engine 6.0.2 in a WSL Podman machine (Fedora 44)
  and client `podman-remote` 6.1.1.
- A systemd user manager on the engine host, with lingering enabled and the user's
  `podman.socket` available (the boot service talks to it).
- Python 3.10 or later on the workstation (for `infra/lib/smarthost_render.py` and the contract
  checker). `curl` is needed by the verification suite.

### WSL Podman machine notes

The Podman engine runs inside the `podman-machine-default` WSL distribution. That distribution's
PID 1 is WSL's `/init`; systemd runs in a nested namespace.

- `smarthostctl` sends systemd operations through `wsl.exe` and the machine's own
  `/usr/local/bin/enterns`, which is the same mechanism `podman machine ssh` uses. Container
  operations use the normal `podman` (remote) CLI.
- The systemd units are installed to `~/.local/share/systemd/user/`. In the machine image,
  `~/.config/systemd/user` is root-owned.
- The repository must be on a path visible to the machine. `/mnt/wsl/...` is shared between WSL
  distributions.
- **Start at boot.** `smarthost.service` is wanted by `default.target` through a link in
  `~/.local/share/systemd/user/default.target.wants/` (`systemctl --user enable` would write to the
  root-owned directory, and `is-enabled` therefore reports `disabled`). With lingering, the user
  manager starts when the machine starts (for example from Podman Desktop), and the service starts
  the existing `smarthost` pod.

## 2. Lifecycle

The `smarthost` pod and its ten service containers are **persistent Podman objects** (D-35). They
are created once and then only started and stopped, exactly like a pod you manage in Podman
Desktop:

| Operation | Command | Pod and containers | Volumes and data |
|---|---|---|---|
| create | `smarthostctl install` (if missing) or `create` | created from the current images and configuration | created if missing, never replaced |
| start | `smarthostctl start`, `podman pod start smarthost`, Podman Desktop *Start* | the same objects start | kept |
| stop | `smarthostctl stop`, `podman pod stop smarthost`, Podman Desktop *Stop* | stopped and **kept** (`podman pod ps`, `podman ps -a` show them exited) | kept |
| restart | `smarthostctl restart` | the same objects are stopped and started | kept |
| recreate | `smarthostctl recreate` | **replaced** (new IDs) from the current images and configuration | kept |
| remove | `smarthostctl remove` | stopped and removed | kept |
| destroy volumes | `smarthostctl destroy-volumes --yes` (only after `remove`) | — | **deleted** |

* **Deploying a change.** After `smarthostctl build` (new images) or a change to a container
  definition (`infra/podman/smarthost-pod.sh.in`, env files), run `smarthostctl recreate`. A plain
  `restart` deliberately keeps the existing containers and therefore their old image and
  configuration.
* **Start order.** `smarthostctl start` (and the boot service) starts PostgreSQL, waits until it is
  healthy, runs the one-off tasks `db-bootstrap` (roles), `db-migrate` (Doctrine migrations as
  `smarthost_owner`) and `db-grants` (the `schema.md` §6 matrix) in that order, then starts the
  whole pod and waits until all ten services are healthy. The tasks are short-lived containers in
  the pod (`podman run --rm`), not pod members.
* **Starting from Podman Desktop** (`podman pod start`) starts all members at once and does not run
  the tasks; that is safe because the database is already initialised and every service retries
  until PostgreSQL is ready. Run `smarthostctl start` or `migrate` after changing migrations.
* **Boot and shutdown.** `smarthost.service` (wanted by `default.target`) runs the ordered start at
  machine boot and `podman pod stop` at shutdown. It never creates or removes objects and has no
  `ExecStopPost`. It talks to the user's Podman API socket, so containers belong to
  `podman.service` exactly as when Podman Desktop starts them, and stopping or starting the pod
  outside systemd does not make the unit interfere.
* **Restart on failure** is Podman's `--restart on-failure` policy; a deliberate stop is never
  restarted.

```mermaid
flowchart TD
    A[init-env<br/>infra/.env with random dev secrets] --> B[build<br/>7 images]
    B --> C[secrets<br/>dev TLS certs as Podman secrets]
    C --> E[dkim-dev-key<br/>disposable key in OpenDKIM volume]
    E --> D[install<br/>render, systemd units, create pod + containers if missing]
    D --> F[start<br/>PostgreSQL → bootstrap → migrate → grants → all services healthy]
    F --> V[verify<br/>Phase 1 suite]
    F --> K[console smarthost:dev:bootstrap<br/>dev client, domain, API key]
    D --> T[test<br/>Phase 2/3 suites, throwaway pods]
    F --> G{status / logs}
    G --> H[stop / restart<br/>same objects]
    H --> F
    G --> R[recreate<br/>new pod + containers, same volumes]
    R --> F
    G --> I[remove<br/>pod + containers removed, volumes kept]
    I --> J[destroy-volumes --yes<br/>data loss]
```

| Command | Effect |
|---|---|
| `smarthostctl init-env` | Creates `infra/.env` (mode 0600, gitignored) from `infra/.env.example` and fills the secrets with random development values. Never overwrites. |
| `smarthostctl render` | Writes one env file per consumer (least privilege, following the contract's *Consumers* column) and renders the pod script (`infra/podman/`) and the systemd units (`infra/systemd/`) into `infra/.generated/`. Rejects any variable that is not in the contract. |
| `smarthostctl build` | Builds the seven Smarthost images (`localhost/smarthost-*:dev`). |
| `smarthostctl secrets` | Creates disposable self-signed TLS certificates for Postfix and nginx as Podman secrets. |
| `smarthostctl install` | Renders the files, installs the systemd units and the boot link (removing the pre-D-35 Quadlet units once), and creates the pod and its containers if they do not exist. It never replaces an existing pod. |
| `smarthostctl create` | Creates the network and volumes if missing, then the pod and its containers. Fails if the pod exists. |
| `smarthostctl dkim-dev-key [domain selector]` | Generates a dev DKIM key inside the OpenDKIM volume, creating the volume with its project label if needed. The default is `smarthost-dev.test` / `phase1`. |
| `smarthostctl start` | Ordered start of the existing pod (see above); returns once all ten services are healthy. |
| `smarthostctl stop` | Stops the pod (`podman pod stop`). The pod and containers are kept. |
| `smarthostctl restart` | `stop` + `start` of the same objects. |
| `smarthostctl recreate` | Stops, removes the pod and its containers, creates them again from the current images and configuration, starts them and shows their status. Volumes are never touched. |
| `smarthostctl remove` | Stops and removes the pod and its containers. Volumes are kept. |
| `smarthostctl status` | The boot service state, the pod and every container with its ID and status. |
| `smarthostctl logs <name> [n]` | A container's log (`logs postfix`), or the journal of a unit (`logs smarthost.service`). |
| `smarthostctl systemctl <args>` | Pass-through to `systemctl --user` on the engine host. |
| `smarthostctl uninstall` | Removes the systemd units and the boot link. The pod, containers and volumes are untouched. |
| `smarthostctl destroy-volumes --yes` | Deletes the six Smarthost volumes by name (data loss). Refuses while the pod exists. |
| `smarthostctl verify [--clean]` | Runs the Phase 1 verification suite (§6). `--clean` first removes the pod and destroys the Smarthost volumes and network to prove a clean-state create and start. |
| `smarthostctl test [phase2\|phase3\|phase4] [args]` | Runs the Phase 2 (PHPUnit), Phase 3 (pytest + end to end) or Phase 4 (Go unit + PostgreSQL integration) suite in throwaway, network-less pods (§7); without a phase, all three. Does not touch the running environment. |
| `smarthostctl test phase4-e2e [A B C D E]` | Phase 4 end to end against the **running** pod's Postfix, OpenDKIM and Mailpit (§7). It stops and restarts Smarthost containers only (OpenDKIM, Mailpit, delivery). |
| `smarthostctl console <command>` | Runs a Symfony console command in the running `smarthost-symfony-app` container as `www-data` (application database role). |
| `smarthostctl migrate` | Runs the `db-migrate` and `db-grants` tasks in the running pod (after `recreate` with a rebuilt app image that brings new migrations, `start` also runs them). |

## 3. Topology

All Smarthost containers run in one Podman **pod**, `smarthost`, defined together with its
containers in `infra/podman/smarthost-pod.sh.in`.

- **Networking is owned by the pod.** The pod (its infra container `smarthost-infra`) is the only
  thing attached to **`smarthost-internal`** (`Internal=true`, subnet `10.89.20.0/24`).
  - It carries the aardvark DNS aliases `postgres`, `symfony-app`, `postfix`, `opendkim`,
    `mailpit` and `fake-smtp`, so the environment contract's host names are unchanged.
  - It publishes the only host ports.
- **Members share the pod's network namespace.** Ports cannot collide, and loopback is shared.
  For that reason Postfix port 25 no longer has `permit_mynetworks`: it relays for no client
  address, including `127.0.0.1`.
- **Throwaway test clients** from the verification suite run outside the pod on
  `smarthost-internal` and reach the services through the same aliases.

| Container (`smarthost-…`) | Image | Pod alias | Identity | Readiness check |
|---|---|---|---|---|
| pod infra (`smarthost-infra`) | infra | — | — | — |
| postgres | postgres:16.15-trixie | `postgres` | image default | `pg_isready -U … -d …` |
| db-bootstrap (task, not a member) | postgres:16.15-trixie | — | admin connection | exit status |
| db-migrate (task, not a member) | smarthost-app | — | www-data; database role `smarthost_owner` | exit status |
| db-grants (task, not a member) | postgres:16.15-trixie | — | admin connection | exit status |
| symfony-app | smarthost-app | `symfony-app` | FPM master root, workers www-data; database role `smarthost_app` | FastCGI `/fpm-ping` |
| webhook-worker | smarthost-app | — | www-data | heartbeat file |
| nginx | smarthost-nginx | — | image default | loopback `/nginx-health` |
| validator | smarthost-validator | — | uid 10001 | database probe |
| delivery | smarthost-delivery | — | `SMARTHOST_DELIVERY_UID`:`SMARTHOST_SPOOL_GID` (5001:5000) | heartbeat (database reachable) |
| postfix | smarthost-postfix | `postfix` | root (Postfix drops privileges) | 220 greeting on ports 25 and 587 |
| opendkim | smarthost-opendkim | `opendkim` | opendkim | port 8891 listening |
| mailpit | mailpit:v1.31.0 | `mailpit` | image default | `mailpit readyz` |
| fake-smtp | smarthost-fake-smtp | `fake-smtp` | uid 10003 | 220 greeting |

The timers are `smarthost-postfix-queue-snapshot.timer` (every
`POSTFIX_QUEUE_SNAPSHOT_INTERVAL_SECONDS`) and `smarthost-postfix-logrotate.timer` (daily). They
run with `smarthost.service` and do nothing while Postfix is not running.

### Published host ports (published by the pod; verified with `podman port smarthost-infra`, T05)

| Bind | Service | Why |
|---|---|---|
| `127.0.0.1:8443` → 443 | nginx | The only HTTP entry (`PROXY_HTTPS_BIND`) |
| `127.0.0.1:8026` → 8025 | Mailpit UI | Development inspection (`MAILPIT_UI_BIND`). 8025 is often taken by other local Mailpit instances. |

PostgreSQL, PHP-FPM, OpenDKIM, Postfix, the Python and Go workers, and fake SMTP publish no
ports. PostgreSQL is unreachable even from other Podman networks.

### Persistent volumes (verified across stop/start, restart, Podman-level stop/start and recreate, T19–T23)

| Volume | Mounted by |
|---|---|
| `smarthost-postgres-data` | postgres |
| `smarthost-postfix-queue` | postfix (`/var/spool/postfix`) |
| `smarthost-postfix-observability` | postfix (rw), delivery (**ro**) |
| `smarthost-dsn-spool` | postfix (rw), delivery (rw, for claim by rename) |
| `smarthost-opendkim-keys` | opendkim only |
| `smarthost-opendkim-tables` | opendkim only |

## 4. Mail safety (verified, T06/T07/T10/T14)

Three independent layers keep development mail off the Internet:

1. **Capture mode.** With `SMARTHOST_LIVE_DELIVERY_ENABLED=false`, Postfix relays all outbound mail
   to `POSTFIX_RELAYHOST` (`[mailpit]:1025`).
   - A message submitted to `someone@example.com` arrived in Mailpit, and Postfix's log shows
     `relay=mailpit[…]:1025 status=sent` and no other relay attempt.
   - With Mailpit stopped, the message stayed **deferred** ("Host not found") instead of falling
     back to an MX lookup, and it was delivered to Mailpit when Mailpit returned.
2. **Live-mode guard.** The Postfix image refuses to start (exit 64) in each of these cases:
   - live mode in `development` or `test`;
   - live mode in `production` while a relayhost is still set;
   - capture mode without a relayhost;
   - any value of the switch other than `true` or `false`.
3. **Network isolation.** Containers on `smarthost-internal` have no default route.
   - TCP to `1.1.1.1:25` fails with "Network is unreachable", and public MX hosts do not resolve.
   - Published loopback ports and container aliases still work.

## 5. Facts established in Phase 1

| Topic | Observation |
|---|---|
| Postfix version | 3.10.13 (Debian trixie). `postqueue -j` is available. |
| `maillog_file_prefixes` | Default `/var, /dev/stdout`, so the observability directory must be under `/var`. |
| `maillog_file_permissions` | Default `0600`; set to `0640`. The log file is `0640 root:5000` in a setgid `2750 root:5000` directory. |
| `milter_default_action` | The 3.10 default is **`shutdown`**, so `tempfail` is set explicitly (globally and on the submission service). |
| Health check | Sending `QUIT` before the greeting triggers Postfix's pipelining protection (`554 5.5.0`), so the check waits for the 220 banner. |
| Cyrus SASL | Debian's Cyrus SASL does not look in `/etc/postfix/sasl` unless `cyrus_sasl_config_path` is set, and `smtpd.conf` must be readable by the `postfix` user. |
| Config file modes | `virtual(8)` runs as the delivery UID and must read the bounce-recipient table, so the entrypoint writes configuration with umask `022`. Only the shared-volume layout uses `027`. The sasldb is explicitly `0640 root:postfix`. |
| OpenDKIM | 2.11.0. `opendkim-genkey` needs the `openssl` CLI. Dev keys are `0600 opendkim:opendkim` in the keys volume. It signs only mail tagged `ORIGINATING` by the submission service. |
| PostgreSQL roles | `smarthost_owner` can create tables. `smarthost_app`, `smarthost_webhook`, `smarthost_validator` and `smarthost_delivery` cannot (`permission denied for schema public`). None is superuser, createdb or createrole, and a wrong password is refused. |
| nginx → PHP-FPM | `/healthz` is served with `sapi=fpm-fcgi`. After a PHP-FPM restart, nginx reaches it again without being restarted, because it resolves the upstream at request time. |
| Quadlet lifecycle (before D-35) | Quadlet's generated units run containers with `--rm` and remove the pod in `ExecStopPost`, so every stop (including a machine shutdown) deleted the pod and its containers. The persistent pod replaced them. |
| Stop signals | The php-fpm base image sets `STOPSIGNAL SIGQUIT`, which the PHP webhook worker running as PID 1 ignores; its container uses `--stop-signal SIGTERM`. The fake SMTP server (Python, PID 1, no SIGTERM handler) runs with `--init`. Without these, every pod stop waited 30 s for SIGKILL. |
| Containers started by the boot service | A unit that runs the local `podman` CLI leaves each container's `conmon` in the unit's cgroup (its journal fills with container output, and a failed unit would kill them). `smarthost.service` therefore uses the user's Podman API socket (`CONTAINER_HOST`), like Podman Desktop, plus `KillMode=process`. |
| Postfix V-1…V-7 | See `docs/architecture/postfix-integration.md` §8. |

## 6. Verification suite

```sh
infra/bin/smarthostctl verify          # against the running environment
infra/bin/smarthostctl verify --clean  # remove the pod, destroy Smarthost volumes/network first (disposable data)
```

The suite is `infra/tests/phase1-verify.sh`. It runs throwaway clients from
`localhost/smarthost-testtools:dev` on the internal network and writes an evidence log to
`infra/.generated/verify/` (gitignored). It exits non-zero if any check fails.

| Group | Proves |
|---|---|
| T01–T02 | All images build; install loads the boot service and both timers; `default.target` wants `smarthost.service`; no legacy Quadlet unit remains; the service has no `ExecStopPost` and stops with a pod stop; the pod and all 11 containers exist |
| T03–T04 | Clean-state create and start (with `--clean`); all 10 services healthy; the bootstrap, migration and grant tasks ran in order; timers active |
| T05–T06 | Every service is a pod member sharing the pod network namespace; only the pod publishes ports (nginx HTTPS and the Mailpit UI, both on loopback); the pod sits only on the `Internal=true` network; no Internet egress |
| T07 | The live-mode guard refuses every unsafe combination; a pod member cannot relay through port 25 via `127.0.0.1` |
| T08 | nginx → FastCGI → PHP-FPM (`/healthz`, now served by the Symfony front controller), including after a PHP-FPM restart |
| T09 | PostgreSQL 16 version, role privileges and isolation from other networks |
| T10–T11 | Submission on 587 → Mailpit only, with a cryptographically valid DKIM signature |
| T12 | The DKIM private key is visible only to OpenDKIM |
| T13 | OpenDKIM down → 4xx tempfail and no unsigned mail; port 25 unaffected; recovery |
| T14–T17 | V-1…V-7: snapshots, rotation, DSN spool claim, inotify, shared identity |
| T18 | Fake SMTP reproduces 250/421/450/451/550, DATA discard and timeout |
| T19 | `smarthostctl restart` keeps the same pod ID and all 11 container IDs; PostgreSQL data, a held Postfix message, the DSN spool, the log generation and the DKIM key survive |
| T20 | `smarthostctl stop` leaves the pod listed by `podman pod ps` and all 11 containers listed by `podman ps -a` as exited; the boot service becomes inactive; `start` reuses the same IDs; data survives |
| T21 | `podman pod stop`/`start` (what Podman Desktop does): after 20 s stopped, nothing was restarted, removed or recreated; start reuses the same IDs; data survives |
| T22 | The boot path (`systemctl --user start smarthost.service`) starts the existing pod with the same IDs; the timers run with it |
| T23 | `smarthostctl recreate` creates a new pod and replaces all 11 containers (no ID survives) while the six volumes stay the same volumes and all data survives |
| T24 | Contract checks pass |
| T25 | Containers of other Podman projects are unchanged |

The latest clean-state run (after Phase 4) passed all 180 checks.

## 7. The Symfony application and the test suites

### Start-up order

`db-bootstrap` (roles) → `db-migrate` (Doctrine migrations as `smarthost_owner`) → `db-grants`
(`infra/postgres/grants.sh`, the `schema.md` §6 matrix) → `symfony-app`, `webhook-worker`,
`validator`, `delivery`. Migrations are idempotent, so every start re-checks them; the grants are
re-applied every time.

### Development data

```sh
infra/bin/smarthostctl console smarthost:dev:bootstrap [--operator-email you@smarthost-dev.test]
```

This creates (or reuses) an active development client and the sending domain
`smarthost-dev.test` and **marks it verified without DNS**, with DKIM active and selector
`phase1`, matching the disposable key from `smarthostctl dkim-dev-key`. It refuses to run unless
`SMARTHOST_ENV` is `development` or `test`. A new API key (and, optionally, an operator with a
random password) is printed **once**; only its SHA-256 hash is stored.

```sh
K=shk_...   # the printed key
curl -sk https://127.0.0.1:8443/v1/send-jobs -H "Authorization: Bearer $K" \
  -H 'Content-Type: application/json' -H "Idempotency-Key: $(uuidgen)" \
  -d '{"external_reference":"demo","message_class":"transactional","sender_identity":{"email":"dev@smarthost-dev.test"}}'
```

Other administration (clients, keys, users, memberships, sending domains, webhook endpoints) is
done with the `smarthost:*` console commands (`console list smarthost`), never through undocumented
API endpoints. Submitted send jobs stay `queued` until the Go delivery daemon exists (Phase 4);
nothing creates messages or talks to Postfix yet. Validation jobs are processed by the Python
validator (Phase 3). The development pod has no Internet route, so real domains classify as
`undeliverable` (NXDOMAIN) there; meaningful validation results come from the fake DNS/SMTP
scenarios of `smarthostctl test phase3`.

### Test suites

```sh
infra/bin/smarthostctl test                                 # Phase 2 and Phase 3 suites
infra/bin/smarthostctl test phase2                          # PHPUnit only
infra/bin/smarthostctl test phase2 --testsuite schema       # one suite (unit, contract, schema, integration)
infra/bin/smarthostctl test phase3                          # pytest + end to end
infra/bin/smarthostctl test phase3 -k leases                # pytest arguments
```

Both harnesses use the throwaway test pod of `infra/tests/testpod.sh`, which has **no network at
all** (members share only loopback): PostgreSQL 16.15, the real role bootstrap, the Doctrine
migrations on an **empty** database and the grants. The pod is labelled `project=smarthost` and
removed afterwards; credentials are random per run.

**Phase 2** (`infra/tests/phase2-test.sh`) also loads the reference schema into a separate
`<db>_reference` database used only for comparison. PHPUnit then runs as the least-privilege
application role; DNS is stubbed and nothing reaches the Internet.

| Suite | Proves |
|---|---|
| unit | D-18 normalisation (including IDNA), canonical request hashing, the keyring, fail-closed configuration, log format |
| contract | `/v1` routes are exactly the OpenAPI operations; OpenAPI enums equal the PHP enums |
| schema | The migrated catalog equals `reference-schema.sql` (tables, columns, defaults, constraints, indexes); migrations up/down/up and per-migration rollback; ORM mapping equals the database; CHECKs equal the vocabulary; grants equal `schema.md` §6; least-privilege behaviour; critical CHECK/UNIQUE/FK behaviour |
| integration | Authentication, tenant isolation, idempotency (including concurrent retries from separate processes), validation jobs, send jobs, sending domains, dashboard users, webhooks, console commands, health and audit; every response is validated against the OpenAPI contract |

Latest run: 163 tests and 1,578 assertions, all passing (unit 37, contract 2, schema 33,
integration 91). Unit includes the shared D-32 vectors; integration includes the D-31 client-status
rules.

**Phase 3** (`infra/tests/phase3-test.sh`) builds the validator `test` and `runtime` images, then:

1. **pytest** as the `smarthost_validator` role: unit tests (D-32 vectors, syntax, typos, roles,
   configuration, DNS, SMTP, limits, classification, pipeline), database tests (claiming, D-31
   suspension, renewal, expiry and reclaim, fencing, atomic finalisation, D-33 metering under
   crash/retry/reclaim, outbox, whole-job failure) and worker tests against fake DNS and fake SMTP;
2. **end to end:** a fake DNS server (`validator/tests/fakes/fake_dns.py`, zone
   `validator/tests/e2e/zone.json`) and the fake SMTP service join the pod; Symfony creates jobs
   through `/v1` (a mixed-scenario job, a 10,000-address job and a suspended client's job); the
   real worker runs, is killed with SIGKILL mid-job and restarted, and Symfony verifies results,
   counters, usage and the outbox through `/v1` and the database. The harness fails if the fake SMTP
   server's command log contains `DATA` or `BDAT`. The report is written to
   `infra/.generated/phase3-e2e-report.json`.

Latest run: 295 pytest tests passed; end to end PASS (details in `CHANGELOG.md`).

**Phase 4** (`infra/tests/phase4-test.sh`) builds the delivery `test` image (gofmt and go vet run
during the build), runs the Go unit tests with no network (D-32 vectors, identifiers and VERP,
MIME and header injection, tracking, SMTP submission outcomes, Postfix log parsing and generations,
snapshots, pacing, projection, configuration) and then the integration tests in the test pod as
`smarthost_delivery`, with an in-process submission server and fixture Postfix logs and snapshots.

**Phase 4 end to end** (`infra/tests/phase4-e2e.sh`, `smarthostctl test phase4-e2e`) uses the
running pod: jobs are created through `/v1`; scenarios A–C run on the pod's own delivery daemon,
D and E on throwaway worker containers in the pod (20 s lease, test pacing) while the pod's daemon
is stopped:

| Scenario | Proves |
|---|---|
| A | Subscription (tracking, Reply-To) and transactional jobs reach Mailpit with the VERP Return-Path, Message-ID, List-Unsubscribe/-Post/List-Id (subscription only), tracking pixel and rewritten links, an untouched text part, a DKIM signature verified with dkimpy; events, purge, one usage unit per message, summary counts, `send.completed` |
| B | OpenDKIM stopped: the message stays queued with its content and no usage, nothing reaches Mailpit, the daemon retries; once OpenDKIM is back the message is delivered signed |
| C | Mailpit stopped: `connection_failure` → deferred, job dispatched not completed; then `delivery_attempt`, `remote_accepted`, completed |
| D | A worker is killed with SIGKILL mid-job; after its lease expires another worker reclaims it, recovers in-flight submissions from the Postfix log, and finishes; every message reached Postfix exactly once (checked in the log), usage and purge exactly once |
| E | 10,000 recipients over 50 domains, a log rotation mid-run: exactly-once submission, usage and purge, completion via the log, pacing limits and bounded memory |

Latest runs: Go unit tests (10 packages) and 14 integration tests pass; end to end A–E pass (the
10,000-recipient job completed in about two minutes with a peak RSS of 25 MiB).
