# infra/ — Podman networks, Quadlet units, nginx, database bootstrap, environment templates

- `.env.example` is the safe environment template (no secrets). The normative contract is
  `docs/contracts/environment.md`. Never commit a filled-in copy.

Phase 1 will add:
- **nginx** (the only public HTTP entry), which reaches Symfony PHP-FPM via FastCGI. The topology
  is the same in development and production.
- Networks and Quadlet units, including the separate Symfony webhook-worker unit and the OpenDKIM
  unit.
- Systemd timers for Postfix log rotation, Postfix queue snapshots and Symfony scheduled
  commands.
- Shared volumes `smarthost-postfix-observability` and `smarthost-dsn-spool`.
- **Database role bootstrap.** It creates `smarthost_owner`, `smarthost_app`,
  `smarthost_webhook`, `smarthost_validator` and `smarthost_delivery` over the administrative
  connection, and idempotently applies the grant matrix in `docs/schema/schema.md` §6 after each
  migration. Roles are infrastructure, not Doctrine schema.

Rootless Podman with Quadlet/systemd user units only. No Docker and no Kubernetes.
