"""Database fixtures for the db-marked tests (run by infra/tests/phase3-test.sh).

Rows are created with the schema owner (test fixtures only); the code under test
always uses the least-privilege validator role.
"""
from __future__ import annotations

import os
import uuid

import psycopg


def env(name: str) -> str:
    value = os.environ.get(name, "")
    if not value:
        import pytest
        pytest.skip(f"{name} not set (database tests run in infra/tests/phase3-test.sh)")
    return value


def conninfo(user_var: str, password_var: str) -> str:
    return psycopg.conninfo.make_conninfo(host=env("SMARTHOST_DB_HOST"), port=env("SMARTHOST_DB_PORT"),
                                          dbname=env("SMARTHOST_DB_NAME"), user=env(user_var), password=env(password_var))


def owner() -> psycopg.Connection:
    return psycopg.connect(conninfo("SMARTHOST_DB_OWNER_USER", "SMARTHOST_DB_OWNER_PASSWORD"), autocommit=True)


def validator_conninfo() -> str:
    return conninfo("VALIDATOR_DB_USER", "VALIDATOR_DB_PASSWORD")


def make_job(addresses: list[str], status: str = "active") -> tuple[str, str]:
    client, job = str(uuid.uuid7()), str(uuid.uuid7())
    with owner() as c:
        c.execute("INSERT INTO clients (id, company_name, contact_email, plan, status) VALUES (%s, 'py-test', 'p@x.test', 'test', %s)",
                  (client, status))
        c.execute("INSERT INTO validation_jobs (id, client_id, idempotency_key, request_hash, total_addresses) VALUES (%s, %s, %s, 'h', %s)",
                  (job, client, str(uuid.uuid7()), len(addresses)))
        for a in addresses:
            c.execute("INSERT INTO validation_addresses (id, job_id, original_address) VALUES (%s, %s, %s)", (str(uuid.uuid7()), job, a))
    return client, job


def q(sql: str, *params: object) -> list[tuple]:
    with owner() as c:
        return c.execute(sql, params).fetchall()


def one(sql: str, *params: object):  # type: ignore[no-untyped-def]
    return q(sql, *params)[0][0]
