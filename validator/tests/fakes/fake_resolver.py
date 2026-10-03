"""In-memory DNS for unit tests: a zone dict, plus per-name failure modes."""
from __future__ import annotations

import asyncio

from smarthost_validator.dns_check import BadResponse, NoAnswer, NxDomain, TempFail


class FakeResolver:
    def __init__(self, zone: dict[str, dict[str, list]] | None = None, delay: float = 0.0) -> None:
        self.zone = zone or {}  # name -> {"MX": [(pref, host)], "A": [...], "AAAA": [...]}
        self.nxdomain: set[str] = set()
        self.tempfail: set[str] = set()
        self.malformed: set[str] = set()
        self.queries: list[tuple[str, str]] = []
        self.delay = delay

    async def _lookup(self, name: str, rdtype: str) -> list:
        self.queries.append((name, rdtype))
        if self.delay:
            await asyncio.sleep(self.delay)
        if name in self.tempfail:
            raise TempFail("SERVFAIL")
        if name in self.malformed:
            raise BadResponse("FormError")
        if name in self.nxdomain or name not in self.zone:
            raise NxDomain(name)
        records = self.zone[name].get(rdtype, [])
        if not records:
            raise NoAnswer(name)
        return list(records)

    async def mx(self, name: str) -> list[tuple[int, str]]:
        return await self._lookup(name, "MX")

    async def addresses(self, name: str, rdtype: str) -> list[str]:
        return await self._lookup(name, rdtype)
