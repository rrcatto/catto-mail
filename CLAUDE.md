# CLAUDE.md — instructions for Claude Code in this repository

Read `AGENTS.md` first; everything there applies. This file adds Claude-specific working notes.

## Authority
1. `docs/20260908-1644-smarthost-llm-spec.yaml` (specification 2.1) is authoritative.
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
- Do not start Phase 2 or later work unless the user explicitly asks for it.

## Public repository
This is a public repository. Documentation, comments, examples, tests, configuration templates and commit content must contain only information relevant to the Catto Mail software. Do not include private business plans, names of unrelated private projects, historical mailing-list information, personal hardware details, personal addresses, credentials, private infrastructure details, or other personally identifying/contextual information unless explicitly required by the user.

## Rules that are easy to get wrong
- **Podman only, rootless, Quadlet.** In this development setup the engine runs in the WSL
  Podman machine `podman-machine-default`:
  - Container commands work through `podman` (remote).
  - systemd/Quadlet commands must run inside the machine's systemd namespace. Use
    `infra/bin/smarthostctl`; it enters that namespace through the machine's
    `/usr/local/bin/enterns`.
  - Do not run the in-machine `podman` CLI from an ad-hoc `wsl.exe` shell outside that
    namespace, because it creates a separate rootless user namespace.
- **All Smarthost containers run in the `smarthost` pod** (`infra/quadlet/smarthost.pod.in`).
  New containers join it with `Pod=smarthost.pod`. Host ports and network aliases belong on the
  pod, never on a container. Loopback is shared by all members, so never use `127.0.0.1` as an
  authorisation boundary.
- **Other projects' containers may run on the same Podman machine.** Never stop, modify or
  remove any container, volume or network that is not Smarthost's (`smarthost-*`, label
  `project=smarthost`). Smarthost avoids common host ports such as 80, 443, 5433 and 8025.
- **No undocumented environment variables.** Every variable must appear in
  `docs/contracts/environment.md`. Regenerate the template with
  `python3 infra/lib/smarthost_render.py env-example`, then run
  `python3 scripts/check-contracts.py`.
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
  - `infra/bin/smarthostctl install` must succeed (the Quadlet dry-run validates units);
  - `infra/bin/smarthostctl status` must show the services healthy;
  - `infra/bin/smarthostctl verify` must pass.
- Report the files changed, the checks run and their results, anything unverified, and any
  specification ambiguity (see `AGENTS.md`).
