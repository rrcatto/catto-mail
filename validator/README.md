# validator/ — Python validation worker (Phase 3)

An asyncio background worker (Python 3.14). It claims validation addresses from PostgreSQL under a
lease, evaluates them, and writes the results, evidence, job counters, usage and the outbox event in
fenced transactions. It has **no public API**, runs **no DDL** and connects only as
`smarthost_validator` (`docs/schema/schema.md` §6). Results are read through the Symfony `/v1` API.

```
python -m smarthost_validator run [--exit-when-idle] [--stats-file PATH]   # the worker (unit entry point)
python -m smarthost_validator health        # container health check (heartbeat age)
python -m smarthost_validator check-db      # privilege probe used by the Phase 1 verification suite
python -m smarthost_validator normalize ADDRESS
```

## Pipeline (spec `validation.pipeline`)

| # | Stage | Module | Notes |
|---|---|---|---|
| 1 | Normalisation | `normalize.py` | The D-32 rule, shared with PHP through `docs/contracts/address-normalization-vectors.json`. UTS #46 is implemented explicitly on the `idna` package's mapping table, because `idna.encode()` (IDNA2008) disagrees with PHP/ICU. `original_address` is never modified. |
| 2 | Syntax | `syntax.py` | RFC 5321/5322 rules, no network access, stable `syntax.*` codes. |
| 3 | Typo suggestions | `typo.py`, `typo_data.py` | Explicit provider, alias and TLD tables plus documented rules. A suggestion never changes an address; confidence is low/medium/high. |
| 4 | DNS | `dns_check.py` | mx, null_mx, address_fallback, no_mail_host, nxdomain, temporary_failure, invalid_response. Per-worker cache and single-flight lookups. |
| 5 | Disposable | `db.disposable_match` | The domain or a parent domain in `disposable_domains` (read-only). A risk signal. |
| 6 | Role | `roles.py` | An explicit list (info, admin, sales, support, abuse, postmaster, ...). A flag, never a rejection. |
| 7 | SMTP probe | `smtp_probe.py` | Only with `VALIDATOR_SMTP_PROBE_ENABLED=true`. EHLO (HELO fallback) → MAIL FROM → RCPT TO → optional accept-all RCPT → QUIT. **Never DATA**: the client refuses any verb outside `EHLO HELO MAIL RCPT RSET QUIT`. |
| 8 | Classification | `classify.py` | A deterministic rule table (in the module docstring) with the certainty invariants. |

`pipeline.py` runs the stages for one address and decides between a final result and a retry;
`worker.py` orchestrates; `db.py` holds every SQL statement.

## Leases and transactions

* **Claim:** `FOR UPDATE SKIP LOCKED` on addresses of `queued`/`processing` jobs of **active or
  throttled** clients (D-31) that are pending, due for retry or whose lease expired.
  `attempt_count` increases on every claim. `claimed_by` is `<VALIDATOR_WORKER_ID>/<random
  instance>`, so a restarted process never mistakes its predecessor's leases for its own.
* **Renewal** every lease/3. An address whose renewal fails (lease lost, job cancelled, client
  suspended) is dropped and its task cancelled; its result is never published.
* **Fenced writes:** every result, retry and evidence write requires
  `claimed_by = me AND lease_expires_at > now()`, the job still `queued`/`processing` and the
  client still active/throttled.
* **Finalisation** is one transaction per job:
  1. the fenced result update;
  2. the evidence;
  3. the incremental job counters (never re-aggregated);
  4. **usage** — one `validation_address` unit per address that really became `done` (D-33),
     aggregated per job and transaction;
  5. on completion, `completed` + `validation.completed` in `webhook_events`.

  A crash before commit writes nothing. After commit, the rows are `done` and can never be written
  or metered again.
* **Whole-job failure:** a job that can never complete (fewer address rows than
  `total_addresses`) becomes `failed`, with `validation.failed` in the outbox and the reason in
  `audit_log`.
* **No HTTP webhooks.** The validator only inserts `validation.completed` / `validation.failed`
  rows into the transactional `webhook_events` outbox. Signing and HTTP delivery belong to the
  Symfony webhook worker (Phase 7).
* **Outbox inserts** are plain `INSERT`s made after winning the job's status transition.
  `ON CONFLICT` would need SELECT on `webhook_events`, which this role does not hold.

## Concurrency, retries and anti-abuse

* **Probe limits:** `VALIDATOR_GLOBAL_CONCURRENCY`, `VALIDATOR_PER_DOMAIN_CONCURRENCY` and
  `VALIDATOR_PER_MX_CONCURRENCY` are enforced independently. A probe takes its domain slot, then
  its MX slot, then a global slot, so a skewed job (thousands of addresses at one provider) waits
  on that provider without holding global slots.
* **In-flight work:** at most about two chunks (`2 × VALIDATOR_CHUNK_SIZE`) are in flight; a new
  chunk is claimed when fewer than one remains.
* **Provider back-off:** 421, 4.7.x, 5.7.x and policy wording mark the MX as throttling. It is not
  probed again until an exponential cool-down ends; affected addresses are rescheduled.
* **Accept-all probes:** at most one per domain per 24 hours (verdict cached), and at most one per
  second per worker. Other addresses of the domain wait for the verdict.
* **Retries:** temporary conditions use `VALIDATOR_RETRY_BASE_SECONDS · 2^(attempt-1)`, capped at
  `VALIDATOR_RETRY_MAX_SECONDS`, set in `next_attempt_at`. After `VALIDATOR_MAX_ATTEMPTS` the
  result is `temporarily_unverifiable` or `unknown`, never `undeliverable`.

## Decisions taken in Phase 3 (also in `docs/architecture/open-decisions.md`)

* **Internationalised local parts (RFC 6531)** are syntactically valid. They are probed only when
  the server offers SMTPUTF8; otherwise `smtp_status = inconclusive`
  (`smtp.smtputf8_unsupported`).
* **Address literals** (`[192.0.2.1]`) are valid syntax but are not looked up or probed: `unknown`.
* **STARTTLS** is not used for probes. A server that insists (530 before MAIL) yields
  `inconclusive`/`blocked`.
* **No live probing outside production.** Outside production, enabling probing requires
  `VALIDATOR_SMTP_ROUTE_OVERRIDE` (the fake SMTP service). In production the override must be
  empty.
* **Development pod DNS:** the development network has no Internet route. aardvark-dns answers
  NXDOMAIN for public names, so real domains classify as `undeliverable` there. Meaningful
  validation testing uses the harness's fake DNS (`smarthostctl test phase3`).

## Tests

`infra/bin/smarthostctl test phase3` (`infra/tests/phase3-test.sh`) runs, in a throwaway pod with
no network:

* **pytest:** unit, database and worker tests (`tests/`), against PostgreSQL 16 with the real
  migrations and grants.
* **End to end:** Symfony creates jobs through `/v1`, including a 10,000-address job. The real
  worker process runs against the fake DNS server (`tests/fakes/fake_dns.py`) and the fake SMTP
  service, is killed with SIGKILL mid-job and restarted. Symfony then verifies the results through
  `/v1` and the database. The fake SMTP command log must not contain `DATA`.
