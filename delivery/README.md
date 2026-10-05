# delivery/ — Go delivery/control daemon (Phases 4 and 5)

A long-running Go 1.26 daemon (`cmd/smarthost-delivery`) that executes sealed send jobs through
Postfix and records what Postfix does with them. It connects only as `smarthost_delivery`
(`docs/schema/schema.md` §6), runs no DDL, exposes no API, has no inbound SMTP listener, never
holds DKIM keys and sends no HTTP webhooks. Contracts: `docs/architecture/postfix-integration.md`,
`docs/contracts/status-vocabulary.yaml`.

```
smarthost-delivery run [--stats-file PATH]   the daemon (pod default command)
smarthost-delivery health                    container health check (heartbeat age)
smarthost-delivery normalize ADDRESS         the D-32 normalised form
smarthost-delivery identity | check-db | check-observability | check-spool [--claim]
                                             infrastructure probes used by `smarthostctl verify`
```

## What it does

```
Symfony /v1 (submit, NOTIFY smarthost_send_work)
  -> send_jobs queued -> lease (SKIP LOCKED) -> processing
  -> one message per staged recipient (D-32 address, UUIDv7, VERP token, tracking token,
     suppression check)                       -> message_created + message_queued|_suppressed
  -> MIME + tracking -> SMTP 587 (STARTTLS, AUTH, RET/ENVID/NOTIFY/ORCPT, DATA)
  -> Postfix -> OpenDKIM (milter) -> relay (Mailpit in development)
  -> "250 ... queued as QID": queue id + submitted_to_postfix + content purge + usage (one tx)
  -> dispatched; Postfix log -> postfix_queued / deferred / connection_failure / delivery_attempt /
     remote_accepted / soft_bounce / hard_bounce -> completed (+ send.completed outbox)
  -> queue snapshots -> reconciliation -> transport_outcome_unknown when nothing is recoverable

Remote MTA / feedback loop -> Postfix :25 -> virtual(8) -> DSN spool inbound/new   (Phase 5)
  -> claim (rename to processing/) -> parse (RFC 3464/6533 DSN, RFC 5965 ARF, other)
  -> correlate (VERP, ENVID, Message-ID, queue id only; D-36) -> one transaction:
     dsn_spool event + projection + outbox + global suppression policy (D-30) | unmatched_dsns row
  -> done/ (deleted after DELIVERY_DSN_RETENTION_DAYS)
Operator match request (NOTIFY smarthost_unmatched_dsn_work) -> re-interpret -> event (matched)
```

| Package | Responsibility |
|---|---|
| `internal/config` | The environment contract (consumer `delivery`), `_FILE` secrets, fail-closed checks. |
| `internal/logx` | Contract JSON logs (`ts`, `level`, `service`, `msg`, `client_id`, `job_id`, `message_id`, `worker_id`). No bodies, secrets, tokens or credentials; recipient addresses only at debug level. |
| `internal/address` | D-32 normalisation (UTS #46 non-transitional on `golang.org/x/net/idna`, plus the explicit "xn-- label must decode to non-ASCII" check); passes the shared 87 vectors. |
| `internal/ids` | UUIDv7 message ids, 128-bit lower-case base32 VERP tokens, 192-bit base64url tracking tokens, `Message-ID: <id@SMARTHOST_BOUNCE_DOMAIN>`, VERP parsing. |
| `internal/mimemsg` | MIME from the client's rendered content under the header contract; RFC 2047 encoded words; header-injection rejection; RFC 8058 headers for subscription mail only. |
| `internal/tracking` | Open pixel (HTML only) and click rewriting of absolute http/https `<a href>` links via `message_links`; never plain text, `mailto:`, fragments, other schemes or the unsubscribe URL. |
| `internal/smtpsub` | One message, one recipient per transaction to Postfix; outcome Accepted / Temporary / Permanent / Ambiguous. |
| `internal/pacing` | Global and per-domain concurrency, per-domain start rate, per-domain deferral back-off, global pause while Postfix refuses. |
| `internal/store` | Every SQL statement: leases and fencing, expansion, acceptance (queue id + event + purge + usage in one transaction), projection, summary counts, dispatch/completion, outbox, log batches with cursor, reconciliation. |
| `internal/worker` | Claims and processes jobs: expansion in chunks of 500, a domain-aware dispatcher with at most 500 queued messages and one message's content per submission slot in memory. |
| `internal/postfixlog` | Postfix log parsing and classification; generations by first-record fingerprint (active file and `.gz`); complete records only; Message-ID and queue-id searches. |
| `internal/ingest` | Follows the log with the `delivery_ingest_cursors` cursor; survives restart and rotation. |
| `internal/snapshot`, `internal/reconcile` | `postqueue -j` snapshots, freshness, D-27 reconciliation. |
| `internal/status` | Status ranks and event → status projection from the vocabulary. |
| `internal/smtpclass` | The one failure-scope classifier (D-18, D-30) for Postfix log and DSN evidence; only mailbox-specific evidence is `recipient`. |
| `internal/dsn` | Bounded parsing of DSNs (RFC 3464, RFC 6533), ARF complaints (RFC 5965) and non-standard bounces; interpretation for a message; correlation evidence. Content is data only. |
| `internal/dsnspool` | The spool processor (claim by rename, inotify + polling, stale-claim reclaim, `failed/`, retention) and the unmatched-DSN match-request resolver. |
| `internal/store` (`dsn.go`, `policy.go`) | DSN ingestion and correlation in one transaction; the global suppression policy applied at commit to every new authoritative event (advisory lock per address, partial unique indexes); the pre-submission suppression check. |
| `internal/testsmtp` | Scripted in-process submission server for tests (STARTTLS, AUTH, 4xx/5xx, drops, milter tempfail). |

## Safety rules

* **Fencing.** Every write that depends on the job runs in a transaction that locks the job row and
  checks `claimed_by`, `lease_expires_at > now()`, `status = processing` and an active or throttled
  client. A stale worker writes nothing; a submission is only started when the whole SMTP
  transaction fits in the remaining lease.
* **Exactly once.** `messages.send_job_recipient_id` is unique (expansion is retry-safe); the
  queue-id update wins once (`postfix_queue_id IS NULL`), and the purge and the
  `message_submitted` usage row are written in that same transaction; events are keyed per source
  (D-06).
* **Never a duplicate.** A message Postfix may already have (connection lost after the end of
  DATA, a crash after acceptance) is never resubmitted before the retained log has been searched for
  its Message-ID (§6.3). An acceptance that cannot be recorded parks the message; it is never
  resubmitted by the worker that submitted it.
* **Content.** Rendered content is purged only together with a recorded Postfix acceptance; a
  temporary failure keeps it; a permanent refusal (`submission_failed`) leaves it to Symfony's
  `APP_RETENTION_STAGED_CONTENT_DAYS` cleanup.
* **Ingestion hold.** A cleanup record of a Smarthost message whose queue id is being recorded holds
  ingestion for up to 60 s, so the message's later records are correlated by queue id.
* **Reconciliation.** Only fresh consecutive snapshots taken after the grace interval count;
  absence is never success; the log is re-scanned first; an open unmatched DSN prevents a
  conclusion; `outcome_unknown` is superseded by any later authoritative event.

* **Global suppression (D-30).** A new `hard_bounce` with failure scope `recipient`, a `complaint`,
  or the third consecutive recipient `soft_bounce` of an address (all clients, distinct messages,
  within the window) creates one global suppression with source message and event, whatever the
  evidence source. Other scopes never suppress. Suppressions are checked at message creation and
  again immediately before each submission.
* **DSN idempotency.** The Maildir unique file name is the `dsn_spool` source key and the
  `unmatched_dsns.spool_ingest_key`; a claim, crash, reclaim, retry or duplicate file never creates
  a second event, row or suppression.

## Tests

* `infra/bin/smarthostctl test phase4` or `test phase5` (`infra/tests/phase4-test.sh`): `gofmt` and `go vet`
  (image build), Go unit tests without network, then the integration tests
  (`internal/integration`, build tag `integration`) against PostgreSQL 16 with the real
  migrations and grants, as `smarthost_delivery`, in a throwaway network-less pod.
* `infra/bin/smarthostctl test phase4-e2e` (`infra/tests/phase4-e2e.sh`): the running pod's real
  Postfix, OpenDKIM and Mailpit (scenarios A–E: headers and DKIM, OpenDKIM down, deferral, crash,
  10,000 recipients).
* `infra/bin/smarthostctl test phase5-e2e` (`infra/tests/phase5-e2e.sh`, driver `phase5_e2e.py`):
  DSNs and ARF reports sent to the running pod's Postfix port 25 (fixtures in
  `internal/dsn/testdata`), scenarios A–G: cross-client hard bounce, opt-out API, correlation,
  complaint, excluded scopes and repeated soft bounces, operator workflow, crash and retention.
