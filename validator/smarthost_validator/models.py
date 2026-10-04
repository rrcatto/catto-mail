"""Plain data passed between the worker stages (no I/O)."""
from __future__ import annotations

from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any


def utcnow() -> datetime:
    return datetime.now(UTC)


@dataclass(frozen=True)
class Evidence:
    """One validation_evidence row (append-only). `evidence_type` is a vocabulary value."""

    evidence_type: str
    detail: dict[str, Any] = field(default_factory=dict)
    provider_host: str | None = None
    response_code: int | None = None
    enhanced_status_code: str | None = None
    occurred_at: datetime = field(default_factory=utcnow)


@dataclass(frozen=True)
class ClaimedAddress:
    id: str
    job_id: str
    client_id: str
    original_address: str
    attempt_count: int  # including the current claim


@dataclass(frozen=True)
class FinalResult:
    """A `done` result. Every status column is set; skipped stages say `skipped`."""

    address_id: str
    job_id: str
    client_id: str
    normalized_address: str | None
    syntax_status: str
    domain_status: str
    smtp_status: str
    is_role: bool | None
    is_disposable: bool | None
    is_catch_all_or_accept_all: bool | None
    is_domain_typo_suspected: bool | None
    suggested_address: str | None
    suggestion_reason_code: str | None
    suggestion_confidence: str | None
    overall_classification: str
    confidence: str
    diagnostic_code: str
    diagnostic_text: str
    evidence: tuple[Evidence, ...] = ()


@dataclass(frozen=True)
class RetryResult:
    """A temporary condition: try again at `next_attempt_at` (status columns stay NULL)."""

    address_id: str
    job_id: str
    next_attempt_at: datetime
    last_error: str
    evidence: tuple[Evidence, ...] = ()
