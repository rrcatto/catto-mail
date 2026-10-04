"""DNS/domain analysis (pipeline stage 4).

Distinguishes (domain_status vocabulary): mx, null_mx (RFC 7505), address_fallback
(no MX but A/AAAA: RFC 5321 implicit MX), no_mail_host, nxdomain,
temporary_failure (SERVFAIL/timeout/refused - never a permanent verdict on its
own) and invalid_response (malformed or unusable answers, e.g. MX hosts that do
not resolve).

Lookups go through the `Resolver` interface. Production uses dnspython against the
configured resolvers (VALIDATOR_DNS_RESOLVERS, or the system resolver) with
VALIDATOR_DNS_TIMEOUT_SECONDS; tests use deterministic fakes. Results are cached
per worker and concurrent lookups of one domain share a single query
(single flight), so a 10,000-address job at one provider costs one DNS check.
"""
from __future__ import annotations

import asyncio
import ipaddress
import time
from dataclasses import dataclass, field
from typing import Protocol

from .models import Evidence


class DnsError(Exception):
    pass


class NxDomain(DnsError):
    """Authoritative NXDOMAIN."""


class NoAnswer(DnsError):
    """NOERROR without records of the requested type."""


class TempFail(DnsError):
    """SERVFAIL, REFUSED, timeout or no reachable resolver."""


class BadResponse(DnsError):
    """Malformed or unusable answer."""


class Resolver(Protocol):
    async def mx(self, name: str) -> list[tuple[int, str]]: ...  # (preference, exchange without trailing dot; "" = root)
    async def addresses(self, name: str, rdtype: str) -> list[str]: ...  # rdtype "A" or "AAAA"


class DnspythonResolver:
    """Resolver backed by dnspython's async resolver."""

    def __init__(self, nameservers: tuple[str, ...], timeout: float, port: int = 53) -> None:
        import dns.asyncresolver

        self._r = dns.asyncresolver.Resolver(configure=not nameservers)
        if nameservers:
            self._r.nameservers = list(nameservers)
        self._r.port = port
        self._r.timeout = timeout
        self._r.lifetime = timeout
        self._r.cache = None

    async def _query(self, name: str, rdtype: str):
        import dns.exception
        import dns.resolver

        try:
            return await self._r.resolve(name + ".", rdtype, search=False, raise_on_no_answer=True)
        except dns.resolver.NXDOMAIN as exc:
            raise NxDomain(str(exc)) from exc
        except dns.resolver.NoAnswer as exc:
            raise NoAnswer(str(exc)) from exc
        except (dns.resolver.NoNameservers, dns.resolver.LifetimeTimeout, dns.exception.Timeout) as exc:
            raise TempFail(type(exc).__name__) from exc
        except (dns.exception.FormError, dns.resolver.YXDOMAIN, dns.exception.SyntaxError) as exc:
            raise BadResponse(type(exc).__name__) from exc
        except dns.exception.DNSException as exc:
            raise TempFail(type(exc).__name__) from exc

    async def mx(self, name: str) -> list[tuple[int, str]]:
        answer = await self._query(name, "MX")
        return [(int(r.preference), r.exchange.to_text(omit_final_dot=True)) for r in answer]

    async def addresses(self, name: str, rdtype: str) -> list[str]:
        answer = await self._query(name, rdtype)
        return [r.address for r in answer]


@dataclass(frozen=True)
class DnsOutcome:
    status: str  # domain_status vocabulary
    code: str
    text: str
    hosts: tuple[str, ...] = ()  # mail hosts in preference order (MX hosts, or the domain for fallback)
    evidence: tuple[Evidence, ...] = field(default_factory=tuple)

    @property
    def deliverable_domain(self) -> bool:
        return self.status in ("mx", "address_fallback")

    @property
    def retryable(self) -> bool:
        return self.status in ("temporary_failure", "invalid_response")


_TTL = {"mx": 600.0, "address_fallback": 600.0, "null_mx": 600.0, "no_mail_host": 600.0, "nxdomain": 600.0,
        "temporary_failure": 30.0, "invalid_response": 60.0}
_MAX_MX_HOSTS_CHECKED = 3


class DnsChecker:
    def __init__(self, resolver: Resolver, slot=None) -> None:
        """`slot` is an async context-manager factory (the global network limit) held
        only while a lookup really talks to DNS, not while waiting for another task's
        single-flight result."""
        self.resolver = resolver
        self._slot = slot
        self._cache: dict[str, tuple[float, DnsOutcome]] = {}
        self._inflight: dict[str, asyncio.Future[DnsOutcome]] = {}
        self.lookups = 0  # domain checks actually performed (for tests/stats)

    async def check(self, domain: str) -> DnsOutcome:
        now = time.monotonic()
        cached = self._cache.get(domain)
        if cached and cached[0] > now:
            return cached[1]
        if domain in self._inflight:
            return await asyncio.shield(self._inflight[domain])
        fut: asyncio.Future[DnsOutcome] = asyncio.get_running_loop().create_future()
        self._inflight[domain] = fut
        try:
            if self._slot is None:
                outcome = await self._evaluate(domain)
            else:
                async with self._slot():
                    outcome = await self._evaluate(domain)
            self._cache[domain] = (time.monotonic() + _TTL[outcome.status], outcome)
            fut.set_result(outcome)
            return outcome
        except BaseException as exc:
            fut.set_exception(exc)
            fut.exception()  # mark retrieved
            raise
        finally:
            self._inflight.pop(domain, None)

    async def _evaluate(self, domain: str) -> DnsOutcome:
        self.lookups += 1
        try:
            records = await self.resolver.mx(domain)
        except NxDomain:
            return DnsOutcome("nxdomain", "dns.nxdomain", "The domain does not exist (NXDOMAIN).",
                              evidence=(Evidence("dns_mx", {"query": "MX", "result": "NXDOMAIN"}, domain),))
        except TempFail as exc:
            return _temp(domain, "MX", str(exc))
        except BadResponse as exc:
            return DnsOutcome("invalid_response", "dns.invalid_response", "The DNS answer for MX was malformed.",
                              evidence=(Evidence("dns_mx", {"query": "MX", "result": "malformed", "error": str(exc)[:100]}, domain),))
        except NoAnswer:
            return await self._fallback(domain)

        ordered = sorted(records, key=lambda r: (r[0], r[1].lower()))
        mx_ev = Evidence("dns_mx", {"query": "MX", "result": "records",
                                    "records": [{"preference": p, "host": h or "."} for p, h in ordered[:10]]},
                         ordered[0][1] or domain)
        if any(h in ("", ".") for _, h in ordered):
            if len(ordered) == 1 and ordered[0][0] == 0:
                return DnsOutcome("null_mx", "dns.null_mx", "The domain publishes a Null MX (RFC 7505): it accepts no mail.",
                                  evidence=(mx_ev,))
            return DnsOutcome("invalid_response", "dns.null_mx_mixed", "A Null MX is mixed with other MX records.",
                              evidence=(mx_ev,))
        evidence = [mx_ev]
        usable: list[str] = []
        temp = False
        for _, host in ordered[:_MAX_MX_HOSTS_CHECKED]:
            host = host.lower()
            if _is_ip(host):
                evidence.append(Evidence("dns_address", {"name": host, "result": "mx_host_is_ip_literal"}, host))
                continue
            found, ev, tmp = await self._host_addresses(host)
            evidence.extend(ev)
            temp = temp or tmp
            if found:
                usable.append(host)
        if usable:
            return DnsOutcome("mx", "dns.mx", f"{len(records)} MX record(s); {len(usable)} usable mail host(s) checked.",
                              tuple(usable), tuple(evidence))
        if temp:
            return DnsOutcome("temporary_failure", "dns.mx_host_temporary_failure",
                              "Resolving the MX hosts failed temporarily.", evidence=tuple(evidence))
        return DnsOutcome("invalid_response", "dns.mx_hosts_unresolvable", "No MX host resolves to an address.",
                          evidence=tuple(evidence))

    async def _fallback(self, domain: str) -> DnsOutcome:
        no_mx = Evidence("dns_mx", {"query": "MX", "result": "no_records"}, domain)
        try:
            found, ev, temp = await self._host_addresses(domain, raise_nx=True)
        except NxDomain:
            return DnsOutcome("nxdomain", "dns.nxdomain", "The domain does not exist (NXDOMAIN).",
                              evidence=(no_mx, Evidence("dns_address", {"name": domain, "result": "NXDOMAIN"}, domain)))
        if found:
            return DnsOutcome("address_fallback", "dns.address_fallback",
                              "No MX; A/AAAA records permit implicit-MX delivery (RFC 5321).", (domain,), (no_mx, *ev))
        if temp:
            return DnsOutcome("temporary_failure", "dns.temporary_failure", "The address lookup failed temporarily.",
                              evidence=(no_mx, *ev))
        return DnsOutcome("no_mail_host", "dns.no_mail_host", "The domain has neither MX nor A/AAAA records.",
                          evidence=(no_mx, *ev))

    async def _host_addresses(self, host: str, raise_nx: bool = False) -> tuple[bool, list[Evidence], bool]:
        counts: dict[str, object] = {"name": host}
        found = temp = False
        for rdtype in ("A", "AAAA"):
            try:
                addrs = await self.resolver.addresses(host, rdtype)
                counts[rdtype.lower()] = len(addrs)
                found = found or bool(addrs)
            except NxDomain:
                if raise_nx:
                    raise
                counts[rdtype.lower()] = "NXDOMAIN"
            except NoAnswer:
                counts[rdtype.lower()] = 0
            except TempFail as exc:
                counts[rdtype.lower()] = "temporary_failure:" + str(exc)[:40]
                temp = True
            except BadResponse:
                counts[rdtype.lower()] = "malformed"
        return found, [Evidence("dns_address", counts, host)], temp


def _temp(domain: str, query: str, reason: str) -> DnsOutcome:
    return DnsOutcome("temporary_failure", "dns.temporary_failure", f"The {query} lookup failed temporarily ({reason}).",
                      evidence=(Evidence("dns_mx", {"query": query, "result": "temporary_failure", "error": reason[:60]}, domain),))


def _is_ip(value: str) -> bool:
    try:
        ipaddress.ip_address(value)
        return True
    except ValueError:
        return False
