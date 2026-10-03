"""DNS classification with deterministic fakes: an in-memory resolver, and the real
dnspython resolver against the fake DNS server (UDP/TCP on loopback)."""
import asyncio
import json

import pytest

from smarthost_validator.dns_check import DnsChecker, DnspythonResolver
from tests.fakes import fake_dns
from tests.fakes.fake_resolver import FakeResolver

ZONE = {
    "mx.test": {"MX": [(10, "mx1.mx.test")]},
    "mx1.mx.test": {"A": ["192.0.2.10"]},
    "multi.test": {"MX": [(20, "backup.multi.test"), (10, "primary.multi.test"), (30, "gone.multi.test")]},
    "primary.multi.test": {"A": ["192.0.2.11"]},
    "backup.multi.test": {"AAAA": ["2001:db8::12"]},
    "nullmx.test": {"MX": [(0, "")]},
    "mixednull.test": {"MX": [(0, ""), (10, "mx1.mx.test")]},
    "fallback.test": {"A": ["192.0.2.20"]},
    "fallback6.test": {"AAAA": ["2001:db8::20"]},
    "nohost.test": {"TXT": ["v=spf1 -all"]},
    "deadmx.test": {"MX": [(10, "nowhere.deadmx.test")]},
    "ipmx.test": {"MX": [(10, "192.0.2.30")]},
    "tempmx.test": {"MX": [(10, "slow.tempmx.test")]},
}


def checker() -> tuple[DnsChecker, FakeResolver]:
    r = FakeResolver({k: dict(v) for k, v in ZONE.items()})
    r.tempfail |= {"servfail.test", "slow.tempmx.test"}
    r.malformed.add("formerr.test")
    return DnsChecker(r), r


@pytest.mark.parametrize("domain,status,hosts,code", [
    ("mx.test", "mx", ("mx1.mx.test",), "dns.mx"),
    ("multi.test", "mx", ("primary.multi.test", "backup.multi.test"), "dns.mx"),
    ("nullmx.test", "null_mx", (), "dns.null_mx"),
    ("mixednull.test", "invalid_response", (), "dns.null_mx_mixed"),
    ("fallback.test", "address_fallback", ("fallback.test",), "dns.address_fallback"),
    ("fallback6.test", "address_fallback", ("fallback6.test",), "dns.address_fallback"),
    ("nohost.test", "no_mail_host", (), "dns.no_mail_host"),
    ("absent.test", "nxdomain", (), "dns.nxdomain"),
    ("servfail.test", "temporary_failure", (), "dns.temporary_failure"),
    ("formerr.test", "invalid_response", (), "dns.invalid_response"),
    ("deadmx.test", "invalid_response", (), "dns.mx_hosts_unresolvable"),
    ("ipmx.test", "invalid_response", (), "dns.mx_hosts_unresolvable"),
    ("tempmx.test", "temporary_failure", (), "dns.mx_host_temporary_failure"),
])
async def test_domain_status(domain, status, hosts, code):
    c, _ = checker()
    out = await c.check(domain)
    assert (out.status, out.hosts, out.code) == (status, hosts, code)
    assert out.evidence and all(e.evidence_type in ("dns_mx", "dns_address") for e in out.evidence)
    assert out.retryable == (status in ("temporary_failure", "invalid_response"))


async def test_mx_evidence_lists_records_in_priority_order():
    c, _ = checker()
    out = await c.check("multi.test")
    assert [r["preference"] for r in out.evidence[0].detail["records"]] == [10, 20, 30]


async def test_cache_and_single_flight():
    r = FakeResolver({k: dict(v) for k, v in ZONE.items()}, delay=0.05)
    c = DnsChecker(r)
    results = await asyncio.gather(*[c.check("mx.test") for _ in range(200)])
    assert {x.status for x in results} == {"mx"} and c.lookups == 1
    await c.check("mx.test")
    assert c.lookups == 1 and r.queries.count(("mx.test", "MX")) == 1


async def test_global_slot_is_held_only_for_real_lookups():
    from smarthost_validator.limits import CountingSemaphore
    sem = CountingSemaphore(1)
    c = DnsChecker(FakeResolver({k: dict(v) for k, v in ZONE.items()}, delay=0.02), slot=sem.slot)
    await asyncio.gather(*[c.check(d) for d in ["mx.test", "fallback.test", "nohost.test"] * 20])
    assert sem.max_seen == 1 and c.lookups == 3


@pytest.fixture
async def dns_server():
    zone = fake_dns.Zone({
        "records": {
            "mx.test": {"MX": [[10, "mx1.mx.test"], [20, "mx2.mx.test"]]}, "mx1.mx.test": {"A": ["192.0.2.10"]},
            "mx2.mx.test": {"A": ["192.0.2.11"]}, "nullmx.test": {"MX": [[0, "."]]},
            "fallback.test": {"A": ["192.0.2.20"], "AAAA": ["2001:db8::20"]}, "nohost.test": {"TXT": ["x"]},
        },
        "servfail": ["servfail.test"], "refused": ["refused.test"], "timeout": ["timeout.test"], "formerr": ["formerr.test"],
    })
    port = free_port()
    udp, tcp = await fake_dns.serve(zone, "127.0.0.1", port)
    yield port
    udp.close()
    tcp.close()
    await tcp.wait_closed()


def free_port() -> int:
    import socket
    for _ in range(50):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as t:
            t.bind(("127.0.0.1", 0))
            port = t.getsockname()[1]
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as u:
                u.bind(("127.0.0.1", port))
            return port
        except OSError:
            continue
    raise RuntimeError("no free port")


@pytest.mark.parametrize("domain,status", [
    ("mx.test", "mx"), ("nullmx.test", "null_mx"), ("fallback.test", "address_fallback"), ("nohost.test", "no_mail_host"),
    ("absent.test", "nxdomain"), ("servfail.test", "temporary_failure"), ("refused.test", "temporary_failure"),
    ("timeout.test", "temporary_failure"), ("formerr.test", "temporary_failure"),
])
async def test_real_resolver_against_fake_dns_server(dns_server, domain, status):
    c = DnsChecker(DnspythonResolver(("127.0.0.1",), timeout=1.0, port=dns_server))
    out = await c.check(domain)
    assert out.status == status, json.dumps([e.detail for e in out.evidence])
    if domain == "mx.test":
        assert out.hosts == ("mx1.mx.test", "mx2.mx.test")
