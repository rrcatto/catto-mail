"""Smarthost Python validation worker (Phase 3).

Claims validation addresses from PostgreSQL under a lease, evaluates them
(normalisation, syntax, typo, DNS, disposable, role, optional SMTP RCPT probe -
never DATA), classifies them conservatively and writes the results, evidence,
job counters, usage and the outbox event in fenced transactions. It has no
public API and runs no DDL.
"""

__version__ = "0.2.2"
