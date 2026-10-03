import pytest

from smarthost_validator import typo_data
from smarthost_validator.typo import suggest_address, suggest_domain


@pytest.mark.parametrize("domain,expected,reason,confidence", [
    ("gmail.com", None, None, None),
    ("example.org", None, None, None),
    ("googlemail.com", "gmail.com", "legacy_domain_alias", "low"),
    ("gmailcom", "gmail.com", "other_domain_typo_rule", "high"),
    ("hotmail", "hotmail.com", "other_domain_typo_rule", "medium"),
    ("gmail.con", "gmail.com", "tld_typo", "high"),
    ("yahoo.cmo", "yahoo.com", "tld_typo", "high"),
    ("mycompany.con", "mycompany.com", "tld_typo", "medium"),
    ("gmail.co", "gmail.com", "tld_typo", "medium"),
    ("gmial.com", "gmail.com", "transposition", "high"),
    ("hotmial.com", "hotmail.com", "transposition", "high"),
    ("gmal.com", "gmail.com", "provider_domain_edit_distance", "medium"),
    ("hotmaill.com", "hotmail.com", "provider_domain_edit_distance", "medium"),
    ("outlookk.comm", "outlookk.com", "tld_typo", "medium"),
    ("aon.com", None, None, None),     # short providers (aol.com) are excluded from edit distance
    ("email.com", None, None, None),   # ... and mail.com too
])
def test_suggestions(domain, expected, reason, confidence):
    s = suggest_domain(domain)
    if expected is None:
        assert s is None
    else:
        assert (s.domain, s.reason_code, s.confidence) == (expected, reason, confidence)


def test_suggested_address_keeps_the_local_part():
    address, s = suggest_address("John.Smith+x", "gmial.com")
    assert address == "John.Smith+x@gmail.com" and s.reason_code == "transposition"


def test_dataset_is_explicit_and_consistent():
    assert all(d == d.lower() and "." in d for d in typo_data.PROVIDER_DOMAINS)
    assert all(target in typo_data.PROVIDER_DOMAINS for target in typo_data.LEGACY_ALIASES.values())
    assert set(typo_data.FAKE_TLD_TYPOS.values()) <= {"com", "net", "org"}
    assert all(suggest_domain(p) is None or p in typo_data.LEGACY_ALIASES for p in typo_data.PROVIDER_DOMAINS)


def test_confidence_is_categorical():
    for d in ["gmial.com", "gmal.com", "gmail.con", "googlemail.com", "gmail.co"]:
        assert suggest_domain(d).confidence in ("low", "medium", "high")
