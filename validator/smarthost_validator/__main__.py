"""Command line:

    python -m smarthost_validator run [--exit-when-idle] [--stats-file PATH]
    python -m smarthost_validator health        # container health check (heartbeat age)
    python -m smarthost_validator check-db      # role privilege probe (Phase 1 verification suite)
    python -m smarthost_validator normalize ADDRESS
"""
from __future__ import annotations

import argparse
import asyncio
import json
import os
import signal
import sys

from . import logs
from .config import Config, ConfigError


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="smarthost_validator")
    sub = parser.add_subparsers(dest="command", required=True)
    run = sub.add_parser("run")
    run.add_argument("--exit-when-idle", action="store_true", help="stop when no claimable work remains (tests)")
    run.add_argument("--stats-file", help="write worker statistics as JSON on exit")
    sub.add_parser("health")
    sub.add_parser("check-db")
    norm = sub.add_parser("normalize")
    norm.add_argument("address")
    args = parser.parse_args(argv)

    if args.command == "normalize":
        from .normalize import normalize
        print(json.dumps({"input": args.address, "normalized": normalize(args.address)}))
        return 0
    try:
        cfg = Config.from_env()
    except ConfigError as exc:
        print(json.dumps({"service": "validator", "level": "error", "msg": f"configuration error: {exc}"}), file=sys.stderr)
        return 78
    if args.command == "health":
        from .worker import heartbeat_age
        limit = 3 * cfg.poll_interval_seconds + 60
        return 0 if heartbeat_age() < limit else 1
    if args.command == "check-db":
        return asyncio.run(_check_db(cfg))
    logs.setup(cfg.log_level, cfg.log_format)
    return asyncio.run(_run(cfg, args.exit_when_idle, args.stats_file))


async def _run(cfg: Config, exit_when_idle: bool, stats_file: str | None) -> int:
    from psycopg_pool import AsyncConnectionPool

    from .worker import Worker

    async with AsyncConnectionPool(cfg.conninfo, min_size=1, max_size=4, open=False) as pool:
        await pool.wait(timeout=60)
        worker = Worker(cfg, pool)
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGTERM, signal.SIGINT):
            loop.add_signal_handler(sig, worker.stop)
        stats = await worker.run(exit_when_idle=exit_when_idle)
        if stats_file:
            with open(stats_file, "w") as fh:
                json.dump(stats.as_dict(worker.limits, worker.prober, worker.dns), fh)
    return 0


async def _check_db(cfg: Config) -> int:
    import psycopg

    async with await psycopg.AsyncConnection.connect(cfg.conninfo) as conn:
        user = (await (await conn.execute("SELECT current_user")).fetchone())[0]  # type: ignore[index]
        can_create = True
        try:
            async with conn.transaction():
                await conn.execute("CREATE TABLE smarthost_validator_privilege_probe (id int)")
                raise RuntimeError("rollback")
        except psycopg.errors.InsufficientPrivilege:
            can_create = False
        except RuntimeError:
            pass
    ok = not can_create
    print(f"role=validator connected_as={user} uid={os.getuid()} can_create_table={'yes' if can_create else 'no'} => {'OK' if ok else 'VIOLATION'}")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
