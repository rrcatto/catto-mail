"""The long-running validator worker.

Loop: keep at most about two chunks (2 x VALIDATOR_CHUNK_SIZE) of addresses in
flight; claim a new chunk whenever fewer than one chunk remains in flight; wait
for NOTIFY smarthost_validation_work or VALIDATOR_POLL_INTERVAL_SECONDS when
idle. Every claimed address becomes one asyncio task (memory is bounded by the
in-flight cap; network concurrency by Limits).

* Lease renewal runs every lease/3 for all held addresses; an address whose lease
  could not be renewed (expired, reclaimed, job cancelled, client suspended) is
  dropped and its task cancelled - its result is never written.
* Results are written by a batching writer (per job, one transaction), always
  through the fenced statements in db.py.
* `claimed_by` is "<VALIDATOR_WORKER_ID>/<random instance id>", so a restarted
  process with the same worker id never mistakes its predecessor's leases for its own.
"""
from __future__ import annotations

import asyncio
import contextlib
import os
import resource
import secrets
import time
from dataclasses import dataclass, field

from psycopg import AsyncConnection
from psycopg_pool import AsyncConnectionPool

from . import db
from .config import Config
from .dns_check import DnsChecker, DnspythonResolver, Resolver
from .limits import AcceptAllPolicy, Limits, ProviderBackoff
from .logs import log
from .models import ClaimedAddress, FinalResult, RetryResult
from .pipeline import Evaluator
from .smtp_probe import SmtpProber

NOTIFY_CHANNEL = "smarthost_validation_work"
HEARTBEAT_FILE = "/tmp/smarthost-validator-heartbeat"


@dataclass
class Stats:
    claimed: int = 0
    finalised: int = 0
    retried: int = 0
    lost: int = 0
    jobs_completed: int = 0
    jobs_failed: int = 0
    max_in_flight: int = 0
    claims: int = 0
    max_claim_size: int = 0
    started: float = field(default_factory=time.monotonic)

    def as_dict(self, limits: Limits, prober: SmtpProber | None, dns: DnsChecker) -> dict[str, object]:
        return {**self.__dict__, "elapsed_seconds": round(time.monotonic() - self.started, 2), **limits.stats(),
                "smtp_commands": sorted(set(prober.commands_sent)) if prober else [],
                "smtp_data_commands": (prober.commands_sent.count("DATA") if prober else 0),
                "dns_domain_lookups": dns.lookups,
                "peak_rss_kib": resource.getrusage(resource.RUSAGE_SELF).ru_maxrss}


class Worker:
    def __init__(self, cfg: Config, pool: AsyncConnectionPool, resolver: Resolver | None = None,
                 prober: SmtpProber | None = None) -> None:
        self.cfg, self.pool = cfg, pool
        self.owner = f"{cfg.worker_id}/{secrets.token_hex(4)}"
        self.limits = Limits(cfg.global_concurrency, cfg.per_domain_concurrency, cfg.per_mx_concurrency)
        self.dns = DnsChecker(resolver or DnspythonResolver(cfg.dns_resolvers, cfg.dns_timeout_seconds), slot=self.limits.network)
        if prober is None and cfg.smtp_probe_enabled:
            prober = SmtpProber(cfg.smtp_helo_hostname, cfg.smtp_mail_from, cfg.smtp_connect_timeout_seconds,
                                cfg.smtp_command_timeout_seconds, cfg.smtp_route_override)
        self.prober = prober if cfg.smtp_probe_enabled else None
        self.backoff = ProviderBackoff(cfg.retry_base_seconds, cfg.retry_max_seconds)
        self.accept_all = AcceptAllPolicy()
        self._disposable_cache: dict[str, tuple[float, bool]] = {}
        self.evaluator = Evaluator(cfg, self.dns, self.prober, self.limits, self.backoff, self.accept_all, self._is_disposable)
        self.stats = Stats()
        self._tasks: dict[str, asyncio.Task[None]] = {}
        self._results: asyncio.Queue[FinalResult | RetryResult] = asyncio.Queue()
        self._wake = asyncio.Event()
        self._stop = asyncio.Event()

    # ------------------------------------------------------------------ public
    def stop(self) -> None:
        self._stop.set()
        self._wake.set()

    async def run(self, exit_when_idle: bool = False) -> Stats:
        log("info", "validator started", worker_id=self.owner, chunk_size=self.cfg.chunk_size,
            probe_enabled=self.cfg.smtp_probe_enabled, **self.limits.stats())
        background = [asyncio.create_task(self._renew_loop(), name="renew"),
                      asyncio.create_task(self._writer_loop(), name="writer"),
                      asyncio.create_task(self._listen_loop(), name="listen")]
        try:
            await self._claim_loop(exit_when_idle)
        finally:
            for t in self._tasks.values():
                t.cancel()
            await asyncio.gather(*self._tasks.values(), return_exceptions=True)
            await self._results.join()
            for t in background:
                t.cancel()
            await asyncio.gather(*background, return_exceptions=True)
            log("info", "validator stopped", worker_id=self.owner, **self.stats.as_dict(self.limits, self.prober, self.dns))
        return self.stats

    # ------------------------------------------------------------------ loops
    async def _claim_loop(self, exit_when_idle: bool) -> None:
        chunk = self.cfg.chunk_size
        idle_since: float | None = None
        while not self._stop.is_set():
            _heartbeat()
            if len(self._tasks) > chunk:
                await self._wait(min(self.cfg.poll_interval_seconds, 0.5))
                continue
            claimed: list[ClaimedAddress] = []
            try:
                async with self.pool.connection() as conn:
                    claimed = await db.claim(conn, self.owner, chunk, self.cfg.lease_seconds)
                    if not claimed and not self._tasks:
                        for job in await db.fail_inconsistent_jobs(conn):
                            self.stats.jobs_failed += 1
                            log("warning", "validation job failed", job_id=job)
            except Exception as exc:  # database unavailable: keep running, retry later
                log("error", "claim failed", error=repr(exc)[:200])
                await self._wait(self.cfg.poll_interval_seconds)
                continue
            if claimed:
                idle_since = None
                self.stats.claims += 1
                self.stats.claimed += len(claimed)
                self.stats.max_claim_size = max(self.stats.max_claim_size, len(claimed))
                for a in claimed:
                    self._tasks[a.id] = asyncio.create_task(self._process(a), name=a.id)
                self.stats.max_in_flight = max(self.stats.max_in_flight, len(self._tasks))
                continue
            if not self._tasks and self._results.empty():
                if exit_when_idle:
                    idle_since = idle_since or time.monotonic()
                    if time.monotonic() - idle_since >= 1.0 and await self._nothing_pending():
                        return
                await self._wait(self.cfg.poll_interval_seconds if not exit_when_idle else 0.5)
            else:
                await self._wait(0.2)

    async def _process(self, a: ClaimedAddress) -> None:
        try:
            outcome = await self.evaluator.evaluate(a)
        except asyncio.CancelledError:
            return
        except Exception as exc:
            log("error", "address evaluation failed", job_id=a.job_id, address_id=a.id, error=repr(exc)[:300])
            return  # lease expires; another attempt follows
        finally:
            self._tasks.pop(a.id, None)
        await self._results.put(outcome)

    async def _writer_loop(self) -> None:
        while True:
            first = await self._results.get()
            batch = [first]
            deadline = time.monotonic() + 0.05
            while len(batch) < 500:
                try:
                    batch.append(self._results.get_nowait())
                except asyncio.QueueEmpty:
                    if time.monotonic() >= deadline:
                        break
                    await asyncio.sleep(0.01)
            try:
                await self._write(batch)
            except Exception as exc:  # nothing committed for the failing transaction; leases expire and are reclaimed
                log("error", "result write failed", error=repr(exc)[:300])
            finally:
                for _ in batch:
                    self._results.task_done()
                self._wake.set()

    async def _write(self, batch: list[FinalResult | RetryResult]) -> None:
        finals = [r for r in batch if isinstance(r, FinalResult)]
        retries = [r for r in batch if isinstance(r, RetryResult)]
        async with self.pool.connection() as conn:
            for job_id, results in db.group_by_job(finals).items():
                out = await db.write_finals(conn, self.owner, results)
                self.stats.finalised += int(out["written"])  # type: ignore[arg-type]
                self.stats.lost += len(results) - int(out["written"])  # type: ignore[arg-type]
                if out["completed"]:
                    self.stats.jobs_completed += 1
                    log("info", "validation job completed", job_id=job_id)
                if out["failed"]:
                    self.stats.jobs_failed += 1
                    log("warning", "validation job failed", job_id=job_id)
            if retries:
                written = await db.write_retries(conn, self.owner, retries)
                self.stats.retried += len(written)
                self.stats.lost += len(retries) - len(written)

    async def _renew_loop(self) -> None:
        interval = max(1.0, self.cfg.lease_seconds / 3)
        while True:
            await asyncio.sleep(interval)
            held = list(self._tasks)
            if not held:
                continue
            try:
                async with self.pool.connection() as conn:
                    kept = await db.renew(conn, self.owner, held, self.cfg.lease_seconds)
            except Exception as exc:
                log("error", "lease renewal failed", error=repr(exc)[:200])
                continue
            for lost in set(held) - kept:
                task = self._tasks.pop(lost, None)
                if task is not None:
                    task.cancel()
                    self.stats.lost += 1
                    log("warning", "lease lost; result will not be published", address_id=lost)

    async def _listen_loop(self) -> None:
        while True:
            try:
                async with await AsyncConnection.connect(self.cfg.conninfo, autocommit=True) as conn:
                    await conn.execute(f"LISTEN {NOTIFY_CHANNEL}")
                    async for _ in conn.notifies():
                        self._wake.set()
            except asyncio.CancelledError:
                raise
            except Exception as exc:
                log("warning", "notification listener reconnecting", error=repr(exc)[:200])
                await asyncio.sleep(self.cfg.poll_interval_seconds)

    # ------------------------------------------------------------------ helpers
    async def _wait(self, seconds: float) -> None:
        self._wake.clear()
        with contextlib.suppress(asyncio.TimeoutError):
            await asyncio.wait_for(self._wake.wait(), seconds)

    async def _nothing_pending(self) -> bool:
        """For --exit-when-idle: no claimable or retry-scheduled work of claimable clients remains."""
        async with self.pool.connection() as conn:
            cur = await conn.execute(
                """SELECT count(*) FROM validation_addresses a JOIN validation_jobs j ON j.id = a.job_id
                     JOIN clients c ON c.id = j.client_id
                    WHERE a.processing_state <> 'done' AND j.status IN ('queued', 'processing')
                      AND c.status IN ('active', 'throttled')""")
            return (await cur.fetchone())[0] == 0  # type: ignore[index]

    async def _is_disposable(self, domain: str) -> bool:
        now = time.monotonic()
        hit = self._disposable_cache.get(domain)
        if hit and hit[0] > now:
            return hit[1]
        async with self.pool.connection() as conn:
            value = await db.disposable_match(conn, domain)
        self._disposable_cache[domain] = (now + 600, value)
        return value


def _heartbeat() -> None:
    try:
        with open(HEARTBEAT_FILE, "w") as fh:
            fh.write(str(int(time.time())))
    except OSError:
        pass


def heartbeat_age() -> float:
    try:
        return time.time() - os.path.getmtime(HEARTBEAT_FILE)
    except OSError:
        return float("inf")
