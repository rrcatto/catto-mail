"""The whole per-address pipeline with fake DNS and the fake SMTP server."""
from datetime import timedelta

import pytest

from smarthost_validator.dns_check import DnsChecker
from smarthost_validator.limits import AcceptAllPolicy, Limits, ProviderBackoff
from smarthost_validator.models import ClaimedAddress, FinalResult, RetryResult, now
from smarthost_validator.pipeline import Evaluator
from smarthost_validator.smtp_probe import SmtpProber
from tests.fakes.fake_resolver import FakeResolver
from tests.helpers import ZONE, config


def evaluator(port: int, probe: bool = True, resolver: FakeResolver | None = None) -> Evaluator:
    cfg = config(VALIDATOR_SMTP_PROBE_ENABLED="true" if probe else "false", VALIDATOR_SMTP_ROUTE_OVERRIDE=f"127.0.0.1:{port}")
    r = resolver or FakeResolver({k: dict(v) for k, v in ZONE.items()})
    r.tempfail.add("servfail.test")
    limits = Limits(5, 2, 2)

    async def disposable(domain: str) -> bool:
        return domain == "tempmail.test"

    prober = SmtpProber("v.test", "v@bounce.test", 1, 1, ("127.0.0.1", port))
    return Evaluator(cfg, DnsChecker(r, slot=limits.network), prober, limits, ProviderBackoff(60, 600),
                     AcceptAllPolicy(min_interval_seconds=0), disposable)


def addr(original: str, attempt: int = 1) -> ClaimedAddress:
    return ClaimedAddress("01999999-0000-7000-8000-000000000001", "job", "client", original, attempt)


@pytest.mark.parametrize("original,classification,domain_status,smtp_status", [
    ("someone@example.test", "deliverable", "mx", "accepted"),
    (" Someone@EXAMPLE.test ", "deliverable", "mx", "accepted"),
    ("reject-550@example.test", "undeliverable", "mx", "rejected"),
    ("anyone@accept-all.test", "risky", "mx", "accepted"),
    ("x@nullmx.test", "undeliverable", "null_mx", "skipped"),
    ("x@absent.test", "undeliverable", "nxdomain", "skipped"),
    ("x@fallback.test", "deliverable", "address_fallback", "accepted"),
    ("x@tempmail.test", "risky", "mx", "accepted"),
    ("x@gmial.com", "risky", "mx", "accepted"),
    ("info@example.test", "deliverable", "mx", "accepted"),
    ("not-an-address", "undeliverable", "skipped", "skipped"),
    ("x@[192.0.2.1]", "unknown", "skipped", "skipped"),
])
async def test_final_results(smtp_server, original, classification, domain_status, smtp_status):
    out = await evaluator(smtp_server).evaluate(addr(original))
    assert isinstance(out, FinalResult)
    assert (out.overall_classification, out.domain_status, out.smtp_status) == (classification, domain_status, smtp_status)
    assert out.syntax_status in ("valid", "invalid") and out.confidence in ("low", "medium", "high")
    assert out.diagnostic_code and out.diagnostic_text


async def test_result_fields(smtp_server):
    typo = await evaluator(smtp_server).evaluate(addr("John@gmial.com"))
    assert (typo.is_domain_typo_suspected, typo.suggested_address, typo.suggestion_reason_code, typo.suggestion_confidence) == \
        (True, "John@gmail.com", "transposition", "high")
    assert typo.normalized_address == "John@gmial.com"
    role = await evaluator(smtp_server).evaluate(addr("info@example.test"))
    assert role.is_role is True and role.is_disposable is False and role.is_catch_all_or_accept_all is False
    assert role.is_domain_typo_suspected is False and role.suggested_address is None
    bad = await evaluator(smtp_server).evaluate(addr("nope"))
    assert bad.normalized_address is None and bad.is_role is None and bad.diagnostic_code == "syntax.missing_at"
    kinds = [e.evidence_type for e in role.evidence]
    assert "dns_mx" in kinds and "smtp_rcpt_to" in kinds and "smtp_accept_all_probe" in kinds


async def test_probing_disabled(smtp_server):
    out = await evaluator(smtp_server, probe=False).evaluate(addr("someone@example.test"))
    assert (out.overall_classification, out.confidence, out.smtp_status, out.diagnostic_code) == \
        ("probably_deliverable", "low", "skipped", "smtp.probe_disabled")


@pytest.mark.parametrize("original,reason", [
    ("tempfail-450@example.test", "smtp.rcpt.4.2.1"),
    ("timeout@example.test", "smtp.command_timeout"),
    ("x@servfail.test", "dns.temporary_failure"),
    ("block-554@example.test", "smtp.rcpt.5.7.1"),
])
async def test_temporary_conditions_retry_with_backoff(smtp_server, original, reason):
    before = now()
    out = await evaluator(smtp_server).evaluate(addr(original, attempt=1))
    assert isinstance(out, RetryResult) and out.last_error == reason
    assert out.next_attempt_at >= before + timedelta(seconds=60)  # base delay; never immediate
    second = await evaluator(smtp_server).evaluate(addr(original, attempt=2))
    assert isinstance(second, RetryResult) and second.next_attempt_at >= before + timedelta(seconds=120)


@pytest.mark.parametrize("original,classification", [
    ("tempfail-450@example.test", "temporarily_unverifiable"),
    ("timeout@example.test", "temporarily_unverifiable"),
    ("x@servfail.test", "temporarily_unverifiable"),
    ("block-554@example.test", "unknown"),
])
async def test_exhausted_retries_are_never_undeliverable(smtp_server, original, classification):
    out = await evaluator(smtp_server).evaluate(addr(original, attempt=3))  # VALIDATOR_MAX_ATTEMPTS=3
    assert isinstance(out, FinalResult) and out.overall_classification == classification


async def test_provider_block_cools_the_mx_down(smtp_server):
    ev = evaluator(smtp_server)
    first = await ev.evaluate(addr("x@block-all.test"))
    assert isinstance(first, RetryResult)
    assert ev.backoff.cooling_for("mx.block-all.test") > 0
    sent_before = len(ev.prober.commands_sent)
    second = await ev.evaluate(addr("y@block-all.test"))
    assert isinstance(second, RetryResult) and second.last_error == "smtp.provider_backoff"
    assert len(ev.prober.commands_sent) == sent_before  # no new connection to a cooling provider


async def test_exhausted_while_the_provider_cools_down(smtp_server):
    ev = evaluator(smtp_server)
    assert isinstance(await ev.evaluate(addr("x@block-all.test")), RetryResult)
    out = await ev.evaluate(addr("y@block-all.test", attempt=3))
    assert (out.overall_classification, out.smtp_status, out.diagnostic_code) == ("temporarily_unverifiable", "skipped", "smtp.provider_backoff")


async def test_one_accept_all_probe_per_domain(smtp_server):
    import asyncio
    ev = evaluator(smtp_server)
    outs = await asyncio.gather(*[ev.evaluate(addr(f"user{i}@example.test")) for i in range(20)])
    assert {o.overall_classification for o in outs} == {"deliverable"}  # every one sees the verdict
    assert ev.accept_all.probes == 1
