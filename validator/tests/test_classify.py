import itertools

import pytest

from smarthost_validator.classify import Facts, classify, retry_delay
from smarthost_validator.dns_check import DnsOutcome
from smarthost_validator.smtp_probe import ProbeResult
from smarthost_validator.syntax import analyse
from smarthost_validator.typo import Suggestion

VALID = analyse("someone@example.test")
MX = DnsOutcome("mx", "dns.mx", "ok", ("mx.example.test",))


def smtp(status: str) -> ProbeResult:
    return ProbeResult(status, f"smtp.{status}", status)


@pytest.mark.parametrize("facts,expected", [
    (Facts(analyse("nope")), ("undeliverable", "high", "syntax.missing_at")),
    (Facts(analyse("a@[192.0.2.1]")), ("unknown", "low", "domain.address_literal")),
    (Facts(VALID, dns=DnsOutcome("nxdomain", "dns.nxdomain", "dns text")), ("undeliverable", "high", "dns.nxdomain")),
    (Facts(VALID, dns=DnsOutcome("null_mx", "dns.null_mx", "dns text")), ("undeliverable", "high", "dns.null_mx")),
    (Facts(VALID, dns=DnsOutcome("no_mail_host", "dns.no_mail_host", "dns text")), ("undeliverable", "medium", "dns.no_mail_host")),
    (Facts(VALID, dns=DnsOutcome("temporary_failure", "dns.temporary_failure", "dns text")), ("temporarily_unverifiable", "low", "dns.temporary_failure")),
    (Facts(VALID, dns=DnsOutcome("invalid_response", "dns.invalid_response", "dns text")), ("unknown", "low", "dns.invalid_response")),
    (Facts(VALID, dns=MX), ("probably_deliverable", "low", "smtp.skipped")),
    (Facts(VALID, dns=MX, smtp_skip_code="smtp.provider_backoff"), ("temporarily_unverifiable", "low", "smtp.provider_backoff")),
    (Facts(VALID, dns=MX, smtp=smtp("rejected")), ("undeliverable", "high", "smtp.rejected")),
    (Facts(VALID, dns=MX, smtp=smtp("accepted"), accept_all=True), ("risky", "low", "smtp.accept_all")),
    (Facts(VALID, dns=MX, smtp=smtp("accepted"), accept_all=False), ("deliverable", "medium", "smtp.accepted")),
    (Facts(VALID, dns=MX, smtp=smtp("accepted")), ("probably_deliverable", "medium", "smtp.accepted")),
    (Facts(VALID, dns=MX, smtp=smtp("temporary_failure")), ("temporarily_unverifiable", "low", "smtp.temporary_failure")),
    (Facts(VALID, dns=MX, smtp=smtp("timeout")), ("temporarily_unverifiable", "low", "smtp.timeout")),
    (Facts(VALID, dns=MX, smtp=smtp("connection_failed")), ("temporarily_unverifiable", "low", "smtp.connection_failed")),
    (Facts(VALID, dns=MX, smtp=smtp("blocked")), ("unknown", "low", "smtp.blocked")),
    (Facts(VALID, dns=MX, smtp=smtp("inconclusive")), ("unknown", "low", "smtp.inconclusive")),
    (Facts(VALID, dns=MX, smtp=smtp("accepted"), accept_all=False, is_disposable=True), ("risky", "medium", "risk.disposable_domain")),
    (Facts(VALID, dns=MX, is_disposable=True), ("risky", "medium", "risk.disposable_domain")),
    (Facts(VALID, Suggestion("gmail.com", "transposition", "high"), dns=MX, smtp=smtp("accepted"), accept_all=False),
     ("risky", "medium", "risk.domain_typo_suspected")),
    (Facts(VALID, Suggestion("gmail.com", "provider_domain_edit_distance", "medium"), dns=MX, smtp=smtp("accepted"), accept_all=False),
     ("deliverable", "medium", "smtp.accepted")),
    (Facts(analyse("info@example.test"), dns=MX, smtp=smtp("accepted"), accept_all=False), ("deliverable", "medium", "smtp.accepted")),
    (Facts(VALID, dns=DnsOutcome("nxdomain", "dns.nxdomain", "dns text"), is_disposable=True), ("undeliverable", "high", "dns.nxdomain")),
])
def test_rules(facts, expected):
    v = classify(facts)
    assert (v.classification, v.confidence, v.code) == expected
    assert v.text


def test_invariants_over_all_combinations():
    statuses = ["accepted", "rejected", "temporary_failure", "blocked", "timeout", "connection_failed", "inconclusive", None]
    for s, aa, disp, typo in itertools.product(statuses, [True, False, None], [True, False, None], [None, "low", "high"]):
        f = Facts(VALID, Suggestion("gmail.com", "transposition", typo) if typo else None, disp, MX, smtp(s) if s else None, accept_all=aa)
        v = classify(f)
        assert not (v.classification == "deliverable" and v.confidence == "high"), "SMTP acceptance is never proof"
        if aa is True and s == "accepted":
            assert v.classification != "deliverable", "accept-all is never definitely valid"
        if s in ("temporary_failure", "timeout", "connection_failed", "blocked"):
            assert v.classification != "undeliverable", "temporary/probe conditions never become undeliverable"
        if disp and s in ("accepted", None):
            assert v.classification == "risky"


def test_retry_delay_is_bounded_exponential():
    assert [retry_delay(a, 300, 7200) for a in range(1, 8)] == [300, 600, 1200, 2400, 4800, 7200, 7200]
