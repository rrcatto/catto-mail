"""Leases, fencing, suspension, metering and completion against PostgreSQL, using
the real validator role. Every test starts with no claimable work of other tests."""
import asyncio
import json
from datetime import timedelta

import psycopg
import pytest

from smarthost_validator import db
from smarthost_validator.models import Evidence, FinalResult, RetryResult, utcnow
from tests.db import make_job, one, owner, q, validator_conninfo

pytestmark = pytest.mark.db


@pytest.fixture(autouse=True)
def isolate():
    with owner() as c:
        c.execute("UPDATE validation_jobs SET status = 'cancelled' WHERE status IN ('queued', 'processing')")
    yield
    with owner() as c:
        c.execute("UPDATE validation_jobs SET status = 'cancelled' WHERE status IN ('queued', 'processing')")


@pytest.fixture
async def conn():
    async with await psycopg.AsyncConnection.connect(validator_conninfo(), autocommit=True) as c:
        yield c


def final(a, classification="deliverable", evidence=()) -> FinalResult:  # type: ignore[no-untyped-def]
    return FinalResult(a.id, a.job_id, a.client_id, a.original_address, "valid", "mx", "accepted", False, False, False, False,
                       None, None, None, classification, "medium", "smtp.rcpt.2.1.5", "ok", tuple(evidence))


async def test_runs_as_the_validator_role_without_ddl(conn):
    assert (await (await conn.execute("SELECT current_user")).fetchone())[0] == "smarthost_validator"
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        await conn.execute("CREATE TABLE py_probe (id int)")


async def test_claim_marks_lease_attempt_and_job_processing(conn):
    _, job = make_job(["a@x.test", "b@x.test", "c@x.test"])
    claimed = await db.claim(conn, "w1/aa", 2, 60)
    assert len(claimed) == 2 and {c.job_id for c in claimed} == {job} and all(c.attempt_count == 1 for c in claimed)
    rows = q("SELECT processing_state, claimed_by, lease_expires_at > now() FROM validation_addresses WHERE id = ANY(%s::uuid[])",
             [c.id for c in claimed])
    assert rows == [("claimed", "w1/aa", True)] * 2
    assert q("SELECT status, started_at IS NOT NULL FROM validation_jobs WHERE id = %s", job) == [("processing", True)]
    # A second worker gets only the remaining address (SKIP LOCKED / not re-claimable while leased).
    other = await db.claim(conn, "w2/bb", 10, 60)
    assert [c.original_address for c in other] == ["c@x.test"]
    assert await db.claim(conn, "w3/cc", 10, 60) == []


async def test_suspended_and_pending_clients_are_never_claimed(conn):
    make_job(["s@x.test"], status="suspended")
    make_job(["p@x.test"], status="pending_approval")
    assert await db.claim(conn, "w", 10, 60) == []
    _, job = make_job(["t@x.test"], status="throttled")
    assert [c.job_id for c in await db.claim(conn, "w", 10, 60)] == [job]


async def test_suspension_during_a_lease_stops_renewal_and_result(conn):
    client, job = make_job(["a@x.test"])
    [a] = await db.claim(conn, "w1", 10, 60)
    assert await db.renew(conn, "w1", [a.id], 60) == {a.id}
    with owner() as c:
        c.execute("UPDATE clients SET status = 'suspended' WHERE id = %s", (client,))
    assert await db.renew(conn, "w1", [a.id], 60) == set()
    out = await db.write_finals(conn, "w1", [final(a)])
    assert out["written"] == 0
    assert one("SELECT processing_state FROM validation_addresses WHERE id = %s", a.id) == "claimed"
    assert one("SELECT count(*) FROM usage_records WHERE reference_id = %s", job) == 0


async def test_renewal_and_expiry_allow_reclaim_and_fence_the_old_owner(conn):
    _, job = make_job(["a@x.test"])
    [a] = await db.claim(conn, "old", 10, 60)
    with owner() as c:  # the old worker stalls: its lease runs out
        c.execute("UPDATE validation_addresses SET lease_expires_at = now() - interval '1 second' WHERE id = %s", (a.id,))
    assert await db.renew(conn, "old", [a.id], 60) == set()
    [again] = await db.claim(conn, "new", 10, 60)
    assert again.id == a.id and again.attempt_count == 2
    stale = await db.write_finals(conn, "old", [final(a, "undeliverable")])
    assert stale["written"] == 0  # the old owner's result is never published
    fresh = await db.write_finals(conn, "new", [final(again)])
    assert fresh["written"] == 1 and fresh["completed"] is True
    assert one("SELECT overall_classification FROM validation_addresses WHERE id = %s", a.id) == "deliverable"
    assert one("SELECT coalesce(sum(quantity), 0) FROM usage_records WHERE reference_id = %s", job) == 1


async def test_finalisation_is_atomic_with_counters_usage_evidence_and_outbox(conn):
    client, job = make_job(["a@x.test", "b@x.test", "c@x.test"])
    claimed = await db.claim(conn, "w", 10, 60)
    ev = (Evidence("dns_mx", {"query": "MX"}, "mx.x.test"), Evidence("smtp_rcpt_to", {"text": "ok"}, "mx.x.test", 250, "2.1.5"))
    out = await db.write_finals(conn, "w", [final(claimed[0], "deliverable", ev), final(claimed[1], "undeliverable", ev)])
    assert out["written"] == 2 and not out["completed"]
    assert q("SELECT processed_count, classification_counts_json, status FROM validation_jobs WHERE id = %s", job) == \
        [(2, {"deliverable": 1, "undeliverable": 1}, "processing")]
    assert one("SELECT count(*) FROM validation_evidence e JOIN validation_addresses a ON a.id = e.validation_address_id WHERE a.job_id = %s", job) == 4
    assert q("SELECT quantity, usage_type, reference_type, client_id::text FROM usage_records WHERE reference_id = %s", job) == \
        [(2, "validation_address", "validation_job", client)]
    out = await db.write_finals(conn, "w", [final(claimed[2], "risky")])
    assert out["completed"] is True
    assert q("SELECT status, completed_at IS NOT NULL, processed_count, classification_counts_json FROM validation_jobs WHERE id = %s", job) == \
        [("completed", True, 3, {"deliverable": 1, "undeliverable": 1, "risky": 1})]
    assert q("SELECT event_type, subject_type, client_id::text FROM webhook_events WHERE subject_id = %s", job) == \
        [("validation.completed", "validation_job", client)]
    assert one("SELECT sum(quantity) FROM usage_records WHERE reference_id = %s", job) == 3


async def test_an_address_can_be_finalised_and_metered_only_once(conn):
    _, job = make_job(["a@x.test", "b@x.test"])
    a, b = await db.claim(conn, "w", 10, 60)
    assert (await db.write_finals(conn, "w", [final(a)]))["written"] == 1
    again = await db.write_finals(conn, "w", [final(a), final(b)])  # retry of a written batch after a timeout
    assert again["written"] == 1
    assert one("SELECT sum(quantity) FROM usage_records WHERE reference_id = %s", job) == 2
    assert one("SELECT processed_count FROM validation_jobs WHERE id = %s", job) == 2
    assert one("SELECT count(*) FROM webhook_events WHERE subject_id = %s", job) == 1


async def test_crash_before_commit_writes_and_meters_nothing():
    _, job = make_job(["a@x.test"])
    async with await psycopg.AsyncConnection.connect(validator_conninfo(), autocommit=True) as c1:
        [a] = await db.claim(c1, "w", 10, 60)
    crashing = await psycopg.AsyncConnection.connect(validator_conninfo())
    await crashing.execute("BEGIN")
    # Same statements as write_finals, interrupted before COMMIT (process crash).
    await crashing.execute("UPDATE validation_addresses SET processing_state = 'done', overall_classification = 'deliverable',"
                           " checked_at = now() WHERE id = %s", (a.id,))
    await crashing.execute("INSERT INTO usage_records (id, client_id, usage_type, quantity, reference_type, reference_id, occurred_at)"
                           " SELECT %s, client_id, 'validation_address', 1, 'validation_job', id, now() FROM validation_jobs WHERE id = %s",
                           (db.new_id(), job))
    await crashing.close()  # connection lost: the transaction is rolled back
    assert one("SELECT processing_state FROM validation_addresses WHERE id = %s", a.id) == "claimed"
    assert one("SELECT count(*) FROM usage_records WHERE reference_id = %s", job) == 0
    with owner() as c:
        c.execute("UPDATE validation_addresses SET lease_expires_at = now() - interval '1 second' WHERE id = %s", (a.id,))
    async with await psycopg.AsyncConnection.connect(validator_conninfo(), autocommit=True) as c2:
        [again] = await db.claim(c2, "w2", 10, 60)
        assert (await db.write_finals(c2, "w2", [final(again)]))["completed"] is True
    assert one("SELECT sum(quantity) FROM usage_records WHERE reference_id = %s", job) == 1


async def test_concurrent_finalisation_of_one_job_by_two_workers_completes_once():
    _, job = make_job([f"u{i}@x.test" for i in range(40)])
    async with await psycopg.AsyncConnection.connect(validator_conninfo(), autocommit=True) as c1, \
            await psycopg.AsyncConnection.connect(validator_conninfo(), autocommit=True) as c2:
        a = await db.claim(c1, "w1", 20, 60)
        b = await db.claim(c2, "w2", 20, 60)
        outs = await asyncio.gather(db.write_finals(c1, "w1", [final(x) for x in a]), db.write_finals(c2, "w2", [final(x) for x in b]))
    assert sum(o["written"] for o in outs) == 40 and sum(o["completed"] for o in outs) == 1
    assert one("SELECT count(*) FROM webhook_events WHERE subject_id = %s AND event_type = 'validation.completed'", job) == 1
    assert one("SELECT sum(quantity) FROM usage_records WHERE reference_id = %s", job) == 40


async def test_retry_is_fenced_and_delays_reclaim(conn):
    _, job = make_job(["a@x.test"])
    [a] = await db.claim(conn, "w", 10, 60)
    later = utcnow() + timedelta(seconds=120)
    assert await db.write_retries(conn, "other", [RetryResult(a.id, job, later, "smtp.rcpt.4.2.1")]) == set()
    assert await db.write_retries(conn, "w", [RetryResult(a.id, job, later, "smtp.rcpt.4.2.1",
                                                          (Evidence("smtp_rcpt_to", {}, "mx", 450, "4.2.1"),))]) == {a.id}
    assert q("SELECT processing_state, claimed_by, last_error, overall_classification FROM validation_addresses WHERE id = %s", a.id) == \
        [("retry_scheduled", None, "smtp.rcpt.4.2.1", None)]
    assert await db.claim(conn, "w", 10, 60) == []  # not before next_attempt_at
    with owner() as c:
        c.execute("UPDATE validation_addresses SET next_attempt_at = now() WHERE id = %s", (a.id,))
    [again] = await db.claim(conn, "w", 10, 60)
    assert again.attempt_count == 2
    assert one("SELECT count(*) FROM usage_records WHERE reference_id = %s", job) == 0  # retries are never metered


async def test_cancelled_jobs_are_not_claimed_or_written(conn):
    _, job = make_job(["a@x.test"])
    [a] = await db.claim(conn, "w", 10, 60)
    with owner() as c:
        c.execute("UPDATE validation_jobs SET status = 'cancelled' WHERE id = %s", (job,))
    assert (await db.write_finals(conn, "w", [final(a)]))["written"] == 0


async def test_inconsistent_job_fails_with_outbox_event_and_audit(conn):
    client, job = make_job(["a@x.test"])
    with owner() as c:
        c.execute("UPDATE validation_jobs SET total_addresses = 2 WHERE id = %s", (job,))  # one address row is missing
    [a] = await db.claim(conn, "w", 10, 60)
    out = await db.write_finals(conn, "w", [final(a)])
    assert out["written"] == 1 and not out["completed"]
    assert await db.fail_inconsistent_jobs(conn) == [job]
    assert one("SELECT status FROM validation_jobs WHERE id = %s", job) == "failed"
    assert one("SELECT event_type FROM webhook_events WHERE subject_id = %s", job) == "validation.failed"
    detail = one("SELECT detail_json FROM audit_log WHERE target_id = %s AND action = 'validation_job.failed'", job)
    assert "integrity" in json.dumps(detail)


async def test_disposable_lookup_includes_parent_domains(conn):
    with owner() as c:
        c.execute("INSERT INTO disposable_domains (domain, source) VALUES ('throwaway.test', 'test') ON CONFLICT DO NOTHING")
    assert await db.disposable_match(conn, "throwaway.test")
    assert await db.disposable_match(conn, "sub.throwaway.test")
    assert not await db.disposable_match(conn, "example.test")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        await conn.execute("INSERT INTO disposable_domains (domain, source) VALUES ('x.test', 'validator')")
