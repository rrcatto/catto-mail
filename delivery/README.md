# delivery/ — Go delivery/control daemon

A long-running daemon with leased work claiming. It:
- creates `messages` from sealed `send_job_recipients`;
- builds MIME from the client's **already rendered** content under the header contract;
- applies tracking instrumentation only when the job enables it (HTML pixel, HTML link
  rewriting);
- adds `List-Unsubscribe`/`List-Id` for subscription mail;
- submits over SMTP to Postfix, records the queue id and purges rendered content;
- ingests the Postfix log through generation-aware cursors;
- reconciles against queue snapshots, producing `outcome_unknown`;
- claims DSN files from the shared Maildir spool and applies operator-requested DSN resolutions;
- appends source-keyed `message_events` and writes `webhook_events` outbox rows.

It has no public API and no inbound SMTP listener. It does no template rendering and no DKIM,
runs no DDL, and sends no HTTP webhooks.

Empty until Phase 1 (container) and Phases 4–5 (implementation). Contracts:
`docs/architecture/postfix-integration.md`, `docs/contracts/status-vocabulary.yaml`.
