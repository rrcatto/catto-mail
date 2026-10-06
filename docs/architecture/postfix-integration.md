# Postfix Integration Contract

**Status:** normative contract for specification 2.7 (spec `go_delivery.initial_integration_strategy`,
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

The verification tasks V-1…V-7 were resolved in Phase 1 against the actual images (Postfix
3.10.13 and OpenDKIM 2.11.0 on Debian trixie, rootless Podman 6.0). §8 records the observed
results; the rest of this document already reflects them. The Phase 1 suite
(`infra/bin/smarthostctl verify`) re-proves them.

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

* OpenDKIM listens on `SMARTHOST_OPENDKIM_MILTER_ADDRESS`. It is never host-published, and Postfix
  is its only client; both run in the `smarthost` pod.
  Private keys are mounted only into OpenDKIM (`OPENDKIM_KEY_DIR`).
* The milter is attached **only to the authenticated submission service** (`smtpd_milters` set on
  the 587 service in `master.cf`).
* No milter runs on:
  * the port 25 inbound listener;
  * `non_smtpd_milters` (Postfix-generated bounces and local deliveries to the DSN spool).

  Inbound DSN traffic is therefore never treated as outbound client mail.
* **Signing scope.** OpenDKIM signs only messages whose `{daemon_name}` milter macro is
  `ORIGINATING`. That value is set via `milter_macro_daemon_name` on the submission service only.
* **Signing basis.** The SigningTable and KeyTable in `OPENDKIM_TABLES_DIR` contain only domains
  whose `sending_domains.status = verified` and `dkim_status = active`, each with that domain's
  `dkim_selector`. Generating these tables from the database and provisioning keys is
  infrastructure tooling (Phase 8 for production keys; a development test key in Phase 1).
* **Failure policy.**
  * For submitted mail, `milter_default_action = tempfail` is set explicitly. Postfix 3.10's
    default is `shutdown`, so the setting matters.
  * If OpenDKIM is unavailable, Postfix fails the milter at connection time. It logs
    `milter-reject: CONNECT … 451 4.7.1 Service unavailable - try again later` and answers the
    session's next command with a 4xx; the observed reply was `454 4.3.0 Try again later` at
    STARTTLS. Nothing is queued.
  * Go keeps the message `queued` and retries with backoff, so it is never sent unsigned.
  * In production, the API's submit step and Go both refuse jobs whose sending domain is not
    verified with active DKIM. OpenDKIM is therefore never asked to sign for an unknown domain.
* **Testing.** Phase 1 verifies both cases against Mailpit:
  * a signed message, cryptographically verified with dkimpy against the published key record;
  * tempfail when OpenDKIM is stopped, with port 25 unaffected and signing resuming once OpenDKIM
    returns.

  Phase 4 adds Go's retry behaviour.

## 3. Transport events (Postfix log → Go)

**Writer.** Postfix `postlogd` writes
`maillog_file = ${SMARTHOST_POSTFIX_OBSERVABILITY_DIR}/log/postfix.log`. There is no syslog and
no journald. Postfix 3.10 only accepts `maillog_file` paths under `maillog_file_prefixes`
(`/var, /dev/stdout`), so the directory must be under `/var`.

**Ownership.**

| Path | Owner | Mode |
|---|---|---|
| Volume root, `log/`, `queue/` | `root:SMARTHOST_SPOOL_GID` | `2750` (setgid) |
| Log files (`maillog_file_permissions = 0640`) and snapshots | `root:SMARTHOST_SPOOL_GID` | `0640` |

Go runs as `SMARTHOST_DELIVERY_UID:SMARTHOST_SPOOL_GID` and mounts the volume `:ro`. A write
attempt fails with `read-only file system`.

**Cursor (generation + position).**
* `delivery_ingest_cursors` holds `(source = 'postfix_log', generation_id, position)`.
* `generation_id` identifies one log generation. A raw byte offset alone is not sufficient across
  rotation, and neither is the inode (V-1):
  * `postfix logrotate` gzip-compresses the rotated file at once, so the previous generation
    survives only as a `.gz` with a **different inode**;
  * inode numbers are reused.

  `generation_id` is therefore the **SHA-256 fingerprint of the generation's first record**. It is
  identical in the active file and in its compressed copy. The active file's `device:inode` is used
  only to detect that a rotation happened.
* `position` is the byte offset of the next unread record within that generation's
  **uncompressed** stream, which is the same whether it is read from the active file or from the
  `.gz`.
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
* A daily systemd user timer runs `postfix logrotate` in the Postfix container (V-2):
  * the active file is renamed to `postfix.log.<%Y%m%d-%H%M%S>` (`maillog_file_rotate_suffix`) and
    immediately compressed with `maillog_file_compressor = gzip`, giving `postfix.log.<suffix>.gz`;
  * `postlogd` reopens a **new** active file straight away, with a new inode,
    `0640 root:SMARTHOST_SPOOL_GID`.
* When Go sees the active file's `device:inode` change, it finishes the previous generation from
  the `.gz` (matched by first-record fingerprint) and then starts the new generation at position 0.
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
* **Snapshot format (V-3)**, from `postqueue -j` in Postfix 3.10: JSON Lines, one object per
  queued message:

  ```json
  {"queue_name": "deferred", "queue_id": "4hxKf66gyVz187c", "arrival_time": 1790971838,
   "message_size": 522, "forced_expire": false, "sender": "editor@smarthost-dev.test",
   "recipients": [{"address": "queued@example.com", "delay_reason": "…"}]}
  ```

  * `queue_name` is one of `active`, `deferred`, `hold`, `incoming` or `maildrop`.
  * `delay_reason` appears only for deferred recipients.
  * An empty queue gives a zero-length file.
  * Snapshots are `0640 root:SMARTHOST_SPOOL_GID`; no `.tmp-*` file survives a run.
* **Freshness.** If the newest snapshot is older than three intervals, Go suspends reconciliation
  conclusions and raises an operator alert (`postfix_queue_snapshot_age`).

## 5. Inbound DSN, bounce and complaint mail (Postfix → Maildir → Go)

**Postfix configuration.**
* The bounce domain is a `virtual_mailbox_domains` entry.
* Recipients are accepted only for `{SMARTHOST_VERP_LOCAL_PART}{SMARTHOST_VERP_DELIMITER}*`,
  `postmaster@` and the feedback-loop address, which is the plain base address
  `{SMARTHOST_VERP_LOCAL_PART}@{bounce domain}` (to be registered with mailbox providers' feedback
  loops). All other recipients are rejected at RCPT.
* There is no relaying and no milter.
* Accepted mail is delivered by `virtual(8)` to `inbound/` as
  `SMARTHOST_DELIVERY_UID:SMARTHOST_SPOOL_GID` (`virtual_uid_maps`/`virtual_gid_maps`).
* Unknown bounce-domain users are rejected with `550 5.1.1`, and relaying to other domains with
  `554 5.7.1 Relay access denied`.
* `smtpd_relay_restrictions = reject_unauth_destination`, with no `permit_mynetworks`. In the
  Smarthost pod every member reaches Postfix from `127.0.0.1`, so port 25 must never trust the
  client address.
* `virtual(8)` runs as the delivery UID, so the recipient table it reads must be world-readable.
  The entrypoint writes configuration with umask `022`.

**Layout.**

| Directory | Writer | Purpose |
|---|---|---|
| `inbound/{tmp,new,cur}` | Postfix (`virtual(8)`) | Standard Maildir, created by `virtual(8)` on first delivery as `0700` (setgid group inherited) and owned by the delivery UID. Postfix writes the file in `tmp/` and then links it into `new/`, so a file in `new/` is always complete. A watcher sees this as **`IN_CREATE`**, not `IN_MOVED_TO` (V-5, V-7). |
| `processing/` | Go | Claimed files |
| `done/` | Go | Processed files. Deleted after `DELIVERY_DSN_RETENTION_DAYS`. |
| `failed/` | Go | Files that could not be read or stored at all. These raise an operator alert. |

The spool root, `processing/`, `done/` and `failed/` are `2770 SMARTHOST_DELIVERY_UID:SMARTHOST_SPOOL_GID`
(created by the Postfix entrypoint). DSN files are `0600` and owned by the delivery UID, which is
why Go must run as `SMARTHOST_DELIVERY_UID`: a group member could not read them.

**Ingestion identity.**
* The key is the Maildir unique file name: the base name with any `:2,…` info suffix stripped.
  The observed form is `<epoch>.V<dev>I<inode>M<usec>.<hostname>`, where `<hostname>` is the
  Postfix container's hostname.
* It is unique by Maildir construction and preserved by every rename, so it stays stable across
  claim, retry and restart.
* Matched events use `event_source = dsn_spool` with that key. Unmatched rows store it in
  `unmatched_dsns.spool_ingest_key`, which is unique.
* The content hash is kept for diagnostics only and is never used as the de-duplication key.

**Atomic claim and processing.**
1. Go claims a file with `rename("inbound/new/F", "processing/F")`. All directories are on one
   volume, so the rename is atomic and exactly one worker wins. The loser gets `ENOENT`.
2. Go parses the DSN (RFC 3464, or RFC 6533 `message/global-delivery-status`), the ARF complaint
   (RFC 5965), or a non-standard bounce (whose quoted headers are scanned for a Smarthost
   Message-ID). It correlates in this order:
   1. VERP token of the topmost `Delivered-To` / `X-Original-To` (the header the receiving Postfix
      prepends; lower ones are ignored)
   2. `Original-Envelope-Id`
   3. `X-Smarthost-Message-ID` or a Smarthost `Message-ID` in the returned headers
   4. `X-Postfix-Queue-ID`

   Nothing else correlates automatically (D-36). The recipient address, even with a matching
   returned `From`, is forgeable and suppressions are global: recent messages to the reported
   recipient (with whether their sender matches) are stored only as operator candidates in
   `unmatched_dsns.detail_json.candidates`.

   An identifier that names no message is not evidence; two identifiers naming different messages
   are a conflict. Anything not correlated with certainty becomes an `unmatched_dsns` row.
3. Go opens one transaction:
   * **If matched:** it appends the event (with `failure_scope` for bounces and deferrals),
     projects status, applies the global suppression policy (D-30), and inserts into
     `webhook_events` for the first entry into `hard_bounced` or `complained`. A report that cannot
     be reconciled with the matched message appends `dsn_unmatched`; a report of success
     (`delivered`/`relayed`/`expanded`, ARF types other than `abuse`) records nothing.
   * **Otherwise:** it inserts an `unmatched_dsns` row with the raw and parsed data.
4. Go commits, then renames the file to `done/F`.
5. **Crash recovery.** Files left in `processing/` longer than `DELIVERY_LEASE_SECONDS` (by their
   claim time, the rename's ctime) are reclaimed by renaming them to `processing/<key>#<worker>-<nonce>`
   (one winner) and re-processed; the key is the name up to `:` or `#`. A database error keeps the
   claim for an in-process retry. The unique ingestion key makes all of this safe.
6. **Retention.** Hourly, files in `done/` older than `DELIVERY_DSN_RETENTION_DAYS` (modification
   time, i.e. Postfix's delivery time) are deleted; database rows are never touched.

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

## 8. Verification results (Phase 1)

Each result was observed on the Phase 1 images and is re-checked by `smarthostctl verify`
(test groups in brackets).

| ID | Result |
|---|---|
| V-1 | **Generation identity is the first-record fingerprint, not the inode.** Compression after rotation gives the old generation a new inode, and inode numbers are reused. The first record of the `.gz` equals the first record of the generation before rotation [T15]. |
| V-2 | `postlogd` creates the log as `0640 root:SMARTHOST_SPOOL_GID` (`maillog_file_permissions = 0640`, setgid directory). `postfix logrotate` renames with `%Y%m%d-%H%M%S`, gzip-compresses immediately and reopens a new active file. `maillog_file_prefixes` restricts the path to `/var` [T14, T15]. |
| V-3 | `postqueue -j` is available. It outputs JSON Lines with `queue_name`, `queue_id`, `arrival_time`, `message_size`, `forced_expire`, `sender` and `recipients[{address, delay_reason}]`. An empty queue gives an empty file, and snapshots are written atomically [T14]. |
| V-4 | Every container uses the same default rootless user-namespace mapping, so numeric IDs are identical across containers. The delivery container (`5001:5000`) reads `root:5000 0640` files and cannot write the read-only volume [T14, T17]. |
| V-5 | `virtual(8)` delivers `0600` files owned by `SMARTHOST_DELIVERY_UID:SMARTHOST_SPOOL_GID`, and creates `inbound/{tmp,new,cur}` itself as `0700`. Files appear complete in `new/`, and `tmp/` is empty afterwards. Go's rename claim succeeds exactly once, and a second claim gets `ENOENT` [T16]. |
| V-6 | The milter is attached to the submission service only. With OpenDKIM stopped, submission gets a 4xx and nothing is queued; port 25 still accepts DSNs. With OpenDKIM running, Mailpit receives messages carrying a cryptographically valid signature [T11, T13]. |
| V-7 | inotify works across containers on the shared volumes. A new DSN raises `IN_CREATE` in `inbound/new` (the link from `tmp/`), and log writes raise `IN_MODIFY`. Polling at `DELIVERY_FILE_POLL_INTERVAL_SECONDS` stays as a fallback [T16]. |

## 9. Phase 4 implementation notes (observed)

These clarify the mechanics above as implemented and observed against Postfix 3.10 and OpenDKIM
2.11 in the development pod. They change no architecture.

* **Submission TLS.** Port 587 requires STARTTLS (`smtpd_tls_security_level = encrypt`) and accepts
  AUTH only over TLS. Go encrypts the session but does not verify Postfix's certificate: the hop
  never leaves the `smarthost` pod and the environment contract has no CA variable. AUTH is never
  sent over a plain connection.
* **DSN parameters** (`RET`, `ENVID`, `NOTIFY`, `ORCPT`) are sent when Postfix advertises `DSN`;
  `ENVID` and `ORCPT` are xtext-encoded. Addresses that need SMTPUTF8 are submitted with
  `SMTPUTF8` and without `ORCPT`.
* **Outcomes.** A 5xx reply to MAIL, RCPT or the end of DATA is `submission_failed` (key
  `submission_failed:<message_id>`, source `delivery_daemon`, because no queue id exists). 4xx
  replies, connection failures and problems before the transaction (greeting, STARTTLS, AUTH) are
  temporary; failures that concern the submission service itself (421, 4.3.x, 4.7.x such as the
  milter tempfail, connection, TLS, AUTH) also pause all submissions with bounded backoff (5 s
  doubling to 2 min), and the message retries after `5 s · 2^(n-1)` capped at
  `DELIVERY_DEFERRAL_BACKOFF_SECONDS`.
* **Ambiguous submissions (§6.3).** The message counts as queued only when, after its
  `cleanup … message-id=<id@bounce-domain>` record, the queue manager logged the same queue id
  (`queue active`, a delivery status or `removed`). The worker polls the retained log for two
  minutes; if Postfix shows nothing by then, it never queued the message and it is resubmitted. A
  reclaimed job (attempt > 1) runs this search for all its queued messages without a queue id before
  any submission, and re-scans the log for the later records of every recovered queue id.
* **Log format.** `postlogd` writes syslog-style records with no year
  (`Oct 04 08:23:16 smarthost postfix/qmgr[235]: <QID>: from=<…>, size=696, nrcpt=1 (queue active)`),
  in UTC in the container. Go infers the year (the latest year that does not put the record more than
  a day into the future) and also accepts ISO 8601 timestamps.
* **Observed record sequence** of a delivered message: `submission/smtpd … client=… sasl_username=…`,
  `cleanup … message-id=<…>`, `qmgr … from=<…> (queue active)`, `smtp … to=<…>, relay=…, dsn=2.0.0,
  status=sent (…)`, `qmgr … removed`. With the relay down: `status=deferred (connect to …:
  Connection refused)` with `dsn=4.4.1`, a later `queue active` and `status=sent`.
* **Event mapping.** The first `queue active` is `postfix_queued`, later ones (after a deferral)
  `delivery_attempt`; `status=sent` is `remote_accepted`; `status=deferred` is `connection_failure`
  (scope `connection`) for connection/TLS failures, otherwise `deferred` with the D-18 scope from the
  enhanced code or text (`dns`, `recipient`, `provider_policy`, `connection`, `infrastructure`,
  `domain`, `unknown`); `status=bounced` is `hard_bounce` (5.x.x) or `soft_bounce` (4.x.x), and
  `status=expired` is `soft_bounce`. A first entry into `hard_bounced` writes `message.hard_bounced`
  to the outbox. (Phase 4 created no suppressions; since Phase 5 these log events feed the global
  suppression policy of §5 and §10, exactly like DSN evidence.)
* **Hold rule.** A `cleanup` record of a Smarthost message whose queue id is being recorded (SMTP
  reply in flight) stops the ingestion batch for up to 60 s, so the message's later records are
  correlated. Should two messages ever share a queue id, a record belongs to the newest message
  created before it.
* **Reconciliation snapshots.** "Consecutive fresh snapshots" are the newest run of snapshots with
  no gap larger than three snapshot intervals, the newest being at most three intervals old (this is
  why `POSTFIX_QUEUE_SNAPSHOT_INTERVAL_SECONDS` is also a delivery variable). A pass also completes
  dispatched jobs whose messages are all terminal.

## 10. Phase 5 implementation notes (observed)

These clarify §5 as implemented and observed against Postfix 3.10 in the development pod
(`smarthostctl test phase5-e2e`). They change no architecture.

* **Path.** A synthetic DSN sent over SMTP to `postfix:25` for `bounce+<token>@<bounce domain>`
  (envelope sender `<>`) is delivered by `virtual(8)` into `inbound/new` with a prepended
  `Delivered-To:`; inotify wakes Go, which claims and ingests it within about a second.
* **Our own bounces.** Postfix's own DSNs (expiry, synchronous 5xx) also reach the spool, because
  their recipient is the VERP return path. They carry our `X-Postfix-Queue-ID` and ENVID. The
  resulting `dsn_spool` event is a second piece of evidence for the same failure as the
  `postfix_log` event; projection is rank-based and the suppression policy finds the active row, so
  nothing is duplicated except the evidence itself.
* **Relay acceptance then DSN.** A relay may answer `250` (`remote_accepted` from the log) and the
  final mailbox later return a DSN. The DSN supersedes the acceptance (higher rank), and that
  acceptance is not a reset of the repeated-soft-bounce sequence.
* **Event time.** A DSN's event time is `Last-Attempt-Date`, else `Date`, else the Maildir receipt
  time (the epoch in the file name); implausible times are replaced by the receipt time.
* **Phase 1 verification.** T16 stops the daemon while it observes raw Maildir delivery, since the
  running daemon now claims spool files itself.
