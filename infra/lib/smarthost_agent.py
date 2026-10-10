#!/usr/bin/env python3
"""catto-mail host agent (specification 2.11).

A small service on the production host, run by the service user's systemd as
<instance>-host-agent.service (`smarthostctl prod agent run`). It is the only bridge
between the web application and the host:

  * it reports what only the host can see — Ubuntu, Podman, lingering, the systemd units,
    disk, memory, clock, the containers' health, backups, the boot recovery — and runs the
    production preflight (host, runtime, exposure, ingress, TLS, DNS, DKIM, Postfix,
    delivery), recording every result as a system check;
  * it carries out the requests an administrator made in the dashboard (system_requests):
    run diagnostics, hold or release the Postfix queue, live enable/disable, backup, restore
    rehearsal, DKIM key generation and activation, certificate renewal — each with the
    existing, audited production tooling (smarthostctl prod ...).

The web application never runs host commands; it only records requests. The agent talks
to the application through `podman exec ... php bin/console smarthost:system:agent` (JSON
on stdin/stdout) and polls the request queue cheaply with psql. Standard library only.

  smarthost_agent.py run [--poll SECONDS]   the service loop
  smarthost_agent.py once [--requested] [--section S] [--no-preflight]
  smarthost_agent.py facts                  print the host facts (JSON)
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import re
import shutil
import sys
import time
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))
import smarthost_render as render  # noqa: E402
from smarthost_preflight import Check, DnsError, Host, Preflight, Resolver, dkim_public_key  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
PROD = str(ROOT / "infra/bin/smarthostctl-prod")
SERVICES = ("postgres", "opendkim", "postfix", "symfony-app", "webhook-worker", "nginx", "validator", "delivery")
SERVICE_COMPONENT = {"postgres": "database", "opendkim": "opendkim", "postfix": "postfix", "symfony-app": "web",
                     "webhook-worker": "webhook", "nginx": "nginx", "validator": "validator", "delivery": "delivery"}
SERVICE_NAME = {"postgres": "PostgreSQL Database", "opendkim": "OpenDKIM Signing Service", "postfix": "Postfix Mail Transfer Agent",
                "symfony-app": "Catto-mail Web Application", "webhook-worker": "Catto-mail Webhook Worker", "nginx": "nginx Frontend",
                "validator": "Catto-mail Email Validator", "delivery": "Catto-mail Delivery Daemon"}
# Non-secret configuration shown in the setup wizard.
CONFIG_KEYS = ("PROXY_SERVER_NAME", "SMARTHOST_PUBLIC_BASE_URL", "APP_ADMIN_EMAIL", "POSTFIX_MYHOSTNAME", "SMARTHOST_PUBLIC_IPV4",
               "SMARTHOST_BOUNCE_DOMAIN", "APP_MAIL_FROM", "VALIDATOR_SMTP_PROBE_ENABLED", "SMARTHOST_LIVE_DELIVERY_ENABLED",
               "SMARTHOST_IMAGE_TAG", "DELIVERY_GLOBAL_RATE_PER_MINUTE", "DELIVERY_PER_DOMAIN_RATE_PER_MINUTE",
               "ACME_DNS_PROVIDER", "ACME_RENEW_DAYS", "BACKUP_SCHEDULE", "BACKUP_KEEP")
HOST_INTERVAL = 60          # host facts and host checks
PREFLIGHT_INTERVAL = 3600   # the full preflight (DNS, TLS, Postfix RCPT test, ingress proof)
APP_INTERVAL = 900          # the application's own checks
SECRET_KEY = re.compile(r"PASSWORD|SECRET|_KEY|KEYS|TOKEN|PASSPHRASE|DSN", re.I)


def slug(text: str) -> str:
    return re.sub(r"-+", "-", re.sub(r"[^a-z0-9]+", "-", text.lower())).strip("-")


def component_of(section: str, name: str) -> str:
    n = name.lower()
    if section == "host":
        return "boot" if ("linger" in n or "persists" in n) else "host"
    if section == "runtime":
        if any(w in n for w in ("database", "migration", "grant")):
            return "database"
        return "web" if "reputation" in n else "host"
    if section == "postfix" and "bounce domain" in n:
        return "bounce"
    return {"config": "security", "exposure": "security", "ingress": "nginx", "tls": "tls", "dns": "dns",
            "dkim": "opendkim", "postfix": "postfix", "delivery": "delivery"}.get(section, "host")


def preflight_checks(results: list[Check]) -> list[dict[str, Any]]:
    out, seen = [], set()
    for r in results:
        key = f"{r.section}.{slug(r.name)}"[:100]
        n = 2
        while key in seen:  # several "production rule" lines
            key, n = f"{r.section}.{slug(r.name)}-{n}"[:100], n + 1
        seen.add(key)
        out.append({"key": key, "component": component_of(r.section, r.name), "title": f"{r.section}: {r.name}"[:200],
                    "result": {"PASS": "pass", "WARN": "warn", "FAIL": "fail", "INFO": "info", "SKIP": "skipped"}.get(r.level, "info"),
                    "summary": r.detail[:2000]})
    return out


def check(key: str, component: str, title: str, result: str, summary: str) -> dict[str, Any]:
    return {"key": key, "component": component, "title": title, "result": result, "summary": summary}


class Agent:
    def __init__(self, values: dict[str, str], host: Host, generated: Path, resolver: Resolver | None = None,
                 now=None):
        self.v, self.host, self.gen = values, host, generated
        # Every time the agent reports is in the installation's zone (SMARTHOST_TIMEZONE).
        self.zone = render.zone_of(values)
        self.now = now or (lambda: dt.datetime.now(self.zone))
        self.i = values["SMARTHOST_INSTANCE"]
        self.resolver = resolver or Resolver()
        self.next_host = self.next_preflight = self.next_app = 0.0

    # ------------------------------------------------------------- plumbing
    def console(self, *args: str, payload: Any = None, timeout: float = 600) -> tuple[int, str]:
        data = None if payload is None else json.dumps(payload).encode()
        return self.host.run("podman", "exec", "-i", "-u", "www-data", f"{self.i}-symfony-app", "php", "bin/console",
                             "smarthost:system:agent", *args, input_=data, timeout=timeout)

    def prod(self, *args: str, timeout: float = 3600) -> tuple[int, str]:
        return self.host.run(PROD, *args, timeout=timeout)

    def pending(self) -> int:
        try:
            rows = self.host.sql("SELECT count(*) FROM system_requests WHERE status = 'pending'")
            return int(rows[0][0]) if rows else 0
        except (RuntimeError, ValueError, IndexError):
            return 0

    def report(self, checks: list[dict[str, Any]], state: dict[str, Any] | None = None, requested: bool = False,
               complete: bool = False) -> bool:
        """complete: the host checks and every preflight section, so the application retires
        agent checks that are no longer reported (e.g. after a host name changed)."""
        args = ("report", "--requested") if requested else ("report",)
        rc, out = self.console(*args, payload={"checks": checks, "state": state or {}, "complete": complete})
        if rc != 0:
            print(f"host-agent: report failed: {out.strip()[-300:]}", file=sys.stderr)
        return rc == 0

    def read_json(self, path: Path) -> dict[str, Any]:
        try:
            data = json.loads(path.read_text())
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError):
            return {}

    # ---------------------------------------------------------------- facts
    def units(self) -> list[str]:
        return ["smarthost.service", f"{self.i}-ingress.socket", f"{self.i}-ingress.service", f"{self.i}-host-agent.service",
                f"{self.i}-reputation-evaluate.timer", f"{self.i}-backup.timer", f"{self.i}-tls-renew.timer",
                "smarthost-postfix-queue-snapshot.timer", "smarthost-postfix-logrotate.timer"]

    def unit_state(self, unit: str) -> dict[str, str]:
        active = self.host.systemctl("is-active", unit)
        enabled = self.host.systemctl("is-enabled", unit)
        wants = Path.home() / ".local/share/systemd/user/default.target.wants" / unit
        en = (enabled[1].strip() if enabled else "?") or "?"
        if wants.exists():
            en = "enabled (boot)"
        return {"active": (active[1].strip() if active else "?") or "?", "enabled": en}

    def containers(self) -> dict[str, dict[str, str]]:
        rc, out = self.host.run("podman", "ps", "-a", "--filter", f"label=smarthost.instance={self.i}", "--format", "json")
        found: dict[str, dict[str, str]] = {}
        try:
            for c in json.loads(out) if rc == 0 else []:
                name = (c.get("Names") or [""])[0]
                svc = name[len(self.i) + 1:] if name.startswith(self.i + "-") else name
                status = c.get("Status", "")
                health = "healthy" if "(healthy)" in status else "unhealthy" if "(unhealthy)" in status else \
                    "starting" if "(starting)" in status else ("none" if c.get("State") == "running" else "-")
                found[svc] = {"name": name, "state": c.get("State", "?"), "health": health, "status": status}
        except ValueError:
            pass
        return found

    def host_facts(self) -> dict[str, Any]:
        osr: dict[str, str] = {}
        try:
            for ln in Path("/etc/os-release").read_text().splitlines():
                k, _, val = ln.partition("=")
                osr[k] = val.strip('"')
        except OSError:
            pass
        user = os.environ.get("USER") or Path.home().name
        _, ver = self.host.run("podman", "version", "--format", "{{.Client.Version}}")
        _, rootless = self.host.run("podman", "info", "--format", "{{.Host.Security.Rootless}}")
        _, linger = self.host.run("loginctl", "show-user", user, "-p", "Linger")
        _, ntp = self.host.run("timedatectl", "show", "-p", "NTPSynchronized", "--value")
        _, graph = self.host.run("podman", "info", "--format", "{{.Store.GraphRoot}}")
        disks = []
        for label, path in (("home", str(Path.home())), ("containers", graph.strip() or str(Path.home())),
                            ("backups", self.v.get("BACKUP_DIR") or str(self.gen / "backups"))):
            p = Path(path)
            while not p.exists() and p != p.parent:
                p = p.parent
            try:
                u = shutil.disk_usage(p)
            except OSError:
                continue
            disks.append({"label": label, "path": path, "total_gb": round(u.total / 1e9, 1), "free_gb": round(u.free / 1e9, 1),
                          "free_percent": round(100 * u.free / u.total, 1) if u.total else 0})
        mem: dict[str, int] = {}
        try:
            for ln in Path("/proc/meminfo").read_text().splitlines():
                k, _, val = ln.partition(":")
                if k in ("MemTotal", "MemAvailable"):
                    mem[k] = int(val.split()[0]) // 1024
        except (OSError, ValueError, IndexError):
            pass
        boot = None
        try:
            for ln in Path("/proc/stat").read_text().splitlines():
                if ln.startswith("btime "):
                    boot = dt.datetime.fromtimestamp(int(ln.split()[1]), self.zone).isoformat(timespec="seconds")
        except (OSError, ValueError):
            pass
        _, tag = self.host.run("git", "-C", str(ROOT), "describe", "--tags", "--always")
        _, commit = self.host.run("git", "-C", str(ROOT), "rev-parse", "--short", "HEAD")
        try:
            # The rules themselves, or the include line of the installation guide and installer.
            conf = Path("/etc/nftables.conf").read_text(errors="replace")
            firewall = "catto_mail" in conf or "catto-mail-firewall.nft" in conf
        except OSError:
            firewall = None
        acme = self.read_json(self.gen / "acme/status.json")
        return {
            "os": osr.get("PRETTY_NAME", "unknown"), "os_supported": osr.get("ID") == "ubuntu" and osr.get("VERSION_ID") == "26.04",
            "podman": ver.strip(), "rootless": rootless.strip() == "true", "user": user, "uid": os.getuid(),
            "linger": "Linger=yes" in linger, "clock_synchronized": ntp.strip() == "yes", "boot_time": boot,
            "disk": disks, "memory": {"total_mb": mem.get("MemTotal"), "available_mb": mem.get("MemAvailable")},
            "units": {u: self.unit_state(u) for u in self.units()},
            "containers": self.containers(),
            "config": {k: self.v.get(k, "") for k in CONFIG_KEYS if not SECRET_KEY.search(k)},
            "release": {"tag": tag.strip(), "commit": commit.strip()},
            "firewall_configured": firewall,
            "dns_records": self.dns_records(),
            "dkim_keys": getattr(self, "dkim_keys_cache", None) or self.dkim_keys(),
            "acme": {"configured": bool(self.v.get("ACME_EMAIL") and self.v.get("ACME_DNS_PROVIDER")),
                     "provider": self.v.get("ACME_DNS_PROVIDER", ""), "last_attempt_at": acme.get("last_attempt_at"),
                     "last_result": acme.get("last_result"), "expires_at": acme.get("expires_at"),
                     "timer": self.unit_state(f"{self.i}-tls-renew.timer")["active"]},
        }

    def dkim_keys(self) -> list[dict[str, Any]]:
        rc, out = self.host.exec("opendkim", "smarthost-dkim-key", "list")
        keys = []
        for ln in out.splitlines()[1:] if rc == 0 else []:
            parts = ln.split("\t")
            if len(parts) < 4:
                continue
            k: dict[str, Any] = {"domain": parts[0], "selector": parts[1], "active": parts[2] == "yes",
                                 "key_present": parts[3] == "present",
                                 "bits": parts[4] if len(parts) > 4 and parts[4] != "-" else None}
            rc2, rec = self.host.exec("opendkim", "smarthost-dkim-key", "dns", parts[0], parts[1])
            if rc2 == 0:
                fields = dict(ln2.split(":", 1) for ln2 in rec.splitlines() if ln2.startswith(("name:", "value:")))
                if fields:
                    k["record"] = {"name": fields.get("name", "").strip(), "value": fields.get("value", "").strip()}
            keys.append(k)
        return keys

    def dns_records(self) -> list[dict[str, str]]:
        v = self.v
        ip, web, mta, bounce = v.get("SMARTHOST_PUBLIC_IPV4", ""), v.get("PROXY_SERVER_NAME", ""), v.get("POSTFIX_MYHOSTNAME", ""), \
            v.get("SMARTHOST_BOUNCE_DOMAIN", "")
        rev = ".".join(reversed(ip.split("."))) + ".in-addr.arpa" if ip.count(".") == 3 else ip
        recs = [{"type": "A", "name": web, "value": ip, "where": "your DNS provider", "check": f"dns.a-{slug(web)}"}]
        if mta != web:
            recs.append({"type": "A", "name": mta, "value": ip, "where": "your DNS provider", "check": f"dns.a-{slug(mta)}"})
        recs += [
            {"type": "PTR", "name": rev, "value": mta, "where": "your VPS provider (reverse DNS)", "check": f"dns.ptr-{slug(ip)}"},
            {"type": "MX", "name": bounce, "value": f"10 {mta}.", "where": "your DNS provider", "check": f"dns.mx-{slug(bounce)}"},
            {"type": "TXT", "name": bounce, "value": f"v=spf1 ip4:{ip} -all", "where": "your DNS provider", "check": f"dns.spf-{slug(bounce)}"},
            {"type": "TXT", "name": mta, "value": f"v=spf1 ip4:{ip} -all", "where": "your DNS provider", "check": f"dns.spf-{slug(mta)}"},
        ]
        domains = {k["domain"] for k in self.dkim_keys_cache} if hasattr(self, "dkim_keys_cache") else set()
        mail_from = v.get("APP_MAIL_FROM", "").rpartition("@")[2]
        for d in sorted(domains | ({mail_from} if mail_from else set())):
            recs.append({"type": "TXT", "name": f"_dmarc.{d}", "value": f"v=DMARC1; p=none; rua=mailto:dmarc-reports@{d}",
                         "where": "the sending domain's DNS", "check": f"dns.dmarc-{slug(d)}"})
        for k in getattr(self, "dkim_keys_cache", []):
            if "record" in k:
                recs.append({"type": "TXT", "name": k["record"]["name"].rstrip("."), "value": k["record"]["value"],
                             "where": "the sending domain's DNS" + ("" if k["active"] else " (new key, not signing yet)"),
                             "check": f"dkim.{slug(k['domain'])}"})
        return recs

    # --------------------------------------------------------------- checks
    def host_checks(self, f: dict[str, Any], backup: dict[str, Any], boot: dict[str, Any] | None) -> list[dict[str, Any]]:
        c = []
        for d in f["disk"]:
            pct = d["free_percent"]
            c.append(check(f"host.disk-{d['label']}", "host", f"Free disk space ({d['label']})",
                           "fail" if pct < 10 else "warn" if pct < 20 else "pass",
                           f"{d['free_gb']} GB free of {d['total_gb']} GB ({pct} %) at {d['path']}."))
        avail = f["memory"].get("available_mb")
        if avail is not None:
            c.append(check("host.memory", "host", "Available memory", "fail" if avail < 150 else "warn" if avail < 400 else "pass",
                           f"{avail} MB available of {f['memory'].get('total_mb')} MB."))
        c.append(check("host.clock", "host", "Clock synchronised (NTP)", "pass" if f["clock_synchronized"] else "warn",
                       "synchronised" if f["clock_synchronized"] else "not synchronised: TLS, DKIM and sign-in links depend on the time"))
        c.append(check("host.rootless", "host", "Rootless Podman", "pass" if f["rootless"] else "fail",
                       f"Podman {f['podman']} as {f['user']} (uid {f['uid']}), rootless: {f['rootless']}."))
        c.append(check("host.firewall", "security", "Host firewall (nftables)",
                       "pass" if f["firewall_configured"] else "warn",
                       "the catto_mail ruleset is in /etc/nftables.conf (directly or included)" if f["firewall_configured"]
                       else "no catto_mail ruleset in /etc/nftables.conf: review and apply `smarthostctl prod firewall` (installation guide)"))
        c.append(check("boot.linger", "boot", "Lingering (services start without a login, at boot)",
                       "pass" if f["linger"] else "fail", "on" if f["linger"] else f"off: as root run loginctl enable-linger {f['user']}"))
        u = f["units"]
        main = u.get("smarthost.service", {})
        c.append(check("boot.smarthost-service", "boot", "smarthost.service starts catto-mail at boot",
                       "pass" if main.get("enabled") == "enabled (boot)" and main.get("active") == "active" else "fail",
                       f"enabled: {main.get('enabled')}, active: {main.get('active')}"))
        ingress = u.get(f"{self.i}-ingress.socket", {})
        c.append(check("boot.ingress-socket", "boot", "Ingress socket (ports 443 and 25)",
                       "pass" if ingress.get("active") == "active" else "fail", f"active: {ingress.get('active')}"))
        timers = ((f"{self.i}-backup.timer", "Backup timer"), (f"{self.i}-tls-renew.timer", "Certificate renewal timer"),
                  (f"{self.i}-reputation-evaluate.timer", "Reputation timer"),
                  ("smarthost-postfix-queue-snapshot.timer", "Postfix queue snapshot timer"))
        for t, title in timers:
            st = u.get(t, {})
            c.append(check(f"boot.timer-{slug(t.removeprefix(self.i + '-'))}", "boot", title,
                           "pass" if st.get("active") == "active" else "warn", f"{t}: {st.get('active')}"))
        if boot:
            ok = bool(boot.get("started_automatically")) and bool(boot.get("containers_healthy")) and bool(boot.get("ingress_active"))
            c.append(check("boot.last-recovery", "boot", "Recovery after the last boot", "pass" if ok else "fail",
                           f"boot {boot.get('boot_time')}: started automatically {boot.get('started_automatically')}, "
                           f"containers healthy {boot.get('containers_healthy')}, ingress {boot.get('ingress_active')}"))
        for svc in SERVICES:
            st = f["containers"].get(svc)
            if st is None:
                c.append(check(f"container.{svc}", SERVICE_COMPONENT[svc], f"{SERVICE_NAME[svc]} container", "fail", "the container does not exist"))
                continue
            good = st["state"] == "running" and st["health"] in ("healthy", "none")
            c.append(check(f"container.{svc}", SERVICE_COMPONENT[svc], f"{SERVICE_NAME[svc]} container",
                           "pass" if good else "fail", f"{st['name']}: {st['status'] or st['state']}"))
        env = Path(os.environ.get("SMARTHOST_DOTENV") or ROOT / "infra/.env")
        for path, title in ((env, "infra/.env (secrets) is private"),
                            (Path(self.v.get("ACME_CREDENTIALS_FILE") or "/nonexistent"), "ACME credentials file is private"),
                            (Path(self.v.get("BACKUP_ENCRYPTION_PASSPHRASE_FILE") or "/nonexistent"), "Backup passphrase file is private")):
            if not path.exists():
                continue
            mode = path.stat().st_mode & 0o777
            c.append(check(f"security.mode-{slug(path.name)}", "security", title, "pass" if mode & 0o077 == 0 else "fail",
                           f"{path} mode {mode:o}" + ("" if mode & 0o077 == 0 else f": run chmod 600 {path}")))
        c += self.backup_checks(backup)
        acme = f["acme"]
        if not acme["configured"]:
            c.append(check("tls.acme-renewal", "tls", "Automatic certificate renewal", "warn",
                           "not configured (ACME_EMAIL, ACME_DNS_PROVIDER): certificates must be renewed by hand"))
        elif acme["provider"] == "manual":
            c.append(check("tls.acme-renewal", "tls", "Automatic certificate renewal", "warn",
                           "manual DNS provider: each renewal needs you (smarthostctl prod tls acme issue)"))
        else:
            failed = str(acme.get("last_result") or "").startswith("failed")
            c.append(check("tls.acme-renewal", "tls", "Automatic certificate renewal",
                           "fail" if failed else ("pass" if acme["timer"] == "active" else "warn"),
                           f"provider {acme['provider']}; timer {acme['timer']}; last attempt {acme.get('last_attempt_at') or 'never'} "
                           f"{acme.get('last_result') or ''}".strip()))
        return c

    def backup_checks(self, b: dict[str, Any]) -> list[dict[str, Any]]:
        now = self.now()

        def age(ts: str | None) -> float | None:
            try:
                return (now - dt.datetime.fromisoformat(str(ts))).total_seconds() / 3600 if ts else None
            except ValueError:
                return None
        c = []
        last = age(b.get("last_success_at"))
        c.append(check("backup.recent", "backup", "Recent backup",
                       "fail" if last is None else "warn" if last > 26 else "pass",
                       "no successful backup yet: smarthostctl prod backup --scheduled" if last is None
                       else f"last successful backup {last:.1f} h ago ({b.get('last_path')})" +
                       (f"; last attempt failed: {b.get('last_result')}" if str(b.get("last_result", "")).startswith("failed") else "")))
        if not b.get("offhost_configured"):
            c.append(check("backup.offhost", "backup", "Off-host copy", "warn",
                           "WARN: backup is not off-host (set BACKUP_OFFHOST_TARGET; a backup on this server does not survive losing it)"))
        else:
            target = b.get("offhost_target")
            c.append(check("backup.offhost", "backup", "Off-host copy", "pass" if b.get("offhost") else "fail",
                           f"copied to {target}" if b.get("offhost") else f"copy to {target} failed: {b.get('offhost_error', '')}"))
        c.append(check("backup.encrypted", "backup", "Backups encrypted", "pass" if b.get("encrypted") else "warn",
                       "AES-256 with the passphrase file" if b.get("encrypted")
                       else "not encrypted (BACKUP_ENCRYPTION_PASSPHRASE_FILE); backups contain every secret"))
        rr = age(b.get("restore_rehearsal_at"))
        res = str(b.get("restore_rehearsal_result") or "")
        c.append(check("backup.restore-rehearsal", "backup", "Restore rehearsal",
                       "fail" if res.startswith("failed") else "warn" if rr is None or rr > 35 * 24 else "pass",
                       "never rehearsed: System setup › Backups › Rehearse a restore" if rr is None
                       else f"{res} {rr / 24:.0f} days ago"))
        return c

    # ------------------------------------------------------------ boot report
    def boot_report(self, facts: dict[str, Any]) -> dict[str, Any] | None:
        """Once per boot, when the services had time to start: did catto-mail come back by itself?"""
        marker = self.gen / "agent/last-boot.json"
        last = self.read_json(marker)
        boot = facts.get("boot_time")
        if not boot or last.get("boot_time") == boot:
            return None
        try:
            uptime = (self.now() - dt.datetime.fromisoformat(boot)).total_seconds()
        except ValueError:
            return None
        containers = facts["containers"]
        unhealthy = [s for s in SERVICES if not (containers.get(s, {}).get("state") == "running"
                                                 and containers.get(s, {}).get("health") in ("healthy", "none"))]
        if unhealthy and uptime < 900:
            return None  # still starting; look again on the next round
        main = facts["units"].get("smarthost.service", {})
        _, started = self.host.systemctl("show", "smarthost.service", "-p", "ActiveEnterTimestamp", "--value") or (1, "")
        paused = self.host.exec("postfix", "sh", "-c", 'test -e "$SMARTHOST_POSTFIX_OBSERVABILITY_DIR/control/outbound-paused"')[0] == 0
        report = {"boot_time": boot, "unit_started_at": started.strip(),
                  "started_automatically": main.get("enabled") == "enabled (boot)" and main.get("active") == "active",
                  "containers_healthy": not unhealthy, "unhealthy": unhealthy,
                  "ingress_active": facts["units"].get(f"{self.i}-ingress.socket", {}).get("active") == "active",
                  "live_delivery": self.v.get("SMARTHOST_LIVE_DELIVERY_ENABLED") == "true", "paused": paused}
        marker.parent.mkdir(parents=True, exist_ok=True)
        marker.write_text(json.dumps(report))
        return report

    # ----------------------------------------------------------- collection
    def collect(self, requested: bool = False, preflight: bool = True, sections: tuple[str, ...] | None = None,
                egress_probe: str = "") -> int:
        self.dkim_keys_cache = self.dkim_keys()
        facts = self.host_facts()
        backup = self.read_json(self.gen / "backup-status.json")
        boot = self.boot_report(facts)
        state: dict[str, Any] = {"host_report": facts, "backup_status": backup}
        if boot:
            state["boot_report"] = boot
        checks = self.host_checks(facts, backup, boot or self.read_json(self.gen / "agent/last-boot.json") or None)
        if preflight:
            pf = Preflight(self.v, self.host, self.resolver, egress_probe=egress_probe)
            checks += preflight_checks(pf.run(sections))
        self.report(checks, state, requested, complete=preflight and sections is None)
        return len(checks)

    # ------------------------------------------------------------- requests
    def handle(self, req: dict[str, Any]) -> tuple[bool, str, dict[str, Any]]:
        action, params = req["action"], req.get("params") or {}
        by, note = req.get("requested_by") or "", req.get("note") or "requested in the dashboard"
        if action == "diagnostics.run":
            section = params.get("section")
            if section and section not in Preflight.SECTIONS:
                return False, f"unknown section {section}", {}
            domain = self.v.get("APP_ADMIN_EMAIL", "").rpartition("@")[2]
            n = self.collect(requested=True, sections=(section,) if section else None,
                             egress_probe=domain if not section or section == "postfix" else "")
            rc, _ = self.console("diagnostics", "--requested")
            return True, f"{n} host checks recorded" + ("" if rc == 0 else "; the application checks failed to run"), {}
        if action == "delivery.pause":
            rc, out = self.host.exec("postfix", "smarthost-postfix-control", "pause", f"{note} ({by})"[:300])
            return rc == 0, out.strip()[-500:] or "Postfix queue held", {}
        if action == "delivery.resume":
            stop = self.host.sql("SELECT emergency_stop FROM delivery_controls WHERE id = 1")
            if stop and stop[0][0] == "t":
                return False, "the emergency stop is in force again; the queue stays held", {}
            rc, out = self.host.exec("postfix", "smarthost-postfix-control", "resume")
            return rc == 0, out.strip()[-500:] or "Postfix queue released", {}
        if action in ("delivery.live_enable", "delivery.live_disable"):
            if not by:
                return False, "the request has no operator to audit", {}
            rc, out = self.prod("live-enable" if action.endswith("enable") else "live-disable", "--operator", by, "--note", note)
            return rc == 0, out.strip()[-1500:], {}
        if action == "settings.apply":
            rc, out = self.prod("settings-apply")
            return rc == 0, out.strip()[-1500:] or "settings applied", {}
        if action == "backup.run":
            rc, out = self.prod("backup", "--scheduled")
            return rc == 0, out.strip()[-800:], {}
        if action == "backup.restore_rehearsal":
            rc, out = self.prod("restore-rehearsal")
            return rc == 0, out.strip()[-800:], {}
        if action in ("dkim.generate", "dkim.activate"):
            domain, selector = params.get("domain", ""), params.get("selector", "")
            if not re.fullmatch(r"[a-z0-9.-]{3,253}", domain) or not re.fullmatch(r"[a-z0-9][a-z0-9-]{0,62}", selector):
                return False, "invalid domain or selector", {}
            if action == "dkim.generate":
                rc, out = self.prod("dkim", "generate", domain, selector)
                rc2, rec = self.host.exec("opendkim", "smarthost-dkim-key", "dns", domain, selector)
                return rc == 0, (out.strip()[-600:] + ("\n" + rec.strip() if rc2 == 0 else "")).strip(), {}
            rc, key = self.host.exec("opendkim", "smarthost-dkim-key", "pubkey", domain, selector)
            if rc != 0:
                return False, key.strip()[:300], {}
            try:
                published = dkim_public_key(self.resolver.query(f"{selector}._domainkey.{domain}", "TXT"))
            except DnsError as exc:
                return False, f"DNS lookup failed: {exc}", {}
            if published != key.strip():
                return False, f"publish the TXT record at {selector}._domainkey.{domain} first (DNS does not show the matching key yet)", {}
            rc, out = self.prod("dkim", "activate", domain, selector)
            if rc != 0:
                return False, out.strip()[-600:], {}
            clients = self.host.sql(f"SELECT client_id FROM sending_domains WHERE domain = '{domain}' AND status = 'verified'")
            recorded = ""
            if len(clients) == 1:
                rc3, out3 = self.host.exec("symfony-app", "php", "bin/console", "smarthost:domain:dkim", clients[0][0], domain,
                                           "active", "--selector", selector, user="www-data")
                recorded = " and recorded for the sending domain" if rc3 == 0 else f"; recording it failed: {out3.strip()[-200:]}"
            return True, f"{domain} now signs with selector {selector}{recorded}", {}
        if action == "tls.renew":
            if self.v.get("ACME_DNS_PROVIDER") == "manual":
                return False, "the manual DNS provider needs you: run smarthostctl prod tls acme issue in a terminal", {}
            rc, out = self.prod("tls", "acme", "ensure")
            return rc == 0, out.strip()[-800:], {}
        return False, f"unknown action {action}", {}

    def process_requests(self) -> int:
        rc, out = self.console("claim")
        if rc != 0:
            print(f"host-agent: claim failed: {out.strip()[-300:]}", file=sys.stderr)
            return 0
        try:
            reqs = json.loads(out.strip().splitlines()[-1]) if out.strip() else []
        except (ValueError, IndexError):
            return 0
        for req in reqs:
            try:
                ok, summary, result = self.handle(req)
            except Exception as exc:  # the request must always be finished
                ok, summary, result = False, f"{type(exc).__name__}: {exc}", {}
            args = ["finish", req["id"]] + ([] if ok else ["--failed"])
            self.console(*args, payload={"summary": summary or ("done" if ok else "failed"), "result": result})
        return len(reqs)

    def tick(self) -> None:
        now = time.monotonic()
        if self.pending():
            self.process_requests()
        if now >= self.next_preflight:
            self.collect(preflight=True)
            self.next_preflight = now + PREFLIGHT_INTERVAL
            self.next_host = now + HOST_INTERVAL
        elif now >= self.next_host:
            self.collect(preflight=False)
            self.next_host = now + HOST_INTERVAL
        if now >= self.next_app:
            self.console("diagnostics")
            self.next_app = now + APP_INTERVAL

    def run(self, poll: float) -> None:
        print(f"host-agent: instance {self.i}, polling every {poll} s", flush=True)
        while True:
            try:
                self.tick()
            except Exception as exc:  # never die: report on the next round
                print(f"host-agent: {type(exc).__name__}: {exc}", file=sys.stderr, flush=True)
            time.sleep(poll)


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description="catto-mail host agent")
    sub = p.add_subparsers(dest="cmd", required=True)
    r = sub.add_parser("run")
    r.add_argument("--poll", type=float, default=10)
    o = sub.add_parser("once")
    o.add_argument("--requested", action="store_true")
    o.add_argument("--no-preflight", action="store_true")
    o.add_argument("--section", choices=Preflight.SECTIONS)
    sub.add_parser("facts")
    a = p.parse_args(argv)
    env = Path(os.environ.get("SMARTHOST_DOTENV") or ROOT / "infra/.env")
    values = render.load(env)   # the .env's values completed with the built-in ones
    generated = Path(os.environ.get("SMARTHOST_GENERATED") or ROOT / "infra/.generated")
    agent = Agent(values, Host(values), generated)
    if a.cmd == "facts":
        agent.dkim_keys_cache = agent.dkim_keys()
        print(json.dumps(agent.host_facts(), indent=2))
        return 0
    if a.cmd == "once":
        agent.process_requests()
        n = agent.collect(requested=a.requested, preflight=not a.no_preflight, sections=(a.section,) if a.section else None)
        agent.console("diagnostics", *(["--requested"] if a.requested else []))
        print(f"host-agent: {n} host checks recorded")
        return 0
    agent.run(a.poll)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
