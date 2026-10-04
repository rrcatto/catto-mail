"""Structured logs (conventions: ts, level, service, msg + context) to stdout.

Never log secrets, and log recipient addresses only at debug level.
"""
from __future__ import annotations

import json
import logging
import sys
from datetime import UTC, datetime


class _JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        fields = {
            "ts": datetime.fromtimestamp(record.created, UTC).strftime("%Y-%m-%dT%H:%M:%S.%f")[:-3] + "Z",
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


def setup(level: str = "info", fmt: str = "json") -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(_JsonFormatter() if fmt == "json" else _TextFormatter())
    root = logging.getLogger()
    root.handlers[:] = [handler]
    root.setLevel(level.upper())
    for noisy in ("psycopg", "psycopg.pool", "asyncio"):
        logging.getLogger(noisy).setLevel("WARNING")


def log(level: str, msg: str, **ctx: object) -> None:
    logging.getLogger("smarthost.validator").log(getattr(logging, level.upper()), msg, extra={"ctx": ctx})
