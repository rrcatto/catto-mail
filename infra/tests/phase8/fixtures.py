"""Shared fixtures for the Phase 8 production-tooling tests (no network)."""
from __future__ import annotations

import base64
import os
import struct
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "infra/lib"))

import smarthost_preflight as pf  # noqa: E402
import smarthost_render as render  # noqa: E402

PUBLIC_IP = "100.42.42.42"   # any globally routable address (never contacted: DNS and hosts are fixtures)


def production_values(**overrides: str) -> dict[str, str]:
    """A complete, valid production configuration (fictitious but non-reserved names)."""
    values = {r["name"]: r["example"] for r in render.contract()}
    values.update(render.production_profile())
    for r in render.contract():
        if r["secret"]:
            values[r["name"]] = "s3cret-" + r["name"].lower() + "-0123456789"
    values.update({
        "APP_ENCRYPTION_KEYS": "prod1:" + base64.b64encode(os.urandom(32)).decode(),
        "SMARTHOST_PUBLIC_BASE_URL": "https://mail.cattomail-ops.net",
        "PROXY_SERVER_NAME": "mail.cattomail-ops.net",
        "POSTFIX_MYHOSTNAME": "mta1.cattomail-ops.net",
        "SMARTHOST_BOUNCE_DOMAIN": "bounce.cattomail-ops.net",
        "VALIDATOR_SMTP_HELO_HOSTNAME": "mta1.cattomail-ops.net",
        "VALIDATOR_SMTP_MAIL_FROM": "validator@bounce.cattomail-ops.net",
        "APP_ADMIN_EMAIL": "operator@cattomail-ops.net",
        "APP_MAIL_FROM": "no-reply@cattomail-ops.net",
        "SMARTHOST_PUBLIC_IPV4": PUBLIC_IP,
    })
    values.update(overrides)
    return values


# ------------------------------------------------------------------ DNS wire
def encode_name(name: str) -> bytes:
    return b"".join(bytes([len(x)]) + x.encode() for x in name.rstrip(".").split(".") if x) + b"\0"


def rdata(rtype: str, value: object) -> bytes:
    if rtype == "A":
        return bytes(int(x) for x in str(value).split("."))
    if rtype in ("PTR", "CNAME", "NS"):
        return encode_name(str(value))
    if rtype == "MX":
        assert isinstance(value, tuple)
        pref, host = value
        return struct.pack(">H", int(pref)) + encode_name(str(host))
    if rtype == "TXT":
        parts = [str(p) for p in value] if isinstance(value, list) else [str(value)]
        return b"".join(bytes([len(p.encode())]) + p.encode() for p in parts)
    raise ValueError(rtype)


def response(query: bytes, answers: list[tuple[str, str, object]], rcode: int = 0, tc: bool = False, compress: bool = False) -> bytes:
    qid = struct.unpack(">H", query[:2])[0]
    flags = 0x8180 | rcode | (0x0200 if tc else 0)
    question = query[12:]
    out = struct.pack(">HHHHHH", qid, flags, 1, len(answers), 0, 0) + question
    for owner, rtype, value in answers:
        name = b"\xc0\x0c" if compress else encode_name(owner)
        rd = rdata(rtype, value)
        out += name + struct.pack(">HHIH", pf.QTYPES[rtype], 1, 300, len(rd)) + rd
    return out


class FakeResolver(pf.Resolver):
    """Answers from a zone dict {(name, type): [values]}; DnsError for names in `broken`."""

    def __init__(self, zone: dict[tuple[str, str], list], broken: set[str] | None = None) -> None:
        super().__init__(servers=["192.0.2.53"])
        self.zone = {(k[0].lower().rstrip("."), k[1]): v for k, v in zone.items()}
        self.broken = broken or set()
        self.queries: list[tuple[str, str]] = []

    def query(self, name: str, qtype: str) -> list:
        n = name.lower().rstrip(".")
        self.queries.append((n, qtype))
        if n in self.broken:
            raise pf.DnsError(f"{qtype} {n}: SERVFAIL")
        return list(self.zone.get((n, qtype), []))


class FakeHost(pf.Host):
    """podman/psql/postconf answers from fixtures; never touches the machine."""

    def __init__(self, values: dict[str, str], *, sql: dict[str, list[list[str]]] | None = None,
                 exec_: dict[tuple[str, ...], tuple[int, str]] | None = None, inspect: dict[str, dict] | None = None,
                 networks: dict[str, str] | None = None, remote: bool = True) -> None:
        super().__init__(values)
        self.sql_answers = sql or {}
        self.exec_answers = exec_ or {}
        self.inspect_answers = inspect or {}
        self.networks = networks or {}
        self.remote = remote

    def podman_remote(self) -> bool:
        return self.remote

    def inspect(self, name: str) -> dict | None:
        return self.inspect_answers.get(name)

    def exec(self, service: str, *cmd: str, user: str | None = None) -> tuple[int, str]:
        for key, answer in self.exec_answers.items():
            if (service, *cmd)[:len(key)] == key:
                return answer
        return 1, f"no fixture for {service} {' '.join(cmd)}"

    def sql(self, query: str) -> list[list[str]]:
        for needle, rows in self.sql_answers.items():
            if needle in query:
                return rows
        return []

    def run(self, *cmd: str, input_: bytes | None = None, timeout: float = 60) -> tuple[int, str]:
        if cmd[:3] == ("podman", "network", "inspect"):
            return (0, self.networks[cmd[3]]) if cmd[3] in self.networks else (125, "no such network")
        return 127, "not available in tests"
