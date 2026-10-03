"""Role-account detection (pipeline stage 6). A risk signal, never a rejection.

The local part is compared case-insensitively after removing a "+tag"
subaddress. This comparison is analysis only; it never changes the stored
normalised address (D-18/D-32 keep the local part byte-for-byte).
"""
from __future__ import annotations

ROLE_LOCAL_PARTS: frozenset[str] = frozenset({
    "abuse", "accounts", "admin", "administrator", "billing", "careers", "contact", "enquiries",
    "finance", "help", "helpdesk", "hostmaster", "hr", "info", "inquiries", "it", "jobs", "mail",
    "marketing", "media", "newsletter", "no-reply", "noc", "noreply", "office", "postmaster",
    "press", "privacy", "root", "sales", "security", "support", "team", "webmaster",
})


def is_role(local: str) -> bool:
    if local.startswith('"'):
        return False
    return local.split("+", 1)[0].lower() in ROLE_LOCAL_PARTS
