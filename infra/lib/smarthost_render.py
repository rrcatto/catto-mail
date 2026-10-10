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
    get NAME [--env FILE]  print one setting's value (the .env's, else the built-in one)
    migrate-env [--env FILE] [--version V]
                           rewrite an older .env in the current grouped layout, keeping
                           every value; the old file stays beside it as
                           .env.v<version>-<YYYYmmddTHHMMSS>
    render  [--env FILE] [--out DIR]
                           validate, write one env file per consumer (contract
                           "Consumers" column - least privilege) and render
                           infra/podman/*.in (the development pod script and the
                           production topology script) and infra/systemd/*.in; in
                           production also infra/systemd/production/*.in as
                           <SMARTHOST_INSTANCE>-<name> (the ingress socket and the
                           nginx service it activates; the reputation-evaluation timer)

The .env holds only the settings an operator sets (LAYOUT); every other contract variable
is built in (BUILT-IN values: the contract's example, in production the production profile).

FILE defaults to $SMARTHOST_DOTENV or infra/.env, DIR to $SMARTHOST_GENERATED or
infra/.generated (smarthostctl sets both for a production rehearsal).

Templates may reference only contract variables as ${VAR}, plus @REPO@ (the
repository path), @GENERATED@ (the output directory), @DOTENV@ (the configuration
file rendered from; production units pass it to the host tooling) and @TOPOLOGY@ (the
topology script the systemd units drive: smarthost-pod.sh in development and test,
smarthost-production.sh in production). Anything else is an error, so no
undocumented variable can reach a service. In shell templates (*.sh.in) every
substituted value is shell-quoted, and the rendered script is executable.
"""
from __future__ import annotations

import argparse
import base64
import datetime as dt
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
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

ROOT = Path(__file__).resolve().parents[2]
CONTRACT = ROOT / "docs/contracts/environment.md"
SETTINGS_CATALOG = ROOT / "docs/contracts/settings.json"
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


# The .env file holds only what an operator sets, grouped under plain headings. Each entry is
# (variable, where): "both", "development" or "production". Every other contract variable is
# built in: the renderer supplies its value (the contract's example, or in production the
# production profile's value), and a .env may still name it to change that value.
B, D, P = "both", "development", "production"
LAYOUT: list[tuple[str, list[tuple[str, str]]]] = [
    ("Installation", [("SMARTHOST_ENV", B), ("SMARTHOST_TIMEZONE", B), ("SMARTHOST_LOG_LEVEL", B)]),
    ("Set by smarthostctl (live-enable, upgrade, rollback); do not edit by hand", [
        ("SMARTHOST_LIVE_DELIVERY_ENABLED", B), ("SMARTHOST_IMAGE_TAG", P)]),
    ("Server", [("SMARTHOST_PUBLIC_IPV4", P), ("SMARTHOST_SSH_PORT", P), ("SMARTHOST_EGRESS_ENABLED", P)]),
    ("Web address", [("SMARTHOST_PUBLIC_BASE_URL", B), ("PROXY_SERVER_NAME", B), ("PROXY_HTTPS_BIND", B)]),
    ("Mail server and domains", [
        ("POSTFIX_MYHOSTNAME", B), ("SMARTHOST_BOUNCE_DOMAIN", B), ("POSTFIX_SMTP_BIND", P),
        ("POSTFIX_MESSAGE_SIZE_LIMIT", B), ("SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS", D)]),
    ("Dashboard sign-in", [("APP_ADMIN_EMAIL", B), ("APP_MAIL_FROM", B), ("APP_LOGIN_LINK_TTL_SECONDS", B)]),
    ("Delivery pacing", [
        ("DELIVERY_GLOBAL_CONCURRENCY", B), ("DELIVERY_PER_DOMAIN_CONCURRENCY", B),
        ("DELIVERY_PER_DOMAIN_RATE_PER_MINUTE", B), ("DELIVERY_GLOBAL_RATE_PER_MINUTE", B),
        ("DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE", B), ("DELIVERY_DEFERRAL_BACKOFF_SECONDS", B),
        ("DELIVERY_POLL_INTERVAL_SECONDS", B), ("DELIVERY_LEASE_SECONDS", B),
        ("DELIVERY_DSN_NOTIFY", B), ("DELIVERY_DSN_RET", B)]),
    ("Bounces and reconciliation", [
        ("DELIVERY_SOFT_BOUNCE_SUPPRESSION_THRESHOLD", B), ("DELIVERY_SOFT_BOUNCE_SUPPRESSION_WINDOW_DAYS", B),
        ("DELIVERY_FILE_POLL_INTERVAL_SECONDS", B), ("DELIVERY_RECONCILE_INTERVAL_SECONDS", B),
        ("DELIVERY_RECONCILE_GRACE_SECONDS", B), ("DELIVERY_RECONCILE_MIN_SNAPSHOTS", B),
        ("POSTFIX_QUEUE_SNAPSHOT_INTERVAL_SECONDS", B), ("POSTFIX_QUEUE_SNAPSHOT_RETENTION_COUNT", B)]),
    ("Email validation", [
        ("VALIDATOR_CHUNK_SIZE", B), ("VALIDATOR_LEASE_SECONDS", B), ("VALIDATOR_POLL_INTERVAL_SECONDS", B),
        ("VALIDATOR_GLOBAL_CONCURRENCY", B), ("VALIDATOR_PER_DOMAIN_CONCURRENCY", B),
        ("VALIDATOR_PER_MX_CONCURRENCY", B), ("VALIDATOR_MAX_ATTEMPTS", B), ("VALIDATOR_RETRY_BASE_SECONDS", B),
        ("VALIDATOR_RETRY_MAX_SECONDS", B), ("VALIDATOR_DNS_RESOLVERS", B), ("VALIDATOR_DNS_TIMEOUT_SECONDS", B),
        ("VALIDATOR_SMTP_PROBE_ENABLED", B), ("VALIDATOR_SMTP_HELO_HOSTNAME", B), ("VALIDATOR_SMTP_MAIL_FROM", B),
        ("VALIDATOR_SMTP_CONNECT_TIMEOUT_SECONDS", B), ("VALIDATOR_SMTP_COMMAND_TIMEOUT_SECONDS", B)]),
    ("API and client limits", [
        ("APP_API_RATE_LIMIT_PER_MINUTE", B), ("APP_THROTTLED_CLIENT_API_RATE_PER_MINUTE", B),
        ("APP_API_MAX_REQUEST_BYTES", B), ("APP_SEND_JOB_MAX_RECIPIENTS", B),
        ("APP_SEND_JOB_MAX_RECIPIENTS_PER_BATCH", B), ("APP_CLIENT_API_KEY_LIMIT", B),
        ("APP_CLIENT_WEBHOOK_ENDPOINT_LIMIT", B), ("APP_CLIENT_SENDING_DOMAIN_LIMIT", B),
        ("APP_DOMAIN_VERIFICATION_RECHECK_HOURS", B), ("APP_ACCEPTABLE_USE_POLICY_VERSION", B),
        ("APP_REPERMISSION_RESPONSE_DAYS", B)]),
    ("Webhooks", [
        ("APP_WEBHOOK_MAX_ATTEMPTS", B), ("APP_WEBHOOK_TIMEOUT_SECONDS", B), ("APP_WEBHOOK_CONNECT_TIMEOUT_SECONDS", B),
        ("APP_WEBHOOK_POLL_INTERVAL_SECONDS", B), ("APP_WEBHOOK_LEASE_SECONDS", B), ("APP_WEBHOOK_RETRY_BASE_SECONDS", B),
        ("APP_WEBHOOK_RETRY_MAX_SECONDS", B), ("APP_WEBHOOK_SECRET_OVERLAP_HOURS", B)]),
    ("Reputation alerts", [
        ("APP_REPUTATION_MIN_MESSAGES", B), ("APP_REPUTATION_HARD_BOUNCE_WARNING_PERCENT", B),
        ("APP_REPUTATION_HARD_BOUNCE_CRITICAL_PERCENT", B), ("APP_REPUTATION_COMPLAINT_WARNING_PERCENT", B),
        ("APP_REPUTATION_COMPLAINT_CRITICAL_PERCENT", B), ("APP_REPUTATION_DEFERRAL_WARNING_PERCENT", B),
        ("APP_REPUTATION_DEFERRAL_CRITICAL_PERCENT", B), ("APP_REPUTATION_VOLUME_INCREASE_WARNING_FACTOR", B),
        ("APP_REPUTATION_VOLUME_INCREASE_CRITICAL_FACTOR", B)]),
    ("Data retention", [
        ("APP_RETENTION_STAGED_CONTENT_DAYS", B), ("APP_RETENTION_TRACKING_DAYS", B),
        ("DELIVERY_DSN_RETENTION_DAYS", B), ("POSTFIX_LOG_RETENTION_DAYS", B)]),
    ("Certificates (Let's Encrypt)", [
        ("ACME_EMAIL", P), ("ACME_DNS_PROVIDER", P), ("ACME_CREDENTIALS_FILE", P), ("ACME_SERVER", P), ("ACME_RENEW_DAYS", P)]),
    ("Backups", [
        ("BACKUP_DIR", P), ("BACKUP_KEEP", P), ("BACKUP_SCHEDULE", P), ("BACKUP_OFFHOST_TARGET", P),
        ("BACKUP_OFFHOST_SSH_KEY", P), ("BACKUP_ENCRYPTION_PASSPHRASE_FILE", P)]),
    ("Development mail viewer (Mailpit)", [("MAILPIT_UI_BIND", D)]),
    ("Secrets: generated by init-env; never share, copy or commit them", [
        ("POSTGRES_PASSWORD", B), ("SMARTHOST_DB_OWNER_PASSWORD", B), ("APP_DB_PASSWORD", B),
        ("APP_WEBHOOK_DB_PASSWORD", B), ("VALIDATOR_DB_PASSWORD", B), ("DELIVERY_DB_PASSWORD", B), ("APP_SECRET", B),
        ("APP_ENCRYPTION_KEYS", B), ("SMARTHOST_SUBMISSION_PASSWORD", B), ("APP_MAIL_SUBMISSION_PASSWORD", B)]),
]
CHANGED_BUILT_INS = "Built-in settings changed from their default (see docs/contracts/environment.md)"
# Names an older .env may still use: old name -> current name.
RENAMED = {"APP_TIMEZONE": "SMARTHOST_TIMEZONE"}


def environment_of(values: dict[str, str]) -> str:
    return "production" if values.get("SMARTHOST_ENV") == "production" else "development"


def layout_names(environment: str) -> list[str]:
    """The variables the .env of this environment holds, in file order."""
    return [name for _, rows in LAYOUT for name, where in rows if where in (B, environment)]


def defaults(environment: str) -> dict[str, str]:
    """Every contract variable's built-in value: the production profile's value in production,
    otherwise the contract's example (secrets: empty)."""
    profile = production_profile() if environment == "production" else {}
    return {r["name"]: "" if r["secret"] else profile.get(r["name"], r["example"]) for r in contract()}


def resolve(values: dict[str, str]) -> dict[str, str]:
    """A .env's values completed with the built-in values of its environment."""
    return {**defaults(environment_of(values)), **values}


def file_text(environment: str, values: dict[str, str], header: list[str]) -> str:
    """The .env text of an environment: header, the grouped settings, then any built-in setting
    whose value differs from its default."""
    out = list(header)
    for title, rows in LAYOUT:
        names = [name for name, where in rows if where in (B, environment)]
        if names:
            out += ["", f"# === {title} ==="] + [f"{n}={values.get(n, '')}" for n in names]
    builtin = defaults(environment)
    placed = set(layout_names(environment))
    changed = [n for n in builtin if n not in placed and n in values and values[n] != builtin[n]]
    if changed:
        out += ["", f"# === {CHANGED_BUILT_INS} ==="] + [f"{n}={values[n]}" for n in changed]
    return "\n".join(out).rstrip() + "\n"


TEMPLATE_HEADER = {
    "development": [
        "# catto-mail configuration, development. This template is safe to commit: it has no secrets.",
        "# `infra/bin/smarthostctl init-env` copies it to infra/.env and generates the secrets there.",
        "# Each setting is explained in docs/contracts/environment.md; settings that are not listed",
        "# here are built in. Settings changed in the dashboard (System > Settings) take precedence",
        "# over this file without changing it.",
    ],
    "production": [
        "# catto-mail configuration, production. This template is safe to commit: it has no secrets.",
        "# `smarthostctl prod init-env` copies it to infra/.env (mode 0600) and generates the secrets",
        "# on the host. Replace every example.com and 203.0.113.x placeholder, then run",
        "# `smarthostctl prod check`. Each setting is explained in docs/contracts/environment.md;",
        "# settings that are not listed here are built in. Settings changed in the dashboard",
        "# (System > Settings) take precedence over this file without changing it.",
    ],
}
FILE_HEADER = {
    "development": ["# catto-mail configuration, development (infra/.env). Never commit this file.",
                    "# Each setting is explained in docs/contracts/environment.md; settings that are not listed",
                    "# here are built in. Settings changed in the dashboard (System > Settings) take precedence",
                    "# over this file without changing it."],
    "production": ["# catto-mail configuration, production (infra/.env, mode 0600). It holds this host's secrets:",
                   "# never copy it anywhere but the encrypted backup (`smarthostctl prod backup`).",
                   "# Each setting is explained in docs/contracts/environment.md; settings that are not listed",
                   "# here are built in. Settings changed in the dashboard (System > Settings) take precedence",
                   "# over this file without changing it."],
}


def write_template(path: Path, production: bool) -> None:
    environment = "production" if production else "development"
    path.write_text(file_text(environment, defaults(environment), TEMPLATE_HEADER[environment]), encoding="utf-8")
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
    return secrets.token_urlsafe(32 if production else 24)


def init_env(production: bool) -> None:
    if DOTENV.exists():
        print(f"{DOTENV} already exists; not overwriting")
        return
    environment = "production" if production else "development"
    values = defaults(environment)
    for r in contract():
        if r["secret"] and r["name"] in layout_names(environment):
            values[r["name"]] = new_secret(r["name"], production)
    DOTENV.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(DOTENV, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        fh.write(file_text(environment, values, FILE_HEADER[environment]))
    kind = "production" if production else "random development"
    print(f"created {DOTENV} with {kind} secrets (mode 0600)")


def software_version() -> str:
    """The checkout's version (app/src/Version.php)."""
    m = re.search(r"VERSION = '([^']+)'", (ROOT / "app/src/Version.php").read_text(encoding="utf-8"))
    return m.group(1) if m else "unknown"


def migrate_env(path: Path, version: str | None) -> None:
    """Rewrite a .env in the current layout, keeping every value. The old file is kept beside it as
    <name>.v<version>-<YYYYmmddTHHMMSS> (installation time zone). Built-in settings that still have
    their default value are dropped, renamed settings get their new name, and names the contract
    no longer has are dropped (both are listed)."""
    old_text = path.read_text(encoding="utf-8")
    raw = parse_dotenv(path)
    notes = []
    for old, new in RENAMED.items():
        if old in raw:
            value = raw.pop(old)
            if new not in raw:
                raw[new] = value
                notes.append(f"renamed {old} to {new}")
            else:
                notes.append(f"dropped {old} ({new} is set)")
    names = {r["name"] for r in contract()}
    for name in sorted(set(raw) - names):
        notes.append(f"dropped {name}={raw.pop(name)} (not a catto-mail setting any more)")
    environment = environment_of(raw)
    builtin = defaults(environment)
    placed = set(layout_names(environment))
    for name in sorted(set(raw) - placed):
        if raw[name] == builtin[name]:
            notes.append(f"dropped {name} (built in, same value)")
    values = {**{n: builtin[n] for n in placed}, **raw}
    added = sorted(placed - set(raw))
    notes += [f"added {n}={builtin[n]} (built-in default)" for n in added]
    new_text = file_text(environment, values, FILE_HEADER[environment])
    if new_text == old_text:
        print(f"{path} is already in the current layout")
        return
    tag = raw.get("SMARTHOST_IMAGE_TAG", "")
    version = version or (tag if re.fullmatch(r"\d+\.\d+\.\d+", tag) else software_version())
    stamp = dt.datetime.now(zone_of(raw)).strftime("%Y%m%dT%H%M%S")
    backup = path.with_name(f"{path.name}.v{version.lstrip('v')}-{stamp}")
    if backup.exists():
        sys.exit(f"{backup} exists; not overwriting it")
    mode = stat.S_IMODE(path.stat().st_mode)
    os.rename(path, backup)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode or 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        fh.write(new_text)
    print(f"kept the previous file as {backup}")
    print(f"rewrote {path} in the current layout ({len(placed)} settings)")
    for note in notes:
        print(f"  {note}")


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
        if row["secret"]:
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


DEFAULT_TIMEZONE = "Africa/Johannesburg"


def zone_of(values: dict[str, str]) -> ZoneInfo:
    """The installation's time zone (SMARTHOST_TIMEZONE; SAST when a configuration predates it)."""
    name = values.get("SMARTHOST_TIMEZONE") or DEFAULT_TIMEZONE
    return ZoneInfo(name if valid_timezone(name) else DEFAULT_TIMEZONE)


def valid_timezone(name: str) -> bool:
    """An IANA zone name the host's zoneinfo knows (the containers carry the same database)."""
    if not re.fullmatch(r"[A-Za-z][A-Za-z0-9_+-]*(/[A-Za-z0-9_+-]+)*", name) or name in ("Local", "localtime"):
        return False
    try:
        ZoneInfo(name)
    except (ZoneInfoNotFoundError, ValueError):
        return False
    return True


def validate(raw: dict[str, str]) -> list[str]:
    """The .env holds every setting of its environment's layout and nothing outside the contract;
    then the environment's safety rules on the resolved values (built-in values included)."""
    names = {r["name"] for r in contract()}
    errors = []
    unknown = sorted(set(raw) - names)
    missing = sorted(set(layout_names(environment_of(raw))) - set(raw))
    renamed = [f"{old} is now {RENAMED[old]}" for old in unknown if old in RENAMED]
    if renamed:
        errors.append("; ".join(renamed) + ": run `smarthostctl env-migrate` (production: `smarthostctl prod env-migrate`)")
    if unknown or missing:
        errors.append(f"the .env does not match the contract: unknown={unknown} missing={missing}")
    values = resolve(raw)
    if "SMARTHOST_TIMEZONE" in values and not valid_timezone(values["SMARTHOST_TIMEZONE"]):
        errors.append(f"SMARTHOST_TIMEZONE must be an IANA time zone name such as Africa/Johannesburg "
                      f"(got {values['SMARTHOST_TIMEZONE']!r})")
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


def load_raw(path: Path) -> dict[str, str]:
    if not path.exists():
        sys.exit(f"{path} missing: run `smarthostctl init-env` (development) or `smarthostctl prod init-env` first")
    return parse_dotenv(path)


def load(path: Path) -> dict[str, str]:
    """Every setting's value: the .env's, else the built-in one."""
    return resolve(load_raw(path))


def check(path: Path) -> None:
    raw = load_raw(path)
    values = resolve(raw)
    errors = validate(raw)
    for e in errors:
        print(f"FAIL  {e}")
    if errors:
        sys.exit(f"{path}: {len(errors)} configuration error(s)")
    print(f"PASS  {path} satisfies the contract and the {values['SMARTHOST_ENV']} safety rules")


def catalog() -> dict[str, dict]:
    """The settings the dashboard may change (docs/contracts/settings.json), by name."""
    import json
    return {e["name"]: e for e in json.loads(SETTINGS_CATALOG.read_text(encoding="utf-8"))["settings"]}


def override_error(entry: dict, value: str, environment: str) -> str | None:
    """Why a dashboard value is not acceptable for its setting, or None (the same rules as
    App\\System\\SettingCatalog in the application)."""
    if entry.get("production_only") and environment != "production":
        return "applies to production only"
    kind = entry["kind"]
    if value == "" and entry.get("empty"):
        return None
    if kind in ("integer", "decimal"):
        pattern = r"-?\d{1,12}" if kind == "integer" else r"-?\d{1,12}(\.\d{1,6})?"
        if not re.fullmatch(pattern, value):
            return f"must be {'a whole number' if kind == 'integer' else 'a number'}"
        if not entry["min"] <= float(value) <= entry["max"]:
            return f"must be between {entry['min']} and {entry['max']}"
        return None
    if kind == "choice":
        return None if value in entry["choices"] else f"must be one of {', '.join(entry['choices'])}"
    if kind == "timezone":
        return None if valid_timezone(value) else "must be an IANA time zone name such as Africa/Johannesburg"
    if kind == "text":
        return None if re.fullmatch(entry["pattern"], value) else "has characters or a form this setting does not accept"
    return f"unknown kind {kind}"


def merge_overrides(raw: dict[str, str], overrides: dict[str, str]) -> tuple[dict[str, str], dict[str, str], dict[str, str], list[str]]:
    """The .env values with the dashboard's overrides on top. Returns (raw values to render,
    applied overrides, ignored overrides with the reason, rule errors). An override that is not
    in the catalogue or breaks its rule is ignored; when the merged configuration breaks a rule
    of the environment that the .env alone keeps, every override is ignored (rule errors listed),
    so a bad dashboard value never stops the services from being rendered."""
    environment = environment_of(raw)
    entries = catalog()
    applied: dict[str, str] = {}
    ignored: dict[str, str] = {}
    for name, value in sorted(overrides.items()):
        value = str(value)
        if name not in entries:
            ignored[name] = "not a setting the dashboard may change"
        elif (why := override_error(entries[name], value, environment)) is not None:
            ignored[name] = why
        else:
            applied[name] = value
    merged = {**raw, **applied}
    errors = validate(merged)
    if applied and errors:
        return raw, {}, {**ignored, **{n: "not applied: the combined configuration breaks a rule" for n in applied}}, errors
    return merged, applied, ignored, []


def render(path: Path, out: Path, overrides_path: Path | None = None) -> None:
    rows = contract()
    names = {r["name"] for r in rows}
    raw = load_raw(path)
    errors = validate(raw)
    if errors:
        sys.exit(f"{path} does not satisfy the contract:\n  " + "\n  ".join(errors))
    overrides: dict[str, str] = {}
    if overrides_path is not None and overrides_path.is_file():
        import json
        overrides = json.loads(overrides_path.read_text(encoding="utf-8") or "{}") or {}
    config = resolve(raw)
    raw, applied, ignored, rule_errors = merge_overrides(raw, overrides)
    for name, why in ignored.items():
        print(f"WARNING: dashboard setting {name} ignored: {why}", file=sys.stderr)
    for e in rule_errors:
        print(f"WARNING: {e}", file=sys.stderr)
    values = resolve(raw)

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

    write_settings_state(out / "settings.json", config, applied, ignored, rule_errors)

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
                    .replace("@DOTENV@", quote(str(path.resolve()))).replace("@TOPOLOGY@", topology))
            target = out_dir / target_name
            target.write_text(text, encoding="utf-8")
            if shell:
                target.chmod(stat.S_IRWXU)
    print(f"rendered {len(consumers)} env files and units ({values['SMARTHOST_ENV']} topology: {topology}) into {out}/")


def write_settings_state(path: Path, config: dict[str, str], applied: dict[str, str], ignored: dict[str, str],
                         rule_errors: list[str]) -> None:
    """What the dashboard's System > Settings page shows as in force: each changeable setting's
    value from the .env (or built in) and the overrides this render applied. Mounted read-only
    into the application container; written in place so the mount sees every render."""
    import json
    environment = environment_of(config)
    state = {
        "rendered_at": dt.datetime.now(zone_of({**config, **applied})).isoformat(timespec="seconds"),
        "environment": environment,
        "config": {n: config[n] for n, e in catalog().items() if not (e.get("production_only") and environment != "production")},
        "applied": applied,
        "ignored": ignored,
        "errors": rule_errors,
    }
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(json.dumps(state, indent=2, sort_keys=True) + "\n")
    path.chmod(0o644)


def main(argv: list[str]) -> None:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("env-example")
    i = sub.add_parser("init-env")
    i.add_argument("--production", action="store_true")
    for name in ("check", "render", "get", "migrate-env"):
        s = sub.add_parser(name)
        s.add_argument("--env", type=Path, default=DOTENV)
        if name == "render":
            s.add_argument("--out", type=Path, default=GENERATED)
            s.add_argument("--overrides", type=Path, default=None,
                           help="JSON object of the dashboard's setting overrides (System > Settings)")
        if name == "get":
            s.add_argument("name")
        if name == "migrate-env":
            s.add_argument("--version", default=None, help="the version the old file belongs to (default: its image tag, else this checkout's)")
    a = p.parse_args(argv)
    if a.cmd == "env-example":
        env_example()
    elif a.cmd == "init-env":
        init_env(a.production)
    elif a.cmd == "check":
        check(a.env)
    elif a.cmd == "get":
        values = load(a.env)
        if a.name not in values:
            sys.exit(f"{a.name} is not a catto-mail setting")
        print(values[a.name])
    elif a.cmd == "migrate-env":
        migrate_env(a.env, a.version)
    else:
        render(a.env, a.out, a.overrides)


if __name__ == "__main__":
    main(sys.argv[1:])
