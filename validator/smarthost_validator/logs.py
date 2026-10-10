"""Structured logs (conventions: ts, level, service, msg + context) to stdout.

Never log secrets, and log recipient addresses only at debug level.
"""
from __future__ import annotations

import json
import logging
import sys
from datetime import datetime, tzinfo


class _JsonFormatter(logging.Formatter):
    def __init__(self, zone: tzinfo | None) -> None:
        super().__init__()
        self.zone = zone

    def format(self, record: logging.LogRecord) -> str:
        at = datetime.fromtimestamp(record.created, self.zone) if self.zone else datetime.fromtimestamp(record.created).astimezone()
        fields = {
            "ts": at.isoformat(timespec="milliseconds"),  # the installation's zone, e.g. 2026-10-10T10:48:56.123+02:00
            "level": record.levelname.lower(),
            "service": "validator",
            "msg": record.getMessage(),
        }
        fields.update(getattr(record, "ctx", {}) or {})
        if record.exc_info:
            fields["exception"] = self.formatException(record.exc_info).splitlines()[-1]
        return json.dumps(fields, default=str)


class _TextFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        ctx = getattr(record, "ctx", {}) or {}
        return f"{record.levelname} {record.getMessage()} {json.dumps(ctx, default=str) if ctx else ''}".rstrip()


def setup(level: str = "info", fmt: str = "json", zone: tzinfo | None = None) -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(_JsonFormatter(zone) if fmt == "json" else _TextFormatter())
    root = logging.getLogger()
    root.handlers[:] = [handler]
    root.setLevel(level.upper())
    for noisy in ("psycopg", "psycopg.pool", "asyncio"):
        logging.getLogger(noisy).setLevel("WARNING")


def log(level: str, msg: str, **ctx: object) -> None:
    logging.getLogger("smarthost.validator").log(getattr(logging, level.upper()), msg, extra={"ctx": ctx})
