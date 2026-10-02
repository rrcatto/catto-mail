"""Phase 1 infrastructure probe for the Python validator container.

Proves the image, unprivileged identity and database credentials of the
validator role. It implements NO validation logic (that is Phase 3).

    python phase1_probe.py serve      # heartbeat loop (unit's main process)
    python phase1_probe.py check-db   # connect as VALIDATOR_DB_USER; must NOT be able to create tables
"""
from __future__ import annotations

import json
import os
import signal
import sys
import time

import psycopg


def env(name: str) -> str:
    file_name = os.environ.get(f"{name}_FILE")
    value = open(file_name).read().strip() if file_name else os.environ.get(name, "")
    if not value:
        sys.exit(f"missing {name}")
    return value


def check_db() -> tuple[bool, str]:
    conninfo = psycopg.conninfo.make_conninfo(
        host=env("SMARTHOST_DB_HOST"), port=env("SMARTHOST_DB_PORT"), dbname=env("SMARTHOST_DB_NAME"),
        sslmode=env("SMARTHOST_DB_SSLMODE"), user=env("VALIDATOR_DB_USER"), password=env("VALIDATOR_DB_PASSWORD"),
        connect_timeout=5,
    )
    with psycopg.connect(conninfo) as conn:
        user = conn.execute("SELECT current_user").fetchone()[0]
        try:
            with conn.transaction():
                conn.execute("CREATE TABLE smarthost_phase1_privilege_probe (id int)")
                raise RuntimeError("rollback")
            can_create = True  # pragma: no cover
        except psycopg.errors.InsufficientPrivilege:
            can_create = False
        except RuntimeError:
            can_create = True
    ok = not can_create
    return ok, f"role=validator connected_as={user} uid={os.getuid()} can_create_table={'yes' if can_create else 'no'} => {'OK' if ok else 'VIOLATION'}"


def log(**fields: object) -> None:
    print(json.dumps({"service": "validator", **fields}), flush=True)


def serve() -> None:
    running = True

    def stop(*_: object) -> None:
        nonlocal running
        running = False

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    log(msg="phase 1 probe started (no validation logic)", uid=os.getuid())
    while running:
        try:
            ok, detail = check_db()
        except Exception as exc:  # report, keep running; health check shows failure
            ok, detail = False, repr(exc)
        log(msg="db check", ok=ok, detail=detail)
        for _ in range(60):
            if not running:
                break
            time.sleep(1)
    log(msg="stopping")


if __name__ == "__main__":
    command = sys.argv[1] if len(sys.argv) > 1 else "serve"
    if command == "check-db":
        ok, detail = check_db()
        print(detail)
        sys.exit(0 if ok else 1)
    serve()
