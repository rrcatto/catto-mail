# infra/ — Podman network and persistent pod, systemd units, nginx, database bootstrap, environment templates

- `.env.example` is the safe environment template (no secrets): only the settings an operator
  sets, grouped under headings; every other contract variable is built in (the renderer supplies
  it). The normative contract is `docs/contracts/environment.md`. Never commit a filled-in copy.
  `smarthostctl env-migrate` rewrites an older `infra/.env` in the current layout and keeps the old
  file as `infra/.env.v<version>-<timestamp>`.

Contents (see `docs/PROJECT.md` for every file):
- **nginx** (the only public HTTP entry), which hands every request to the Symfony front
  controller via FastCGI.
  - Development uses `nginx/templates/`.
  - Production uses `/etc/nginx/templates-production/`: the same HTTPS server, listening on the
    socket systemd hands it, plus `nginx/production/`, the SMTP stream to Postfix with the PROXY
    protocol.
- `podman/smarthost-pod.sh.in`: the single definition of the internal network, the volumes, the
  persistent `smarthost` pod and its ten service containers (including the separate Symfony
  webhook worker and OpenDKIM), and the ordered one-off DB tasks. Rendered into
  `.generated/podman/smarthost-pod.sh`; `bin/smarthostctl` drives it (create, start, stop,
  recreate, remove). A stop never removes the pod or its containers (D-35).
- `systemd/`: `smarthost.service`, which starts the existing pod at boot and stops it at shutdown
  (never removes it), and the timers for Postfix log rotation and queue snapshots.
- Shared volumes `smarthost-postfix-observability` and `smarthost-dsn-spool`.
- **Database role bootstrap and grants.** `postgres/bootstrap.sh` creates `smarthost_owner`,
  `smarthost_app`, `smarthost_webhook`, `smarthost_validator` and `smarthost_delivery` over the
  administrative connection. After the Doctrine migrations (task `db-migrate`, as the owner),
  `postgres/grants.sh` applies the grant matrix of `docs/schema/schema.md` §6 exactly
  (task `db-grants`). `smarthostctl start` runs the three tasks in order on every start. Roles and grants are infrastructure, not Doctrine schema.
- The Phase 1 verification suite (`tests/phase1-verify.sh`), the shared throwaway test pod
  (`tests/testpod.sh`), the Phase 2, 3 and 4/5 test harnesses (`tests/phase2-test.sh`,
  `tests/phase3-test.sh`, `tests/phase4-test.sh`) and the Phase 4 and Phase 5 end-to-end runs
  against the running pod (`tests/phase4-e2e.sh`, `tests/phase4_e2e.py`, `tests/phase5-e2e.sh`,
  `tests/phase5_e2e.py`).

**Production (Phase 8):**
- `podman/smarthost-production.sh.in` defines the production topology: standalone persistent
  containers on internal, ingress and egress networks, with no published ports
  (`docs/production/README.md`).
- `systemd/production/`: the production ingress socket (443 and 25 bound by the service user's
  systemd) and the service that starts nginx with it, so client addresses are preserved; the
  reputation, backup and certificate-renewal timers; the host agent (specification 2.11).
- `bin/smarthostctl-prod` (`smarthostctl prod <command>`) operates it.
- `lib/smarthost_preflight.py` is the production preflight, the DNS checklist and the firewall
  ruleset; `lib/smarthost_seedtest.py` is the operator's seed test; `lib/smarthost_agent.py` is
  the host agent (host checks and dashboard requests, specification 2.11).
- The installer `install-catto-mail` (repository root) prepares an Ubuntu Server 26.04 LTS host
  and runs these commands (`docs/production/VPS-INSTALL.md`).
- `production.env.example` is the production template.
- Tests: `tests/phase8-test.sh` and `tests/phase8/` (no network), and `tests/phase8-rehearsal.sh`
  (the production topology locally, no Internet egress).
- Guides: `docs/production/VPS-INSTALL.md` (installation), `docs/production/runbook.md`.

Rootless Podman with persistent containers (a pod in development) and systemd user units only.
No Docker and no Kubernetes.
