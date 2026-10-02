# Postfix Integration Contract

**Status:** normative contract for specification 2.1 (spec `go_delivery.initial_integration_strategy`,
`service_topology.opendkim`, `transport_reconciliation`). This document describes the
mechanics. It does not change the architecture.

Go never runs an inbound SMTP listener, never reads the host systemd journal, never holds DKIM
keys, and never implements a milter.

| # | Channel | Direction | Mechanism | Access |
|---|---|---|---|---|
| 1 | Submission | Go → Postfix | SMTP on the private network, port 587, SASL AUTH | Go is the client |
| 2 | DKIM signing | Postfix ↔ OpenDKIM | Milter protocol on the private network, outbound submission only | OpenDKIM holds the keys |
| 3 | Transport events | Postfix → Go | Log file in the shared observability volume (`log/`) | Go mounts it **read-only** |
| 4 | Queue snapshots | Postfix → Go | Atomic JSON queue snapshots in the shared observability volume (`queue/`) | Go mounts it **read-only** |
| 5 | Inbound DSN/bounce/complaint | Postfix → Go | Maildir on the shared DSN spool volume | Go mounts it read-write, for atomic claim by rename |

The verification tasks in §8 must be confirmed against the Phase 1 Postfix and OpenDKIM images
before Phase 4 code relies on them. They are implementation checks, not open architecture
decisions.

## 1. Submission (Go → Postfix)

* Go connects to `DELIVERY_POSTFIX_SUBMISSION_HOST:DELIVERY_POSTFIX_SUBMISSION_PORT`, uses
  STARTTLS when it is offered, and authenticates with `SMARTHOST_SUBMISSION_*`.
* **One message and one recipient per SMTP transaction:**

  ```
  MAIL FROM:<{return_path}> RET=HDRS ENVID={message_id}
  RCPT TO:<{recipient_address}> NOTIFY=FAILURE,DELAY ORCPT=rfc822;{recipient_address}
  DATA …
  ```
* **Queue id capture.** Postfix's final reply is `250 2.0.0 Ok: queued as <QID>`. In one
  transaction, Go:
  * stores `messages.postfix_queue_id`;
  * appends `submitted_to_postfix` with `event_source = postfix_submission` and key
    `<message_id>:<QID>`;
  * **purges the recipient's rendered content.** It sets `send_job_recipients.subject`,
    `html_body` and `text_body` to NULL and sets `content_purged_at`. `content_bytes` and
    `content_sha256` are kept.

  Postfix runs with `enable_long_queue_ids = yes`.
* **Reply handling:**

  | Reply | Effect |
  |---|---|
  | 2xx with a queue id | Status becomes `submitted`. |
  | 4xx | Includes milter tempfail. The message stays `queued` and is retried with backoff. |
  | 5xx | `submission_failed` is appended and the status becomes `failed`. Content is purged after `APP_RETENTION_STAGED_CONTENT_DAYS`. |
  | Connection lost after DATA | Ambiguous: Go does not resubmit straight away (§6.3). |
* **Header contract** (spec `sending.header_contract`). Go writes:
  * `From`, which is the approved sender identity;
  * `Reply-To`, when the job sets one;
  * `To`, `Subject` (rendered), `Date`;
  * `Message-ID: <{message_id}@{SMARTHOST_BOUNCE_DOMAIN}>` and `X-Smarthost-Message-ID`;
  * the MIME structure;
  * for subscription messages, `List-Unsubscribe`, `List-Unsubscribe-Post` and `List-Id`.

  No caller-supplied headers are accepted.

## 2. DKIM signing (Postfix ↔ OpenDKIM)

* OpenDKIM listens on `SMARTHOST_OPENDKIM_MILTER_ADDRESS`, which is reachable only from Postfix.
  Private keys are mounted only into OpenDKIM (`OPENDKIM_KEY_DIR`).
* The milter is attached **only to the authenticated submission service** (`smtpd_milters` set on
  the 587 service in `master.cf`).
* No milter runs on:
  * the port 25 inbound listener;
  * `non_smtpd_milters` (Postfix-generated bounces and local deliveries to the DSN spool).

  Inbound DSN traffic is therefore never treated as outbound client mail.
* **Signing basis.** The SigningTable and KeyTable in `OPENDKIM_TABLES_DIR` contain only domains
  whose `sending_domains.status = verified` and `dkim_status = active`, each with that domain's
  `dkim_selector`. Generating these tables from the database and provisioning keys is
  infrastructure tooling (Phase 8 for production keys; a development test key in Phase 1).
* **Failure policy.**
  * For submitted mail, `milter_default_action = tempfail`. If OpenDKIM is unavailable or errors,
    Postfix answers 4xx. Go keeps the message `queued` and retries with backoff, so it is never
    sent unsigned.
  * In production, the API's submit step and Go both refuse jobs whose sending domain is not
    verified with active DKIM. OpenDKIM is therefore never asked to sign for an unknown domain.
* **Testing.** Phase 4 tests two cases against Mailpit: a signed message, and the tempfail-and-retry
  behaviour when OpenDKIM is stopped.

## 3. Transport events (Postfix log → Go)

**Writer.** Postfix ≥ 3.4 `postlogd` writes
`maillog_file = ${SMARTHOST_POSTFIX_OBSERVABILITY_DIR}/log/postfix.log`. There is no syslog and
no journald.

**Ownership.**

| Path | Owner | Mode |
|---|---|---|
| Volume root, `log/`, `queue/` | Postfix, group `SMARTHOST_SPOOL_GID` | `2750` |
| Files | as above | `0640` |

Go runs with that supplementary group and mounts the volume `:ro`.

**Cursor (generation + position).**
* `delivery_ingest_cursors` holds `(source = 'postfix_log', generation_id, position)`.
* `generation_id` identifies one log generation. A raw byte offset alone is not sufficient across
  rotation. The planned derivation is `device:inode` of the file plus a fingerprint of its first
  record, so that a recycled inode cannot be mistaken for an earlier generation. This is
  verification task V-1.
* `position` is the byte offset of the next unread record within that generation.
* The cursor is updated **in the same database transaction** as the events derived from the batch.
* Only complete, newline-terminated records are consumed.

**Event identity.**
* Each log-derived event has `event_source = postfix_log` and
  `source_event_key = <generation_id>:<record_position>`, which is the record's identity and
  position.
* Content is never used as the de-duplication key: two identical `451` deferrals at different
  positions are two events.
* Re-reading a generation is harmless, because `(event_source, source_event_key)` is unique.

**Correlation.**
* Events are correlated by queue id within a time window that starts at `messages.created_at`.
* Records for queue ids that belong to no message are ignored. Examples are Smarthost's own
  Symfony Mailer notifications and DSN-spool deliveries.

**Rotation and retention.**
* A daily systemd user timer runs `postfix logrotate` in the Postfix container. This renames the
  file with `maillog_file_rotate_suffix` and may compress the old file with
  `maillog_file_compressor` (verification task V-2).
* When Go sees the active file's identity change, it finishes the previous generation (reading the
  compressed copy if necessary) and then starts the new generation at position 0.
* The timer deletes rotated files older than `POSTFIX_LOG_RETENTION_DAYS`.
* If a generation recorded in the checkpoint has disappeared, Go records a **log gap**: it is
  logged, shown on the operator dashboard, and the affected messages are left for reconciliation
  (§6).

## 4. Queue snapshots (Postfix → Go)

* A systemd user timer runs a snapshot step in the Postfix container every
  `POSTFIX_QUEUE_SNAPSHOT_INTERVAL_SECONDS`:
  1. `postqueue -j > queue/.tmp-<ts>`.
  2. Only if the exit status is 0, `rename` it to `queue/snapshot-<UTC ts>.jsonl`.

  The rename is atomic, so Go never sees a partial file, and an empty file genuinely means an
  empty queue. A failed run produces no snapshot.
* The step keeps the newest `POSTFIX_QUEUE_SNAPSHOT_RETENTION_COUNT` snapshots.
* The snapshot format is verification task V-3: one JSON object per queued message, including
  `queue_id` and recipients.
* **Freshness.** If the newest snapshot is older than three intervals, Go suspends reconciliation
  conclusions and raises an operator alert (`postfix_queue_snapshot_age`).

## 5. Inbound DSN, bounce and complaint mail (Postfix → Maildir → Go)

**Postfix configuration.**
* The bounce domain is a `virtual_mailbox_domains` entry.
* Recipients are accepted only for `{SMARTHOST_VERP_LOCAL_PART}{SMARTHOST_VERP_DELIMITER}*`,
  `postmaster@` and a registered feedback-loop address. All other recipients are rejected at RCPT.
* There is no relaying and no milter.
* Accepted mail is delivered by `virtual(8)` to `inbound/` with the static GID
  `SMARTHOST_SPOOL_GID`.

**Layout.**

| Directory | Writer | Purpose |
|---|---|---|
| `inbound/{tmp,new,cur}` | Postfix | Standard Maildir. Postfix writes to `tmp/` and renames into `new/`, so files in `new/` are always complete. |
| `processing/` | Go | Claimed files |
| `done/` | Go | Processed files. Deleted after `DELIVERY_DSN_RETENTION_DAYS`. |
| `failed/` | Go | Files that could not be read or stored at all. These raise an operator alert. |

All directories have mode `2770` with group `SMARTHOST_SPOOL_GID`, and both processes use umask
`007`.

**Ingestion identity.**
* The key is the Maildir unique file name: the base name with any `:2,…` info suffix stripped.
* It is unique by Maildir construction and preserved by every rename, so it stays stable across
  claim, retry and restart.
* Matched events use `event_source = dsn_spool` with that key. Unmatched rows store it in
  `unmatched_dsns.spool_ingest_key`, which is unique.
* The content hash is kept for diagnostics only and is never used as the de-duplication key.

**Atomic claim and processing.**
1. Go claims a file with `rename("inbound/new/F", "processing/F")`. All directories are on one
   volume, so the rename is atomic and exactly one worker wins. The loser gets `ENOENT`.
2. Go parses the DSN (RFC 3464), the ARF complaint (RFC 5965), or a non-standard bounce. It
   correlates in this order:
   1. VERP token
   2. `Original-Envelope-Id`
   3. `X-Smarthost-Message-ID`
   4. `X-Postfix-Queue-ID`
   5. `Original-Recipient`
3. Go opens one transaction:
   * **If matched:** it appends the event (with `failure_scope` for bounces and deferrals),
     projects status, applies suppression policy, and inserts into `webhook_events` for hard
     bounces and complaints.
   * **Otherwise:** it inserts an `unmatched_dsns` row with the raw and parsed data.
4. Go commits, then renames the file to `done/F`.
5. **Crash recovery.** Files left in `processing/` longer than `DELIVERY_LEASE_SECONDS` are
   re-processed. The unique ingestion key makes this safe.

**Unmatched DSN resolution.**
1. An operator identifies the candidate message in the dashboard. Symfony sets
   `matched_message_id`, `resolution_requested_by`, `resolution_requested_at` and
   `status = match_requested`, and sends NOTIFY `smarthost_unmatched_dsn_work`.
2. Go claims the row (SKIP LOCKED), re-interprets the retained DSN, and appends the normal
   transport event (`event_source = unmatched_dsn_resolution`, key = row id) with projection and
   suppression policy.
3. Go sets `resolution_event_id`, `resolved_at` and `status = matched`.

The row is never deleted, except by the configured retention.

## 6. Reconciliation (D-27)

Go runs reconciliation every `DELIVERY_RECONCILE_INTERVAL_SECONDS`.

### 6.1 Candidates

Messages in `submitted` or `deferred` status that have a queue id, and whose latest transport
event is older than `DELIVERY_RECONCILE_GRACE_SECONDS`.

### 6.2 Disappearance procedure

1. If the queue id appears in the newest fresh snapshot, the message is still in Postfix. Nothing
   is done.
2. If it is absent, absence alone is **never** treated as success. Go requires the queue id to be
   absent from at least `DELIVERY_RECONCILE_MIN_SNAPSHOTS` (≥ 2) consecutive fresh snapshots, all
   taken after the grace interval.
3. Go then re-scans the retained log generations for that queue id, and the DSN spool and
   `unmatched_dsns` for the message's VERP token or envelope id. Any authoritative outcome found
   is ingested normally.
4. If none is found, Go appends `transport_outcome_unknown` (`event_source = queue_reconciliation`,
   key `<message_id>:<queue_id>`). The status becomes `outcome_unknown`, and the message appears in
   the operator's outcome-unknown view and the `messages_outcome_unknown` metric.
5. A later authoritative event or DSN has a higher rank and supersedes `outcome_unknown`.

### 6.3 Queued messages without a queue id

These come from an ambiguous submission or a crashed worker. Before resubmitting, Go searches the
log for the `cleanup … message-id=<{message_id}@…>` record:
* **If found,** Go records `submitted_to_postfix` (`event_source = postfix_log`) with that queue
  id.
* **If not found,** and the job lease has expired, the message is resubmitted normally.

A message is therefore never submitted twice.

### 6.4 Job completion

A `dispatched` job becomes `completed` when none of its messages remains in a non-terminal status.
`outcome_unknown` counts as terminal.

## 7. Leases (D-03)

| Work item | Lease |
|---|---|
| Send jobs | `claimed_by` / `lease_expires_at`. Renewed by Go while the job is processed, and every Go write is fenced on still holding the lease. |
| DSN files | Claimed by rename, with no database lease. |
| Unmatched-DSN resolutions | Claimed with SKIP LOCKED inside one transaction. |
| Reconciliation | Performed by any one Go instance holding a PostgreSQL advisory lock, so two instances never reconcile at the same time. |

## 8. Verification tasks (Phase 1, before Phase 4 relies on them)

| ID | Verify against the Phase 1 images |
|---|---|
| V-1 | Exact log-generation identity: whether `device:inode` plus a first-record fingerprint is stable under `postfix logrotate` on the chosen volume driver. |
| V-2 | `postlogd` file ownership and modes; `postfix logrotate` behaviour and the `maillog_file_rotate_suffix` and `maillog_file_compressor` parameter names in the image's Postfix version. |
| V-3 | `postqueue -j` output format (one JSON object per line) and its behaviour when the queue is empty. |
| V-4 | Rootless Podman: both containers see the same numeric `SMARTHOST_SPOOL_GID` on the shared volumes, checked with `podman unshare`. |
| V-5 | `virtual(8)` Maildir delivery uses tmp→new rename semantics on the shared volume, and the unique-name format is as expected. |
| V-6 | The OpenDKIM milter is attached to the submission service only; `milter_default_action = tempfail` produces a 4xx to Go when OpenDKIM is stopped. |
| V-7 | Inotify behaviour on the shared volumes. If it is unreliable, polling at `DELIVERY_FILE_POLL_INTERVAL_SECONDS` is used. |
