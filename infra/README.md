# infra/ — Podman networks, Quadlet units, nginx, database bootstrap, environment templates

- `.env.example` is the safe environment template (no secrets). The normative contract is
  `docs/contracts/environment.md`. Never commit a filled-in copy.

Contents (see `docs/PROJECT.md` for every file):
- **nginx** (the only public HTTP entry), which hands every request to the Symfony front
  controller via FastCGI. The topology is the same in development and production.
- The `smarthost` pod, the internal network, volumes and Quadlet units, including the separate
  Symfony webhook-worker unit and the OpenDKIM unit.
- Systemd timers for Postfix log rotation and Postfix queue snapshots.
- Shared volumes `smarthost-postfix-observability` and `smarthost-dsn-spool`.
- **Database role bootstrap and grants.** `postgres/bootstrap.sh` creates `smarthost_owner`,
  `smarthost_app`, `smarthost_webhook`, `smarthost_validator` and `smarthost_delivery` over the
  administrative connection. After the Doctrine migrations (`smarthost-db-migrate`, as the owner),
  `postgres/grants.sh` applies the grant matrix of `docs/schema/schema.md` §6 exactly
  (`smarthost-db-grants`). Roles and grants are infrastructure, not Doctrine schema.
- The Phase 1 verification suite (`tests/phase1-verify.sh`), the shared throwaway test pod
  (`tests/testpod.sh`) and the Phase 2 and Phase 3 test harnesses (`tests/phase2-test.sh`,
  `tests/phase3-test.sh`).

Rootless Podman with Quadlet/systemd user units only. No Docker and no Kubernetes.
