"""Mailbox syntax analysis (pipeline stage 2): RFC 5321/5322 rules, no network access.

Analyses the D-32 normalised address (or, when there is none, the trimmed input
to explain why). It never rewrites anything: the result is a verdict plus a
stable diagnostic code.

Decisions (documented in validator/README.md):
* Internationalised local parts (UTF-8, RFC 6531) are syntactically valid; they
  are flagged `eai` because probing them needs a server that offers SMTPUTF8.
* Address literals (`[192.0.2.1]`, `[IPv6:...]`) are syntactically valid but are
  not looked up or probed; they classify as `unknown`.
* A domain must have at least two labels, use letters/digits/hyphens (A-labels for
  IDN), have no empty label or trailing dot, and a non-numeric top-level label.
"""
from __future__ import annotations

import ipaddress
import re
from dataclasses import dataclass

from .normalize import normalize

_ATEXT = re.compile(r"^[A-Za-z0-9!#$%&'*+\-/=?^_`{|}~\u0080-\U0010FFFF]+$")
_LABEL = re.compile(r"^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$")
_WS = " \t\n\x0b\x0c\r"


@dataclass(frozen=True)
class SyntaxResult:
    valid: bool
    code: str
    text: str
    local: str | None = None
    domain: str | None = None
    eai: bool = False
    address_literal: bool = False

    @property
    def status(self) -> str:
        return "valid" if self.valid else "invalid"


def _invalid(code: str, text: str, local: str | None = None, domain: str | None = None) -> SyntaxResult:
    return SyntaxResult(False, "syntax." + code, text, local, domain)


def analyse(original: str) -> SyntaxResult:
    normalized = normalize(original)
    if normalized is None:
        trimmed = original.strip(_WS)
        at = trimmed.rfind("@")
        if at < 0:
            return _invalid("missing_at", "The address has no @.")
        if at == 0:
            return _invalid("empty_local_part", "The local part before @ is empty.")
        if at == len(trimmed) - 1:
            return _invalid("empty_domain", "The domain after @ is empty.")
        return _invalid("invalid_idn_domain", "The internationalised domain cannot be converted to A-labels (UTS #46).")
    at = normalized.rfind("@")
    local, domain = normalized[:at], normalized[at + 1:]

    if len(normalized.encode()) > 254:
        return _invalid("address_too_long", "The address exceeds 254 octets.", local, domain)
    # Local part
    if len(local.encode()) > 64:
        return _invalid("local_part_too_long", "The local part exceeds 64 octets.", local, domain)
    eai = not local.isascii()
    if local.startswith('"'):
        if not _quoted_ok(local):
            return _invalid("invalid_quoted_local_part", "The quoted local part is malformed.", local, domain)
    else:
        if local.startswith(".") or local.endswith(".") or ".." in local:
            return _invalid("local_part_dots", "The local part has a leading, trailing or repeated dot.", local, domain)
        if not all(_ATEXT.match(atom) for atom in local.split(".")):
            return _invalid("invalid_local_part", "The local part contains characters that are not allowed unquoted.", local, domain)
    # Domain
    if domain.startswith("[") and domain.endswith("]"):
        if not _literal_ok(domain[1:-1]):
            return _invalid("invalid_address_literal", "The address literal is not a valid IPv4 or IPv6 address.", local, domain)
        return SyntaxResult(True, "syntax.address_literal", "Valid address-literal domain (not looked up or probed).",
                            local, domain, eai, True)
    if domain.endswith("."):
        return _invalid("domain_trailing_dot", "The domain ends with a dot.", local, domain)
    if len(domain) > 253:
        return _invalid("domain_too_long", "The domain exceeds 253 octets.", local, domain)
    labels = domain.split(".")
    if any(label == "" for label in labels):
        return _invalid("domain_empty_label", "The domain has an empty label.", local, domain)
    if not all(_LABEL.match(label) for label in labels):
        return _invalid("invalid_domain_label", "A domain label contains characters other than letters, digits and hyphens, or starts or ends with a hyphen.", local, domain)
    if len(labels) < 2:
        return _invalid("single_label_domain", "The domain has a single label.", local, domain)
    if labels[-1].isdigit():
        return _invalid("numeric_tld", "The top-level label is numeric.", local, domain)
    if eai:
        return SyntaxResult(True, "syntax.valid_eai", "Valid internationalised mailbox (needs SMTPUTF8).", local, domain, True)
    return SyntaxResult(True, "syntax.valid", "Valid mailbox syntax.", local, domain)


def _quoted_ok(local: str) -> bool:
    if len(local) < 2 or not local.endswith('"'):
        return False
    body, i = local[1:-1], 0
    while i < len(body):
        ch = body[i]
        if ch == "\\":
            if i + 1 >= len(body) or not 32 <= ord(body[i + 1]) <= 126:
                return False
            i += 2
            continue
        if ch == '"' or ch == "\\" or (ord(ch) < 128 and not (32 <= ord(ch) <= 126)):
            return False
        i += 1
    return True


def _literal_ok(inner: str) -> bool:
    try:
        if inner.lower().startswith("ipv6:"):
            ipaddress.IPv6Address(inner[5:])
        else:
            ipaddress.IPv4Address(inner)
        return True
    except ValueError:
        return False
