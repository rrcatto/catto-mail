"""The whole worker (claim -> evaluate -> fenced write) against PostgreSQL with
fake DNS and the fake SMTP server."""
import asyncio
import os

import pytest
from psycopg_pool import AsyncConnectionPool

from smarthost_validator.smtp_probe import SmtpProber
from smarthost_validator.worker import Worker
from tests.db import make_job, one, owner, q
from tests.fakes import fake_smtp
from tests.fakes.fake_resolver import FakeResolver
from tests.helpers import ZONE, config

pytestmark = pytest.mark.db


@pytest.fixture(autouse=True)
def isolate():
    with owner() as c:
        c.execute("UPDATE validation_jobs SET status = 'cancelled' WHERE status IN ('queued', 'processing')")
    yield
    with owner() as c:
        c.execute("UPDATE validation_jobs SET status = 'cancelled' WHERE status IN ('queued', 'processing')")


def cfg(port: int, **over: str):
    base = {k: os.environ[k] for k in ("SMARTHOST_DB_HOST", "SMARTHOST_DB_PORT", "SMARTHOST_DB_NAME", "VALIDATOR_DB_USER", "VALIDATOR_DB_PASSWORD")}
    settings = {**base, "VALIDATOR_SMTP_ROUTE_OVERRIDE": f"127.0.0.1:{port}", "VALIDATOR_CHUNK_SIZE": "20", "VALIDATOR_LEASE_SECONDS": "30",
                "VALIDATOR_POLL_INTERVAL_SECONDS": "1", "VALIDATOR_RETRY_BASE_SECONDS": "1", "VALIDATOR_RETRY_MAX_SECONDS": "2",
                "VALIDATOR_GLOBAL_CONCURRENCY": "6", "VALIDATOR_PER_DOMAIN_CONCURRENCY": "2", "VALIDATOR_PER_MX_CONCURRENCY": "2"}
    return config(**{**settings, **over})


async def run_worker(c, until_idle=True, stop_after: float | None = None):  # type: ignore[no-untyped-def]
    async with AsyncConnectionPool(c.conninfo, min_size=1, max_size=4, open=False) as pool:
        resolver = FakeResolver({k: dict(v) for k, v in ZONE.items()})
        w = Worker(c, pool, resolver=resolver, prober=SmtpProber("v.test", "v@b.test", 1, 1, c.smtp_route_override))
        w.accept_all.min_interval = 0
        if stop_after is None:
            await asyncio.wait_for(w.run(exit_when_idle=until_idle), 120)
        else:
            task = asyncio.create_task(w.run())
            await asyncio.sleep(stop_after)
            task.cancel()  # abrupt stop: in-flight results are not written
            await asyncio.gather(task, return_exceptions=True)
        return w


MIXED = ["someone@example.test", "reject-550@example.test", "x@accept-all.test", "x@nullmx.test", "x@absent.test",
         "not-an-address", "x@tempmail.test", "info@example.test", "x@gmial.com", "  Mixed.Case@EXAMPLE.test ",
         "tempfail-450@example.test"]
EXPECTED = {"someone@example.test": "deliverable", "reject-550@example.test": "undeliverable", "x@accept-all.test": "risky",
            "x@nullmx.test": "undeliverable", "x@absent.test": "undeliverable", "not-an-address": "undeliverable",
            "x@tempmail.test": "risky", "info@example.test": "deliverable", "x@gmial.com": "risky",
            "  Mixed.Case@EXAMPLE.test ": "deliverable", "tempfail-450@example.test": "temporarily_unverifiable"}


async def test_mixed_job_end_to_end(smtp_server):
    with owner() as c:
        c.execute("INSERT INTO disposable_domains (domain, source) VALUES ('tempmail.test', 'test') ON CONFLICT DO NOTHING")
    client, job = make_job(MIXED)
    suspended_client, suspended_job = make_job(["s1@example.test", "s2@example.test"], status="suspended")
    w = await run_worker(cfg(smtp_server, VALIDATOR_MAX_ATTEMPTS="2"))
    rows = dict(q("SELECT original_address, overall_classification FROM validation_addresses WHERE job_id = %s", job))
    assert rows == EXPECTED
    assert q("SELECT original_address FROM validation_addresses WHERE job_id = %s ORDER BY id", job) == [(a,) for a in MIXED]
    assert one("SELECT normalized_address FROM validation_addresses WHERE job_id = %s AND original_address LIKE %s", job, "%Mixed.Case%") == \
        "Mixed.Case@example.test"
    status, processed, counts = q("SELECT status, processed_count, classification_counts_json FROM validation_jobs WHERE id = %s", job)[0]
    assert (status, processed, sum(counts.values())) == ("completed", len(MIXED), len(MIXED))
    assert one("SELECT sum(quantity) FROM usage_records WHERE reference_id = %s", job) == len(MIXED)
    assert one("SELECT count(*) FROM webhook_events WHERE subject_id = %s AND event_type = 'validation.completed'", job) == 1
    assert one("SELECT attempt_count FROM validation_addresses WHERE job_id = %s AND original_address LIKE 'tempfail%%'", job) == 2
    # The suspended client's work was never touched.
    assert q("SELECT DISTINCT processing_state, attempt_count FROM validation_addresses WHERE job_id = %s", suspended_job) == [("pending", 0)]
    assert one("SELECT status FROM validation_jobs WHERE id = %s", suspended_job) == "queued"
    assert "DATA" not in fake_smtp.COMMANDS and "DATA" not in w.prober.commands_sent
    del client, suspended_client


async def test_restart_and_reclaim_never_double_count(smtp_server):
    addresses = [f"user{i}@example.test" for i in range(300)]
    _, job = make_job(addresses)
    c = cfg(smtp_server, VALIDATOR_LEASE_SECONDS="5")
    # A worker that dies holding leases (it claimed, then never wrote anything).
    import psycopg
    from smarthost_validator import db as dbmod
    from tests.db import validator_conninfo
    async with await psycopg.AsyncConnection.connect(validator_conninfo(), autocommit=True) as dead:
        orphaned = await dbmod.claim(dead, "dead-worker/0000", 40, 5)
    assert len(orphaned) == 40
    await run_worker(c, stop_after=0.4)  # a second process is stopped abruptly mid-job
    partial = one("SELECT processed_count FROM validation_jobs WHERE id = %s", job)
    held = one("SELECT count(*) FROM validation_addresses WHERE job_id = %s AND processing_state = 'claimed'", job)
    assert 0 < partial < 300 and held >= 40  # some results committed, leases left behind
    await asyncio.sleep(5.5)  # the dead process's leases expire
    await run_worker(c)
    assert q("SELECT status, processed_count FROM validation_jobs WHERE id = %s", job) == [("completed", 300)]
    assert one("SELECT count(*) FROM validation_addresses WHERE job_id = %s AND processing_state = 'done'", job) == 300
    assert one("SELECT sum(quantity) FROM usage_records WHERE reference_id = %s", job) == 300
    assert one("SELECT count(*) FROM webhook_events WHERE subject_id = %s", job) == 1
    assert one("SELECT max(attempt_count) FROM validation_addresses WHERE job_id = %s", job) == 2  # reclaimed ones only
    assert one("SELECT count(*) FROM validation_addresses WHERE job_id = %s AND attempt_count = 2", job) >= 40


async def test_chunking_and_concurrency_limits(smtp_server):
    domains = ["example.test", "accept-all.test", "fallback.test"]
    _, job = make_job([f"u{i}@{domains[i % 3]}" for i in range(150)])
    w = await run_worker(cfg(smtp_server))
    s = w.stats.as_dict(w.limits, w.prober, w.dns)
    assert s["max_claim_size"] <= 20 and s["max_in_flight"] <= 40
    assert s["max_global"] <= 6 and s["max_per_domain"] <= 2 and s["max_per_mx"] <= 2
    assert s["dns_domain_lookups"] == 3 and s["smtp_data_commands"] == 0
    assert one("SELECT status FROM validation_jobs WHERE id = %s", job) == "completed"
