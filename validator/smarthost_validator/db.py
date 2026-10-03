"""PostgreSQL access for the validator (role smarthost_validator; DML only, no DDL).

Grants used (docs/schema/schema.md §6): clients S; validation_jobs S U;
validation_addresses S U; validation_evidence S I; disposable_domains S;
usage_records I; webhook_events I; audit_log I.

Leased work (conventions "Database access", D-03/D-31):
* claim: `FOR UPDATE SKIP LOCKED`, only addresses of queued/processing jobs of
  active or throttled clients that are pending, due for retry, or whose lease
  expired; sets claimed_by/lease_expires_at and increments attempt_count.
* every later write is FENCED: it applies only while this worker still holds an
  unexpired lease (claimed_by = me AND lease_expires_at > now()), the job is still
  queued/processing and the client still active/throttled. Rows that fail the
  fence are silently not written: their result is never published.
* finalisation is one transaction per job: fenced result update -> evidence ->
  job counters (incremental, never re-aggregated) -> usage (D-33: quantity =
  rows that really became done) -> completion with the validation.completed
  outbox row. A crash before commit writes nothing; after commit the rows are
  `done` and can never be finalised (or metered) again.
"""
from __future__ import annotations

import json
import uuid
from collections import Counter, defaultdict

from psycopg import AsyncConnection

from .models import ClaimedAddress, Evidence, FinalResult, RetryResult

_FENCE = """
  a.claimed_by = %(owner)s AND a.processing_state = 'claimed' AND a.lease_expires_at > now()
  AND j.id = a.job_id AND j.status IN ('queued', 'processing')
  AND c.id = j.client_id AND c.status IN ('active', 'throttled')"""


def new_id() -> str:
    return str(uuid.uuid7())


async def claim(conn: AsyncConnection, owner: str, limit: int, lease_seconds: int) -> list[ClaimedAddress]:
    async with conn.transaction():
        cur = await conn.execute(
            """
            WITH picked AS (
                SELECT a.id FROM validation_addresses a
                JOIN validation_jobs j ON j.id = a.job_id
                JOIN clients c ON c.id = j.client_id
                WHERE j.status IN ('queued', 'processing') AND c.status IN ('active', 'throttled')
                  AND (a.processing_state = 'pending'
                       OR (a.processing_state = 'retry_scheduled' AND a.next_attempt_at <= now())
                       OR (a.processing_state = 'claimed' AND a.lease_expires_at <= now()))
                ORDER BY a.id
                LIMIT %(limit)s
                FOR UPDATE OF a SKIP LOCKED)
            UPDATE validation_addresses a
               SET processing_state = 'claimed', claimed_by = %(owner)s,
                   lease_expires_at = now() + make_interval(secs => %(lease)s),
                   attempt_count = a.attempt_count + 1, next_attempt_at = NULL
              FROM picked, validation_jobs j
             WHERE a.id = picked.id AND j.id = a.job_id
            RETURNING a.id::text, a.job_id::text, j.client_id::text, a.original_address, a.attempt_count
            """, {"limit": limit, "owner": owner, "lease": lease_seconds})
        rows = await cur.fetchall()
        jobs = sorted({r[1] for r in rows})
        if jobs:
            await conn.execute(
                "UPDATE validation_jobs SET status = 'processing', started_at = coalesce(started_at, now())"
                " WHERE id = ANY(%s::uuid[]) AND status = 'queued'", (jobs,))
    return [ClaimedAddress(r[0], r[1], r[2], r[3], r[4]) for r in rows]


async def renew(conn: AsyncConnection, owner: str, ids: list[str], lease_seconds: int) -> set[str]:
    """Extends the lease of `ids` still held; returns the ids that are still ours."""
    if not ids:
        return set()
    async with conn.transaction():
        cur = await conn.execute(
            f"""UPDATE validation_addresses a SET lease_expires_at = now() + make_interval(secs => %(lease)s)
                  FROM validation_jobs j, clients c
                 WHERE a.id = ANY(%(ids)s::uuid[]) AND {_FENCE}
                RETURNING a.id::text""", {"lease": lease_seconds, "ids": ids, "owner": owner})
        return {r[0] for r in await cur.fetchall()}


async def write_finals(conn: AsyncConnection, owner: str, results: list[FinalResult]) -> dict[str, object]:
    """One job's final results in one transaction. Returns what was written."""
    assert results and len({r.job_id for r in results}) == 1
    job_id, client_id = results[0].job_id, results[0].client_id
    cols = list(zip(*[(r.address_id, r.normalized_address, r.syntax_status, r.domain_status, r.smtp_status, r.is_role,
                       r.is_disposable, r.is_catch_all_or_accept_all, r.is_domain_typo_suspected, r.suggested_address,
                       r.suggestion_reason_code, r.suggestion_confidence, r.overall_classification, r.confidence,
                       r.diagnostic_code, r.diagnostic_text) for r in results]))
    async with conn.transaction():
        cur = await conn.execute(
            f"""UPDATE validation_addresses a SET
                    normalized_address = v.normalized, syntax_status = v.syntax, domain_status = v.domain,
                    smtp_status = v.smtp, is_role = v.role, is_disposable = v.disposable,
                    is_catch_all_or_accept_all = v.catch_all, is_domain_typo_suspected = v.typo,
                    suggested_address = v.suggested, suggestion_reason_code = v.reason, suggestion_confidence = v.sconf,
                    overall_classification = v.overall, confidence = v.conf, diagnostic_code = v.code,
                    diagnostic_text = v.dtext, checked_at = now(), processing_state = 'done',
                    claimed_by = NULL, lease_expires_at = NULL, next_attempt_at = NULL, last_error = NULL
                  FROM unnest(%(c0)s::uuid[], %(c1)s::text[], %(c2)s::text[], %(c3)s::text[], %(c4)s::text[],
                              %(c5)s::bool[], %(c6)s::bool[], %(c7)s::bool[], %(c8)s::bool[], %(c9)s::text[],
                              %(c10)s::text[], %(c11)s::text[], %(c12)s::text[], %(c13)s::text[], %(c14)s::text[],
                              %(c15)s::text[])
                       AS v(id, normalized, syntax, domain, smtp, role, disposable, catch_all, typo, suggested,
                            reason, sconf, overall, conf, code, dtext),
                       validation_jobs j, clients c
                 WHERE a.id = v.id AND {_FENCE}
                RETURNING a.id::text, a.overall_classification""",
            {**{f"c{i}": list(col) for i, col in enumerate(cols)}, "owner": owner})
        written = dict(await cur.fetchall())
        if not written:
            return {"written": 0, "completed": False, "failed": False}
        await _insert_evidence(conn, [(r.address_id, e) for r in results if r.address_id in written for e in r.evidence])
        delta = Counter(written.values())
        cur = await conn.execute(
            """UPDATE validation_jobs j SET processed_count = j.processed_count + %(n)s,
                      classification_counts_json = (
                        SELECT coalesce(jsonb_object_agg(k, t), '{}'::jsonb) FROM (
                          SELECT k, sum(v)::int AS t FROM (
                            SELECT key AS k, value::int AS v FROM jsonb_each_text(j.classification_counts_json)
                            UNION ALL SELECT key, value::int FROM jsonb_each_text(%(delta)s::jsonb)) x
                          GROUP BY k) y)
                WHERE j.id = %(job)s
            RETURNING processed_count, total_addresses, status""",
            {"n": len(written), "delta": json.dumps(delta), "job": job_id})
        processed, total, status = await cur.fetchone()  # type: ignore[misc]
        await conn.execute(
            "INSERT INTO usage_records (id, client_id, usage_type, quantity, reference_type, reference_id, occurred_at)"
            " VALUES (%s, %s, 'validation_address', %s, 'validation_job', %s, now())",
            (new_id(), client_id, len(written), job_id))
        completed = failed = False
        if processed >= total and status == "processing":
            completed, failed = await _finish_job(conn, job_id, client_id, processed, total)
        return {"written": len(written), "ids": set(written), "completed": completed, "failed": failed}


async def _finish_job(conn: AsyncConnection, job_id: str, client_id: str, processed: int, total: int) -> tuple[bool, bool]:
    cur = await conn.execute("SELECT count(*) FROM validation_addresses WHERE job_id = %s AND processing_state = 'done'", (job_id,))
    done = (await cur.fetchone())[0]  # type: ignore[index]
    if done == total == processed:
        cur = await conn.execute(
            "UPDATE validation_jobs SET status = 'completed', completed_at = now() WHERE id = %s AND status = 'processing' RETURNING id",
            (job_id,))
        if await cur.fetchone():
            await _outbox(conn, client_id, "validation.completed", job_id)
            return True, False
        return False, False
    await fail_job(conn, job_id, client_id, f"integrity: processed_count={processed}, done rows={done}, total_addresses={total}")
    return False, True


async def fail_job(conn: AsyncConnection, job_id: str, client_id: str, reason: str) -> bool:
    """The job as a whole cannot be processed: `failed`, the reason in the audit log,
    and validation.failed in the outbox - all in the caller's transaction."""
    cur = await conn.execute(
        "UPDATE validation_jobs SET status = 'failed', completed_at = now() WHERE id = %s AND status IN ('queued', 'processing') RETURNING id",
        (job_id,))
    if not await cur.fetchone():
        return False
    await conn.execute(
        "INSERT INTO audit_log (id, actor_type, actor_id, action, target_type, target_id, detail_json, occurred_at)"
        " VALUES (%s, 'system', 'validator', 'validation_job.failed', 'validation_job', %s, %s::jsonb, now())",
        (new_id(), job_id, json.dumps({"reason": reason[:500]})))
    await _outbox(conn, client_id, "validation.failed", job_id)
    return True


async def fail_inconsistent_jobs(conn: AsyncConnection) -> list[str]:
    """Jobs that can never complete: no unfinished address left, yet fewer processed
    than total (e.g. missing address rows). Marked failed with validation.failed."""
    failed: list[str] = []
    async with conn.transaction():
        cur = await conn.execute(
            """SELECT j.id::text, j.client_id::text, j.processed_count, j.total_addresses FROM validation_jobs j
                WHERE j.status IN ('queued', 'processing') AND j.processed_count < j.total_addresses
                  AND NOT EXISTS (SELECT 1 FROM validation_addresses a WHERE a.job_id = j.id AND a.processing_state <> 'done')
                FOR UPDATE OF j SKIP LOCKED""")
        for job_id, client_id, processed, total in await cur.fetchall():
            if await fail_job(conn, job_id, client_id, f"integrity: only {processed} of {total} addresses exist or were processed"):
                failed.append(job_id)
    return failed


async def write_retries(conn: AsyncConnection, owner: str, results: list[RetryResult]) -> set[str]:
    if not results:
        return set()
    async with conn.transaction():
        cur = await conn.execute(
            f"""UPDATE validation_addresses a SET processing_state = 'retry_scheduled', next_attempt_at = v.next_at,
                       last_error = v.err, claimed_by = NULL, lease_expires_at = NULL
                  FROM unnest(%(ids)s::uuid[], %(next)s::timestamptz[], %(err)s::text[]) AS v(id, next_at, err),
                       validation_jobs j, clients c
                 WHERE a.id = v.id AND {_FENCE}
                RETURNING a.id::text""",
            {"ids": [r.address_id for r in results], "next": [r.next_attempt_at for r in results],
             "err": [r.last_error[:500] for r in results], "owner": owner})
        written = {r[0] for r in await cur.fetchall()}
        await _insert_evidence(conn, [(r.address_id, e) for r in results if r.address_id in written for e in r.evidence])
    return written


async def disposable_match(conn: AsyncConnection, domain: str) -> bool:
    """The domain or one of its parent domains is on the disposable list (read-only)."""
    labels = domain.lower().split(".")
    candidates = [".".join(labels[i:]) for i in range(len(labels) - 1)]
    if not candidates:
        return False
    cur = await conn.execute("SELECT 1 FROM disposable_domains WHERE domain = ANY(%s::text[]) LIMIT 1", (candidates,))
    return await cur.fetchone() is not None


async def _insert_evidence(conn: AsyncConnection, rows: list[tuple[str, Evidence]]) -> None:
    if not rows:
        return
    await conn.execute(
        """INSERT INTO validation_evidence (id, validation_address_id, evidence_type, provider_host, response_code,
                                           enhanced_status_code, detail_json, occurred_at)
           SELECT * FROM unnest(%s::uuid[], %s::uuid[], %s::text[], %s::text[], %s::smallint[], %s::text[], %s::jsonb[], %s::timestamptz[])""",
        ([new_id() for _ in rows], [a for a, _ in rows], [e.evidence_type for _, e in rows], [e.provider_host for _, e in rows],
         [e.response_code for _, e in rows], [e.enhanced_status_code for _, e in rows],
         [json.dumps(e.detail, default=str) for _, e in rows], [e.occurred_at for _, e in rows]))


async def _outbox(conn: AsyncConnection, client_id: str, event_type: str, job_id: str) -> None:
    """Transactional-outbox row (D-22). Exactly once because callers insert it only
    after winning the job's status transition (UPDATE ... WHERE status IN (...)
    RETURNING, under the row lock). A plain INSERT on purpose: ON CONFLICT would
    need SELECT on webhook_events, which the validator role does not hold
    (schema.md §6); the unique index webhook_events_once_uq stays the final guard."""
    await conn.execute(
        "INSERT INTO webhook_events (id, client_id, event_type, subject_type, subject_id, created_at)"
        " VALUES (%s, %s, %s, 'validation_job', %s, now())",
        (new_id(), client_id, event_type, job_id))


def group_by_job(results: list[FinalResult]) -> dict[str, list[FinalResult]]:
    out: dict[str, list[FinalResult]] = defaultdict(list)
    for r in results:
        out[r.job_id].append(r)
    return dict(sorted(out.items()))
