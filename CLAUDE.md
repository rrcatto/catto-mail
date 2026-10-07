# CLAUDE.md — instructions for Claude Code in this repository

Read `AGENTS.md` first; everything there applies. This file adds Claude-specific working notes.

## Authority
1. `docs/20260908-1644-smarthost-llm-spec.yaml` (specification 2.10) is authoritative.
2. The normative contracts it lists (`instruction_for_llm.normative_contracts`) elaborate it:
   - `docs/contracts/status-vocabulary.yaml`
   - `docs/api/openapi.v1.yaml`
   - `docs/schema/reference-schema.sql` and `docs/schema/schema.md`
   - `docs/contracts/environment.md`
   - `docs/architecture/postfix-integration.md`
3. `docs/architecture/open-decisions.md` is a **decision log only**, never a source of authority.
4. `docs/PROJECT.md` describes every file and the main workflows. Keep it current when you add,
   move or delete files.

## Current state
- **Phase 0:** complete.
- **Phase 1** (rootless Podman development environment): complete. `infra/bin/smarthostctl verify`
  (optionally `--clean`) re-proves it. See `docs/development-environment.md`.
- **Phase 2** (database and Symfony foundation): complete (v0.1.2). The Symfony 8.1
  application is in `app/`; `infra/bin/smarthostctl test phase2` runs its suite in a throwaway pod.
- **Phase 3** (Python validation engine): complete (v0.1.3). The worker is in `validator/`;
  `infra/bin/smarthostctl test phase3` runs pytest and the 10,000-address end-to-end
  run in a throwaway pod; `smarthostctl test` runs Phases 2 and 3.
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
  `app/src/Security/LoginLink*`, Operator › Users and Roles & permissions).
- **Phase 7** (first client API integration, spec 2.8): Smarthost side complete (v0.1.7). The
  webhook worker is `smarthost:webhook:work` (`app/src/Webhook/`, `WebhookWorkCommand`), with client
  and operator webhook dashboard pages. `infra/bin/smarthostctl test phase7-e2e` proves the client
  workflow through the API and signed webhooks only, with the external receiver fixture in
  `tests/webhook-receiver/`. Integrating the real first client application (its own repository) is
  outstanding.
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
  - Dashboards: Operator › Clients/Alerts/Usage; Client › API keys.
  - Public docs at `/docs/api`. Operator guide: `docs/production/onboarding.md`.
  - Tests are in `smarthostctl test phase2`, including `Phase9TenantIsolationTest` and
    `Phase9QueryPlanTest`; the rehearsal has a Phase 9 section.
- Do not start Phase 10 or later work unless the user explicitly asks for it.

## Public repository
This is a public repository. Documentation, comments, examples, tests, configuration templates and commit content must contain only information relevant to the Catto Mail software. Do not include private business plans, names of unrelated private projects, historical mailing-list information, personal hardware details, personal addresses, credentials, private infrastructure details, or other personally identifying/contextual information unless explicitly required by the user.

## Rules that are easy to get wrong
- **Production is not the development pod (Phase 8).**
  - `smarthostctl prod <command>` acts only on a configuration with `SMARTHOST_ENV=production`;
    the development commands refuse one.
  - Production containers are `<instance>-<service>` on `<instance>-internal`/`-ingress`/`-egress`.
    Only Postfix, validator, webhook worker and the app join egress, and only nginx and Postfix
    join ingress. **No production container publishes a port:** rootless port forwarding hides
    client addresses. 443/25 belong to the systemd socket `<instance>-ingress.socket`, which
    `<instance>-ingress.service` hands to nginx. nginx passes SMTP to Postfix with the PROXY
    protocol.
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
  `smarthostctl recreate`. In this development setup the engine runs in the WSL Podman machine
  `podman-machine-default`:
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
- **SaaS operations (Phase 9, spec 2.10).**
  - Public onboarding stays disabled (`APP_PUBLIC_ONBOARDING_ENABLED=false`) and has no route.
    Never add a public registration route without an explicit owner decision.
  - Create clients and change their status only through `ClientLifecycle`, never by setting
    `clients.status`. Every transition has its permission and a reason, and is audited.
  - Volume quotas are admitted only through `QuotaEnforcer::admit`, inside the transaction that
    creates the work. Never sum `usage_records` to enforce a quota.
  - `usage_records` is the only metering source (at most one unit per message). Billing
    statements hold quantities, never prices. No billing provider is chosen.
  - Reputation alerts never act on a client.
  - `client_notes`, `client_reputation_metrics` and `client_alerts` are operator-only: never
    show them to clients (the tenant filter denies them).
- **Suppressions (D-30).** Automatic suppressions and recipient global opt-outs are global
  (`client_id` NULL); the opt-out reporter is `source_client_id`. Only Go creates `hard_bounce`,
  `complaint` and `repeated_soft_bounce`, through the one policy in `delivery/internal/store/policy.go`;
  only `failure_scope = recipient` counts. Unsuppress by setting `lifted_at`, never by deleting.
  An ordinary unsubscribe is never Smarthost state.
- **Never commit secrets.** `infra/.env` and `infra/.generated/` (env files, rendered units,
  development TLS material) are gitignored. Development DKIM private keys live only in the
  `smarthost-opendkim-keys` Podman volume.
- **Development mail must never reach the Internet.**
  - Postfix capture mode (`SMARTHOST_LIVE_DELIVERY_ENABLED=false`) relays to Mailpit.
  - The `smarthost-internal` network has no route out.
  - Live mode is accepted only with `SMARTHOST_ENV=production`.
- **Change control.** Do not commit, push, tag or release unless the user explicitly asks in that
  turn.

## Checks before reporting completion
- `python3 scripts/check-contracts.py` must pass.
- For infrastructure changes:
  - `infra/bin/smarthostctl install` must succeed, followed by `recreate` when container
    definitions or images changed;
  - `infra/bin/smarthostctl status` must show the services healthy;
  - `infra/bin/smarthostctl verify` must pass.
- For application changes: `infra/bin/smarthostctl test` must pass.
- Report the files changed, the checks run and their results, anything unverified, and any
  specification ambiguity (see `AGENTS.md`).
