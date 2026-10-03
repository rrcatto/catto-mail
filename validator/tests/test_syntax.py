import pytest

from smarthost_validator.syntax import analyse


@pytest.mark.parametrize("address,valid,code", [
    ("john@example.com", True, "syntax.valid"),
    ("  John.Smith+tag@Example.COM ", True, "syntax.valid"),
    ("o'reilly@example.co.uk", True, "syntax.valid"),
    ('"john doe"@example.com', True, "syntax.valid"),
    ('"a@b"@example.com', True, "syntax.valid"),
    ("jöhn@example.com", True, "syntax.valid_eai"),
    ("john@[192.0.2.1]", True, "syntax.address_literal"),
    ("john@[IPv6:2001:db8::1]", True, "syntax.address_literal"),
    ("john@bücher.example", True, "syntax.valid"),
    ("johnexample.com", False, "syntax.missing_at"),
    ("@example.com", False, "syntax.empty_local_part"),
    ("john@", False, "syntax.empty_domain"),
    ("john@-badé.example", False, "syntax.invalid_idn_domain"),
    (".john@example.com", False, "syntax.local_part_dots"),
    ("john.@example.com", False, "syntax.local_part_dots"),
    ("jo..hn@example.com", False, "syntax.local_part_dots"),
    ("jo hn@example.com", False, "syntax.invalid_local_part"),
    ("a@b@example.com", False, "syntax.invalid_local_part"),
    ('"unterminated@example.com', False, "syntax.invalid_quoted_local_part"),
    ("x" * 65 + "@example.com", False, "syntax.local_part_too_long"),
    ("john@localhost", False, "syntax.single_label_domain"),
    ("john@example..com", False, "syntax.domain_empty_label"),
    ("john@example.com.", False, "syntax.domain_trailing_dot"),
    ("john@exa_mple.com", False, "syntax.invalid_domain_label"),
    ("john@-example.com", False, "syntax.invalid_domain_label"),
    ("john@example.123", False, "syntax.numeric_tld"),
    ("john@[999.1.1.1]", False, "syntax.invalid_address_literal"),
    ("a@" + ".".join(["a" * 63] * 4), False, "syntax.address_too_long"),
])
def test_syntax(address, valid, code):
    r = analyse(address)
    assert (r.valid, r.code) == (valid, code)
    assert r.status == ("valid" if valid else "invalid")
    assert r.text  # never a silent verdict


def test_syntax_does_not_modify_the_local_part():
    r = analyse("  MiXeD.Case@Example.Org ")
    assert r.local == "MiXeD.Case" and r.domain == "example.org"
