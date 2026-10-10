# catto-mail (Smarthost)

A self-hosted platform for **email validation**, **tracked outbound SMTP delivery**,
**bounce and event tracking**, and **reputation control**, for client applications such as a
mailing-list application. The data model is multi-tenant from the start, so it can later serve
third-party clients as a SaaS.

**Version:** 0.2.3 ([releases](https://github.com/rrcatto/catto-mail/releases)) · **Status:** Phases 0–7 complete (Phase 7 Smarthost side); Phases 8 and 9 repository side (v0.1.8); Phase 10 operator self-service (installer, setup wizard, diagnostics, help, address batches, re-permission) in v0.1.9 (specification 2.11); v0.2.0 runs the development environment on a local Linux Podman 4.9 engine; v0.2.1 fixes the installer when run from root's home; v0.2.2 redesigns the dashboard ([screenshots](#screenshots)); v0.2.3 puts every time in the installation's time zone (SAST by default), shortens `infra/.env` to grouped operator settings and adds System › Settings; live production steps and the ctnlist side of re-permission outstanding

**Install it:** [docs/production/VPS-INSTALL.md](docs/production/VPS-INSTALL.md) — one command on a clean Ubuntu Server 26.04 LTS VPS.

Contents: [What is catto-mail?](#what-is-catto-mail) · [Screenshots](#screenshots) · [Architecture](#architecture) ·
[Install on an Ubuntu VPS](#install-on-an-ubuntu-vps) · [Administrator setup](#administrator-setup) ·
[How validation works](#how-validation-works) · [How sending works](#how-sending-works) ·
[How tracking works](#how-tracking-works) · [Production operations](#production-operations) ·
[API integration](#api-integration) · [Troubleshooting](#troubleshooting) ·
[Development](#development) · [Project status](#project-status)

---

## What is catto-mail?

| Function | How |
|---|---|
| Email validation | A Python worker checks syntax, typos, DNS/MX/Null-MX, disposable and role addresses, and SMTP RCPT probing (never `DATA`), and records evidence-based results that keep uncertainty visible. |
| Tracked delivery | Client applications submit fully rendered, recipient-specific messages in batches. A Go daemon creates one message per recipient, applies VERP and optional tracking, and submits to Postfix. OpenDKIM signs. |
| Bounces and events | Postfix logs, queue snapshots, inbound DSNs and ARF complaints become append-only message events, correlated to the exact message (or kept as unmatched DSNs for the operator). Reconciliation catches lost events. |
| Engagement tracking | Opt-in per job: an open pixel and click redirects with opaque per-message tokens record *recorded* opens and clicks (never claimed as proof of reading); redirects go only to targets stored when the message was built. |
| Sign-in and access control | Passwordless: the dashboard emails a single-use sign-in link (captured by Mailpit in development). `APP_ADMIN_EMAIL` is always the administrator (ADMIN: every permission); other users, roles, permissions and client memberships are managed in the browser. |
| Dashboards | Server-rendered Twig + Stimulus pages: a client dashboard (validation results and CSV export, send jobs, message timelines, suppressions, sending domains, usage) and an operator dashboard (system health from durable signals, clients, unmatched DSNs, suppressions, audit log, webhook deliveries and workers) and client webhook-endpoint management. |
| Reputation safety | Global transport suppressions (recipient-specific hard bounce, complaint, repeated recipient soft bounce) that apply to every client, explicit recipient global opt-outs from operator-authorised clients, sending-domain verification and operator controls. |
| Integration | A versioned HTTPS API (`/v1`) and signed webhooks. Clients never touch Smarthost's database. |

Smarthost is **not** a mailing-list or template engine. Subscribers, consent, templates, mail
merge and unsubscribe state all belong to client applications. The one exception is a recipient's
explicit request not to receive email from any source using the installation, which an authorised
client may report as a global opt-out.

## Screenshots

The operator console's overview, with the period choice, charts and *Needs attention*:

![Operator overview](docs/screenshots/operator-overview.png)

| Mail flow › Delivery | A client's overview |
|---|---|
| ![Mail flow › Delivery](docs/screenshots/operator-mail-flow-delivery.png) | ![Client overview](docs/screenshots/client-overview.png) |

Every page, including the client workspace, the setup wizard and the phone layout, is in
[docs/screenshots](docs/screenshots/README.md). They are taken on the development pod, so the
figures are test data.

## Architecture

```mermaid
flowchart LR
    client["Client application"] -- "HTTPS /v1" --> nginx
    nginx -- FastCGI --> app["Symfony PHP-FPM<br/>API · dashboards · tracking"]
    app <--> pg[(PostgreSQL 16)]
    worker["Symfony webhook worker"] <--> pg
    worker -- "signed webhooks" --> client
    val["Python validator"] <--> pg
    val -- "RCPT probes (no DATA)" --> mx[(Remote MX)]
    del["Go delivery daemon"] <--> pg
    del -- "SMTP 587" --> pf[Postfix]
    pf -- milter --> dkim[OpenDKIM]
    pf -- "dev: capture" --> mp[Mailpit]
    pf -- "prod: live" --> net((Internet))
    net -- "DSNs on port 25" --> pf
    pf -- "log · queue snapshots · DSN Maildir" --> del
```

Everything runs under **rootless Podman** as an unprivileged user; there is no Docker, no
Kubernetes and no message broker: PostgreSQL is the only coordination medium. In production the
eight services run as standalone containers on internal, ingress and egress networks; only nginx
receives traffic from the Internet, through a systemd socket that preserves client addresses. In
development they share one persistent pod. The components, one by one:
[docs/production/components.md](docs/production/components.md); what happens to an address and a
message, step by step: [docs/production/architecture.md](docs/production/architecture.md).

## Install on an Ubuntu VPS

You need an Ubuntu Server 26.04 LTS VPS with a static public IPv4, ports 25 and 443 open, outbound
port 25 allowed, and control of the IP's PTR. Releases (notes and zip downloads) are on the
[releases page](https://github.com/rrcatto/catto-mail/releases); `v0.1.9` is the first with the
installer. As root:

```sh
apt-get update && apt-get install -y git
git clone --branch vX.Y.Z --depth 1 https://github.com/rrcatto/catto-mail.git /root/catto-mail-installer
/root/catto-mail-installer/install-catto-mail
```

It installs Podman and its helpers from Ubuntu, creates the `cattomail` service user, sets the
server's time zone (`Africa/Johannesburg`, SAST, unless `--timezone` names another), installs the
release, generates the configuration and secrets, builds the images, starts everything in **HELD**
mode (nothing is delivered to the Internet) and prints the address and a sign-in link. You log in
to the server with your own account and reach the service user from root with
`sudo -iu cattomail` (it has no password). No DNS
server (BIND), PHP, PostgreSQL or Postfix is installed on the host. Everything — sizing, DNS, PTR,
Let's Encrypt, DKIM, firewall, backups, going live, upgrades — is in
**[docs/production/VPS-INSTALL.md](docs/production/VPS-INSTALL.md)**.

## Administrator setup

After the first sign-in, the web application opens **System setup**: a resumable wizard through
the host, web and SMTP identity, DNS (each record with a live test), TLS, DKIM, the database, each
component, backups, a seed delivery test, a bounce test, a reboot test and a production-readiness
summary. Every check says PASS, WARN, FAIL or SKIPPED with an explanation and a fix, and keeps a
history (System › Diagnostics). **Help** explains every subject from the implementation. Live
delivery stays a separate, deliberate, audited action.

## How validation works

The Python validator checks each address: normalisation, syntax, typo suggestions (never applied
automatically), DNS (MX, null MX, address fallback), disposable and role flags, and — only if
enabled — an SMTP mailbox probe with accept-all detection. Temporary failures are retried before a
final verdict: valid, invalid, risky, unknown or temporary failure, each with its evidence. A valid
address is evidence, never proof of a reader and never consent. Administrators can validate lists
of up to 10,000 addresses as **address batches** and review them by separate dimensions
(validation result, eligibility, consent, delivery, engagement).

## How sending works

Clients submit fully rendered messages in send jobs. The Go delivery daemon claims the job, skips
suppressed recipients (checked again right before each message), builds each message (VERP return
path, tracking, List-Unsubscribe) and submits it to Postfix at a controlled pace; OpenDKIM signs
it and Postfix delivers it. Results come from Postfix's log, queue snapshots, and bounces and
complaints received on port 25. catto-mail reports **remote accepted**, never "delivered to the
inbox". New installations send in stages: a seed test, a controlled sample, a gradual rollout.

## How tracking works

When a job asks for it, each HTML message gets a tracking image and rewritten links with an opaque
per-message token. An **open observed** means the image was fetched — by the person, a mail
client, a privacy proxy or a scanner — so it is never proof of reading. Clicks redirect only to
the link's stored original address. Tokens are never logged, and no cookie is set.

## Production operations

| Need | Where |
|---|---|
| Delivery mode, **STOP SENDING EMAIL NOW** (also in the top bar), component health | Mail flow › Delivery |
| Every check and its history | System › Diagnostics |
| Operational settings (pacing, validation, webhooks, limits, reputation thresholds, retention, time zone, log level) without editing `infra/.env` | System › Settings (a dashboard value overrides the file; *Apply changes* restarts only the affected services) |
| Configuration file | `infra/.env`: only the operator's settings, grouped; everything else is built in. Every time is in `SMARTHOST_TIMEZONE` (SAST by default) |
| Commands (`smarthostctl prod …`): logs, backups, restore, upgrade, rollback, DKIM, TLS | [docs/production/runbook.md](docs/production/runbook.md) |
| Topology, networks, launch decisions | [docs/production/README.md](docs/production/README.md) |
| Clients: onboarding, limits, keys, reputation, usage and billing | [docs/production/onboarding.md](docs/production/onboarding.md) |
| Address batches and re-permission | Clients › Address batches; Help |

## API integration

Clients use the versioned HTTPS API (`/v1`) with an API key and receive signed webhooks; they never
touch catto-mail's database. The contract and the integration guide are served publicly at
`/docs/api` ([docs/api/integration-guide.md](docs/api/integration-guide.md),
[docs/api/openapi.v1.yaml](docs/api/openapi.v1.yaml)). The ctnlist mailing-list application's
integration, including the outstanding re-permission handling:
[docs/integration/ctnlist.md](docs/integration/ctnlist.md).

## Troubleshooting

Start with System › Diagnostics: each FAIL or WARN says what it means and what to do. Help ›
Troubleshooting maps symptoms to causes; logs are one command away
(`smarthostctl prod logs app|webhook|validator|delivery|postfix|opendkim|nginx|postgres|agent`).
Installation problems: [VPS-INSTALL.md §15](docs/production/VPS-INSTALL.md#15-troubleshooting-the-installation).



## Repository layout

```
app/              Symfony 8.1 application (PHP-FPM): /v1 API, tracking endpoints, dashboards, entities, migrations, auth
validator/        Python validation worker: leased claiming, DNS/SMTP evidence, classification (never DATA)
delivery/         Go delivery daemon: send jobs -> Postfix, VERP, tracking, MIME, log ingestion, reconciliation
postfix/          Postfix image: capture/live safety switch, DSN spool, snapshots
opendkim/         OpenDKIM milter image and disposable dev-key tool
infra/            Persistent pod definition, systemd boot service and timers, nginx, DB bootstrap, smarthostctl, test suites
tests/fake-smtp/  Deterministic fake SMTP server
docs/             Specifications, contracts, architecture, schema, API
scripts/          Contract consistency checker
```

The full file-by-file description and workflow diagrams are in
**[docs/PROJECT.md](docs/PROJECT.md)**.

## Documentation

| Start here | Purpose |
|---|---|
| [docs/production/VPS-INSTALL.md](docs/production/VPS-INSTALL.md) | **Installing on an Ubuntu VPS** (start here) |
| [docs/production/components.md](docs/production/components.md) | Every component: container, unit, purpose, inputs, outputs, network, data |
| [docs/production/architecture.md](docs/production/architecture.md) | What happens to an address and a message, step by step |
| [docs/integration/ctnlist.md](docs/integration/ctnlist.md) | The ctnlist integration and its outstanding re-permission work |
| [docs/20260908-1644-smarthost-llm-spec.yaml](docs/20260908-1644-smarthost-llm-spec.yaml) | **Authoritative** specification (2.11) |
| [docs/20260908-1644-smarthost-human-specification.md](docs/20260908-1644-smarthost-human-specification.md) | Human-readable companion |
| [docs/PROJECT.md](docs/PROJECT.md) | Directory structure, every file's purpose, workflow diagrams |
| [docs/development-environment.md](docs/development-environment.md) | Running the Podman environment, the application and the test suites |
| [docs/production/README.md](docs/production/README.md) | Production topology: network matrix, the socket-activated ingress (client addresses), launch decisions, delivery states, where state lives |
| [docs/production/runbook.md](docs/production/runbook.md) | Production runbook: VPS, installation, TLS, DNS/PTR/SPF/DKIM/DMARC, activation, seed tests, warm-up, emergencies, backups, upgrades |
| [docs/production/onboarding.md](docs/production/onboarding.md) | Client onboarding checklist, lifecycle, limits, keys, reputation alerts, usage and billing statements; the public-onboarding decision |
| [docs/api/integration-guide.md](docs/api/integration-guide.md) | For client developers (served at `/docs/api`): authentication, jobs, limits, webhooks, and what the statuses mean |
| [docs/README.md](docs/README.md) | Index of all contracts and architecture documents |

## Development

Requirements: rootless Podman 4.9 or later, either on a Linux host (local engine) or in a WSL
Podman machine, with lingering and the user's `podman.socket` enabled, plus Python 3.10 or later
with PyYAML. Details: [docs/development-environment.md](docs/development-environment.md) §1.

```sh
infra/bin/smarthostctl init-env     # infra/.env with random dev secrets (gitignored; grouped settings)
infra/bin/smarthostctl build        # build all images
podman pull docker.io/library/postgres:16.15-trixie docker.io/axllent/mailpit:v1.31.0   # once per host
infra/bin/smarthostctl secrets      # disposable dev TLS certs as Podman secrets
infra/bin/smarthostctl dkim-dev-key # disposable dev DKIM key (OpenDKIM volume only)
infra/bin/smarthostctl install      # systemd boot service + create the persistent pod and containers
infra/bin/smarthostctl start        # start the existing pod (ordered: PostgreSQL, DB tasks, services)
infra/bin/smarthostctl status       # pod and containers (they stay listed when stopped)
infra/bin/smarthostctl verify       # Phase 1 verification suite
infra/bin/smarthostctl test         # Phase 2, 3 and 4/5 test suites (throwaway, network-less pods)
infra/bin/smarthostctl console smarthost:dev:bootstrap   # dev client + API key (shown once)
```

Lifecycle: `stop`, `start` and `restart` keep the same pod and containers (exactly like Podman
Desktop's Stop/Start buttons). After `build` or a container-definition change, `recreate` replaces
the pod and containers while keeping every volume. Only `destroy-volumes --yes` deletes data. See
[docs/development-environment.md](docs/development-environment.md) §2.

Production is a separate command family, `infra/bin/smarthostctl prod help`. It deploys a
different topology on a dedicated Ubuntu Server 26.04 LTS host; start with
[docs/production/VPS-INSTALL.md](docs/production/VPS-INSTALL.md).

The API is then at `https://127.0.0.1:8443/v1` (disposable self-signed certificate). Submitted send
jobs are delivered by the Go daemon through Postfix and OpenDKIM to Mailpit
(`http://127.0.0.1:8026`); their messages' events come from the Postfix log.

Development mail never leaves the machine. Postfix runs in capture mode, relaying everything to
Mailpit, and every Smarthost container sits on an `Internal=true` Podman network with no route
to the Internet.

Check the contracts with:

```sh
python3 scripts/check-contracts.py
```

## Project status

| Phase | Status |
|---|---|
| 0: Architecture and contracts | **Complete** (current specification 2.11) |
| 1: Rootless Podman development environment | **Complete.** `smarthostctl verify` passes all 176 checks (including the persistent pod lifecycle and the DSN spool with the Phase 5 daemon); `verify --clean` additionally proves a start from destroyed volumes. Since v0.2.0 it also runs on a local Linux engine with Podman 4.9, where two DNS checks fail for a known aardvark-dns limitation ([§1](docs/development-environment.md#1-requirements)). |
| 2: Database and Symfony foundation | **Complete.** Migrations reproduce the reference schema; API-key auth, tenant isolation, idempotency, validation-job and send-job primitives. `smarthostctl test phase2`: 303/303 tests pass (including the Phase 5 opt-out API and operator commands, the Phase 6 tracking and dashboard tests, passwordless sign-in and ACL, and the Phase 7 webhook worker, dashboard, query-plan and web-isolation tests, and the Phase 8 operations and production-guard tests). |
| 3: Python validation engine | **Complete.** Leased claiming, D-32 normalisation, syntax, typo suggestions, DNS/MX/Null MX, disposable/role flags, SMTP probing without DATA, per-domain/MX limits, retries, conservative classification, D-33 metering, outbox events. `smarthostctl test phase3`: 298 pytest tests and the 10,000-address end-to-end run pass. |
| 4: Go/Postfix delivery pipeline | **Complete.** Leased send-job claiming, exactly-once message creation, suppression lookup, VERP, tracking, MIME and RFC 8058 headers, authenticated submission through OpenDKIM, queue-id capture with content purge and `message_submitted` metering, Postfix log ingestion with persistent cursors, D-27 reconciliation, pacing. `smarthostctl test phase4` (Go unit + PostgreSQL integration) and `test phase4-e2e` (real Postfix/OpenDKIM/Mailpit, crash/restart, 10,000 recipients) pass. |
| 5: Inbound DSN, complaint and global suppression processing | **Complete** (v0.1.5; the spec 2.5 corrections D-36–D-38 in v0.1.6). D-30: global suppressions with provenance, the trusted-client opt-out API with durable request idempotency and operator capability, RFC 3464/6533 DSN and ARF parsing, correlation only by Smarthost identifiers (VERP, ENVID, Message-ID, queue id; never by recipient), conservative classification, the unmatched-DSN operator workflow, spool claim/reclaim/retention. `smarthostctl test phase5` and `test phase5-e2e` (real Postfix :25 and spool) pass. |
| 6: Tracking and dashboards | **Complete** (v0.1.6; tracking and dashboard behaviour from specification 2.6, sign-in and ACL from 2.7). Public `/t/o/{token}.gif` and `/t/c/{token}/{n}` with eligibility checks, identical answers for unknown tokens, a bounded recording rule and token-free logs; redirects only to stored targets (open-redirect attempts tested). Client and operator dashboards with passwordless emailed single-use sign-in links (specification 2.7), tenant isolation (foreign ids are 404), keyset pagination, whitelisted sorting/filtering, CSRF-protected POST actions through the existing audited services, CSP. `smarthostctl test phase2` (tracking, dashboards, two-client isolation, operator authorisation, 10,000-row load test with query plans) and `test phase6-e2e` (through nginx against the running pod, sign-in links taken from Mailpit) passes 75/75. |
| 6.1: Passwordless sign-in, roles and ACL | **Complete** (v0.1.6; specification 2.7). Emailed single-use links through Postfix, `APP_ADMIN_EMAIL` administrator, permission-keyed operator pages, built-in ADMIN/OPERATOR and custom roles, Users and Roles pages; no passwords. |
| 7: First client API integration | **Smarthost side complete** (v0.1.7; specification 2.8). The real Symfony webhook worker: transactional outbox fan-out; HMAC-SHA256 signatures with rotation overlap; retries with backoff; at-least-once delivery with leases and fencing; SSRF protection with address pinning and no redirects; health and heartbeats. Also `POST /v1/webhooks/test`, dashboard webhook management and delivery visibility. `smarthostctl test phase7-e2e` proves the client workflow without database access using a deterministic external client (an API-only driver and an independent signature-verifying receiver): validation, a 501-recipient batched send, tracking, bounce and complaint webhooks, receiver outage, worker crash with de-duplication, permanent failure with polling fallback, and SSRF refusals. **Outstanding:** integrating the real first client application in its own repository. |
| 8: Controlled production launch | **Repository side implemented** (v0.1.8; specification 2.9). A production topology of standalone containers on internal, ingress and egress networks (no published ports: a systemd socket-activated ingress hands 443/25 to nginx, so nginx/Symfony and Postfix see real client addresses), `smarthostctl prod` (install, build, TLS, DKIM keys, preflight, live activation, pause, backup/restore, upgrade/rollback, seed test), the production configuration profile and safety rules, held mode until an audited, preflight-gated activation, the production preflight (host, runtime, exposure, ingress, TLS, DNS/PTR/SPF/DKIM/DMARC, bounce domain, Postfix, delivery), an installation-wide warm-up ceiling and per-client throttling, Postfix queue depth on the dashboard, and the runbook. Proven by `smarthostctl test phase8` and a local production rehearsal without Internet egress (`test phase8-rehearsal`). **Outstanding (operator):** VPS, DNS/PTR, certificates, live seed and bounce tests, traffic rollout. |
| 9: Public SaaS hardening | **Repository side implemented** (v0.1.8; specification 2.10). Client lifecycle with approval, throttling, suspension and closure (reasons, permissions, audit); public onboarding gated off (no public route); service-policy acceptance; per-client limits with concurrency-safe quotas (`429 quota-exceeded`); API-key names and expiry; usage summaries, reconciliation, provider-neutral billing statements and export; reputation metrics and alerts (no automatic action; production timer); webhook endpoint health; public API docs at `/docs/api`; a two-client isolation review and query plans at scale. Plans, prices, policy text, threshold calibration and opening public onboarding are owner decisions ([onboarding.md](docs/production/onboarding.md)). |
| 10: Operator self-service | **Repository side implemented** (v0.1.9; specification 2.11). The one-command installer for Ubuntu Server 26.04 LTS (exercised on Ubuntu 26.04 under systemd), the bootstrap sign-in link, the host agent, the administrator setup wizard, diagnostics with history and the system health dashboard, the web emergency stop, Let's Encrypt DNS-01 automation, scheduled encrypted off-host backups and restore rehearsals, log shortcuts, help and tutorials, address batches (10,000 addresses) with separate state dimensions and staged sends, and the re-permission page. **Outstanding:** the real VPS steps, and ctnlist's handling of `repermission.responded`. |
| Dashboard redesign | **Complete** (v0.2.2; owner decision, specification `user_interfaces.dashboard_layout`). Every page in one full-width shell with area buttons and page pills, Chart.js charts, global search, a period choice on both overviews, a submission-rate chart and times, quota days and billing months in the installation time zone. [Screenshots](docs/screenshots/README.md). |
| Time zone, configuration and settings | **Complete** (v0.2.3; owner decisions). Every time — database sessions, API and webhook timestamps, exports, every service's logs, Postfix, file names, quota and billing days — is in `SMARTHOST_TIMEZONE` (SAST by default, offset `+02:00`). `infra/.env` holds only the operator's settings under plain headings (`smarthostctl env-migrate` converts an older file and keeps it as `infra/.env.v<version>-<date and time>`). System › Settings overrides 69 operational settings without changing the file, with a reason and an audit record for every change. |

See [CHANGELOG.md](CHANGELOG.md).

## Licence

MIT. See [LICENSE](LICENSE).
