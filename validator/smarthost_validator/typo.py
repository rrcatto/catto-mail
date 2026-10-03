"""Typo suggestions (pipeline stage 3, D-04). A suggestion never modifies an
address; confidence is categorical (low/medium/high), never a probability.

Rules, in order (first match wins), all against typo_data:
1. legacy_domain_alias      known alias                                   -> low
2. (no suggestion)          the domain is a known provider domain
3. other_domain_typo_rule   provider domain with its dots removed          -> high
                            ("gmailcom"); provider label without TLD
                            ("gmail" -> gmail.com)                         -> medium
4. tld_typo                 fake TLD slip (".con"): provider target        -> high
                                                    other domain           -> medium
                            real-TLD slip for .com on a provider (".co")   -> medium
5. transposition            one adjacent swap of a provider domain        -> high
6. provider_domain_edit_distance  unique provider at distance 1           -> medium
                                  unique provider at distance 2, domain
                                  of at least 10 characters               -> low
   Only provider domains of at least 9 characters take part in edit-distance
   matching: short ones (aol.com, gmx.de, mail.com) are too close to unrelated
   real domains.
"""
from __future__ import annotations

from dataclasses import dataclass

from .typo_data import FAKE_TLD_TYPOS, LEGACY_ALIASES, PROVIDER_DOMAINS, REAL_TLD_SLIPS_FOR_COM


@dataclass(frozen=True)
class Suggestion:
    domain: str
    reason_code: str
    confidence: str


def suggest_domain(domain: str) -> Suggestion | None:
    d = domain.lower()
    if d in LEGACY_ALIASES:
        return Suggestion(LEGACY_ALIASES[d], "legacy_domain_alias", "low")
    if d in PROVIDER_DOMAINS:
        return None
    for p in sorted(PROVIDER_DOMAINS):
        if d == p.replace(".", ""):
            return Suggestion(p, "other_domain_typo_rule", "high")
    if "." not in d and d + ".com" in PROVIDER_DOMAINS:
        return Suggestion(d + ".com", "other_domain_typo_rule", "medium")
    base, _, tld = d.rpartition(".")
    if base and tld in FAKE_TLD_TYPOS:
        target = f"{base}.{FAKE_TLD_TYPOS[tld]}"
        return Suggestion(target, "tld_typo", "high" if target in PROVIDER_DOMAINS else "medium")
    if base and tld in REAL_TLD_SLIPS_FOR_COM and f"{base}.com" in PROVIDER_DOMAINS:
        return Suggestion(f"{base}.com", "tld_typo", "medium")
    for p in sorted(PROVIDER_DOMAINS):
        if _is_transposition(d, p):
            return Suggestion(p, "transposition", "high")
    by_distance: dict[int, list[str]] = {}
    for p in PROVIDER_DOMAINS:
        if len(p) < 9:
            continue
        dist = _osa_distance(d, p, limit=2)
        if dist <= 2:
            by_distance.setdefault(dist, []).append(p)
    if len(by_distance.get(1, [])) == 1:
        return Suggestion(by_distance[1][0], "provider_domain_edit_distance", "medium")
    if not by_distance.get(1) and len(by_distance.get(2, [])) == 1 and len(d) >= 10:
        return Suggestion(by_distance[2][0], "provider_domain_edit_distance", "low")
    return None


def suggest_address(local: str, domain: str) -> tuple[str, Suggestion] | None:
    """The suggested address keeps the local part byte-for-byte."""
    s = suggest_domain(domain)
    return None if s is None else (f"{local}@{s.domain}", s)


def _is_transposition(a: str, b: str) -> bool:
    if len(a) != len(b) or a == b:
        return False
    diff = [i for i in range(len(a)) if a[i] != b[i]]
    return len(diff) == 2 and diff[1] == diff[0] + 1 and a[diff[0]] == b[diff[1]] and a[diff[1]] == b[diff[0]]


def _osa_distance(a: str, b: str, limit: int) -> int:
    """Optimal-string-alignment (Damerau) distance, short-circuited above `limit`."""
    if abs(len(a) - len(b)) > limit:
        return limit + 1
    prev2: list[int] = []
    prev = list(range(len(b) + 1))
    for i in range(1, len(a) + 1):
        cur = [i] + [0] * len(b)
        for j in range(1, len(b) + 1):
            cost = 0 if a[i - 1] == b[j - 1] else 1
            cur[j] = min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost)
            if i > 1 and j > 1 and a[i - 1] == b[j - 2] and a[i - 2] == b[j - 1]:
                cur[j] = min(cur[j], prev2[j - 2] + 1)
        if min(cur) > limit:
            return limit + 1
        prev2, prev = prev, cur
    return prev[-1]
