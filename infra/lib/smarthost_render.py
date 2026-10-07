#!/usr/bin/env python3
"""catto-mail (Smarthost) environment and unit renderer (stdlib only).

Commands:
    env-example            regenerate infra/.env.example (development) and
                           infra/production.env.example (the contract's Production
                           profile) from docs/contracts/environment.md
    init-env [--production]
                           create infra/.env (gitignored) from the development or the
                           production template and generate every secret locally;
                           never overwrites an existing file
    check   [--env FILE]   validate a .env against the contract and, for
                           SMARTHOST_ENV=production, the production safety rules
    render  [--env FILE] [--out DIR]
                           validate, write one env file per consumer (contract
                           "Consumers" column - least privilege) and render
                           infra/podman/*.in (the development pod script and the
                           production topology script) and infra/systemd/*.in; in
                           production also infra/systemd/production/*.in as
                           <SMARTHOST_INSTANCE>-<name> (the ingress socket and the
                           nginx service it activates; the reputation-evaluation timer)

FILE defaults to $SMARTHOST_DOTENV or infra/.env, DIR to $SMARTHOST_GENERATED or
infra/.generated (smarthostctl sets both for a production rehearsal).

Templates may reference only contract variables as ${VAR}, plus @REPO@ (the
repository path), @GENERATED@ (the output directory) and @TOPOLOGY@ (the
topology script the systemd units drive: smarthost-pod.sh in development and test,
smarthost-production.sh in production). Anything else is an error, so no
undocumented variable can reach a service. In shell templates (*.sh.in) every
substituted value is shell-quoted, and the rendered script is executable.
"""
from __future__ import annotations

import argparse
import base64
import ipaddress
import os
import re
import secrets
import shlex
import stat
import sys
from collections.abc import Callable
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
CONTRACT = ROOT / "docs/contracts/environment.md"
EXAMPLE = ROOT / "infra/.env.example"
PRODUCTION_EXAMPLE = ROOT / "infra/production.env.example"
DOTENV = Path(os.environ.get("SMARTHOST_DOTENV") or ROOT / "infra/.env")
GENERATED = Path(os.environ.get("SMARTHOST_GENERATED") or ROOT / "infra/.generated")
TEMPLATE_DIRS = {"podman": ROOT / "infra/podman", "systemd": ROOT / "infra/systemd"}
# Production only, rendered as <instance>-<name>: the systemd socket that binds
# PROXY_HTTPS_BIND/POSTFIX_SMTP_BIND on the host and the service that starts nginx with
# those sockets (client addresses preserved); the Phase 9 reputation-evaluation timer.
PRODUCTION_TEMPLATE_DIR = ROOT / "infra/systemd/production"

ROW = re.compile(r"^\| `([A-Z][A-Z0-9_]*)` \| ([^|]+)\| (\*\*yes\*\*|no) \| ([^|]+)\| ([^|]*)\|", re.M)
PROFILE_ROW = re.compile(r"^\| `([A-Z][A-Z0-9_]*)` \| ([^|]*)\| ([^|]*)\|$", re.M)
# Secrets that may stay empty in production (Smarthost's own notifications are unused).
OPTIONAL_SECRETS = {"MAILER_DSN"}
# Reserved or placeholder names (RFC 2606, RFC 6761, RFC 5737): never production identities.
RESERVED_SUFFIXES = (".test", ".example", ".invalid", ".localhost", ".local", ".internal",
                     "example.com", "example.net", "example.org")


def contract() -> list[dict]:
    text = CONTRACT.read_text(encoding="utf-8")
    rows = []
    for block in re.split(r"\n## ", text)[1:]:
        title = block.splitlines()[0].strip()
        for name, consumers, secret, phase, example in ROW.findall(block):
            rows.append({
                "section": title, "name": name, "secret": secret == "**yes**",
                "consumers": [c.strip() for c in consumers.split(",")],
                "phase": phase.strip(), "example": "" if secret == "**yes**" else example.strip().strip("`"),
            })
    return rows


def production_profile() -> dict[str, str]:
    """The contract's `## Production profile` table: variable -> production value."""
    text = CONTRACT.read_text(encoding="utf-8")
    block = next((b for b in re.split(r"\n## ", text) if b.startswith("Production profile")), "")
    names = {r["name"] for r in contract()}
    profile = {}
    for name, value, _why in PROFILE_ROW.findall(block):
        if name not in names:
            sys.exit(f"environment.md: production profile lists unknown variable {name}")
        profile[name] = value.strip().strip("`")
    return profile


def write_template(path: Path, production: bool) -> None:
    profile = production_profile() if production else {}
    if production:
        head = [
            "# catto-mail PRODUCTION environment template — SAFE TO COMMIT, CONTAINS NO SECRETS.",
            "#",
            "# Contract: docs/contracts/environment.md (normative), section 'Production profile'.",
            "# Generated by: python3 infra/lib/smarthost_render.py env-example",
            "# On the production host run `infra/bin/smarthostctl prod init-env`: it copies this",
            "# file to infra/.env (gitignored, mode 0600) and generates every secret on the host.",
            "# Then replace every example.com / 203.0.113.x placeholder and run",
            "# `smarthostctl prod check`. Runbook: docs/production/runbook.md.",
            "# Empty APP_RETENTION_* values mean 'no automatic deletion' (decide before long-term",
            "# operation; see 'Retention settings in production' in the contract).",
            "",
        ]
    else:
        head = [
            "# Smarthost environment template — SAFE TO COMMIT, CONTAINS NO SECRETS.",
            "#",
            "# Contract: docs/contracts/environment.md (normative; consumers, phases, meanings).",
            "# Generated by: python3 infra/lib/smarthost_render.py env-example",
            "# For development run `infra/bin/smarthostctl init-env`, which copies this file to",
            "# infra/.env (gitignored) and fills the empty secrets with random development values.",
            "# Production uses infra/production.env.example (`smarthostctl prod init-env`).",
            "# Empty APP_RETENTION_* values mean 'no automatic deletion' until the",
            "# operator's compliance specification sets the production policy.",
            "# Each container receives only the variables listed as its consumers (rendered",
            "# per service into infra/.generated/env/ by `smarthostctl render`).",
            "",
        ]
    out, section = head, None
    for row in contract():
        if row["section"] != section:
            section = row["section"]
            out += ["# " + "-" * 70, f"# {section}", "# " + "-" * 70]
        tail = " | SECRET — leave empty here" if row["secret"] else ""
        if production and row["name"] in profile:
            tail += " | production profile"
        out.append(f"# consumers: {', '.join(row['consumers'])} | phase {row['phase']}{tail}")
        value = "" if row["secret"] else profile.get(row["name"], row["example"])
        out.append(f"{row['name']}={value}")
    out.append("")
    path.write_text("\n".join(out).rstrip() + "\n", encoding="utf-8")
    print(f"wrote {path.relative_to(ROOT)}")


def env_example() -> None:
    write_template(EXAMPLE, production=False)
    write_template(PRODUCTION_EXAMPLE, production=True)


def parse_dotenv(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for lineno, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        m = re.match(r"^([A-Z][A-Z0-9_]*)=(.*)$", line)
        if not m:
            sys.exit(f"{path}:{lineno}: not KEY=value")
        values[m.group(1)] = m.group(2)
    return values


def new_secret(name: str, production: bool) -> str:
    if name == "APP_ENCRYPTION_KEYS":
        return ("prod1:" if production else "dev1:") + base64.b64encode(secrets.token_bytes(32)).decode()
    if name == "MAILER_DSN":
        return ""  # Smarthost's own notifications are not used (sign-in mail uses APP_MAIL_SUBMISSION_*)
    return secrets.token_urlsafe(32 if production else 24)


def init_env(production: bool) -> None:
    if DOTENV.exists():
        print(f"{DOTENV} already exists; not overwriting")
        return
    template = PRODUCTION_EXAMPLE if production else EXAMPLE
    secret_names = {r["name"] for r in contract() if r["secret"]}
    lines = []
    for line in template.read_text(encoding="utf-8").splitlines():
        m = re.match(r"^([A-Z][A-Z0-9_]*)=$", line)
        if m and m.group(1) in secret_names:
            line = f"{m.group(1)}={new_secret(m.group(1), production)}"
        lines.append(line)
    header = (["# PRODUCTION — generated on this host by `smarthostctl prod init-env`. Never commit or copy",
               "# this file anywhere but the encrypted backup (`smarthostctl prod backup`).", ""] if production else
              ["# DEVELOPMENT ONLY — generated by `smarthostctl init-env`. Never commit this file.", ""])
    DOTENV.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(DOTENV, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        fh.write("\n".join(header + lines) + "\n")
    kind = "production" if production else "random development"
    print(f"created {DOTENV} with {kind} secrets (mode 0600)")


def reserved(name: str) -> bool:
    n = name.lower().rstrip(".")
    return n in ("localhost",) or any(n == s.lstrip(".") or n.endswith(s if s.startswith(".") else "." + s)
                                      for s in RESERVED_SUFFIXES)


HOSTNAME = re.compile(r"^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$")


def production_errors(v: dict[str, str]) -> list[str]:
    """The production safety rules (specification 2.9, Phase 8). Empty when safe."""
    errors: list[str] = []

    def need(cond: bool, msg: str) -> None:
        if not cond:
            errors.append(msg)

    need(v.get("APP_ENV") == "prod", "APP_ENV must be 'prod' in production")
    need(v.get("SMARTHOST_LIVE_DELIVERY_ENABLED") in ("true", "false"), "SMARTHOST_LIVE_DELIVERY_ENABLED must be true or false")
    need(v.get("SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS") == "false",
         "SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS must be false in production")
    need(v.get("POSTFIX_RELAYHOST", "") == "",
         "POSTFIX_RELAYHOST must be empty in production (no Mailpit or capture relay)")
    need(v.get("APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS", "") == "",
         "APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS must be empty in production (SSRF policy)")
    need(v.get("VALIDATOR_SMTP_ROUTE_OVERRIDE", "") == "", "VALIDATOR_SMTP_ROUTE_OVERRIDE must be empty in production")
    for row in contract():
        name = row["name"]
        if row["secret"] and name not in OPTIONAL_SECRETS:
            val = v.get(name, "")
            need(val != "", f"secret {name} is missing")
            need(val == "" or len(val) >= 16 or name == "APP_ENCRYPTION_KEYS", f"secret {name} is shorter than 16 characters")
    keys = v.get("APP_ENCRYPTION_KEYS", "")
    for item in filter(None, keys.split(",")):
        kid, _, b64 = item.partition(":")
        try:
            ok = bool(kid) and len(base64.b64decode(b64, validate=True)) == 32
        except ValueError:
            ok = False
        need(ok, "APP_ENCRYPTION_KEYS must be key_id:base64(32 bytes)[,...]")
    server = v.get("PROXY_SERVER_NAME", "").lower()
    # A rehearsal (no Internet egress at all) may use reserved names such as *.test;
    # a deployment that can reach the Internet never may.
    rehearsal = v.get("SMARTHOST_EGRESS_ENABLED") == "false"
    for var in ("PROXY_SERVER_NAME", "POSTFIX_MYHOSTNAME", "SMARTHOST_BOUNCE_DOMAIN", "VALIDATOR_SMTP_HELO_HOSTNAME"):
        name = v.get(var, "").lower()
        need(bool(HOSTNAME.match(name)), f"{var} must be a fully qualified DNS name (got '{name}')")
        need(rehearsal or not reserved(name), f"{var} is a reserved/placeholder name ('{name}'); set the real production name")
    url = urlsplit(v.get("SMARTHOST_PUBLIC_BASE_URL", ""))
    need(url.scheme == "https" and url.path in ("", "/") and not url.query and url.port in (None, 443),
         "SMARTHOST_PUBLIC_BASE_URL must be https://<PROXY_SERVER_NAME> (port 443, no path)")
    need((url.hostname or "") == server, "SMARTHOST_PUBLIC_BASE_URL host must equal PROXY_SERVER_NAME")
    bounce = v.get("SMARTHOST_BOUNCE_DOMAIN", "").lower()
    need(bounce != server, "SMARTHOST_BOUNCE_DOMAIN must be a dedicated domain, not the public hostname")
    for var in ("APP_MAIL_FROM", "APP_ADMIN_EMAIL", "VALIDATOR_SMTP_MAIL_FROM"):
        addr = v.get(var, "")
        local, _, domain = addr.rpartition("@")
        need(bool(local) and bool(HOSTNAME.match(domain.lower())) and (rehearsal or not reserved(domain)),
             f"{var} must be a real address in a production domain (got '{addr}')")
    egress = v.get("SMARTHOST_EGRESS_ENABLED")
    need(egress in ("true", "false"), "SMARTHOST_EGRESS_ENABLED must be true or false")
    try:
        ip = ipaddress.IPv4Address(v.get("SMARTHOST_PUBLIC_IPV4", ""))
        if egress == "true":
            need(ip.is_global, f"SMARTHOST_PUBLIC_IPV4 must be the host's public IPv4 address (got {ip})")
    except ValueError:
        errors.append("SMARTHOST_PUBLIC_IPV4 must be an IPv4 address")
    if v.get("SMARTHOST_LIVE_DELIVERY_ENABLED") == "true":
        need(egress == "true", "live delivery requires SMARTHOST_EGRESS_ENABLED=true")
    binds = []
    for var in ("PROXY_HTTPS_BIND", "POSTFIX_SMTP_BIND"):
        m = re.match(r"^([0-9.]+):([0-9]+)$", v.get(var, ""))
        try:
            ipaddress.IPv4Address(m.group(1) if m else "")
            ok = m is not None and 0 < int(m.group(2)) < 65536
        except ValueError:
            ok = False
        need(ok, f"{var} must be IPv4-address:port (the ingress socket binds it on the host)")
        binds.append(v.get(var, ""))
    need(binds[0] != binds[1], "PROXY_HTTPS_BIND and POSTFIX_SMTP_BIND must differ")
    subnets = []
    for var in ("SMARTHOST_INTERNAL_SUBNET", "SMARTHOST_EGRESS_SUBNET", "SMARTHOST_INGRESS_SUBNET"):
        try:
            net = ipaddress.IPv4Network(v.get(var, ""))
            need(net.is_private, f"{var} must be a private subnet")
            need(net.prefixlen == 24, f"{var} must be a /24 (services have fixed .10-.17 addresses)")
            subnets.append(net)
        except ValueError:
            errors.append(f"{var} must be an IPv4 CIDR")
    if len(subnets) == 3:
        need(not any(a.overlaps(b) for n, a in enumerate(subnets) for b in subnets[n + 1:]),
             "SMARTHOST_INTERNAL_SUBNET, SMARTHOST_EGRESS_SUBNET and SMARTHOST_INGRESS_SUBNET must not overlap")
    # nginx receives each client connection itself (the inherited ingress socket) and
    # passes the peer as REMOTE_ADDR: no forwarded header is ever trusted in production.
    need(v.get("APP_PUBLIC_ONBOARDING_ENABLED") in ("true", "false"), "APP_PUBLIC_ONBOARDING_ENABLED must be true or false")
    policy = v.get("APP_ACCEPTABLE_USE_POLICY_VERSION", "")
    need(policy == "" or bool(re.match(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$", policy)),
         "APP_ACCEPTABLE_USE_POLICY_VERSION must be empty or a short version identifier such as 2026-10")
    need(v.get("TRUSTED_PROXIES", "") == "",
         "TRUSTED_PROXIES must be empty in production (no proxy in front of nginx; the client address is REMOTE_ADDR)")
    need(bool(re.match(r"^[a-z][a-z0-9-]{1,30}$", v.get("SMARTHOST_INSTANCE", ""))), "SMARTHOST_INSTANCE must match [a-z][a-z0-9-]+")
    need(bool(re.match(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$", v.get("SMARTHOST_IMAGE_TAG", ""))), "SMARTHOST_IMAGE_TAG must be a valid image tag")
    # Service addresses are the production topology's network aliases.
    expected = {"SMARTHOST_DB_HOST": "postgres", "DELIVERY_POSTFIX_SUBMISSION_HOST": "postfix",
                "APP_MAIL_SUBMISSION_HOST": "postfix"}
    for var, alias in expected.items():
        need(v.get(var) == alias, f"{var} must be '{alias}' (the production network alias)")
    need(v.get("PROXY_FASTCGI_ADDRESS", "").startswith("symfony-app:"), "PROXY_FASTCGI_ADDRESS must be symfony-app:<port>")
    need(v.get("SMARTHOST_OPENDKIM_MILTER_ADDRESS", "").startswith("inet:opendkim:"),
         "SMARTHOST_OPENDKIM_MILTER_ADDRESS must be inet:opendkim:<port>")
    try:
        connect, timeout = int(v.get("APP_WEBHOOK_CONNECT_TIMEOUT_SECONDS", "0")), int(v.get("APP_WEBHOOK_TIMEOUT_SECONDS", "0"))
        need(1 <= connect <= timeout, "APP_WEBHOOK_CONNECT_TIMEOUT_SECONDS must be between 1 and APP_WEBHOOK_TIMEOUT_SECONDS")
    except ValueError:
        errors.append("webhook timeouts must be integers")
    return errors


def validate(values: dict[str, str]) -> list[str]:
    """Contract completeness plus the environment's safety rules."""
    names = {r["name"] for r in contract()}
    errors = []
    unknown, missing = sorted(set(values) - names), sorted(names - set(values))
    if unknown or missing:
        errors.append(f"the .env does not match the contract: unknown={unknown} missing={missing}")
    env = values.get("SMARTHOST_ENV")
    if env not in ("development", "test", "production"):
        errors.append("SMARTHOST_ENV must be development, test or production")
    if env == "production":
        errors += production_errors(values)
    else:
        if values.get("SMARTHOST_LIVE_DELIVERY_ENABLED") == "true":
            errors.append("SMARTHOST_LIVE_DELIVERY_ENABLED=true is only permitted with SMARTHOST_ENV=production")
    if env == "production" and values.get("SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS") == "true":
        errors.append("SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS=true is forbidden in production")
    return sorted(set(errors))


def load(path: Path) -> dict[str, str]:
    if not path.exists():
        sys.exit(f"{path} missing: run `smarthostctl init-env` (development) or `smarthostctl prod init-env` first")
    return parse_dotenv(path)


def check(path: Path) -> None:
    values = load(path)
    errors = validate(values)
    for e in errors:
        print(f"FAIL  {e}")
    if errors:
        sys.exit(f"{path}: {len(errors)} configuration error(s)")
    print(f"PASS  {path} satisfies the contract and the {values['SMARTHOST_ENV']} safety rules")


def render(path: Path, out: Path) -> None:
    rows = contract()
    names = {r["name"] for r in rows}
    values = load(path)
    errors = validate(values)
    if errors:
        sys.exit(f"{path} does not satisfy the contract:\n  " + "\n  ".join(errors))

    env_dir = out / "env"
    env_dir.mkdir(parents=True, exist_ok=True)
    os.chmod(out, 0o700)
    consumers = sorted({c for r in rows for c in r["consumers"]})
    for consumer in consumers:
        body = [f"# Generated for consumer '{consumer}' from {path.name}. Do not edit."]
        body += [f"{r['name']}={values[r['name']]}" for r in rows if consumer in r["consumers"]]
        target = env_dir / f"{consumer}.env"
        target.write_text("\n".join(body) + "\n", encoding="utf-8")
        target.chmod(stat.S_IRUSR | stat.S_IWUSR)

    production = values["SMARTHOST_ENV"] == "production"
    topology = "smarthost-production.sh" if production else "smarthost-pod.sh"
    placeholder = re.compile(r"\$\{([A-Za-z0-9_]+)\}")
    for kind, src_dir in TEMPLATE_DIRS.items():
        out_dir = out / kind
        out_dir.mkdir(parents=True, exist_ok=True)
        for old in out_dir.iterdir():
            old.unlink()
        templates = [(t, t.name[:-3]) for t in sorted(src_dir.glob("*.in"))]
        if kind == "systemd" and production:
            templates += [(t, f"{values['SMARTHOST_INSTANCE']}-{t.name[:-3]}") for t in sorted(PRODUCTION_TEMPLATE_DIR.glob("*.in"))]
        for template, target_name in templates:
            text = template.read_text(encoding="utf-8")
            bad = sorted({m for m in placeholder.findall(text) if m not in names})
            if bad:
                sys.exit(f"{template.relative_to(ROOT)} references non-contract variables {bad}")
            shell = template.name.endswith(".sh.in")
            quote: Callable[[str], str] = shlex.quote if shell else str

            def substitute(m: re.Match[str], q: Callable[[str], str] = quote) -> str:
                return q(values[m.group(1)])

            text = placeholder.sub(substitute, text)
            text = (text.replace("@REPO@", quote(str(ROOT))).replace("@GENERATED@", quote(str(out)))
                    .replace("@TOPOLOGY@", topology))
            target = out_dir / target_name
            target.write_text(text, encoding="utf-8")
            if shell:
                target.chmod(stat.S_IRWXU)
    print(f"rendered {len(consumers)} env files and units ({values['SMARTHOST_ENV']} topology: {topology}) into {out}/")


def main(argv: list[str]) -> None:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("env-example")
    i = sub.add_parser("init-env")
    i.add_argument("--production", action="store_true")
    for name in ("check", "render"):
        s = sub.add_parser(name)
        s.add_argument("--env", type=Path, default=DOTENV)
        if name == "render":
            s.add_argument("--out", type=Path, default=GENERATED)
    a = p.parse_args(argv)
    if a.cmd == "env-example":
        env_example()
    elif a.cmd == "init-env":
        init_env(a.production)
    elif a.cmd == "check":
        check(a.env)
    else:
        render(a.env, a.out)


if __name__ == "__main__":
    main(sys.argv[1:])
