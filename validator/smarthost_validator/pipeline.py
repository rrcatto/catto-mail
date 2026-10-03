"""Evaluation of one claimed address: the specification's pipeline order
(normalisation, syntax, typo, DNS, disposable, role, SMTP probe, classification).

Returns a FinalResult (address becomes `done`) or a RetryResult (temporary
condition, `retry_scheduled`). Retries use bounded exponential back-off and are
final only when VALIDATOR_MAX_ATTEMPTS is exhausted (then never `undeliverable`).
Nothing here writes to the database or alters the submitted address.
"""
from __future__ import annotations

from datetime import timedelta
from typing import Awaitable, Callable

from . import roles, syntax, typo
from .classify import Facts, classify, retry_delay
from .config import Config
from .dns_check import DnsChecker
from .limits import AcceptAllPolicy, Limits, ProviderBackoff
from .models import ClaimedAddress, Evidence, FinalResult, RetryResult, utcnow
from .normalize import normalize
from .smtp_probe import ProbeResult, SmtpProber

# A retry caused by provider back-off becomes due only after the cool-down has
# ended (database and worker clocks differ slightly).
_BACKOFF_MARGIN_SECONDS = 1.0

DisposableLookup = Callable[[str], Awaitable[bool]]


class Evaluator:
    def __init__(self, cfg: Config, dns: DnsChecker, prober: SmtpProber | None, limits: Limits,
                 backoff: ProviderBackoff, accept_all: AcceptAllPolicy, disposable: DisposableLookup) -> None:
        self.cfg, self.dns, self.prober, self.limits = cfg, dns, prober, limits
        self.backoff, self.accept_all, self.disposable = backoff, accept_all, disposable

    async def evaluate(self, a: ClaimedAddress) -> FinalResult | RetryResult:
        normalized = normalize(a.original_address)
        syn = syntax.analyse(a.original_address)
        local, domain = syn.local, syn.domain
        suggestion, suggested = None, None
        if local is not None and domain is not None and not syn.address_literal:
            hit = typo.suggest_address(local, domain)
            if hit:
                suggested, suggestion = hit
        role = roles.is_role(local) if local is not None else None
        typo_flag = (suggestion is not None) if domain is not None and not syn.address_literal else None
        evidence: list[Evidence] = []

        def final(dns_status: str, smtp_status: str, *, disposable: bool | None = None, dns_outcome=None,  # type: ignore[no-untyped-def]
                  probe=None, accept_all: bool | None = None, skip_code: str = "smtp.skipped") -> FinalResult:
            verdict = classify(Facts(syn, suggestion, disposable, dns_outcome, probe, skip_code, accept_all))
            return FinalResult(
                a.id, a.job_id, a.client_id, normalized, syn.status, dns_status, smtp_status, role, disposable,
                accept_all, typo_flag, suggested, suggestion.reason_code if suggestion else None,
                suggestion.confidence if suggestion else None, verdict.classification, verdict.confidence,
                verdict.code, verdict.text, tuple(evidence))

        if not syn.valid or syn.address_literal or domain is None:
            disposable = await self.disposable(domain) if domain and not syn.address_literal else None
            return final("skipped", "skipped", disposable=disposable)

        dns = await self.dns.check(domain)
        evidence.extend(dns.evidence)
        if dns.retryable:
            retry = self._retry(a, dns.code, evidence)
            if retry is not None:
                return retry
            return final(dns.status, "skipped", dns_outcome=dns)
        if not dns.deliverable_domain:
            return final(dns.status, "skipped", disposable=await self.disposable(domain), dns_outcome=dns)

        disposable = await self.disposable(domain)
        if not self.cfg.smtp_probe_enabled or self.prober is None:
            return final(dns.status, "skipped", disposable=disposable, dns_outcome=dns, skip_code="smtp.probe_disabled")

        mx = dns.hosts[0]
        cooling = self.backoff.cooling_for(mx)
        if cooling > 0:
            retry = self._retry(a, "smtp.provider_backoff", evidence, min_delay=cooling + _BACKOFF_MARGIN_SECONDS)
            if retry is not None:
                return retry
            return final(dns.status, "skipped", disposable=disposable, dns_outcome=dns, skip_code="smtp.provider_backoff")

        want_probe, cached = await self.accept_all.begin(domain)
        probed: ProbeResult | None = None
        try:
            async with self.limits.smtp(domain, mx):
                probed = await self.prober.probe(normalized or "", domain, dns.hosts, want_probe)
        finally:
            if want_probe:  # always release waiters, also on cancellation
                executed = probed is not None and any(e.evidence_type == "smtp_accept_all_probe" for e in probed.evidence)
                self.accept_all.finish(domain, executed, probed.accept_all if executed and probed else None)
        result = probed
        evidence.extend(result.evidence)
        if result.provider_throttling:
            self.backoff.record_throttling(result.host or mx)
        elif result.status in ("accepted", "rejected"):
            self.backoff.record_success(result.host or mx)
        accept_all = result.accept_all if result.accept_all is not None else cached
        if result.status == "rejected":
            accept_all = False
        if result.retryable:
            cooling = self.backoff.cooling_for(mx)
            retry = self._retry(a, result.code, evidence, min_delay=cooling + _BACKOFF_MARGIN_SECONDS if cooling else 0.0)
            if retry is not None:
                return retry
        return final(dns.status, result.status, disposable=disposable, dns_outcome=dns, probe=result, accept_all=accept_all)

    def _retry(self, a: ClaimedAddress, reason: str, evidence: list[Evidence], min_delay: float = 0.0) -> RetryResult | None:
        """A RetryResult while attempts remain; None when they are exhausted (caller finalises)."""
        if a.attempt_count >= self.cfg.max_attempts:
            return None
        delay = max(retry_delay(a.attempt_count, self.cfg.retry_base_seconds, self.cfg.retry_max_seconds), min_delay)
        return RetryResult(a.id, a.job_id, utcnow() + timedelta(seconds=delay), reason, tuple(evidence))
