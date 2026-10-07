#!/usr/bin/env python3
"""catto-mail production preflight (Phase 8, specification 2.9). Stdlib only.

    smarthostctl prod preflight [--activation] [--section S] [--json] [--quiet]
                                [--smtp-egress-probe DOMAIN] [--resolver IP]
    smarthostctl prod dns-checklist
    smarthostctl prod firewall

Every check reports PASS, WARN, FAIL, INFO or SKIP. The exit status is 1 when any
check FAILs. `--activation` is the gate of `smarthostctl prod live-enable`: the
checks that live Internet delivery depends on (DNS identity, TLS, DKIM, DMARC, the
bounce domain, Postfix) must PASS; a WARN on such a check becomes a FAIL.

Sections: config, host, runtime, exposure, ingress, tls, dns, dkim, postfix, delivery.

`host` holds the bootstrap prerequisites (runbook §1); `smarthostctl prod` checks it
before it creates or starts containers. `ingress` proves on this host that nginx and
Postfix see each client's own address: it connects from two random loopback
source addresses through the HTTPS and SMTP binds and finds both, distinct, in the
nginx access log and the Postfix log (`smarthostctl prod ingress-check`).

Nothing here sends mail or changes anything. The SMTP checks open a connection to
the local Postfix and stop at RCPT (relay refusal, bounce-domain acceptance) or at
EHLO (ingress probe); `--smtp-egress-probe` asks Postfix's posttls-finger to EHLO an
outside MX and QUIT.
"""
from __future__ import annotations

import argparse
import datetime as dt
import ipaddress
import json
import os
import random
import re
import shutil
import smtplib
import socket
import ssl
import struct
import subprocess
import sys
import time
from collections.abc import Callable
from dataclasses import asdict, dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
import smarthost_render as render  # noqa: E402

LEVELS = ("PASS", "WARN", "FAIL", "INFO", "SKIP")
SERVICES = ("postgres", "opendkim", "postfix", "symfony-app", "webhook-worker", "nginx", "validator", "delivery")
# Production network matrix (docs/production/README.md): which services join the egress
# and the ingress network. No container publishes a port: the ingress socket is systemd's.
EGRESS_SERVICES = {"postfix", "symfony-app", "webhook-worker", "validator"}
INGRESS_SERVICES = {"nginx", "postfix"}
SYSCTL_PORTS = "net.ipv4.ip_unprivileged_port_start"
SYSCTL_FIX = ("echo 'net.ipv4.ip_unprivileged_port_start=25' | sudo tee /etc/sysctl.d/60-catto-mail-ports.conf"
              " && sudo sysctl --system")
MIN_PODMAN = (4, 9)
CERT_WARN_DAYS = 14


# =========================================================================== DNS
class DnsError(Exception):
    """The name could not be resolved (timeout, SERVFAIL, refused): not an answer."""


QTYPES = {"A": 1, "NS": 2, "CNAME": 5, "PTR": 12, "MX": 15, "TXT": 16, "AAAA": 28}


def build_query(qid: int, name: str, qtype: str) -> bytes:
    labels = [lab.encode("idna") for lab in name.rstrip(".").split(".") if lab]
    qname = b"".join(bytes([len(lab)]) + lab for lab in labels) + b"\0"
    return struct.pack(">HHHHHH", qid, 0x0100, 1, 0, 0, 0) + qname + struct.pack(">HH", QTYPES[qtype], 1)


def read_name(msg: bytes, off: int) -> tuple[str, int]:
    labels: list[str] = []
    jumped, end, hops = False, off, 0
    while True:
        if off >= len(msg):
            raise DnsError("truncated name")
        n = msg[off]
        if n & 0xC0 == 0xC0:
            if hops > 32:
                raise DnsError("compression loop")
            ptr = ((n & 0x3F) << 8) | msg[off + 1]
            if not jumped:
                end = off + 2
            off, jumped, hops = ptr, True, hops + 1
            continue
        if n == 0:
            if not jumped:
                end = off + 1
            return ".".join(labels), end
        labels.append(msg[off + 1:off + 1 + n].decode("ascii", "replace"))
        off += 1 + n


def parse_response(msg: bytes, qid: int) -> tuple[int, bool, list[tuple[str, int, object]]]:
    """(rcode, truncated, answers) where an answer is (owner, type, parsed rdata)."""
    if len(msg) < 12:
        raise DnsError("short response")
    rid, flags, qd, an, _ns, _ar = struct.unpack(">HHHHHH", msg[:12])
    if rid != qid:
        raise DnsError("response id mismatch")
    off = 12
    for _ in range(qd):
        _, off = read_name(msg, off)
        off += 4
    answers = []
    for _ in range(an):
        owner, off = read_name(msg, off)
        rtype, _cls, _ttl, rdlen = struct.unpack(">HHIH", msg[off:off + 10])
        off += 10
        rdata = msg[off:off + rdlen]
        if rtype == 1 and rdlen == 4:
            value: object = str(ipaddress.IPv4Address(rdata))
        elif rtype == 28 and rdlen == 16:
            value = str(ipaddress.IPv6Address(rdata))
        elif rtype in (2, 5, 12):
            value = read_name(msg, off)[0]
        elif rtype == 15:
            value = (struct.unpack(">H", rdata[:2])[0], read_name(msg, off + 2)[0])
        elif rtype == 16:
            parts, i = [], 0
            while i < len(rdata):
                ln = rdata[i]
                parts.append(rdata[i + 1:i + 1 + ln].decode("utf-8", "replace"))
                i += 1 + ln
            value = "".join(parts)
        else:
            value = rdata
        answers.append((owner.lower().rstrip("."), rtype, value))
        off += rdlen
    return flags & 0x000F, bool(flags & 0x0200), answers


def system_nameservers() -> list[str]:
    try:
        lines = Path("/etc/resolv.conf").read_text(encoding="utf-8").splitlines()
    except OSError:
        lines = []
    servers = [ln.split()[1] for ln in lines if ln.startswith("nameserver") and len(ln.split()) > 1]
    return servers or ["127.0.0.53"]


def udp_tcp_transport(server: str, payload: bytes, tcp: bool, timeout: float) -> bytes:
    family = socket.AF_INET6 if ":" in server else socket.AF_INET
    if not tcp:
        with socket.socket(family, socket.SOCK_DGRAM) as s:
            s.settimeout(timeout)
            s.sendto(payload, (server, 53))
            return s.recvfrom(65535)[0]
    with socket.create_connection((server, 53), timeout=timeout) as s:
        s.sendall(struct.pack(">H", len(payload)) + payload)
        head = s.recv(2)
        want = struct.unpack(">H", head)[0]
        data = b""
        while len(data) < want:
            chunk = s.recv(want - len(data))
            if not chunk:
                break
            data += chunk
        return data


class Resolver:
    """A minimal recursive-resolver client (the host's resolver does the recursion)."""

    def __init__(self, servers: list[str] | None = None, timeout: float = 3.0,
                 transport: Callable[[str, bytes, bool, float], bytes] = udp_tcp_transport) -> None:
        self.servers = servers or system_nameservers()
        self.timeout = timeout
        self.transport = transport

    def query(self, name: str, qtype: str) -> list:
        want = QTYPES[qtype]
        errors = []
        for server in self.servers:
            qid = random.randrange(1, 65535)
            payload = build_query(qid, name, qtype)
            try:
                rcode, tc, answers = parse_response(self.transport(server, payload, False, self.timeout), qid)
                if tc:
                    rcode, tc, answers = parse_response(self.transport(server, payload, True, self.timeout), qid)
            except (OSError, DnsError, struct.error) as exc:
                errors.append(f"{server}: {exc}")
                continue
            if rcode == 3:   # NXDOMAIN
                return []
            if rcode != 0:
                errors.append(f"{server}: rcode {rcode}")
                continue
            return [value for _owner, rtype, value in answers if rtype == want]
        raise DnsError(f"{qtype} {name}: " + "; ".join(errors))


def reverse_name(ip: str) -> str:
    return ipaddress.ip_address(ip).reverse_pointer


# =========================================================================== SPF
@dataclass
class SpfResult:
    result: str            # pass fail softfail neutral none permerror temperror unsupported
    mechanism: str = ""
    detail: str = ""


def spf_records(resolver: Resolver, domain: str) -> list[str]:
    return [t for t in resolver.query(domain, "TXT") if re.match(r"(?i)^v=spf1(\s|$)", t)]


def spf_check(ip: str, domain: str, resolver: Resolver, _state: dict | None = None) -> SpfResult:
    """RFC 7208 evaluation of the IPv4 address `ip` for `domain` (no macros, no ptr)."""
    state = _state if _state is not None else {"lookups": 0}
    addr = ipaddress.IPv4Address(ip)
    try:
        records = spf_records(resolver, domain)
    except DnsError as exc:
        return SpfResult("temperror", detail=str(exc))
    if not records:
        return SpfResult("none", detail=f"no SPF record at {domain}")
    if len(records) > 1:
        return SpfResult("permerror", detail=f"{len(records)} SPF records at {domain}")
    terms = records[0].split()[1:]
    redirect = None
    qualifiers = {"+": "pass", "-": "fail", "~": "softfail", "?": "neutral"}

    def count() -> bool:
        state["lookups"] += 1
        return state["lookups"] <= 10

    def in_net(addrs: list[str], cidr: int) -> bool:
        return any(addr in ipaddress.IPv4Network(f"{a}/{cidr}", strict=False) for a in addrs)

    for term in terms:
        if "=" in term.split(":")[0]:
            key, _, val = term.partition("=")
            if key.lower() == "redirect":
                redirect = val
            continue
        q = term[0] if term[0] in qualifiers else "+"
        mech = term[1:] if term[0] in qualifiers else term
        if "%" in mech:
            return SpfResult("unsupported", term, "SPF macros are not evaluated by the preflight")
        m = re.match(r"^([A-Za-z0-9]+)(?::([^/]+))?(?:/(\d+))?(?://\d+)?$", mech)
        if not m:
            return SpfResult("permerror", term, "malformed mechanism")
        name, arg, cidr = m.group(1).lower(), m.group(2) or "", int(m.group(3) or 32)
        target = arg or domain
        try:
            if name == "all":
                return SpfResult(qualifiers[q], term)
            if name == "ip4":
                if addr in ipaddress.IPv4Network(f"{arg}/{cidr}", strict=False):
                    return SpfResult(qualifiers[q], term)
            elif name == "ip6":
                continue
            elif name == "a":
                if not count():
                    return SpfResult("permerror", term, "more than 10 DNS lookups")
                if in_net(resolver.query(target, "A"), cidr):
                    return SpfResult(qualifiers[q], term)
            elif name == "mx":
                if not count():
                    return SpfResult("permerror", term, "more than 10 DNS lookups")
                hosts = [h for _p, h in sorted(resolver.query(target, "MX"))][:10]
                if any(in_net(resolver.query(h, "A"), cidr) for h in hosts):
                    return SpfResult(qualifiers[q], term)
            elif name == "include":
                if not count():
                    return SpfResult("permerror", term, "more than 10 DNS lookups")
                inner = spf_check(ip, arg, resolver, state)
                if inner.result == "pass":
                    return SpfResult(qualifiers[q], term, f"via include:{arg}")
                if inner.result in ("temperror", "unsupported"):
                    return inner
                if inner.result in ("permerror", "none"):
                    return SpfResult("permerror", term, f"include:{arg}: {inner.result}")
            elif name == "exists":
                if not count():
                    return SpfResult("permerror", term, "more than 10 DNS lookups")
                if resolver.query(arg, "A"):
                    return SpfResult(qualifiers[q], term)
            elif name == "ptr":
                if not count():
                    return SpfResult("permerror", term, "more than 10 DNS lookups")
                continue  # deprecated (RFC 7208 5.5): not relied on
            else:
                return SpfResult("permerror", term, f"unknown mechanism {name}")
        except DnsError as exc:
            return SpfResult("temperror", term, str(exc))
        except ValueError as exc:
            return SpfResult("permerror", term, str(exc))
    if redirect:
        if not count():
            return SpfResult("permerror", f"redirect={redirect}", "more than 10 DNS lookups")
        return spf_check(ip, redirect, resolver, state)
    return SpfResult("neutral", detail="no mechanism matched")


# ================================================================= DKIM / DMARC
def tags(record: str) -> dict[str, str]:
    out = {}
    for part in record.split(";"):
        key, sep, val = part.partition("=")
        if sep:
            out[key.strip().lower()] = re.sub(r"\s+", "", val) if key.strip().lower() == "p" else val.strip()
    return out


def dkim_public_key(txts: list[str]) -> str | None:
    """The p= value of the DKIM record among the TXT strings (None without one)."""
    for t in txts:
        tg = tags(t)
        if "p" in tg and tg.get("v", "DKIM1").upper() == "DKIM1":
            return tg["p"]
    return None


def dmarc_policy(txts: list[str]) -> dict[str, str] | None:
    recs = [t for t in txts if re.match(r"(?i)^v=DMARC1(\s*;|$)", t.strip())]
    if len(recs) != 1:
        return None
    return tags(recs[0])


def org_domain(domain: str) -> str:
    """Approximate organisational domain (the last two labels; no public suffix list)."""
    labels = domain.rstrip(".").split(".")
    return ".".join(labels[-2:])


# ========================================================================== TLS
@dataclass
class CertInfo:
    subject: str
    issuer: str
    not_before: dt.datetime
    not_after: dt.datetime
    names: list[str]


def cert_info(der: bytes | None) -> CertInfo:
    if not der:
        raise ValueError("no certificate was presented")
    out = subprocess.run(["openssl", "x509", "-inform", "DER", "-noout", "-subject", "-issuer", "-startdate", "-enddate",
                          "-ext", "subjectAltName"], input=der, capture_output=True, check=True).stdout.decode()

    def field_(key: str) -> str:
        m = re.search(rf"^{key}=(.*)$", out, re.M)
        if m is None:
            raise ValueError(f"openssl did not report {key}")
        return m.group(1).strip()

    def date(key: str) -> dt.datetime:
        raw = field_(key)
        return dt.datetime.strptime(re.sub(r"\s+", " ", raw.strip()), "%b %d %H:%M:%S %Y %Z").replace(tzinfo=dt.UTC)

    subject, issuer = field_("subject"), field_("issuer")
    names = re.findall(r"DNS:([^,\s]+)", out)
    cn = re.search(r"CN\s*=\s*([^,/]+)", subject)
    if not names and cn:
        names = [cn.group(1).strip()]
    return CertInfo(subject, issuer, date("notBefore"), date("notAfter"), [n.lower() for n in names])


def name_matches(host: str, names: list[str]) -> bool:
    host = host.lower().rstrip(".")
    for n in names:
        if n == host or (n.startswith("*.") and host.count(".") == n.count(".") and host.endswith(n[1:])):
            return True
    return False


def fetch_https_cert(addr: str, port: int, sni: str, timeout: float = 10) -> tuple[bytes | None, str | None]:
    """(DER certificate, None when the chain and name verify, else the verification error)."""
    ctx = ssl.create_default_context()
    ctx.check_hostname, ctx.verify_mode = False, ssl.CERT_NONE
    with socket.create_connection((addr, port), timeout=timeout) as raw, ctx.wrap_socket(raw, server_hostname=sni) as s:
        der = s.getpeercert(binary_form=True)
    try:
        with socket.create_connection((addr, port), timeout=timeout) as raw, \
                ssl.create_default_context().wrap_socket(raw, server_hostname=sni):
            pass
        return der, None
    except ssl.SSLError as exc:
        return der, str(exc)


def fetch_smtp_cert(addr: str, port: int, sni: str, timeout: float = 15) -> tuple[bytes | None, str | None, str]:
    """(DER certificate, verification error or None, banner) via STARTTLS; never sends mail."""
    ctx = ssl.create_default_context()
    ctx.check_hostname, ctx.verify_mode = False, ssl.CERT_NONE
    with smtplib.SMTP(addr, port, local_hostname="preflight.invalid", timeout=timeout) as s:
        banner = (s.ehlo_resp or b"").decode(errors="replace")
        s.starttls(context=ctx)
        sock = s.sock
        der = sock.getpeercert(binary_form=True) if isinstance(sock, ssl.SSLSocket) else None
    try:
        with smtplib.SMTP(addr, port, local_hostname="preflight.invalid", timeout=timeout) as s:
            s.starttls(context=ssl.create_default_context())
        return der, None, banner
    except (ssl.SSLError, smtplib.SMTPException) as exc:
        return der, str(exc), banner


# ======================================================================= grants
def grant_matrix(schema_md: str) -> dict[str, dict[str, set[str]]]:
    """schema.md §6: table -> {app, webhook, validator, delivery} -> {S, I, U, D}."""
    section = schema_md.split("## 6.", 1)[1].split("\n## ", 1)[0]
    roles = ("app", "webhook", "validator", "delivery")
    out: dict[str, dict[str, set[str]]] = {}
    for line in section.splitlines():
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        if len(cells) != 5 or cells[0] in ("Table", "") or set(cells[0]) <= {"-"}:
            continue
        for table in (t.strip() for t in cells[0].split(",")):
            out[table] = {r: set(re.findall(r"[SIUD]", c)) for r, c in zip(roles, cells[1:], strict=True)}
    return out


# ========================================================================= host
class Host:
    """Everything the preflight learns from the machine (overridable in tests)."""

    def __init__(self, values: dict[str, str]):
        self.v = values
        self.i = values["SMARTHOST_INSTANCE"]

    def run(self, *cmd: str, input_: bytes | None = None, timeout: float = 60) -> tuple[int, str]:
        try:
            p = subprocess.run(cmd, input=input_, capture_output=True, timeout=timeout)
            return p.returncode, (p.stdout + p.stderr).decode(errors="replace")
        except (OSError, subprocess.TimeoutExpired) as exc:
            return 127, str(exc)

    def podman_remote(self) -> bool:
        """True when the Podman engine runs elsewhere (e.g. a WSL Podman machine). The user's own API
        socket on this host (CONTAINER_HOST of the production units) is local."""
        runtime = os.environ.get("XDG_RUNTIME_DIR")
        if runtime and os.environ.get("CONTAINER_HOST") == f"unix://{runtime}/podman/podman.sock":
            return False
        return self.run("podman", "info", "--format", "{{.Host.ServiceIsRemote}}")[1].strip() == "true"

    def systemctl(self, *args: str) -> tuple[int, str] | None:
        """systemctl --user on the Podman engine's host; None when it cannot be reached."""
        hostctl = os.environ.get("SMARTHOST_HOSTCTL")
        if hostctl:
            return self.run(hostctl, "machine", "systemctl", *args)
        if self.podman_remote():
            return None
        return self.run("systemctl", "--user", *args)

    def sysctl(self, name: str) -> str | None:
        try:
            return Path("/proc/sys/" + name.replace(".", "/")).read_text().strip()
        except OSError:
            return None

    def sysctl_persisted(self, name: str) -> list[str]:
        """Lines of the sysctl.d configuration that set `name` (later files win)."""
        found = []
        for d in ("/usr/lib/sysctl.d", "/run/sysctl.d", "/etc/sysctl.d"):
            for f in sorted(Path(d).glob("*.conf")) if Path(d).is_dir() else []:
                found += [f"{f}: {ln.strip()}" for ln in f.read_text(errors="replace").splitlines()
                          if re.match(rf"^\s*{re.escape(name)}\s*=", ln)]
        etc = Path("/etc/sysctl.conf")
        if etc.is_file():
            found += [f"{etc}: {ln.strip()}" for ln in etc.read_text(errors="replace").splitlines()
                      if re.match(rf"^\s*{re.escape(name)}\s*=", ln)]
        return found

    def logs(self, service: str, since: str) -> str:
        return self.run("podman", "logs", "--since", since, f"{self.i}-{service}")[1]

    def started_at(self, service: str) -> str | None:
        rc, out = self.run("podman", "inspect", f"{self.i}-{service}", "--format",
                           '{{.State.StartedAt.Format "2006-01-02T15:04:05Z07:00"}}')
        return out.strip() if rc == 0 and out.strip() else None

    def https_probe(self, source: str, addr: str, port: int, sni: str, path: str) -> str:
        """GET path over TLS from a chosen source address; returns the status line."""
        ctx = ssl.create_default_context()
        ctx.check_hostname, ctx.verify_mode = False, ssl.CERT_NONE
        with socket.create_connection((addr, port), timeout=15, source_address=(source, 0)) as raw, \
                ctx.wrap_socket(raw, server_hostname=sni) as tls:
            tls.sendall(f"GET {path} HTTP/1.1\r\nHost: {sni}\r\nUser-Agent: catto-mail-preflight\r\n"
                        "Connection: close\r\n\r\n".encode())
            return tls.recv(200).decode(errors="replace").split("\r\n", 1)[0]

    def smtp_probe(self, source: str, addr: str, port: int, helo: str) -> str:
        """Banner, EHLO and QUIT from a chosen source address; returns the banner."""
        with smtplib.SMTP(local_hostname=helo, timeout=15, source_address=(source, 0)) as s:
            code, msg = s.connect(addr, port)
            s.ehlo()
            return f"{code} {msg.decode(errors='replace')}"

    def inspect(self, name: str) -> dict | None:
        rc, out = self.run("podman", "inspect", f"{self.i}-{name}")
        if rc != 0:
            return None
        try:
            return json.loads(out)[0]
        except (ValueError, IndexError):
            return None

    def exec(self, service: str, *cmd: str, user: str | None = None) -> tuple[int, str]:
        args = ["podman", "exec"] + (["-u", user] if user else []) + [f"{self.i}-{service}", *cmd]
        return self.run(*args)

    def sql(self, query: str) -> list[list[str]]:
        rc, out = self.exec("postgres", "psql", "-X", "-At", "-F", "\t", "-U", self.v["POSTGRES_USER"],
                            "-d", self.v["SMARTHOST_DB_NAME"], "-c", query)
        if rc != 0:
            raise RuntimeError(out.strip()[:300])
        return [ln.split("\t") for ln in out.splitlines() if ln]


# ======================================================================= checks
@dataclass
class Check:
    section: str
    name: str
    level: str
    detail: str = ""
    activation: bool = False   # live delivery depends on it


@dataclass
class Preflight:
    values: dict[str, str]
    host: Host
    resolver: Resolver
    activation: bool = False
    egress_probe: str = ""
    # Before an upgrade the checkout is already the new release, whose grant matrix the upgrade
    # applies itself; the grants are compared by the runtime preflight after the upgrade.
    pre_upgrade: bool = False
    results: list[Check] = field(default_factory=list)
    now: dt.datetime = field(default_factory=lambda: dt.datetime.now(dt.UTC))

    def add(self, section: str, name: str, level: str, detail: str = "", activation: bool = False) -> None:
        if self.activation and activation and level == "WARN":
            level, detail = "FAIL", detail + " [required for live activation]"
        self.results.append(Check(section, name, level, detail, activation))

    @property
    def v(self) -> dict[str, str]:
        return self.values

    # ---------------------------------------------------------------- config
    def check_config(self) -> None:
        errors = render.validate(self.v)
        if errors:
            for e in errors:
                self.add("config", "production rule", "FAIL", e, True)
        else:
            self.add("config", "contract and production rules", "PASS", "the .env satisfies every rule")
        live = self.v.get("SMARTHOST_LIVE_DELIVERY_ENABLED") == "true"
        self.add("config", "live delivery", "INFO", "enabled (live mode)" if live else "disabled (held mode: send jobs are not claimed)")
        if self.activation and self.v.get("SMARTHOST_EGRESS_ENABLED") != "true":
            self.add("config", "egress", "FAIL", "SMARTHOST_EGRESS_ENABLED must be true for live delivery", True)
        tag = self.v.get("SMARTHOST_IMAGE_TAG", "")
        self.add("config", "image tag", "WARN" if tag in ("dev", "latest") else "PASS", f":{tag}")
        for name in ("DELIVERY_GLOBAL_RATE_PER_MINUTE",):
            val = int(self.v.get(name, "0") or 0)
            self.add("config", "warm-up ceiling", "PASS" if val > 0 else "WARN",
                     f"{name}={val}" + ("" if val > 0 else " (no installation-wide ceiling; set one while the IP is warming up)"))
        public = self.v.get("APP_PUBLIC_ONBOARDING_ENABLED") == "true"
        self.add("config", "public onboarding", "WARN" if public else "PASS",
                 "ENABLED: public applications can create pending client records (an explicit operator decision; see "
                 "docs/production/onboarding.md)" if public else "disabled: clients are created and approved by operators only")
        policy = self.v.get("APP_ACCEPTABLE_USE_POLICY_VERSION", "")
        self.add("config", "service policy version", "INFO",
                 f"{policy} must be accepted before approval (clients that require it)" if policy else "none in force: approval needs no policy acceptance")
        empty = [k for k, val in self.v.items() if k.startswith("APP_RETENTION_") and val == "" ]
        self.add("config", "retention decisions", "INFO",
                 f"{len(empty)} categories with no automatic deletion: decide before long-term operation (runbook)")

    # ------------------------------------------------------------------ host
    def ports_needed(self) -> list[int]:
        out = []
        for var in ("PROXY_HTTPS_BIND", "POSTFIX_SMTP_BIND"):
            port = self.v.get(var, "").rpartition(":")[2]
            if port.isdigit():
                out.append(int(port))
        return out

    def check_host(self) -> None:
        """Bootstrap prerequisites (runbook §1): checked before containers exist."""
        h = self.host
        remote = h.podman_remote()
        osr = {}
        try:
            for ln in Path("/etc/os-release").read_text().splitlines():
                k, _, val = ln.partition("=")
                osr[k] = val.strip('"')
        except OSError:
            pass
        supported = osr.get("ID") == "ubuntu" and osr.get("VERSION_ID") == "26.04"
        self.add("host", "operating system", "PASS" if supported else "WARN",
                 f"{osr.get('PRETTY_NAME', 'unknown')}" + ("" if supported else " (supported and tested: Ubuntu Server 26.04 LTS)"))
        rc, out = h.run("podman", "version", "--format", "{{.Client.Version}}")
        try:
            ver = tuple(int(x) for x in re.findall(r"\d+", out)[:2])
            self.add("host", "podman version", "PASS" if ver >= MIN_PODMAN else "FAIL", f"{out.strip()} (minimum 4.9)", True)
        except ValueError:
            self.add("host", "podman version", "FAIL", out.strip()[:200], True)
        missing = [b for b in ("podman", "openssl", "python3", "ss", "systemctl", "loginctl") if not shutil.which(b)]
        self.add("host", "required binaries", "FAIL" if missing else "PASS",
                 f"missing: {missing}" if missing else "podman openssl python3 ss systemctl loginctl")
        self.check_low_ports(remote)
        if remote:
            self.add("host", "lingering and podman.socket", "SKIP",
                     "the Podman engine is remote (development machine); run the preflight on the production host")
        else:
            rc, out = h.run("loginctl", "show-user", os.environ.get("USER", ""), "-p", "Linger")
            self.add("host", "lingering (start at boot)", "PASS" if "Linger=yes" in out else "WARN", out.strip() or "unknown", True)
            rc, out = h.run("systemctl", "--user", "is-active", "podman.socket")
            self.add("host", "podman.socket", "PASS" if out.strip() == "active" else "WARN", out.strip())

    def check_low_ports(self, remote: bool) -> None:
        """The rootless service user's systemd binds the ingress ports (25, 443) on the host."""
        needed = self.ports_needed()
        low = min(needed) if needed else 0
        name = "rootless binding of the ingress ports"
        if not needed:
            self.add("host", name, "FAIL", "PROXY_HTTPS_BIND and POSTFIX_SMTP_BIND must be set", True)
            return
        if low >= 1024:
            self.add("host", name, "PASS", f"ports {sorted(needed)} are unprivileged: no host setting needed")
            return
        if remote:
            self.add("host", name, "SKIP", f"ports {sorted(needed)} need {SYSCTL_PORTS} <= {low} on the engine host (remote here)")
            return
        raw = self.host.sysctl(SYSCTL_PORTS)
        if raw is None or not raw.isdigit():
            self.add("host", name, "FAIL", f"cannot read {SYSCTL_PORTS}", True)
            return
        start = int(raw)
        if start > low:
            self.add("host", name, "FAIL",
                     f"{SYSCTL_PORTS}={start}: the service user cannot bind port {low}. Set it to 25 (system-wide; "
                     f"see runbook §1): {SYSCTL_FIX}", True)
            return
        self.add("host", name, "PASS", f"{SYSCTL_PORTS}={start} (ports {sorted(needed)} bindable)")
        persisted = self.host.sysctl_persisted(SYSCTL_PORTS)
        last = persisted[-1].rpartition("=")[2].strip() if persisted else ""
        ok = last.isdigit() and int(last) <= low
        self.add("host", f"{SYSCTL_PORTS} persists across reboots", "PASS" if ok else "WARN",
                 persisted[-1] if persisted else f"set at runtime only; persist it: {SYSCTL_FIX}", True)

    # --------------------------------------------------------------- runtime
    def check_runtime(self) -> None:
        h = self.host
        tag = self.v["SMARTHOST_IMAGE_TAG"]
        absent = [s for s in ("postfix", "opendkim", "app", "nginx", "validator", "delivery")
                  if h.run("podman", "image", "exists", f"localhost/smarthost-{s}:{tag}")[0] != 0]
        self.add("runtime", "images", "FAIL" if absent else "PASS", f"missing :{tag}: {absent}" if absent else f"all six images :{tag}", True)
        states = {}
        for s in SERVICES:
            c = h.inspect(s)
            states[s] = "missing" if c is None else (c.get("State", {}).get("Health", {}) or {}).get("Status") or c.get("State", {}).get("Status", "?")
        bad = {s: st for s, st in states.items() if st != "healthy"}
        self.add("runtime", "service health", "FAIL" if bad else "PASS", f"not healthy: {bad}" if bad else "all 8 services healthy", True)
        if states.get("postgres") == "healthy":
            try:
                rows = self.host.sql("SELECT 1")
                self.add("runtime", "database", "PASS" if rows == [["1"]] else "FAIL", "PostgreSQL answers", True)
            except RuntimeError as exc:
                self.add("runtime", "database", "FAIL", str(exc), True)
                return
            if states.get("symfony-app") == "healthy":
                rc, out = h.exec("symfony-app", "php", "bin/console", "doctrine:migrations:up-to-date", user="www-data")
                self.add("runtime", "migrations current", "PASS" if rc == 0 else "FAIL", out.strip().splitlines()[-1] if out.strip() else "", True)
            if self.pre_upgrade:
                self.add("runtime", "grants current", "SKIP", "compared after the upgrade, which applies the new release's grants")
            else:
                self.check_grants()
            try:
                rows = self.host.sql("SELECT COALESCE(extract(epoch FROM now() - max(computed_at))::bigint, -1) FROM client_reputation_metrics")
                age = int(rows[0][0]) if rows else -1
                self.add("runtime", "reputation evaluation", "PASS" if 0 <= age <= 3600 else "WARN",
                         f"last run {age // 60} minutes ago" if age >= 0 else
                         f"never run ({self.host.i}-reputation-evaluate.timer runs it every 15 minutes after start)")
            except (RuntimeError, ValueError, IndexError) as exc:
                self.add("runtime", "reputation evaluation", "WARN", str(exc)[:200])

    def check_grants(self) -> None:
        roles = {"app": self.v["APP_DB_USER"], "webhook": self.v["APP_WEBHOOK_DB_USER"],
                 "validator": self.v["VALIDATOR_DB_USER"], "delivery": self.v["DELIVERY_DB_USER"]}
        matrix = grant_matrix((ROOT / "docs/schema/schema.md").read_text(encoding="utf-8"))
        letter = {"SELECT": "S", "INSERT": "I", "UPDATE": "U", "DELETE": "D"}
        try:
            # Table privileges plus column-restricted ones (e.g. UPDATE (subject, ...) on send_job_recipients).
            grantees = ",".join(f"'{r}'" for r in roles.values())
            rows = self.host.sql("SELECT table_name, grantee, privilege_type FROM information_schema.role_table_grants "
                                 f"WHERE table_schema = 'public' AND grantee IN ({grantees}) UNION "
                                 "SELECT table_name, grantee, privilege_type FROM information_schema.column_privileges "
                                 f"WHERE table_schema = 'public' AND grantee IN ({grantees})")
        except RuntimeError as exc:
            self.add("runtime", "grants current", "FAIL", str(exc), True)
            return
        held: dict[tuple[str, str], set[str]] = {}
        back = {v: k for k, v in roles.items()}
        for table, grantee, priv in rows:
            if priv in letter:
                held.setdefault((table, back[grantee]), set()).add(letter[priv])
        diffs = [f"{t}/{r}: want {sorted(want)} have {sorted(held.get((t, r), set()))}"
                 for t, per in matrix.items() for r, want in per.items() if held.get((t, r), set()) != want]
        self.add("runtime", "grants current", "FAIL" if diffs else "PASS",
                 "; ".join(diffs[:5]) if diffs else f"{len(matrix)} tables match schema.md §6", True)

    # -------------------------------------------------------------- exposure
    def check_exposure(self) -> None:
        h, i = self.host, self.host.i
        published = []
        for s in SERVICES:
            c = h.inspect(s)
            if c is None:
                continue
            ports = (c.get("NetworkSettings") or {}).get("Ports") or {}
            for cport, binds in ports.items():
                for b in binds or []:
                    published.append(f"{s} {cport} -> {b.get('HostIp') or '0.0.0.0'}:{b.get('HostPort')}")
            nets = set(((c.get("NetworkSettings") or {}).get("Networks") or {}).keys())
            want = ({f"{i}-internal"} | ({f"{i}-egress"} if s in EGRESS_SERVICES else set())
                    | ({f"{i}-ingress"} if s in INGRESS_SERVICES else set()))
            self.add("exposure", f"networks of {s}", "PASS" if nets == want else "FAIL",
                     f"{sorted(nets)}" + ("" if nets == want else f" (expected {sorted(want)})"), True)
            text = json.dumps(c)
            for key, owner in ((f"{i}-proxy-tls-key", "nginx"), (f"{i}-postfix-tls-key", "postfix"), (f"{i}-opendkim-keys", "opendkim")):
                if key in text and s != owner:
                    self.add("exposure", f"private key {key}", "FAIL", f"mounted into {s}; only {owner} may hold it", True)
        # Rootless port forwarding would replace every client address with one internal
        # address; the public listeners belong to the ingress socket (section ingress).
        self.add("exposure", "published container ports", "FAIL" if published else "PASS",
                 "; ".join(published) + " (production publishes nothing: HTTPS and SMTP come from the ingress socket)"
                 if published else "none: HTTPS and SMTP reach nginx through the systemd ingress socket", True)
        if not any(r.section == "exposure" and r.name.startswith("private key") for r in self.results):
            self.add("exposure", "private keys", "PASS", "proxy and Postfix TLS keys and DKIM keys only in their own container")
        for net, internal in ((f"{i}-internal", "true"), (f"{i}-ingress", "true"),
                              (f"{i}-egress", "false" if self.v["SMARTHOST_EGRESS_ENABLED"] == "true" else "true")):
            rc, out = h.run("podman", "network", "inspect", net, "--format", "{{.Internal}}")
            self.add("exposure", f"network {net}", "PASS" if rc == 0 and out.strip() == internal else "FAIL",
                     f"internal={out.strip() if rc == 0 else 'missing'} (expected {internal})", True)
        if not h.podman_remote():
            rc, out = h.run("ss", "-Hltn")
            public = sorted({ln.split()[3] for ln in out.splitlines() if len(ln.split()) > 3
                             and not re.match(r"^(127\.|\[::1\]|::1)", ln.split()[3])})
            ssh = self.v.get("SMARTHOST_SSH_PORT", "22")
            other = [a for a in public if a.rsplit(":", 1)[-1] not in ("25", "443", ssh)]
            self.add("exposure", "host listeners", "WARN" if other else "PASS",
                     f"public listeners: {public}" + (f"; not catto-mail: {other} (close or firewall them)" if other else ""))

    # --------------------------------------------------------------- ingress
    def check_ingress(self) -> None:
        """Client addresses reach nginx/Symfony and Postfix unchanged (Phase 8 networking).

        Structure: the systemd socket holds both binds and nginx runs with it; Postfix's
        port 25 is the PROXY-protocol listener on the ingress network; Symfony trusts no
        forwarded header. Behaviour: two connections from two random loopback source
        addresses through each bind must appear with exactly those addresses in the nginx
        access log and in the Postfix log. One shared address for both is what rootless
        port forwarding produces: that is a FAIL.
        """
        h, i = self.host, self.host.i
        sock, svc = f"{i}-ingress.socket", f"{i}-ingress.service"
        state = h.systemctl("is-active", sock, svc)
        if state is None:
            self.add("ingress", "ingress units", "WARN",
                     "cannot reach the engine host's systemd (run through smarthostctl prod, which sets SMARTHOST_HOSTCTL)", True)
        else:
            active = state[1].split()
            self.add("ingress", "ingress units", "PASS" if active == ["active", "active"] else "FAIL",
                     f"{sock} {active[0] if active else '?'}, {svc} {active[1] if len(active) > 1 else '?'}", True)
            listen = h.systemctl("show", "-p", "Listen", "--value", sock)
            got = sorted(ln.split()[0] for ln in (listen[1] if listen else "").splitlines() if ln.strip())
            want = sorted([self.v.get("PROXY_HTTPS_BIND", ""), self.v.get("POSTFIX_SMTP_BIND", "")])
            self.add("ingress", "socket binds", "PASS" if got == want else "FAIL", f"{got} (expected {want})", True)
        # nginx announces inherited sockets at every start (its process title overwrites
        # /proc/1/environ, so the environment cannot be read back).
        started = h.started_at("nginx")
        log = h.logs("nginx", started) if started else ""
        ok = 'using inherited sockets from "3;4;"' in log and "invalid socket number" not in log
        self.add("ingress", "nginx holds the inherited sockets", "PASS" if ok else "FAIL",
                 f'since its start at {started}: "using inherited sockets from 3;4;"' if ok else
                 "nginx was not started by its socket unit (start and stop it only with smarthostctl prod)", True)
        rc, master = h.exec("postfix", "postconf", "-M")
        lines = master.splitlines() if rc == 0 else []
        proxy = any(ln.startswith("postfix-ingress:smtp ") and "smtpd_upstream_proxy_protocol=haproxy" in ln for ln in lines)
        open_25 = [ln.split()[0] for ln in lines if re.match(r"^(smtp|25|0\.0\.0\.0:(smtp|25))\s+inet\s", ln)]
        self.add("ingress", "Postfix port 25 takes the PROXY protocol from nginx only", "PASS" if proxy and not open_25 else "FAIL",
                 "postfix-ingress:25 (ingress network), PROXY protocol" if proxy and not open_25 else
                 f"proxy listener {'present' if proxy else 'missing'}; plain listeners {open_25 or 'none'}", True)
        trusted = self.v.get("TRUSTED_PROXIES", "")
        self.add("ingress", "application client address", "PASS" if trusted == "" else "FAIL",
                 "REMOTE_ADDR from nginx; no forwarded header trusted" if trusted == ""
                 else f"TRUSTED_PROXIES={trusted}: forwarded headers from those addresses would replace the client address", True)
        self.probe_sources()

    def probe_sources(self) -> None:
        h = self.host
        base = f"127.{random.randint(64, 254)}.{random.randint(1, 254)}"
        sources = (f"{base}.2", f"{base}.3")
        nonce = f"{random.getrandbits(48):012x}"
        since = (self.now - dt.timedelta(seconds=5)).strftime("%Y-%m-%dT%H:%M:%SZ")

        def target(bind: str) -> tuple[str, int]:
            host, _, port = bind.rpartition(":")
            return ("127.0.0.1" if host in ("", "0.0.0.0") else host), int(port or 0)

        def verdict(label: str, seen: dict[str, list[str]], errors: list[str]) -> None:
            name = f"{label} sees each client's own address"
            if errors:
                self.add("ingress", name, "FAIL", "; ".join(errors), True)
                return
            exact = all(seen[src] == [src] for src in sources)
            observed = sorted({a for got in seen.values() for a in got})
            if exact:
                self.add("ingress", name, "PASS", f"{sources[0]} and {sources[1]} arrived as themselves", True)
            elif observed and all(seen[src] for src in sources) and len(observed) == 1:
                self.add("ingress", name, "FAIL", f"both clients collapsed to {observed[0]}: the ingress hides client "
                         "addresses (rootless port forwarding?); production needs the socket-activated ingress", True)
            else:
                self.add("ingress", name, "FAIL", f"sent from {list(sources)}, logged {dict(seen)}", True)

        # HTTPS: nginx logs $remote_addr, which is also the REMOTE_ADDR Symfony receives.
        addr, port = target(self.v.get("PROXY_HTTPS_BIND", ""))
        errors, paths = [], {src: f"/.well-known/catto-mail-ingress-probe/{nonce}-{n}" for n, src in enumerate(sources)}
        for src, path in paths.items():
            try:
                h.https_probe(src, addr, port, self.v["PROXY_SERVER_NAME"], path)
            except (OSError, ssl.SSLError) as exc:
                errors.append(f"HTTPS from {src} to {addr}:{port}: {exc}")
        seen: dict[str, list[str]] = {src: [] for src in sources}
        for _ in range(10 if not errors else 0):
            log = h.logs("nginx", since)
            for src, path in paths.items():
                seen[src] = [ln.split()[0] for ln in log.splitlines() if path in ln and ln.split()]
            if all(seen.values()):
                break
            time.sleep(1)
        verdict("nginx (HTTPS, Symfony REMOTE_ADDR)", seen, errors)

        # SMTP: Postfix logs "connect from name[address]" for the PROXY-protocol client.
        addr, port = target(self.v.get("POSTFIX_SMTP_BIND", ""))
        errors = []
        for src in sources:
            try:
                h.smtp_probe(src, addr, port, f"ingress-probe-{nonce}.invalid")
            except (OSError, smtplib.SMTPException) as exc:
                errors.append(f"SMTP from {src} to {addr}:{port}: {exc}")
        seen = {src: [] for src in sources}
        recent: list[str] = []
        for _ in range(10 if not errors else 0):
            rc, log = h.exec("postfix", "sh", "-c", 'tail -n 3000 "$SMARTHOST_POSTFIX_OBSERVABILITY_DIR/log/postfix.log"')
            connects = re.findall(r"connect from [^\s\[]*\[([0-9.]+)\]", log if rc == 0 else "")
            recent = connects[-4:]
            for src in sources:
                seen[src] = [src] if src in connects else []
            if all(seen.values()):
                break
            time.sleep(1)
        if not errors and not all(seen.values()):
            for src in sources:
                seen[src] = seen[src] or recent[-1:]
        verdict("Postfix (SMTP peer)", seen, errors)

    # ------------------------------------------------------------------- tls
    def check_tls(self) -> None:
        server, myhost = self.v["PROXY_SERVER_NAME"], self.v["POSTFIX_MYHOSTNAME"]

        def addr(bind: str) -> tuple[str, int]:
            host, _, port = bind.rpartition(":")
            return ("127.0.0.1" if host in ("", "0.0.0.0") else host), int(port)

        for label, bind, name, fetch, need_trust in (
                ("nginx", self.v["PROXY_HTTPS_BIND"], server, "https", True),
                ("postfix", self.v.get("POSTFIX_SMTP_BIND", ""), myhost, "smtp", False)):
            if not bind:
                self.add("tls", f"{label} certificate", "SKIP", "not published")
                continue
            try:
                host, port = addr(bind)
                if fetch == "https":
                    der, err = fetch_https_cert(host, port, name)
                else:
                    der, err, _banner = fetch_smtp_cert(host, port, name)
                info = cert_info(der)
            except (OSError, ValueError, smtplib.SMTPException, subprocess.CalledProcessError) as exc:
                self.add("tls", f"{label} certificate", "FAIL", f"cannot read the served certificate: {exc}", True)
                continue
            days = (info.not_after - self.now).days
            if info.not_before > self.now or info.not_after < self.now:
                self.add("tls", f"{label} certificate validity", "FAIL", f"valid {info.not_before:%Y-%m-%d} to {info.not_after:%Y-%m-%d}", True)
            else:
                self.add("tls", f"{label} certificate validity", "PASS" if days >= CERT_WARN_DAYS else "WARN",
                         f"expires {info.not_after:%Y-%m-%d} ({days} days)", days < CERT_WARN_DAYS)
            self.add("tls", f"{label} certificate name", "PASS" if name_matches(name, info.names) else "FAIL",
                     f"{name} in {info.names}" if name_matches(name, info.names) else f"{name} not in {info.names}", True)
            if err is None:
                self.add("tls", f"{label} certificate trust", "PASS", f"chain verifies (issuer {info.issuer})")
            else:
                self.add("tls", f"{label} certificate trust", "FAIL" if need_trust else "WARN",
                         f"{err[:160]} (issuer {info.issuer})", need_trust)

    # ------------------------------------------------------------------- dns
    def check_dns(self) -> None:
        r, v = self.resolver, self.v
        ip = v["SMARTHOST_PUBLIC_IPV4"]
        server, myhost, bounce = v["PROXY_SERVER_NAME"].lower(), v["POSTFIX_MYHOSTNAME"].lower(), v["SMARTHOST_BOUNCE_DOMAIN"].lower()

        def a(name: str) -> list[str] | None:
            try:
                return r.query(name, "A")
            except DnsError as exc:
                self.add("dns", f"A {name}", "WARN", str(exc), True)
                return None

        for label, name in (("public hostname", server), ("mail hostname (EHLO)", myhost)):
            got = a(name)
            if got is not None:
                self.add("dns", f"A {name} ({label})", "PASS" if ip in got else "FAIL", f"{got or 'no A record'} (expected {ip})", True)
        try:
            ptrs = [p.lower().rstrip(".") for p in r.query(reverse_name(ip), "PTR")]
            self.add("dns", f"PTR {ip}", "PASS" if ptrs == [myhost] else ("WARN" if myhost in ptrs else "FAIL"),
                     f"{ptrs or 'no PTR record'} (expected exactly {myhost}; set it at the IP provider)", True)
            fcr = [p for p in ptrs if ip in (a(p) or [])]
            self.add("dns", "forward-confirmed reverse DNS", "PASS" if fcr else "FAIL",
                     f"{fcr[0]} -> {ip}" if fcr else "no PTR name resolves back to the sending IP", True)
        except DnsError as exc:
            self.add("dns", f"PTR {ip}", "WARN", str(exc), True)
        try:
            mx = sorted(r.query(bounce, "MX"))
            hosts = [h.lower().rstrip(".") for _p, h in mx]
            ours = [h for h in hosts if ip in (a(h) or [])]
            if not hosts:
                self.add("dns", f"MX {bounce} (bounce domain)", "FAIL", "no MX: DSNs cannot reach this host", True)
            else:
                self.add("dns", f"MX {bounce} (bounce domain)", "PASS" if ours == hosts else ("WARN" if ours else "FAIL"),
                         f"{hosts}; pointing to {ip}: {ours}", True)
        except DnsError as exc:
            self.add("dns", f"MX {bounce}", "WARN", str(exc), True)
        for label, domain, act in (("bounce domain (envelope sender)", bounce, True), ("EHLO name", myhost, False)):
            res = spf_check(ip, domain, r)
            level = {"pass": "PASS", "softfail": "WARN", "neutral": "WARN", "temperror": "WARN", "unsupported": "WARN"}.get(res.result, "FAIL")
            if not act and level == "FAIL":
                level = "WARN"
            self.add("dns", f"SPF {domain} ({label})", level,
                     f"{res.result}" + (f" by {res.mechanism}" if res.mechanism else "") + (f": {res.detail}" if res.detail else ""), act)
        rows = self.sending_domains()
        for row in rows:
            self.check_dmarc(row["domain"])
        mail_from_domain = v["APP_MAIL_FROM"].rpartition("@")[2].lower()
        if mail_from_domain not in {row["domain"] for row in rows}:
            self.check_dmarc(mail_from_domain)
        if not self.host.podman_remote() and v.get("SMARTHOST_EGRESS_ENABLED") == "true":
            try:
                mx = sorted(r.query(bounce, "MX"))
                mx_ips = a(mx[0][1]) if mx else None
                mx_ip = mx_ips[0] if mx_ips else None
                if mx_ip:
                    with smtplib.SMTP(mx_ip, 25, local_hostname="preflight.invalid", timeout=10) as s:
                        banner = s.ehlo()[1].decode(errors="replace")
                    self.add("dns", "inbound SMTP reaches this Postfix", "PASS" if myhost in banner.lower() else "WARN",
                             f"{mx_ip}:25 answered {banner.splitlines()[0][:80]}")
            except (OSError, smtplib.SMTPException, DnsError) as exc:
                self.add("dns", "inbound SMTP reaches this Postfix", "WARN",
                         f"{exc} (hairpin NAT may prevent this test from the host itself; test from outside)")

    def check_dmarc(self, domain: str) -> None:
        try:
            pol = dmarc_policy(self.resolver.query(f"_dmarc.{domain}", "TXT"))
            where = f"_dmarc.{domain}"
            if pol is None and org_domain(domain) != domain:
                pol = dmarc_policy(self.resolver.query(f"_dmarc.{org_domain(domain)}", "TXT"))
                where = f"_dmarc.{org_domain(domain)} (organisational domain)"
        except DnsError as exc:
            self.add("dns", f"DMARC {domain}", "WARN", str(exc), True)
            return
        if pol is None:
            self.add("dns", f"DMARC {domain}", "WARN", "no DMARC record (create one; p=none with rua= is a safe start)", True)
            return
        p = pol.get("p", "?")
        self.add("dns", f"DMARC {domain}", "PASS" if p in ("none", "quarantine", "reject") else "WARN",
                 f"{where}: p={p}" + (f" sp={pol['sp']}" if "sp" in pol else "") + (f" pct={pol['pct']}" if "pct" in pol else "")
                 + (f" rua={pol['rua']}" if "rua" in pol else " (no rua: no aggregate reports)"), True)

    # ------------------------------------------------------------------ dkim
    def sending_domains(self) -> list[dict[str, str]]:
        """Verified sending domains of clients that may send (active, throttled)."""
        try:
            rows = self.host.sql("SELECT d.domain, d.dkim_status, COALESCE(d.dkim_selector, ''), c.company_name FROM sending_domains d "
                                 "JOIN clients c ON c.id = d.client_id WHERE d.status = 'verified' AND c.status IN ('active', 'throttled') "
                                 "ORDER BY d.domain")
        except RuntimeError:
            return []
        return [{"domain": d, "dkim_status": s, "selector": sel, "client": c} for d, s, sel, c in rows]

    def check_dkim(self) -> None:
        rc, out = self.host.exec("opendkim", "smarthost-dkim-key", "list")
        if rc != 0:
            self.add("dkim", "OpenDKIM tables", "FAIL", out.strip()[:200], True)
            return
        table: dict[str, dict[str, tuple[bool, bool]]] = {}
        for ln in out.splitlines()[1:]:
            parts = ln.split("\t")
            if len(parts) >= 4:
                table.setdefault(parts[0], {})[parts[1]] = (parts[2] == "yes", parts[3] == "present")
        rows = self.sending_domains()
        if not rows:
            self.add("dkim", "sending domains", "WARN", "no verified sending domain of an active client yet", True)
        signed = set()
        for row in rows:
            d, sel = row["domain"], row["selector"]
            if row["dkim_status"] != "active" or not sel:
                self.add("dkim", f"{d}", "FAIL", f"verified but DKIM is {row['dkim_status']} (selector '{sel or '-'}'): "
                         "the delivery daemon refuses its jobs in production", True)
                continue
            active = [s for s, (act, _k) in table.get(d, {}).items() if act]
            if sel not in table.get(d, {}):
                self.add("dkim", f"{d} selector {sel}", "FAIL", "not in the OpenDKIM KeyTable (smarthostctl prod dkim generate)", True)
                continue
            if not table[d][sel][1]:
                self.add("dkim", f"{d} selector {sel}", "FAIL", "KeyTable entry without a private key", True)
                continue
            if active != [sel]:
                self.add("dkim", f"{d} selector {sel}", "FAIL", f"OpenDKIM signs with {active or 'nothing'}; the database says {sel}", True)
                continue
            signed.add(d)
            self.check_dkim_dns(d, sel)
        mail_from = self.v["APP_MAIL_FROM"].rpartition("@")[2].lower()
        mf_active = [s for s, (act, _k) in table.get(mail_from, {}).items() if act]
        if mf_active:
            if mail_from not in signed:
                self.check_dkim_dns(mail_from, mf_active[0])
        else:
            self.add("dkim", f"{mail_from} (APP_MAIL_FROM)", "WARN", "the sign-in mail domain is not DKIM-signed by OpenDKIM", True)

    def check_dkim_dns(self, domain: str, selector: str) -> None:
        rc, key = self.host.exec("opendkim", "smarthost-dkim-key", "pubkey", domain, selector)
        if rc != 0:
            self.add("dkim", f"{domain} selector {selector}", "FAIL", key.strip()[:200], True)
            return
        try:
            published = dkim_public_key(self.resolver.query(f"{selector}._domainkey.{domain}", "TXT"))
        except DnsError as exc:
            self.add("dkim", f"{domain} selector {selector}", "WARN", str(exc), True)
            return
        if published is None:
            self.add("dkim", f"{domain} selector {selector}", "FAIL",
                     f"no DKIM TXT at {selector}._domainkey.{domain} (smarthostctl prod dkim dns {domain})", True)
        elif published != key.strip():
            self.add("dkim", f"{domain} selector {selector}", "FAIL", "the published public key does not match the installed private key", True)
        else:
            self.add("dkim", f"{domain} selector {selector}", "PASS", "key installed, active, and published in DNS", True)

    # --------------------------------------------------------------- postfix
    def check_postfix(self) -> None:
        v, live = self.v, self.v["SMARTHOST_LIVE_DELIVERY_ENABLED"] == "true"
        names = ["myhostname", "relayhost", "mydestination", "mynetworks", "inet_protocols", "smtpd_relay_restrictions",
                 "virtual_mailbox_domains", "default_transport", "defer_transports", "smtpd_tls_cert_file",
                 "smtpd_sender_login_maps", "recipient_delimiter", "message_size_limit", "disable_vrfy_command"]
        rc, out = self.host.exec("postfix", "postconf", "-h", *names)
        if rc != 0:
            self.add("postfix", "configuration", "FAIL", out.strip()[:200], True)
            return
        lines = [ln.strip() for ln in out.splitlines()]
        if len(lines) != len(names):
            self.add("postfix", "configuration", "FAIL", f"postconf returned {len(lines)} values for {len(names)} parameters", True)
            return
        pc = dict(zip(names, lines, strict=True))
        expect = {
            "myhostname": (pc.get("myhostname") == v["POSTFIX_MYHOSTNAME"], f"{pc.get('myhostname')} (POSTFIX_MYHOSTNAME)"),
            "relayhost": (pc.get("relayhost") == "", f"'{pc.get('relayhost')}' (production: none)"),
            "mydestination": (pc.get("mydestination") == "", "no local delivery"),
            "mynetworks": (pc.get("mynetworks") == "127.0.0.0/8", f"{pc.get('mynetworks')} (loopback only)"),
            "relay restrictions": ("reject_unauth_destination" in pc.get("smtpd_relay_restrictions", ""), pc.get("smtpd_relay_restrictions")),
            "bounce domain": (pc.get("virtual_mailbox_domains") == v["SMARTHOST_BOUNCE_DOMAIN"],
                              f"{pc.get('virtual_mailbox_domains')} (SMARTHOST_BOUNCE_DOMAIN)"),
            "VERP delimiter": (pc.get("recipient_delimiter") == v["SMARTHOST_VERP_DELIMITER"], pc.get("recipient_delimiter")),
            "TLS certificate configured": (pc.get("smtpd_tls_cert_file") == v["POSTFIX_TLS_CERT_FILE"], pc.get("smtpd_tls_cert_file")),
            "submission sender ownership": ("smarthost_sender_logins" in pc.get("smtpd_sender_login_maps", ""), pc.get("smtpd_sender_login_maps")),
            "IPv4 only": (pc.get("inet_protocols") == "ipv4", pc.get("inet_protocols")),
            "VRFY disabled": (pc.get("disable_vrfy_command") == "yes", pc.get("disable_vrfy_command")),
        }
        for name, (ok, detail) in expect.items():
            self.add("postfix", name, "PASS" if ok else "FAIL", str(detail), True)
        transport = pc.get("default_transport", "")
        mode_ok = transport == "smtp" if live else transport.startswith("retry:")
        self.add("postfix", "delivery mode", "PASS" if mode_ok else "FAIL",
                 f"default_transport={transport} ({'live' if live else 'held'} mode expected)", True)
        paused = pc.get("defer_transports") == "smtp"
        self.add("postfix", "emergency pause", "WARN" if paused else "PASS", "PAUSED: outbound mail is held" if paused else "not paused")
        rc, out = self.host.exec("postfix", "postconf", "-P", "submission/inet/smtpd_milters")
        self.add("postfix", "OpenDKIM milter on submission", "PASS" if v["SMARTHOST_OPENDKIM_MILTER_ADDRESS"] in out else "FAIL", out.strip(), True)
        bind = v.get("POSTFIX_SMTP_BIND", "")
        if bind:
            host, _, port = bind.rpartition(":")
            host = "127.0.0.1" if host in ("", "0.0.0.0") else host
            try:
                with smtplib.SMTP(host, int(port), local_hostname="preflight.invalid", timeout=15) as s:
                    s.ehlo()
                    s.mail("")
                    code, msg = s.rcpt("relay-test@example.net")
                    self.add("postfix", "port 25 is not an open relay", "PASS" if code >= 500 else "FAIL",
                             f"RCPT to an outside domain: {code} {msg.decode(errors='replace')[:80]}", True)
                    s.rset()
                    s.mail("")
                    code, msg = s.rcpt(f"{v['SMARTHOST_VERP_LOCAL_PART']}@{v['SMARTHOST_BOUNCE_DOMAIN']}")
                    self.add("postfix", "port 25 accepts the bounce domain", "PASS" if code == 250 else "FAIL",
                             f"RCPT {v['SMARTHOST_VERP_LOCAL_PART']}@{v['SMARTHOST_BOUNCE_DOMAIN']}: {code}", True)
                    s.rset()
            except (OSError, smtplib.SMTPException) as exc:
                self.add("postfix", "port 25", "FAIL", f"cannot talk to {host}:{port}: {exc}", True)
        if self.egress_probe:
            rc, out = self.host.exec("postfix", "posttls-finger", "-c", "-l", "may", "-L", "summary", self.egress_probe)
            ok = rc == 0 and any(s in out for s in ("TLS connection established", "Untrusted", "Trusted", "Anonymous"))
            self.add("postfix", f"outbound SMTP to {self.egress_probe}", "PASS" if ok else "FAIL",
                     (out.strip().splitlines() or ["no output"])[-1][:160], True)
        elif self.activation:
            self.add("postfix", "outbound SMTP (port 25 egress)", "WARN",
                     "not probed: add --smtp-egress-probe <a large mailbox provider's domain> (EHLO and QUIT only)", False)

    # -------------------------------------------------------------- delivery
    def check_delivery(self) -> None:
        try:
            rows = self.host.sql("SELECT live_delivery, send_work_held, outbound_paused, "
                                 "(stopped_at IS NULL AND last_seen_at > now() - interval '2 minutes'), "
                                 "COALESCE(queue_snapshot_at > now() - interval '5 minutes', false), global_rate_per_minute, version "
                                 "FROM delivery_heartbeats ORDER BY last_seen_at DESC LIMIT 1")
        except RuntimeError as exc:
            self.add("delivery", "delivery daemon", "FAIL", str(exc), True)
            return
        if not rows:
            self.add("delivery", "delivery daemon", "FAIL", "no heartbeat recorded", True)
            return
        live, held, paused, alive, fresh, rate, version = rows[0]
        self.add("delivery", "delivery daemon", "PASS" if alive == "t" else "FAIL", f"version {version}, alive={alive == 't'}", True)
        want_live = self.v["SMARTHOST_LIVE_DELIVERY_ENABLED"] == "true"
        state = "paused" if paused == "t" else ("held" if held == "t" else ("live" if live == "t" else "capture"))
        self.add("delivery", "delivery state", "PASS" if (live == "t") == want_live else "FAIL",
                 f"{state} (configuration: {'live' if want_live else 'held'})", True)
        self.add("delivery", "queue snapshots", "PASS" if fresh == "t" else "WARN",
                 "fresh (timer running)" if fresh == "t" else "no snapshot in 5 minutes (smarthost-postfix-queue-snapshot.timer)", True)
        self.add("delivery", "warm-up ceiling in force", "INFO", f"{rate} submissions/minute" if rate != "0" else "none")

    # ------------------------------------------------------------------- run
    SECTIONS = ("config", "host", "runtime", "exposure", "ingress", "tls", "dns", "dkim", "postfix", "delivery")

    def run(self, sections: tuple[str, ...] | None = None) -> list[Check]:
        for s in sections or self.SECTIONS:
            try:
                getattr(self, f"check_{s}")()
            except Exception as exc:  # a broken check is a FAIL, never a silent pass
                self.add(s, "check error", "FAIL", f"{type(exc).__name__}: {exc}", True)
        return self.results


# ============================================================ checklist/firewall
def dns_checklist(values: dict[str, str], dkim_records: list[str]) -> str:
    v = values
    ip, server, myhost, bounce = v["SMARTHOST_PUBLIC_IPV4"], v["PROXY_SERVER_NAME"], v["POSTFIX_MYHOSTNAME"], v["SMARTHOST_BOUNCE_DOMAIN"]
    mail_from_domain = v["APP_MAIL_FROM"].rpartition("@")[2]
    lines = [
        "catto-mail DNS checklist (create these at your DNS and IP providers; then run `smarthostctl prod preflight`)",
        "",
        "1. Public hostname and mail hostname",
        f"   {server}.  A  {ip}",
        *([f"   {myhost}.  A  {ip}"] if myhost != server else []),
        "",
        "2. Reverse DNS (at the provider of the IP address, not in your zone)",
        f"   PTR for {ip} -> {myhost}   (it must resolve back: forward-confirmed reverse DNS)",
        "",
        "3. Bounce domain (VERP return paths, DSNs, ARF reports)",
        f"   {bounce}.  MX  10 {myhost}.",
        f"   {bounce}.  TXT \"v=spf1 ip4:{ip} -all\"      (the envelope sender domain of every message)",
        f"   {myhost}.  TXT \"v=spf1 ip4:{ip} -all\"      (the EHLO name)",
        "",
        "4. DKIM (per sending domain; from `smarthostctl prod dkim dns <domain>`)",
        *(["   " + r for r in dkim_records] or ["   (no DKIM key yet: smarthostctl prod dkim generate <domain> <selector>)"]),
        "",
        "5. DMARC (per sending domain; start with monitoring, tighten after reviewing reports)",
        "   _dmarc.<sending-domain>.  TXT \"v=DMARC1; p=none; rua=mailto:dmarc-reports@<sending-domain>\"",
        f"   (including {mail_from_domain}, the sign-in mail domain)",
        "",
        "6. Sending-domain SPF (optional for alignment; the return path is the bounce domain)",
        "   Mail is DKIM-aligned with the sending domain; SPF aligns only when the bounce domain is",
        "   in the same organisational domain as the sending domain.",
        "",
        "7. Ownership verification of each sending domain (Smarthost's TXT challenge)",
        "   smarthostctl prod console smarthost:domain:add <client-id> <domain>   prints the TXT record",
    ]
    return "\n".join(lines) + "\n"


def firewall_ruleset(values: dict[str, str], uid: int) -> str:
    ssh = values.get("SMARTHOST_SSH_PORT", "22") or "22"
    return f"""#!/usr/sbin/nft -f
# catto-mail production host firewall (generated by `smarthostctl prod firewall`).
# REVIEW before applying:  sudo nft -f <this file>   (persist via /etc/nftables.conf).
# Inbound: SSH ({ssh}), SMTP 25 (bounce domain), HTTPS 443 only.
# Outbound of the service user (uid {uid}): the rootless containers' traffic leaves
# through processes of this user (rootlessport, slirp4netns/pasta), so it is limited
# here to DNS, SMTP 25 (MX delivery, validation probes), HTTPS 443 (webhooks, image
# pulls, the ACME and DNS-provider APIs), HTTP 80 (package access) and SSH 22 (off-host
# backups with rsync; add the port if BACKUP_OFFHOST_TARGET uses another one). Per-container egress is enforced by the
# Podman networks (only postfix, validator, webhook-worker and symfony-app join the
# egress network).
table inet catto_mail
delete table inet catto_mail
table inet catto_mail {{
    chain input {{
        type filter hook input priority filter; policy drop;
        ct state established,related accept
        ct state invalid drop
        iif "lo" accept
        meta l4proto {{ icmp, ipv6-icmp }} accept
        tcp dport {{ {ssh}, 25, 443 }} ct state new accept
    }}
    chain output {{
        type filter hook output priority filter; policy accept;
        oif "lo" accept
        meta skuid {uid} ct state established,related accept
        meta skuid {uid} udp dport 53 accept
        meta skuid {uid} tcp dport {{ 22, 25, 53, 80, 443 }} accept
        meta skuid {uid} counter reject
    }}
}}
"""


# =========================================================================== CLI
def report(results: list[Check], as_json: bool, quiet: bool) -> int:
    fails = sum(r.level == "FAIL" for r in results)
    if as_json:
        print(json.dumps({"results": [asdict(r) for r in results], "fail": fails,
                          "warn": sum(r.level == "WARN" for r in results)}, indent=2))
    else:
        width = max((len(r.section) + len(r.name) for r in results), default=20) + 3
        for r in results:
            if quiet and r.level not in ("FAIL",):
                continue
            print(f"{r.level:<4}  {(r.section + ': ' + r.name):<{width}} {r.detail}")
        print(f"preflight: {sum(r.level == 'PASS' for r in results)} PASS, {sum(r.level == 'WARN' for r in results)} WARN, "
              f"{fails} FAIL, {sum(r.level == 'SKIP' for r in results)} SKIP")
    return 1 if fails else 0


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description="catto-mail production preflight")
    p.add_argument("--activation", action="store_true", help="the live-delivery activation gate")
    p.add_argument("--section", action="append", choices=Preflight.SECTIONS)
    p.add_argument("--pre-upgrade", action="store_true", help="before an upgrade: skip the grant comparison (the upgrade applies the new grants)")
    p.add_argument("--json", action="store_true")
    p.add_argument("--quiet", action="store_true", help="print only FAIL lines and the summary")
    p.add_argument("--smtp-egress-probe", default="", metavar="DOMAIN")
    p.add_argument("--resolver", action="append", metavar="IP", help="DNS server(s) to ask instead of the system resolver")
    p.add_argument("--checklist", action="store_true")
    p.add_argument("--firewall", action="store_true")
    a = p.parse_args(argv)
    env = Path(os.environ.get("SMARTHOST_DOTENV") or ROOT / "infra/.env")
    values = render.parse_dotenv(env)
    host = Host(values)
    if a.checklist:
        records = []
        rc, out = host.exec("opendkim", "smarthost-dkim-key", "list")
        for ln in out.splitlines()[1:] if rc == 0 else []:
            parts = ln.split("\t")
            if len(parts) >= 3 and parts[2] == "yes":
                rc2, rec = host.exec("opendkim", "smarthost-dkim-key", "dns", parts[0], parts[1])
                records += [ln2 for ln2 in rec.splitlines() if ln2.startswith(("name:", "value:"))] if rc2 == 0 else []
        print(dns_checklist(values, records), end="")
        return 0
    if a.firewall:
        print(firewall_ruleset(values, os.getuid()), end="")
        return 0
    pf = Preflight(values, host, Resolver(a.resolver), activation=a.activation, egress_probe=a.smtp_egress_probe,
                   pre_upgrade=a.pre_upgrade)
    return report(pf.run(tuple(a.section) if a.section else None), a.json, a.quiet)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
