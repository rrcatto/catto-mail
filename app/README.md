# app/ — Symfony application

Runs as **PHP-FPM** behind nginx (FastCGI, internal network only). It provides:
- the public `/v1` API, including batched send-job ingestion (create → recipient batches →
  submit);
- client and operator dashboards (users and client memberships, never API keys);
- sending-domain TXT verification;
- tracking endpoints using opaque random tokens;
- usage metering and retention commands;
- Doctrine entities and migrations, as the only owner of schema objects.

The same image runs the **webhook worker**: a long-running console process that consumes the
`webhook_events` outbox, signs, delivers, retries and records outcomes. It is the only component
that sends webhooks.

Empty until Phase 1 (container) and Phase 2 (entities, migrations, auth). Contracts:
`docs/api/openapi.v1.yaml`, `docs/schema/`, `docs/contracts/`.

Must not:
- probe mailboxes;
- send tracked mail;
- render templates or merge data;
- hold DKIM keys;
- call Python or Go over RPC.
