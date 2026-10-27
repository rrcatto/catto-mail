# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Read `AGENTS.md` first; everything there applies. This file adds Claude-specific working notes.

## Authority
1. `docs/20260908-1644-smarthost-llm-spec.yaml` (specification 2.13) is authoritative.
2. The normative contracts it lists (`instruction_for_llm.normative_contracts`) elaborate it:
   - `docs/contracts/status-vocabulary.yaml`
   - `docs/api/openapi.v1.yaml`
   - `docs/schema/reference-schema.sql` and `docs/schema/schema.md`
   - `docs/contracts/environment.md`
   - `docs/architecture/postfix-integration.md`
3. `docs/architecture/open-decisions.md` is a **decision log only**, never a source of authority.
4. `docs/PROJECT.md` describes every file and the main workflows. Keep it current when you add,
   move or delete files.

## Commands
The toolchains (PHP 8.5, Python 3.14, Go 1.26) exist only in the container images: every build,
lint and test runs through Podman via `infra/bin/smarthostctl`. The host needs rootless Podman 4.9+
(the spec's minimum), lingering and the user's `podman.socket`, and Python 3.10+ with PyYAML
(`scripts/check-contracts.py`).

```sh
# First setup (docs/development-environment.md §1–2)
infra/bin/smarthostctl init-env && infra/bin/smarthostctl build && infra/bin/smarthostctl secrets
podman pull docker.io/library/postgres:16.15-trixie docker.io/axllent/mailpit:v1.31.0  # once per engine (--pull never)
infra/bin/smarthostctl dkim-dev-key && infra/bin/smarthostctl install && infra/bin/smarthostctl start
infra/bin/smarthostctl console smarthost:dev:bootstrap   # dev client + API key (printed once)

python3 scripts/check-contracts.py    # spec <-> vocabulary <-> OpenAPI <-> DDL <-> env contract
infra/bin/smarthostctl test           # phase2 + phase3 + phase4 in throwaway, network-less pods
infra/bin/smarthostctl console <cmd>  # Symfony console in the running app container
```

The throwaway suites build their test images from the working tree, so they always test the
current code. The running pod's images contain the code (no bind mounts), so the e2e suites
(`test phase4-e2e` … `phase7-e2e`) see a change only after `build` and `recreate`.

Arguments after the phase go to the underlying runner:

| Suite | What runs | Narrowing it |
|---|---|---|
| `test phase2` | PHPUnit (`app/phpunit.dist.xml`: suites unit, contract, schema, integration). Any deprecation, notice or warning fails. No PHP linter. | `test phase2 --testsuite schema`, `test phase2 --filter GlobalSuppressionApiTest` |
| `test phase3` | `ruff check` and `mypy`, then pytest, then the 10,000-address e2e (always) | `test phase3 tests/test_syntax.py -k <expr>` (paths relative to `validator/`) |
| `test phase4` = `phase5` | gofmt and go vet (in the image build), Go unit tests, then all `integration`-tagged tests | `test phase4 -run TestX ./internal/dsn/...` (name the package; the default is `./...`); `PHASE4_RUN=<regex>` narrows the integration tests |
| `test phase8` | `infra/tests/phase8` unittest, shellcheck of the shell tooling and the installer, Postfix/nginx/DKIM-tool checks | — |
| `test phase4-e2e [A-E]`, `phase5-e2e [A-G]` | scenarios against the running pod | scenario letters |

## Architecture
Diagrams: `docs/PROJECT.md` §3. Cross-language rules: `docs/architecture/conventions.md`. These
points span components and need several files to see:
- **PostgreSQL is the only coordination medium.** There is no broker and no service-to-service
  RPC. The processes share one schema, each as its own least-privilege role: `smarthost_app` (web),
  `smarthost_webhook` (webhook worker), `smarthost_validator`, `smarthost_delivery`, and
  `smarthost_owner` for migrations only. The grants are part of the design: Python and Go
  deliberately lack some privileges (for example SELECT on `webhook_events`, so no
  `ON CONFLICT`). All suites run as the real roles.
- **Leased work.** Workers claim with `FOR UPDATE SKIP LOCKED` and a lease. Every write is fenced
  on still holding the lease and re-checks that the client is active or throttled (D-31). Symfony
  `NOTIFY`s `smarthost_send_work` / `smarthost_validation_work`; the workers also poll.
- **Transactional outbox.** A client-visible change inserts `webhook_events` in the same
  transaction. Only `smarthost:webhook:work` fans out to `webhook_deliveries` and sends.
- **Append-only events.** `messages.current_status` is a rank-ordered projection
  (`delivery/internal/status`), so replayed or out-of-order events never regress a message.
- **Postfix has no API to Go.** Go submits on 587 with VERP and ENVID. It learns outcomes from:
  - the Postfix log (`ingest`, `postfixlog`; persistent cursors);
  - `postqueue -j` snapshots (`snapshot`, `reconcile`, D-27); both are on the shared
    observability volume;
  - inbound DSN/ARF files in the Maildir spool (`dsnspool`, `dsn`; claimed by rename).
  Correlation uses only Smarthost identifiers, never the recipient address (D-36). All SQL and
  the suppression policy live in `delivery/internal/store`.
- **One vocabulary in three languages.** Statuses and events come from `status-vocabulary.yaml`:
  - the PHP enums are checked against OpenAPI (contract suite);
  - the CHECK constraints are checked against the vocabulary (schema suite);
  - Go mirrors it in `delivery/internal/status`.
  Address normalisation (D-32) is implemented in PHP, Python and Go and tested against the shared
  `docs/contracts/address-normalization-vectors.json`. A change touches every copy.
- **Contracts are enforced at runtime and in tests.**
  - Request bodies are validated against `openapi.v1.yaml` (`app/src/Api/OpenApiContract.php`),
    and tests validate every API response.
  - `/v1` routes must equal the OpenAPI operations.
  - The migrated schema must equal `reference-schema.sql`.
- **Rendered configuration.** `infra/.env` goes through `smarthost_render.py` to per-service env
  files, the pod script and the systemd units in `infra/.generated/`. The templates are
  `infra/podman/smarthost-pod.sh.in` (dev) and `smarthost-production.sh.in`.
- **Symfony layout.** Domain services are in `app/src/<Area>/`. Controllers in
  `app/src/Controller/{Api,Dashboard}/` stay thin. Operator CLI commands are in
  `app/src/Command/` (`smarthost:*`).

## Current state
- **Phase 0:** complete.
- **Phase 1** (rootless Podman development environment): complete. `infra/bin/smarthostctl verify`
  (optionally `--clean`) re-proves it. See `docs/development-environment.md`. Since v0.2.0 it also
  runs on a local Linux engine with Podman 4.9; there, verify T06 and T13 fail for a known
  aardvark-dns 1.4 limitation (§1 *Podman 4.9 notes*), which is not a regression.
- **Phase 2** (database and Symfony foundation): complete (v0.1.2). The Symfony 8.1
  application is in `app/`; `infra/bin/smarthostctl test phase2` runs its suite in a throwaway pod.
- **Phase 3** (Python validation engine): complete (v0.1.3). The worker is in `validator/`;
  `infra/bin/smarthostctl test phase3` runs pytest and the 10,000-address end-to-end
  run in a throwaway pod; `smarthostctl test` runs Phases 2, 3 and 4/5.
- **Phase 4** (Go/Postfix delivery pipeline): complete (v0.1.4). The daemon is in `delivery/`;
  `infra/bin/smarthostctl test phase4` runs the Go unit and PostgreSQL
  integration tests in a throwaway pod; `test phase4-e2e` runs against the running pod's Postfix,
  OpenDKIM and Mailpit.
- **Phase 5** (inbound DSN, complaint and global suppression processing, D-30, spec 2.4):
  complete (v0.1.5). `infra/bin/smarthostctl test phase5` runs the Go suite (DSN parser,
  suppression policy, spool, PostgreSQL integration); `test phase5-e2e` sends DSNs and ARF reports
  through the running pod's Postfix port 25 and DSN spool. Operator workflows are console commands
  (`smarthost:dsn:*`, `smarthost:suppression:*`, `smarthost:client:global-suppressions`, each with
  `--operator`) and, since Phase 6, the operator dashboard.
- **Phase 6** (tracking and dashboards, spec 2.6): complete (v0.1.6). Public tracking endpoints
  `/t/o/{token}.gif` and `/t/c/{token}/{n}` (`app/src/Tracking/`, `TrackingController`); client
  dashboard under `/dashboard/c/{client}` and operator dashboard under `/dashboard/operator`
  (`app/src/Dashboard/`, `app/src/Controller/Dashboard/`, `app/templates/dashboard/`). Its tests are
  in `smarthostctl test phase2`; `test phase6-e2e` runs through nginx against the running pod.
- **Passwordless sign-in, roles and ACL** (spec 2.7): complete (v0.1.6; `app/src/Access/`,
  `app/src/Security/LoginLink*`, Access › Users and Access › Roles & permissions).
- **Phase 7** (first client API integration, spec 2.8): Smarthost side complete (v0.1.7). The
  webhook worker is `smarthost:webhook:work` (`app/src/Webhook/`, `WebhookWorkCommand`), with client
  and operator webhook dashboard pages. `infra/bin/smarthostctl test phase7-e2e` proves the client
  workflow through the API and signed webhooks only, with the external receiver fixture in
  `tests/webhook-receiver/`. The first client application, ctnlist, implements its side in its own
  repository; the two development stacks have been run together through the connect flow (see
  *ctnlist, the first client application*).
- **Phase 8** (controlled production launch, spec 2.9): repository side implemented (v0.1.8).
  - Production topology: `infra/podman/smarthost-production.sh.in`, standalone containers on
    internal and egress networks.
  - Tooling: `smarthostctl prod` (`infra/bin/smarthostctl-prod`); preflight
    `infra/lib/smarthost_preflight.py`; seed test `infra/lib/smarthost_seedtest.py`.
  - Docs: `docs/production/`.
  - Tests: `smarthostctl test phase8` (no network) and `test phase8-rehearsal` (the production
    topology locally, instance `smarthost-rehearsal`, no Internet egress).
  - The live steps (VPS, DNS/PTR, certificates, seed tests, rollout) are the operator's.
- **Phase 9** (public SaaS hardening, spec 2.10, `saas_operations`): repository side implemented
  (v0.1.8).
  - Client lifecycle (`app/src/Client/ClientLifecycle.php`), limits and quotas
    (`ClientLimitPolicy`, `QuotaEnforcer`), usage and billing (`app/src/Usage/`), reputation
    alerts (`app/src/Reputation/`, production timer `<instance>-reputation-evaluate.timer`).
  - Dashboards: Clients › Clients/Abuse & reputation/Usage & billing; client workspace › Settings › API keys.
  - Public docs at `/docs/api`. Operator guide: `docs/production/onboarding.md`.
  - Tests are in `smarthostctl test phase2`, including `Phase9TenantIsolationTest` and
    `Phase9QueryPlanTest`; the rehearsal has a Phase 9 section.
- **Phase 10** (operator self-service, spec 2.11, `operator_self_service`): repository side
  implemented (v0.1.9, the first GitHub Release).
  - The installer `install-catto-mail` and, for an installed server, `upgrade-catto-mail` (both
    at the repository root, both tested by `smarthostctl test installer`, the second with
    `--with-upgrade`, and before a release with `--from <the last release>`); the host agent
    `infra/lib/smarthost_agent.py`
    (`<instance>-host-agent.service`).
  - `app/src/System/`: diagnostics, requests, the setup wizard, the emergency stop.
  - `app/src/AddressBatch/`: batches and re-permission; `app/src/Help/` with
    `templates/dashboard/help/`.
  - Guides: `docs/production/VPS-INSTALL.md`, `components.md`, `architecture.md`,
    `docs/integration/ctnlist.md`.
- **Dashboard redesign** (v0.2.2, owner decision, spec `user_interfaces.dashboard_layout`): one
  shell (`app/templates/dashboard/layout.html.twig`, `App\Dashboard\Navigation`), Chart.js charts
  (`app/assets/controllers/chart_controller.js`), global search (`App\Dashboard\Search`), period
  choice on both overviews. Screenshots of every page: `docs/screenshots/`.
- **Installation time zone, grouped .env, settings in the dashboard** (v0.2.3, owner
  decisions): every time is in `SMARTHOST_TIMEZONE` (SAST by default); `infra/.env` holds only the
  operator's settings, grouped (`LAYOUT` in `infra/lib/smarthost_render.py`; the rest is built in;
  `env-migrate` converts older files); System › Settings overrides the operational settings of
  `docs/contracts/settings.json` without changing the file (`setting_overrides`, applied by
  `settings-apply`).
- **v0.2.4 and v0.2.5** (owner decisions): every page has the version in its head and
  the footer (`templates/_site.html.twig`, `PageFrameTest`); Bootstrap 6.0.0-alpha.1 is the base
  of the design and every dashboard page uses its four-column `.grid`; the public front page at `/` (`FrontPageController`, `templates/front.html.twig`); production
  port 80 redirects to HTTPS (`PROXY_HTTP_BIND`, `infra/nginx/production/http-redirect.conf.template`);
  client admins set up sending domains in the client dashboard, the DKIM key through the host agent
  (`ClientDomainController`, `App\Domain\DkimSetup`, `smarthost:system:agent domains`,
  `ClientDomainSetupTest`); public sign-up into a starter tier behind `APP_PUBLIC_ONBOARDING_ENABLED`
  (`PublicSignUpController`, `App\Client\PublicSignUp`, `PublicSignUpTest`); a client's unfinished domain is a preflight WARN, never blocking (only
  the `APP_MAIL_FROM` domain must have its key); `prod dkim activate` reloads OpenDKIM (SIGUSR1,
  confirmed by `smarthost_preflight.py --dkim-probe`) and restarts it only as the fallback.
- **Application connect** (v0.2.6, owner instruction, spec 2.12 `api.connect`): an application is
  connected by one approval of a client admin, in an authorisation-code flow with PKCE.
  `App\Connect\` (`ConnectRequest`, `ConnectService`), the connect page `/dashboard/connect`
  (`Dashboard\ConnectController`, `templates/dashboard/connect/`), `POST /v1/connect/exchange` and
  `DELETE /v1/connection` (`Api\ConnectController`), Settings › Connected applications
  (`ClientConnectionController`), `GET /v1/sending-domains` (`Api\SendingDomainController`,
  `App\Sending\SendingDomainRule`), table `application_connections`; tests `ConnectTest`,
  `ConnectRequestTest`, `SendingDomainApiTest`. The acceptable use policy is `/acceptable-use-policy`
  (`PolicyController`, `App\Util\Markdown`, built from `docs/AUP.md`; `PolicyPageTest`). ctnlist
  uses the flow (`docs/integration/ctnlist.md`).
- **The installation's name** (v0.2.7, owner decision 2026-10-12): the software can be installed
  on any domain, so pages, emails and the policy name the installation (`App\Config\Installation`:
  `APP_SITE_NAME`, `APP_ORGANISATION_NAME`, the host of the public address, `APP_ADMIN_EMAIL`; Twig
  `site_name()`). `docs/AUP.md` holds placeholders only, and `APP_ACCEPTABLE_USE_POLICY_VERSION`
  is the version it states in the development example and the production profile.
- **Guided account setup** (unreleased, owner decisions 2026-10-27, spec 2.13; migration
  `Version20261027000100`, OpenAPI 1.0.0-draft.11): plans (`App\Client\PlanCatalog`, Clients ›
  Plans), invitations (`App\Client\Invitations`, `InvitationController`, Clients › Invitations),
  New client on its own page (`App\Client\AccountOpening`), the connect page that never dead-ends
  and takes `sending_domain`, `SendingDomain.setup_url`, each sending domain's steps page
  (`ClientDomainController`, `dnscheck_controller.js`, `DomainReadyMail`), the account's start-page
  checklist (`ClientAccountReadModel::nextSteps`), copy buttons (`ui.copy`,
  `copy_controller.js`), System setup split into "needs you" and "checked automatically"
  (`SetupWizard::NEEDS_YOU`), and `smarthostctl dev-agent` (the development pod's stand-in for the
  host agent's DKIM work). Tests: `AccountSetupTest`, `ConnectTest`, `ClientDomainSetupTest`.
- Do not start Phase 11 or later work unless the user explicitly asks for it.

## ctnlist, the first client application
ctnlist (`github.com/rrcatto/ctnlist`, a separate public repository) is the mailing-list application
that sends its campaigns through catto-mail. `docs/integration/ctnlist.md` is this repository's
record of the integration: what each side owns, what ctnlist does, and the ctnlist work that is
outstanding. Keep it current when either side changes.
- **The seam is the public contract only:** `/v1` (`docs/api/openapi.v1.yaml`) and the signed
  webhooks. ctnlist never reads this database, and catto-mail never calls ctnlist except to
  deliver a webhook. Never add a ctnlist-specific endpoint, field, table or shortcut: what ctnlist
  needs, every client gets, through the specification and the contract.
- **ctnlist is changed from here only when the owner asks for it in that task.** Its checkout is
  usually beside this one (`../ctnlist`). It has its own `CLAUDE.md` files (read them first; its
  rules differ from this repository's), its own gate (`bin/dev check`) and its own commits and
  versions. Without that instruction, specify the ctnlist work in `docs/integration/ctnlist.md`.
- **A contract change has a second half in ctnlist.** Its tests never reach a real catto-mail:
  they run against `tests/Support/FakeCattoMail.php`, a hand-written fake of `/v1`, and
  `src/CattoMail/CattoMailClient.php` is its only HTTP caller. A green suite here says nothing
  about ctnlist. After changing the OpenAPI document, the vocabulary, a limit, a problem type or
  a webhook payload, name the ctnlist files that must follow, or change them when asked.
- **What ctnlist relies on beyond the schemas** (keep these, or change both sides together):
  - A resource that does not exist answers `404` only to an authenticated request, and a refused
    key answers `401`: ctnlist's connection check reads a send job that cannot exist
    (`GET /v1/send-jobs/00000000-0000-7000-8000-000000000000`), because the API has no health
    endpoint.
  - It retries `408`, `425`, `429`, `5xx` and a `409` whose problem type ends in
    `idempotency-in-progress`, with the same `Idempotency-Key` and body, and reads `Retry-After`
    as whole seconds. Every other `4xx` is a final refusal of that job, batch or opt-out.
  - It tells an administrator that the From domain is not registered from an `errors` pointer
    that starts with `/sender_identity`.
  - It assumes the contract ceilings as constants (500 recipients per batch, 10,000 per send job
    and per validation job, 10 MiB per request) and cuts its own batches at 8 MiB.
  - It matches results by reference only: messages by `external_recipient_reference`, validation
    results by `external_address_reference`.
  - Its webhook endpoint verifies `Smarthost-Signature` before parsing, de-duplicates on
    `Smarthost-Event-Id`, refuses bodies over 1 MiB, and records and ignores event types it does
    not know.
- **How ctnlist connects** (ctnlist 6.0.11 and later): an administrator enters this installation's
  address on ctnlist's Delivery page; ctnlist sends the browser to `/dashboard/connect`, exchanges
  the code (`POST /v1/connect/exchange`) and stores the key and the webhook secret encrypted. Its
  Disconnect calls `DELETE /v1/connection` with the connection's own key, and it reads
  `GET /v1/sending-domains` (`can_send`) to warn when its From domain cannot send. It relies on:
  `api_base_url`, `api_key` and `webhook.signing_secret` in the exchange answer; `409` meaning
  "refused for now, the code stays usable" and every other `4xx` of the exchange meaning "start
  again"; `401` from `DELETE /v1/connection` meaning the connection is already ended.
- **Where ctnlist is behind the contract** (it follows OpenAPI 1.0.0-draft.10 otherwise):
  - `repermission.responded` is recorded and ignored. The handling is specified in
    `docs/integration/ctnlist.md` (*Outstanding ctnlist work*).
  - `429 quota-exceeded` is handled like `rate-limited`: retried in place, then by ctnlist's
    worker on every pass until the quota resets. The quota appears only in the text of its last
    API error.
  - A limit set below a contract ceiling (a client's `max_recipients_per_send_job`, the
    installation's `APP_SEND_JOB_MAX_RECIPIENTS_PER_BATCH`) is not known to ctnlist: the batch
    that passes it is refused with `422`, and ctnlist marks the whole job refused.
- **Development route** (`docs/development-environment.md` §3, *Client development route*). The
  two development stacks are separate. ctnlist's `bin/dev cattomail-link` creates an internal
  Podman network, `cattomail-dev`; `smarthostctl recreate` attaches this pod to it as
  `catto-mail` whenever it exists (`infra/podman/smarthost-pod.sh.in`; nothing here creates the
  network, and a network that is not internal is refused). `ctnlist-web` goes into
  `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS` in `infra/.env`, which the webhook worker and the connect
  page both apply. ctnlist then connects with its button to `https://localhost:8443`. The two
  have been run together this way: connect, a proof and a validation job, signed webhooks,
  disconnect.

## Public repository
This is a public repository. Documentation, comments, examples, tests, configuration templates and commit content must contain only information relevant to the Catto Mail software. Do not include private business plans, names of unrelated private projects, historical mailing-list information, personal hardware details, personal addresses, credentials, private infrastructure details, or other personally identifying/contextual information unless explicitly required by the user.

## Rules that are easy to get wrong
- **Production is not the development pod (Phase 8).**
  - `smarthostctl prod <command>` acts only on a configuration with `SMARTHOST_ENV=production`;
    the development commands refuse one.
  - Production containers are `<instance>-<service>` on `<instance>-internal`/`-ingress`/`-egress`.
    Only Postfix, validator, webhook worker and the app join egress, and only nginx and Postfix
    join ingress. **No production container publishes a port:** rootless port forwarding hides
    client addresses. 443/25/80 belong to the systemd socket `<instance>-ingress.socket`, which
    `<instance>-ingress.service` hands to nginx. nginx passes SMTP to Postfix with the PROXY
    protocol; port 80 (`PROXY_HTTP_BIND`) only redirects to `https://PROXY_SERVER_NAME`.
  - Start or stop production nginx only through those units (the topology script), never with
    plain `podman start`/`restart`. `TRUSTED_PROXIES` stays empty in production.
  - `prod preflight --section exposure` and `--section ingress` (`prod ingress-check`) enforce
    this.
  - The host prerequisite `net.ipv4.ip_unprivileged_port_start=25` is checked by
    `--section host`.
  - Production never has a relayhost or Mailpit. Live delivery is activated only by
    `prod live-enable` (activation preflight, audited, `SYSTEM.DELIVERY.CONTROL`).
  - Production configuration rules live in `production_errors()` of
    `infra/lib/smarthost_render.py`; production values in the contract's *Production profile*
    table.
  - Never run the rehearsal or any test against a real production host. The rehearsal uses its
    own instance name and never touches `smarthost-*` development objects.
- **Podman only, rootless, persistent pod (D-35).** The `smarthost` pod and its containers are
  persistent Podman objects: `stop`/`start`/`restart` keep them (as Podman Desktop does), only
  `smarthostctl recreate` replaces them (volumes kept), and only `destroy-volumes --yes` deletes
  data. Never reintroduce Quadlet `.pod`/`.container` units or any unit that removes the pod or
  containers on stop. After rebuilding images or changing `infra/podman/smarthost-pod.sh.in`, run
  `smarthostctl recreate`. Always run systemd steps through `smarthostctl`. With a local engine
  on a Linux host it runs them directly. When the engine is remote, it must be the WSL Podman
  machine (`podman-machine-default`):
  - Container commands work through `podman` (remote).
  - systemd commands must run inside the machine's systemd namespace. Use
    `infra/bin/smarthostctl`; it enters that namespace through the machine's
    `/usr/local/bin/enterns`.
  - Do not run the in-machine `podman` CLI from an ad-hoc `wsl.exe` shell outside that
    namespace, because it creates a separate rootless user namespace.
- **All Smarthost containers run in the `smarthost` pod** (`infra/podman/smarthost-pod.sh.in`).
  New containers join it with `--pod smarthost` (the `service` helper there). Host ports and
  network aliases belong on the pod, never on a container. Loopback is shared by all members, so never use `127.0.0.1` as an
  authorisation boundary.
- **Other projects' containers may run on the same Podman machine.** Never stop, modify or
  remove any container, volume or network that is not Smarthost's (`smarthost-*`, label
  `project=smarthost`). Smarthost avoids common host ports such as 80, 443, 5433 and 8025.
- **Schema changes only through Doctrine migrations** in `app/migrations/`, run as
  `smarthost_owner`. They must keep `docs/schema/reference-schema.sql` structurally identical (the
  schema test suite compares the catalogs), and any new table needs a row in the `schema.md` §6
  grant matrix, `infra/postgres/grants.sql` and the tenant filter (`app/src/Tenant/TenantFilter.php`,
  which denies unknown tables).
- **API code loads tenant resources only through `TenantScope`**; `client_id` never comes from a
  request. Request bodies are validated against the OpenAPI contract itself.
- **Dashboard code resolves the client only through `ClientAccess`** (voter; a client the user may
  not see is a 404) and every dashboard query carries the client id explicitly (DBAL queries are
  not covered by the ORM tenant filter). Sorts and filters are whitelisted; changes are POST + CSRF
  through the existing audited services. UI wording: "Remote accepted" (never "delivered"),
  "Recorded open/click" (never "read").
- **Dashboard UI (v0.2.2; Bootstrap 6 base and the four-column grid, owner decision 2026-10-10).**
  - The Content-Security-Policy allows no inline styles: every style lives in
    `app/assets/styles/app.css`, and data-driven widths or positions are SVG attributes (Bootstrap's
    CSS Grid examples set `--bs-columns` inline: never do that here).
  - Bootstrap 6.0.0-alpha.1's compiled CSS (`app/assets/vendor/bootstrap/`, no Bootstrap JS) is linked
    before `app.css`; Bootstrap's rules sit in cascade layers, so `app.css` (unlayered) always wins,
    even over Bootstrap classes in the markup. A class name Bootstrap also defines picks up its
    component styles underneath ours: check `bootstrap.min.css` before naming a new class.
  - Page layout is Bootstrap's `.grid` (four columns, 20px gaps): every direct child names its span,
    mobile first (`g-col-4 md:g-col-2 xl:g-col-1` a quarter, `g-col-4 lg:g-col-2` a half,
    `g-col-4 xl:g-col-3` + `g-col-4 xl:g-col-1` main and side, `g-col-4` full width; `sticky-side`
    for a sticky side column). A child without a span takes one column. Wide tables stay full width;
    a half or main column holding a table of five or more columns pairs only from `2xl:` (1536px).
  - New pages get their place in the menu bar (`App\Dashboard\Navigation`: area › page › views,
    each gated by its permission key; owner decision 2026-10-25) and follow the page rules of the
    specification's `dashboard_layout`: one job per page, the state in one sentence under the title,
    what needs attention first, at most four key figures (`.kpis`), one main list or chart, every
    explanation behind `ui.tip()` (never a paragraph on the page), common filters as `.chips` with
    the full form in `details.morefilters`, actions that need a reason behind a button
    (`details.actionform`), times in lists with `|when` (the full `|ts` in a title or tooltip), and
    a detail page's place in the breadcrumb through `{% block crumb_tail %}`. Charts go through
    `chart_controller.js`.
  - Show times with the `ts` filter and compute dashboard days, quota days and billing months with
    `App\Util\InstallationTime`.
  - Every HTML page imports `templates/_site.html.twig`: `application_meta()` in the head and
    `footer()` after `main` (version, administrator's address, time zone). A new standalone page
    does the same and gets a line in `PageFrameTest`.
  - When pages change visibly, retake the affected `docs/screenshots/` images (test data only).
  - Guided setup (owner decisions 2026-10-27): the customer's screens say *account*, never
    *workspace* or *client* (the operator console keeps *client*); a create form is a page of its
    own (`.formpage`, centred, each field with its `field-help`), never a `details.actionform`
    beside a title; the breadcrumb shows on detail pages only (`crumb_tail`); ready-made views of a
    list have plain addresses (`/dashboard/operator/clients/waiting`), never internal values in the
    query; every value a person copies elsewhere gets `ui.copy()`; no internal name (host agent,
    fan-out, origin) on a customer's screen.
- **Every time is in the installation's time zone** (`SMARTHOST_TIMEZONE`, default
  `Africa/Johannesburg`; owner decision). Never write UTC or a `Z` suffix:
  - PHP: the kernel sets the default zone on boot; use `Clock::now()` and `Clock::rfc3339()` (offset
    `+02:00`); `SessionTimeZoneMiddleware` sets every database session.
  - Go: `time.Local` is set from the config; never call `.UTC()` for anything written or shown.
    Postfix logs local time (`TZ` in its container) and `postfixlog.Parse` reads it in `now`'s zone.
  - Python: `models.now()` and the log formatter use `Config.timezone`.
  - Shell and host tools: `zdate` / `TZ="$(envval SMARTHOST_TIMEZONE)" date ... %z`.
  - Every container gets `TZ` and PostgreSQL `-c timezone`/`log_timezone` from the topology
    scripts; the installer sets the host's zone. Only HTTP date headers (GMT) and the Unix time in
    `Smarthost-Signature` keep their standard form.
- **The .env layout and dashboard settings.**
  - `infra/.env` holds only the settings in `LAYOUT` (`infra/lib/smarthost_render.py`); every other
    contract variable is built in (`defaults()`), and scripts read values with
    `smarthost_render.py get NAME` (`envval`/`ev`), never by grepping the file.
  - A new variable goes into the contract table and into `LAYOUT` or stays built in; then
    `env-example` and `check-contracts.py`. A renamed one goes into `RENAMED` so `env-migrate`
    handles old files.
  - A setting the dashboard may change goes into `docs/contracts/settings.json` (never a secret,
    database, network, certificate, host or domain value, or a safety switch). The renderer
    (`override_error`) and `App\System\SettingCatalog` apply the same rule: change both together.
- **Dashboard sign-in is passwordless** (spec 2.7): emailed single-use links (`LoginLinkService`),
  `APP_ADMIN_EMAIL` always gets ADMIN. Operator pages require permission keys
  (`App\Access\PermissionCatalog`, `#[IsGranted('PLATFORM.…')]`), never role names; ADMIN holds
  every key. Never reintroduce passwords. Development sign-in emails land in Mailpit
  (http://localhost:8026); the dashboard is https://localhost:8443/dashboard.
- **The owner's instructions override the specification**; update the spec to match.
- **Tracking endpoints** answer unknown, ineligible and valid-but-unrecorded tokens identically,
  redirect only to the stored `message_links` target, never log tokens and never set cookies.
- **Images:** `smarthostctl build` rebuilds the images; `install` only renders and installs units.
  After `build`, run `recreate`.
- **No undocumented environment variables.** Every variable must appear in
  `docs/contracts/environment.md`. Regenerate the template with
  `python3 infra/lib/smarthost_render.py env-example`, then run
  `python3 scripts/check-contracts.py`.
- **Webhook delivery (spec 2.8).**
  - Only `smarthost:webhook:work` (`WebhookDispatcher`) sends HTTP webhooks. It uses the `webhook`
    Doctrine connection and entity manager (role `smarthost_webhook`).
  - Never use these from web code: the `symfony-app` container has no `APP_WEBHOOK_DB_*`
    credentials (`WebProcessIsolationTest`).
  - Destinations must pass `WebhookTargetGuard` and are pinned through the HTTP client's `resolve`
    option. Redirects are never followed. `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS` is for development
    fixtures only.
  - Delivery is at-least-once; never claim exactly-once.
  - Fixed decisions (spec 2.8):
    - `POST /v1/webhooks/test` names exactly one endpoint (`webhook_endpoint_id` required).
    - There is no `/v1` webhook-endpoint CRUD and no suppression-lookup API.
    - `APP_RETENTION_WEBHOOK_DELIVERIES_DAYS` empty means no automatic deletion.
    - Production `APP_WEBHOOK_*` values belong to Phase 8.
- **Operator self-service (spec 2.11).**
  - The web application never runs host commands. Host actions are `system_requests`, carried
    out by the host agent with the production CLI. Add an action to the vocabulary, the
    agent's `handle()` and `SystemRequests::validParams` together.
  - The emergency stop is `delivery_controls` (read by Go) plus the Postfix hold. Never weaken
    live activation: it always runs the activation preflight.
  - Address batches use ordinary validation and send jobs. Never bypass suppressions or the
    batch's compliance approval. Show separate state dimensions, never one status.
  - Stored check output and request results go through `Redactor`.
  - Shell code may remove only paths it created (`remove_owned` in `smarthostctl-prod`).
  - Help pages must describe the real implementation; update them with behaviour changes.
  - Client sending domains (owner decision 2026-10-11) get their DKIM key only through the host
    agent (`dkim.generate`/`dkim.activate` with `sending_domain_id`). The agent returns only the
    public half, and `DkimSetup::requestFinished` records the outcome for exactly that domain
    when the request is finished. Never generate, hold or move a private key in the web
    application, and never set up self-service DKIM for a domain another client has verified.
  - A client's domain must never make the production preflight FAIL for the client's own gaps (no
    active key, its DNS records): those are WARNs naming the client, never escalated by
    `--activation`. Only the installation's own domain (`APP_MAIL_FROM`) and OpenDKIM/database
    disagreement FAIL.
  - Apply DKIM table changes with the reload (`dkim_apply` in `smarthostctl-prod`), not a
    restart. A milter client talking to OpenDKIM must wait for each reply: OpenDKIM 2.11 crashes
    when a pipelined session meets a pending reload.
- **SaaS operations (Phase 9, spec 2.10).**
  - Public sign-up (owner decision 2026-10-11) exists only behind `APP_PUBLIC_ONBOARDING_ENABLED`
    (default false: every sign-up route is 404). It creates clients only through
    `ClientLifecycle::apply` (`App\Client\PublicSignUp`), which then adds the client admin, the
    policy acceptance and the starter limits (`APP_STARTER_*`, capped by the ceilings) and
    activates the client. Keep it: GET never changes state, every POST has CSRF, the answer never
    reveals whether an address has an account, the rate limits and the honeypot stay, tokens are
    stored only as hashes and never logged. The only other public route that creates clients is an
    operator's invitation (owner decision 2026-10-27, `App\Client\Invitations`, origin
    `invitation`): single use, hash only, the same limits and honeypot, open while the sign-up is
    closed. Accounts open by invitation for now: no public registration and no waiting list.
  - Plans (`plans`, `App\Client\PlanCatalog`): a client's limits are its plan's, copied into
    `client_limits` (enforcement reads only `client_limits`); changed by hand they are custom.
    `standard` and `starter` are built in. Never re-add the `APP_STARTER_*` settings.
  - New client (`App\Client\AccountOpening`) is the operator's approval: exempt → active at once;
    must accept → `pending_approval` with `opens_on_policy_acceptance`, opened by
    `ClientLifecycle::openAfterAcceptance` when the account's admin accepts. The billing and abuse
    contact addresses are gone; `contact_email` is the address the account was opened for.
  - Create clients and change their status only through `ClientLifecycle`, never by setting
    `clients.status`. Every transition has its permission and a reason, and is audited.
  - Volume quotas are admitted only through `QuotaEnforcer::admit`, inside the transaction that
    creates the work. Never sum `usage_records` to enforce a quota.
  - `usage_records` is the only metering source (at most one unit per message). Billing
    statements hold quantities, never prices. No billing provider is chosen.
  - Reputation alerts never act on a client.
  - `client_notes`, `client_reputation_metrics` and `client_alerts` are operator-only: never
    show them to clients (the tenant filter denies them).
- **The installation is named, not the software** (owner decision 2026-10-12).
  - Text a person reads (templates, emails, flash messages) says `{{ site_name() }}` or
    `Installation::siteName()`, never "Catto Mail". Only `application_meta()` and the footer's
    software line name the software and its version; operator documentation and help pages may
    name the software and its commands.
  - `docs/AUP.md` names no product, domain or repository: only `{{site_name}}`, `{{domain}}` and
    `{{organisation}}` (`Installation::placeholders()`, filled after rendering, escaped).
  - **One address on the policy page: `abuse@{{domain}}`** (owner, 2026-10-12, stated three
    times). Abuse reports and complaints go there. The administrator's address
    (`APP_ADMIN_EMAIL`) must never appear in the policy or anywhere on its page: no "questions
    about this policy" line, no `{{contact}}` placeholder, and the page's footer is rendered
    without the administrator's line (`site.footer(false)`). `check-contracts.py`,
    `PolicyPageTest` and `PageFrameTest` enforce it. When its rules change, change
    the version it states and `APP_ACCEPTABLE_USE_POLICY_VERSION` in the contract (example and
    production profile) together, then `env-example`.
- **Application connect (spec 2.12).**
  - The API key and the webhook endpoint of a connection are created by the exchange
    (`ConnectService::exchange`), never by the approval: an API key is stored only as a hash, so
    its raw value exists only in the response that hands it over. Never store a raw key, code or
    code verifier, and never put a key or secret in a redirect, a log or the audit log.
  - Approval is POST + CSRF by a client admin (a `client_memberships` admin, not a platform
    permission) of an `active` client, within `max_api_keys` and `max_webhook_endpoints`. The
    connect page's GET stores nothing and never redirects to an address it did not accept.
  - A code is single use (row lock in the exchange) and lives 10 minutes. Every reason it is not
    accepted is the same `400 invalid-connect-code`: keep it one answer.
  - `POST /v1/connect/exchange` is the only `/v1` operation without an API key
    (`ApiKeyAuthenticator::PUBLIC_OPERATION` and the `access_control` rule in `security.yaml`:
    change both together). Do not add another.
  - The connect page is `PUBLIC_ACCESS`; sign-in and sign-up accept `return_to` only when it is a
    connect path (`ConnectRequest::returnPath`, which also makes it canonical). Never widen it.
  - It never dead-ends (2.13): signed in without an account, `POST /dashboard/connect/account`
    opens one with the organisation's name (public sign-up open: Starter, policy accepted on the
    form; an operator with `PLATFORM.CLIENT.APPROVE`: Standard, exempt); anyone else is told that
    accounts are opened by invitation. `sending_domain` (optional) is added to the account by the
    approval within its limit. A domain's steps page shows "Back to <application>" only for a
    `back` URL on the redirect origin of one of the account's active connections.
  - Ending a connection revokes its key and sets its endpoint to `removed` (terminal:
    `WebhookEndpointService::remove`, called only from `ConnectService`). A removed endpoint is
    never listed, changed or counted against a limit; its row stays for the delivery history.
  - Connecting never grants `clients.can_submit_global_suppressions`: only an operator does.
  - An existing `/v1` operation must not change for the sake of a client application.
- **Suppressions (D-30).** Automatic suppressions and recipient global opt-outs are global
  (`client_id` NULL); the opt-out reporter is `source_client_id`. Only Go creates `hard_bounce`,
  `complaint` and `repeated_soft_bounce`, through the one policy in `delivery/internal/store/policy.go`;
  only `failure_scope = recipient` counts. Unsuppress by setting `lifted_at`, never by deleting.
  An ordinary unsubscribe is never Smarthost state.
- **Account blocks (spec 2.13, owner decision 2026-10-27).** An account blocks an address or a whole
  domain for its own mail: reason `account_block`, `client_id` the account, never global, no
  expiry (`suppressions_account_block_scoped`), one active per account, scope and value. Its admins
  add and lift them on Sending › Suppressions; its application uses `/v1/blocks` (idempotent through
  `global_suppression_requests`, operation `create_account_block`). `GET /v1/blocks` lists only the
  account's own blocks, never other suppressions: keep it that way (no lookup oracle). Creating is
  allowed in every status that authenticates; lifting needs active or throttled. Go needs nothing:
  it already applies every active client-scoped address or domain row.
- **Never commit secrets.** `infra/.env` and `infra/.generated/` (env files, rendered units,
  development TLS material) are gitignored. Development DKIM private keys live only in the
  `smarthost-opendkim-keys` Podman volume.
- **Development mail must never reach the Internet.**
  - Postfix capture mode (`SMARTHOST_LIVE_DELIVERY_ENABLED=false`) relays to Mailpit.
  - The `smarthost-internal` network has no route out.
  - Live mode is accepted only with `SMARTHOST_ENV=production`.
- **Change control.** Do not commit, push, tag or release unless the user explicitly asks in that
  turn. When the owner asks to commit and push a version, do all of it without asking or debating:
  - bump the version in `app/src/Version.php`, `delivery/cmd/smarthost-delivery/main.go`,
    `validator/smarthost_validator/__init__.py`, the help page `upgrades.html.twig`, the contract's
    production profile `SMARTHOST_IMAGE_TAG` (then `env-example`), `infra/tests/phase8/test_render.py`
    (`TAG=`), the `VPS-INSTALL.md` example, the README status line and the `CHANGELOG.md` heading;
  - commit, create the annotated tag `vX.Y.Z`, push `main` and the tag;
  - publish a GitHub Release for the tag (`gh release create`) with the notes (install, upgrade,
    downloads, not yet proven, the CHANGELOG entry) and the assets `catto-mail-vX.Y.Z.zip`,
    `catto-mail-vX.Y.Z.tar.gz` (`git archive` of the tag) and `SHA256SUMS`. Every pushed version
    gets a release.

## Checks before reporting completion
- `python3 scripts/check-contracts.py` must pass.
- For infrastructure changes:
  - `infra/bin/smarthostctl install` must succeed, followed by `recreate` when container
    definitions or images changed;
  - `infra/bin/smarthostctl status` must show the services healthy;
  - `infra/bin/smarthostctl verify` must pass (on a Podman 4.9 engine: everything except T06 and
    T13, see `docs/development-environment.md` §1).
- For application changes: `infra/bin/smarthostctl test` must pass.
- Report the files changed, the checks run and their results, anything unverified, and any
  specification ambiguity (see `AGENTS.md`).
