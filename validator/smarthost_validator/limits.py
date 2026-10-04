"""Concurrency limits and anti-abuse state (spec validation.pipeline smtp_probe).

* Global (VALIDATOR_GLOBAL_CONCURRENCY): concurrent network checks of this worker.
* Per recipient domain (VALIDATOR_PER_DOMAIN_CONCURRENCY) and per MX host
  (VALIDATOR_PER_MX_CONCURRENCY): concurrent SMTP probes.

An SMTP probe acquires domain -> MX -> global, always in that order (no
deadlock), and takes a global slot only once it may actually talk to the MX. A
skewed job (thousands of addresses at one provider) therefore waits on that
provider's domain/MX slots without holding global slots that other domains could
use. The limiters record the highest concurrency they observed, which the tests
and the worker statistics use to prove the limits hold.

ProviderBackoff: when an MX blocks or throttles probes (5.7.x, 421, 4.7.x,
policy wording), that MX "cools down" with exponential back-off and is not
probed until the cool-down ends; affected addresses are rescheduled instead of
escalating the probe rate.

AcceptAllPolicy: at most one accept-all probe per domain per window, plus a
worker-wide minimum interval between such probes; the per-domain verdict is
cached for the window.
"""
from __future__ import annotations

import asyncio
import time
from collections import defaultdict
from collections.abc import AsyncIterator, Callable
from contextlib import asynccontextmanager


class CountingSemaphore:
    def __init__(self, limit: int) -> None:
        self.limit = limit
        self._sem = asyncio.Semaphore(limit)
        self.current = 0
        self.max_seen = 0

    @asynccontextmanager
    async def slot(self) -> AsyncIterator[None]:
        async with self._sem:
            self.current += 1
            self.max_seen = max(self.max_seen, self.current)
            try:
                yield
            finally:
                self.current -= 1


class KeyedLimiter:
    """One CountingSemaphore per key, created on demand and dropped when idle."""

    def __init__(self, limit: int) -> None:
        self.limit = limit
        self._sems: dict[str, CountingSemaphore] = {}
        self._users: dict[str, int] = defaultdict(int)
        self.max_seen = 0
        self.max_seen_by_key: dict[str, int] = {}

    @asynccontextmanager
    async def slot(self, key: str) -> AsyncIterator[None]:
        sem = self._sems.setdefault(key, CountingSemaphore(self.limit))
        self._users[key] += 1
        try:
            async with sem.slot():
                self.max_seen = max(self.max_seen, sem.current)
                self.max_seen_by_key[key] = max(self.max_seen_by_key.get(key, 0), sem.current)
                yield
        finally:
            self._users[key] -= 1
            if self._users[key] == 0:
                del self._users[key]
                self._sems.pop(key, None)

    @property
    def keys_alive(self) -> int:
        return len(self._sems)


class Limits:
    def __init__(self, global_limit: int, per_domain: int, per_mx: int) -> None:
        self.global_ = CountingSemaphore(global_limit)
        self.domain = KeyedLimiter(per_domain)
        self.mx = KeyedLimiter(per_mx)

    @asynccontextmanager
    async def smtp(self, domain: str, mx_host: str) -> AsyncIterator[None]:
        async with self.domain.slot(domain), self.mx.slot(mx_host), self.global_.slot():
            yield

    @asynccontextmanager
    async def network(self) -> AsyncIterator[None]:
        async with self.global_.slot():
            yield

    def stats(self) -> dict[str, int]:
        return {"max_global": self.global_.max_seen, "max_per_domain": self.domain.max_seen, "max_per_mx": self.mx.max_seen,
                "limit_global": self.global_.limit, "limit_per_domain": self.domain.limit, "limit_per_mx": self.mx.limit}


class ProviderBackoff:
    def __init__(self, base_seconds: float, max_seconds: float, clock: Callable[[], float] = time.monotonic) -> None:
        self.base, self.max, self.clock = base_seconds, max_seconds, clock
        self._until: dict[str, float] = {}
        self._strikes: dict[str, int] = defaultdict(int)

    def cooling_for(self, host: str) -> float:
        """Seconds until `host` may be probed again (0 when it may be probed now)."""
        return max(0.0, self._until.get(host, 0.0) - self.clock())

    def record_throttling(self, host: str) -> float:
        self._strikes[host] += 1
        delay = min(self.max, self.base * (2.0 ** (self._strikes[host] - 1)))
        self._until[host] = max(self._until.get(host, 0.0), self.clock() + delay)
        return delay

    def record_success(self, host: str) -> None:
        self._strikes.pop(host, None)


class AcceptAllPolicy:
    """At most one executed accept-all probe per domain per window (24 h by
    default) and a worker-wide minimum interval between probes. The verdict is
    cached per domain. While one task probes a domain, other tasks for that domain
    wait for its verdict instead of probing too; if the probe was not executed
    (the target recipient itself was rejected, or the probe failed before RCPT),
    the next task may try. Every `begin()` returning True must be paired with
    `finish()`.
    """

    def __init__(self, window_seconds: float = 86400.0, min_interval_seconds: float = 1.0,
                 clock: Callable[[], float] = time.monotonic) -> None:
        self.window, self.min_interval, self.clock = window_seconds, min_interval_seconds, clock
        self._verdict: dict[str, tuple[float, bool]] = {}
        self._executed: dict[str, float] = {}
        self._pending: dict[str, asyncio.Event] = {}
        self._last_probe = -1e18
        self._rate = asyncio.Lock()
        self.probes = 0

    def known(self, domain: str) -> tuple[bool, bool | None]:
        entry = self._verdict.get(domain)
        if entry and entry[0] > self.clock():
            return True, entry[1]
        return False, None

    async def begin(self, domain: str) -> tuple[bool, bool | None]:
        """(this task should probe, cached verdict or None)."""
        while True:
            known, verdict = self.known(domain)
            if known:
                return False, verdict
            event = self._pending.get(domain)
            if event is not None:
                await event.wait()
                continue
            if self._executed.get(domain, -1e18) + self.window > self.clock():
                return False, None  # probed recently without a usable verdict: do not probe again yet
            self._pending[domain] = asyncio.Event()
            async with self._rate:
                wait = self._last_probe + self.min_interval - self.clock()
                if wait > 0:
                    await asyncio.sleep(wait)
                self._last_probe = self.clock()
            return True, None

    def finish(self, domain: str, executed: bool, verdict: bool | None) -> None:
        if executed:
            self.probes += 1
            self._executed[domain] = self.clock()
            if verdict is not None:
                self._verdict[domain] = (self.clock() + self.window, verdict)
        event = self._pending.pop(domain, None)
        if event is not None:
            event.set()
