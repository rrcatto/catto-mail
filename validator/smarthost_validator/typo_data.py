"""Explicit, reviewable data for typo suggestions (D-04). No hidden behaviour:
every suggestion comes from one of these tables plus the documented rules in
typo.py. Extend by adding entries here, with a test.
"""

# Large public mailbox providers (exact registrable domains).
PROVIDER_DOMAINS: frozenset[str] = frozenset({
    "gmail.com", "googlemail.com", "yahoo.com", "yahoo.co.uk", "yahoo.fr", "yahoo.de", "ymail.com",
    "hotmail.com", "hotmail.co.uk", "hotmail.fr", "hotmail.de", "outlook.com", "live.com", "msn.com",
    "icloud.com", "me.com", "mac.com", "aol.com", "gmx.com", "gmx.de", "gmx.net", "web.de",
    "protonmail.com", "proton.me", "mail.com", "email.com", "yandex.ru", "zoho.com", "fastmail.com",
    "comcast.net", "verizon.net", "att.net", "sbcglobal.net", "btinternet.com", "orange.fr",
    "free.fr", "t-online.de", "qq.com", "163.com", "naver.com", "mail.ru",
})

# Explicitly maintained legacy aliases: old domain -> current domain. Both still
# work; the suggestion is informational (confidence low).
LEGACY_ALIASES: dict[str, str] = {
    "googlemail.com": "gmail.com",
}

# Top-level labels that are not real TLDs and are common slips -> intended TLD.
FAKE_TLD_TYPOS: dict[str, str] = {
    "con": "com", "cmo": "com", "ocm": "com", "vom": "com", "xom": "com", "comm": "com", "coom": "com",
    "cpm": "com", "cim": "com", "c0m": "com", "nte": "net", "nett": "net", "ogr": "org", "rog": "org",
    "orgg": "org",
}

# Real TLDs that are frequent slips for ".com" on a provider domain (gmail.co).
# Because these TLDs exist, the confidence is lower.
REAL_TLD_SLIPS_FOR_COM: frozenset[str] = frozenset({"co", "cm", "om"})
