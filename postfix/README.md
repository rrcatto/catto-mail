# postfix/ — Postfix container and configuration templates

Handles SMTP transport, queueing, retry and TLS.

Production ports:
- **25:** inbound for the bounce domain only (VERP, postmaster and FBL recipients). Mail is
  delivered to the shared DSN Maildir spool. No milter applies.
- **587:** authenticated submission, with the OpenDKIM milter (`milter_default_action = tempfail`).

Postfix writes its log (`maillog_file`) and periodic atomic `postqueue -j` snapshots into the
shared observability volume, which Go reads read-only. In development, Postfix relays only to
Mailpit unless `SMARTHOST_LIVE_DELIVERY_ENABLED=true`.

Empty until Phase 1. Contract: `docs/architecture/postfix-integration.md`.
