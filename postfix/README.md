# postfix/ — Postfix container and configuration templates

Handles SMTP transport, queueing, retry and TLS.

Production ports:
- **25:** inbound for the bounce domain only: VERP return paths (`bounce+<token>@<bounce domain>`),
  `postmaster@` and the feedback-loop address `<SMARTHOST_VERP_LOCAL_PART>@<bounce domain>` (the
  address to register with mailbox providers' feedback loops). Mail is delivered by `virtual(8)` to
  the shared DSN Maildir spool, where the Go delivery daemon claims and processes it (DSNs and ARF
  complaints, Phase 5). No milter applies, and port 25 never relays.
- **587:** authenticated submission, with the OpenDKIM milter (`milter_default_action = tempfail`).
  Two SASL accounts: the Go delivery daemon (`SMARTHOST_SUBMISSION_*`) and the Symfony application,
  which sends only the dashboard sign-in emails (`APP_MAIL_SUBMISSION_*`).

Postfix writes its log (`maillog_file`) and periodic atomic `postqueue -j` snapshots into the
shared observability volume, which Go reads read-only. In development, Postfix relays only to
Mailpit unless `SMARTHOST_LIVE_DELIVERY_ENABLED=true`.

Files: `Containerfile`; `entrypoint.sh` (builds `main.cf`/`master.cf` from the environment
contract, the capture/live safety switch, the shared-volume layout and the bounce-domain recipient
table); `healthcheck.sh`; `logrotate.sh` and `queue-snapshot.sh` (run by the systemd user timers).
Contract: `docs/architecture/postfix-integration.md`.
