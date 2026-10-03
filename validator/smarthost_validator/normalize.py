"""D-32 address normalisation (docs/architecture/conventions.md "Email addresses").

The one cross-language rule, identical to PHP's App\\Sending\\AddressNormalizer and
proven by docs/contracts/address-normalization-vectors.json:

1. trim surrounding ASCII whitespace only (SP, HT, LF, VT, FF, CR);
2. split at the final "@"; both parts must be non-empty;
3. keep the local part byte-for-byte (never case-folded);
4. an all-ASCII domain is lower-cased (ASCII only);
5. a domain with non-ASCII characters is converted to A-labels with UTS #46
   non-transitional processing (CheckHyphens, CheckBidi, CheckJoiners; no
   STD3 rules; DNS length verification), then lower-cased;
6. a failed conversion means there is no normalised address (None).

Python's `idna.encode()` applies the stricter IDNA2008 label rules (e.g. it
rejects symbols that UTS #46 accepts, and enforces CONTEXTO), which would
disagree with PHP/ICU. This module therefore implements UTS #46 processing
explicitly on top of the `idna` package's UTS #46 mapping table, so Python and
PHP agree on every vector.
"""
from __future__ import annotations

import unicodedata

import idna
from idna import core as idna_core

_ASCII_WHITESPACE = " \t\n\x0b\x0c\r"
_MAX_LABEL = 63
_MAX_DOMAIN = 253


class NormalizationError(ValueError):
    """The domain cannot be converted (no normalised form)."""


def normalize(address: str) -> str | None:
    """Return the D-32 normalised address, or None when there is none."""
    trimmed = address.strip(_ASCII_WHITESPACE)
    at = trimmed.rfind("@")
    if at <= 0 or at == len(trimmed) - 1:
        return None
    domain = normalize_domain(trimmed[at + 1:])
    return None if domain is None else trimmed[:at] + "@" + domain


def normalize_domain(domain: str) -> str | None:
    if domain == "":
        return None
    if domain.isascii():
        return domain.lower()  # ASCII-only lower-casing, as PHP strtolower
    try:
        return _uts46_to_ascii(domain).lower()
    except (NormalizationError, idna.IDNAError, UnicodeError):
        return None


def _uts46_to_ascii(domain: str) -> str:
    # UTS #46 §4 step 1: map (non-transitional, no STD3 rules); also NFC-normalises.
    mapped = idna.uts46_remap(domain, std3_rules=False, transitional=False)
    labels = mapped.split(".")
    trailing_root = len(labels) > 1 and labels[-1] == ""
    if trailing_root:
        labels = labels[:-1]
    decoded: list[str] = []
    for label in labels:
        if label == "":
            raise NormalizationError("empty label")
        if label.lower().startswith("xn--"):
            try:
                label = label[4:].encode("ascii").decode("punycode")
            except (UnicodeError, ValueError) as exc:
                raise NormalizationError("invalid punycode") from exc
            if label == "" or label.isascii():
                raise NormalizationError("punycode label decodes to nothing or to ASCII only")
            if idna.uts46_remap(label, std3_rules=False, transitional=False) != label:
                raise NormalizationError("punycode label is not in mapped form")
        decoded.append(label)

    bidi_domain = any(_has_rtl(label) for label in decoded)
    out: list[str] = []
    for label in decoded:
        _check_validity(label, bidi_domain)
        ascii_label = label if label.isascii() else "xn--" + label.encode("punycode").decode("ascii")
        if not 1 <= len(ascii_label) <= _MAX_LABEL:
            raise NormalizationError("label length")
        out.append(ascii_label)
    result = ".".join(out)
    if len(result) > _MAX_DOMAIN:
        raise NormalizationError("domain length")
    return result + ("." if trailing_root else "")


def _check_validity(label: str, bidi_domain: bool) -> None:
    """UTS #46 §4.1 validity criteria (non-transitional)."""
    if unicodedata.normalize("NFC", label) != label:
        raise NormalizationError("not NFC")
    # CheckHyphens
    if len(label) >= 4 and label[2:4] == "--":
        raise NormalizationError("hyphens in positions 3 and 4")
    if label.startswith("-") or label.endswith("-"):
        raise NormalizationError("leading or trailing hyphen")
    if unicodedata.category(label[0]).startswith("M"):
        raise NormalizationError("leading combining mark")
    # CheckJoiners (RFC 5892 Appendix A.1/A.2, CONTEXTJ only - not CONTEXTO)
    for pos, ch in enumerate(label):
        if ch in ("\u200c", "\u200d") and not idna_core.valid_contextj(label, pos):
            raise NormalizationError("joiner context")
    # CheckBidi (RFC 5893), applied to every label of a Bidi domain name
    if bidi_domain:
        try:
            idna_core.check_bidi(label, check_ltr=True)
        except idna.IDNABidiError as exc:
            raise NormalizationError("bidi rule") from exc


def _has_rtl(label: str) -> bool:
    return any(unicodedata.bidirectional(ch) in ("R", "AL", "AN") for ch in label)


def split(normalized: str) -> tuple[str, str]:
    """Local part and domain of a normalised address."""
    at = normalized.rfind("@")
    return normalized[:at], normalized[at + 1:]
