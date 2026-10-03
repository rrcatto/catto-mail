"""Deterministic classification of collected evidence (pipeline stage 8).

Rules, evaluated top to bottom (first match wins):

 #  condition                                           classification            confidence  code
 1  syntax invalid                                      undeliverable             high        syntax.*
 2  address-literal domain                              unknown                   low         domain.address_literal
 3  DNS nxdomain                                        undeliverable             high        dns.nxdomain
 4  DNS null_mx                                         undeliverable             high        dns.null_mx
 5  DNS no_mail_host                                    undeliverable             medium      dns.no_mail_host
 6  DNS temporary_failure (retries exhausted)           temporarily_unverifiable  low         dns.*
 7  DNS invalid_response (retries exhausted)            unknown                   low         dns.*
 8a SMTP never possible: provider back-off until the
    retries were exhausted                              temporarily_unverifiable  low         smtp.provider_backoff
 8  SMTP probing not performed (disabled)               probably_deliverable      low         smtp.skipped / smtp.*
 9  SMTP rejected (permanent, mailbox level)            undeliverable             high        smtp.rcpt.*
10  SMTP accepted, domain accepts all recipients        risky                     low         smtp.accept_all
11  SMTP accepted, random recipient rejected            deliverable               medium      smtp.rcpt.*
12  SMTP accepted, accept-all unknown                   probably_deliverable      medium      smtp.rcpt.*
13  SMTP temporary_failure/timeout/connection_failed
    (retries exhausted)                                 temporarily_unverifiable  low         smtp.*
14  SMTP blocked (retries exhausted)                    unknown                   low         smtp.*
15  SMTP inconclusive                                   unknown                   low         smtp.*

Risk modifiers, applied only to deliverable / probably_deliverable results:
 - disposable domain                     -> risky, medium, risk.disposable_domain
 - high-confidence domain typo suspected -> risky, medium, risk.domain_typo_suspected
Role accounts are reported (is_role) but do not change the classification.

Invariants (spec validation.result_model.certainty_policy): SMTP acceptance is
evidence, never proof of a person, so nothing is `deliverable` with high
confidence; accept-all is never `deliverable`; a temporary condition becomes a
final verdict only after retries are exhausted and then only
`temporarily_unverifiable`/`unknown`, never `undeliverable`; disposable and
role status are risk signals, not syntax failures; nothing here claims spam-trap
detection.
"""
from __future__ import annotations

from dataclasses import dataclass

from .dns_check import DnsOutcome
from .smtp_probe import ProbeResult
from .syntax import SyntaxResult
from .typo import Suggestion


@dataclass(frozen=True)
class Facts:
    syntax: SyntaxResult
    suggestion: Suggestion | None = None
    is_disposable: bool | None = None
    dns: DnsOutcome | None = None
    smtp: ProbeResult | None = None
    smtp_skip_code: str = "smtp.skipped"
    accept_all: bool | None = None


@dataclass(frozen=True)
class Verdict:
    classification: str
    confidence: str
    code: str
    text: str


def classify(f: Facts) -> Verdict:
    if not f.syntax.valid:
        return Verdict("undeliverable", "high", f.syntax.code, f.syntax.text)
    if f.syntax.address_literal:
        return Verdict("unknown", "low", "domain.address_literal", "Address-literal domains are not looked up or probed.")
    d = f.dns
    assert d is not None, "DNS facts are required for a syntactically valid hostname address"
    if d.status in ("nxdomain", "null_mx"):
        return Verdict("undeliverable", "high", d.code, d.text)
    if d.status == "no_mail_host":
        return Verdict("undeliverable", "medium", d.code, d.text)
    if d.status == "temporary_failure":
        return Verdict("temporarily_unverifiable", "low", d.code, d.text + " Retries were exhausted.")
    if d.status == "invalid_response":
        return Verdict("unknown", "low", d.code, d.text)
    s = f.smtp
    if s is None and f.smtp_skip_code == "smtp.provider_backoff":
        return Verdict("temporarily_unverifiable", "low", "smtp.provider_backoff",
                       "The mail provider was throttling or blocking probes; retries were exhausted before it could be checked.")
    if s is None:
        base = Verdict("probably_deliverable", "low", f.smtp_skip_code,
                       "The domain accepts mail; the mailbox was not probed.")
    elif s.status == "rejected":
        return Verdict("undeliverable", "high", s.code, s.text)
    elif s.status == "accepted":
        if f.accept_all is True:
            return Verdict("risky", "low", "smtp.accept_all",
                           "The server accepts any recipient on this domain, so acceptance proves nothing about this mailbox.")
        if f.accept_all is False:
            base = Verdict("deliverable", "medium", s.code, s.text)
        else:
            base = Verdict("probably_deliverable", "medium", s.code, s.text)
    elif s.status in ("temporary_failure", "timeout", "connection_failed"):
        return Verdict("temporarily_unverifiable", "low", s.code, s.text + " Retries were exhausted.")
    elif s.status == "blocked":
        return Verdict("unknown", "low", s.code, s.text)
    else:
        return Verdict("unknown", "low", s.code, s.text)

    if f.is_disposable:
        return Verdict("risky", "medium", "risk.disposable_domain", "The domain is a known disposable-address domain.")
    if f.suggestion is not None and f.suggestion.confidence == "high":
        return Verdict("risky", "medium", "risk.domain_typo_suspected",
                       f"The domain looks like a typo of {f.suggestion.domain}.")
    return base


def retry_delay(attempt: int, base: int, maximum: int) -> int:
    """Bounded exponential back-off: base * 2^(attempt-1), capped at maximum."""
    return int(min(maximum, base * (2 ** max(0, attempt - 1))))
