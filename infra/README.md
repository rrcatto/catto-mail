# infra/ — Podman network and persistent pod, systemd units, nginx, database bootstrap, environment templates

- `.env.example` is the safe environment template (no secrets). The normative contract is
  `docs/contracts/environment.md`. Never commit a filled-in copy.

Contents (see `docs/PROJECT.md` for every file):
- **nginx** (the only public HTTP entry), which hands every request to the Symfony front
  controller via FastCGI. The topology is the same in development and production.
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

Rootless Podman with a persistent pod and systemd user units only. No Docker and no Kubernetes.
