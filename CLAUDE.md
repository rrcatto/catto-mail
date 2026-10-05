# CLAUDE.md — instructions for Claude Code in this repository

Read `AGENTS.md` first; everything there applies. This file adds Claude-specific working notes.

## Authority
1. `docs/20260908-1644-smarthost-llm-spec.yaml` (specification 2.5) is authoritative.
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
  `--operator`).
- Do not start Phase 6 or later work unless the user explicitly asks for it.

## Public repository
This is a public repository. Documentation, comments, examples, tests, configuration templates and commit content must contain only information relevant to the Catto Mail software. Do not include private business plans, names of unrelated private projects, historical mailing-list information, personal hardware details, personal addresses, credentials, private infrastructure details, or other personally identifying/contextual information unless explicitly required by the user.

## Rules that are easy to get wrong
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
- **No undocumented environment variables.** Every variable must appear in
  `docs/contracts/environment.md`. Regenerate the template with
  `python3 infra/lib/smarthost_render.py env-example`, then run
  `python3 scripts/check-contracts.py`.
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
