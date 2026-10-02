# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Project versions are independent of
the *specification* version, which is 2.1.

## [0.1] - 2026-10-02

First published snapshot: Phase 0 is complete and Phase 1 is in progress.

### Added: Phase 0 (architecture and contracts)
- **Canonical specification 2.1** (`docs/20260908-1644-smarthost-llm-spec.yaml`) and its
  human-readable companion. It incorporates decisions D-01 to D-29, including:
  - fully rendered recipient content from client applications, with no mail merge;
  - staged recipient upload (`collecting` → submit);
  - the webhook transactional outbox and Symfony webhook worker;
  - nginx with PHP-FPM;
  - the OpenDKIM milter;
  - opaque random tracking tokens;
  - RFC 8058 one-click unsubscribe;
  - sending-domain verification;
  - transient rendered-content retention;
  - Postfix queue-snapshot reconciliation with `outcome_unknown`;
  - leased work claiming;
  - origin-aware event de-duplication;
  - the database role bootstrap;
  - the live-sending compliance gate.
- **Normative contracts:**
  - status/event vocabulary;
  - OpenAPI 3.1 `/v1` contract;
  - reference PostgreSQL 16 schema (24 tables) with ERD and grant matrix;
  - environment-variable contract and `infra/.env.example`;
  - the Postfix integration contract.
- **Architecture docs:** overview, conventions and decision log.
- **`scripts/check-contracts.py`:** cross-artifact consistency checker.

### Added: Phase 1 (rootless Podman development environment, in progress)
- **Container images:**
  - Postfix 3.10 (Debian trixie), with a capture/live safety switch, SASL submission on 587,
    a milter on submission only, the DSN Maildir spool, and atomic `postqueue -j` snapshots;
  - OpenDKIM 2.11 with a disposable dev-key tool;
  - nginx 1.28 to PHP-FPM 8.5 over FastCGI;
  - a Symfony runtime image with Phase 1 probes and a webhook-worker placeholder;
  - Python 3.14 validator and Go 1.25 delivery probe images;
  - a deterministic fake SMTP server.
- **Quadlet and systemd:**
  - one `Internal=true` network and six named volumes;
  - eleven containers with readiness health checks (`Notify=healthy`);
  - `smarthost.target` and systemd timers for queue snapshots and log rotation.
- **`infra/bin/smarthostctl`** (init-env, render, build, secrets, install, dkim-dev-key,
  start/stop/restart/status/logs), with support for a WSL Podman machine through its `enterns`
  helper.
- **`infra/lib/smarthost_render.py`:** contract-driven, per-service least-privilege env files and
  unit rendering.
- **Idempotent PostgreSQL role bootstrap** (`infra/postgres/bootstrap.sh`).

### Changed: environment contract (from Phase 1 findings)
- Added `SMARTHOST_DELIVERY_UID`, because DSN files must be written as the Go delivery identity.
- `MAILPIT_UI_BIND` now defaults to `127.0.0.1:8026` (8025 is commonly taken by other local Mailpit instances), and
  `TRUSTED_PROXIES` to `10.89.20.0/24`.
- Documented the `/var` restriction on the Postfix log path and the production-only live mode.
- OpenDKIM now consumes `SMARTHOST_ENV`.

### Known incomplete
- The Phase 1 verification suite (`smarthostctl verify`) is not yet written.
- The V-1…V-7 Postfix/OpenDKIM verification items are only partly observed.
- End-to-end mail, DKIM-signature, milter-failure, DSN-spool, snapshot and persistence tests
  are still pending.
