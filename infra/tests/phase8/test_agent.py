"""The host agent (specification 2.11): check conversion, host and backup checks, request
handling and the console protocol, with fake host, database and DNS; nothing on the
machine is touched."""
from __future__ import annotations

import datetime as dt
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from fixtures import ROOT, FakeHost, FakeResolver, pf, production_values

sys.path.insert(0, str(ROOT / "infra/lib"))
import smarthost_agent as ag  # noqa: E402

NOW = dt.datetime(2026, 10, 7, 12, 0, tzinfo=dt.UTC)


class AgentHost(FakeHost):
    """Records the commands the agent runs and answers the console protocol."""

    def __init__(self, values: dict[str, str], **kw) -> None:
        super().__init__(values, remote=False, **kw)
        self.calls: list[tuple[str, ...]] = []
        self.inputs: list[dict] = []
        self.claim_answer: list[dict] = []
        self.prod_rc = 0

    def run(self, *cmd: str, input_: bytes | None = None, timeout: float = 60) -> tuple[int, str]:
        self.calls.append(cmd)
        if input_:
            self.inputs.append(json.loads(input_))
        if "smarthost:system:agent" in cmd:
            if "claim" in cmd:
                return 0, json.dumps(self.claim_answer)
            return 0, "recorded"
        if cmd and cmd[0] == ag.PROD:
            return self.prod_rc, f"prod {' '.join(cmd[1:])}"
        return super().run(*cmd, input_=input_, timeout=timeout)

    def systemctl(self, *args: str) -> tuple[int, str] | None:
        return 0, "active" if args[0] == "is-active" else "enabled"


def facts(**over) -> dict:
    f = {"disk": [{"label": "home", "path": "/home/cattomail", "total_gb": 40.0, "free_gb": 30.0, "free_percent": 75.0}],
         "memory": {"total_mb": 4000, "available_mb": 2500}, "clock_synchronized": True, "rootless": True, "podman": "5.7.0",
         "user": "cattomail", "uid": 1001, "firewall_configured": True, "linger": True,
         "units": {"smarthost.service": {"active": "active", "enabled": "enabled (boot)"},
                   "smarthost-ingress.socket": {"active": "active", "enabled": "static"}},
         "containers": {s: {"name": f"smarthost-{s}", "state": "running", "health": "healthy", "status": "Up (healthy)"} for s in ag.SERVICES},
         "acme": {"configured": True, "provider": "cloudflare", "timer": "active", "last_result": "ok: not due"}}
    f.update(over)
    return f


class ConversionTest(unittest.TestCase):
    def test_slug_and_components(self) -> None:
        self.assertEqual("a-mail-example-com-web", ag.slug("A mail.example.com (web)"))
        self.assertEqual("boot", ag.component_of("host", "lingering (start at boot)"))
        self.assertEqual("database", ag.component_of("runtime", "migrations current"))
        self.assertEqual("bounce", ag.component_of("postfix", "port 25 accepts the bounce domain"))
        self.assertEqual("nginx", ag.component_of("ingress", "socket binds"))
        checks = ag.preflight_checks([pf.Check("config", "production rule", "FAIL", "x"), pf.Check("config", "production rule", "FAIL", "y"),
                                      pf.Check("tls", "nginx certificate trust", "WARN", "self-signed"), pf.Check("dns", "PTR 1.2.3.4", "SKIP")])
        self.assertEqual(["config.production-rule", "config.production-rule-2", "tls.nginx-certificate-trust", "dns.ptr-1-2-3-4"],
                         [c["key"] for c in checks])
        self.assertEqual(["fail", "fail", "warn", "skipped"], [c["result"] for c in checks])


class ChecksTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.values = production_values()
        self.agent = ag.Agent(self.values, AgentHost(self.values), Path(self.tmp.name), FakeResolver({}), now=lambda: NOW)

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def by(self, checks: list[dict]) -> dict[str, dict]:
        return {c["key"]: c for c in checks}

    def test_healthy_host(self) -> None:
        c = self.by(self.agent.host_checks(facts(), {"last_success_at": (NOW - dt.timedelta(hours=3)).isoformat(), "offhost_configured": True,
                                                     "offhost": True, "offhost_target": "b@h:/x", "encrypted": True,
                                                     "restore_rehearsal_at": (NOW - dt.timedelta(days=3)).isoformat(),
                                                     "restore_rehearsal_result": "ok: 48 tables"}, None))
        for key in ("host.disk-home", "host.memory", "host.clock", "boot.linger", "boot.smarthost-service", "boot.ingress-socket",
                    "container.postfix", "container.validator", "backup.recent", "backup.offhost", "backup.restore-rehearsal", "tls.acme-renewal"):
            self.assertEqual("pass", c[key]["result"], f"{key}: {c[key]['summary']}")

    def test_problems_are_named(self) -> None:
        f = facts(linger=False, disk=[{"label": "home", "path": "/", "total_gb": 40, "free_gb": 2, "free_percent": 5.0}],
                  containers={"postgres": {"name": "smarthost-postgres", "state": "exited", "health": "-", "status": "Exited (1)"}},
                  acme={"configured": False, "provider": "", "timer": "inactive"})
        f["units"]["smarthost.service"] = {"active": "inactive", "enabled": "disabled"}
        c = self.by(self.agent.host_checks(f, {}, {"boot_time": "x", "started_automatically": False, "containers_healthy": False, "ingress_active": True}))
        self.assertEqual("fail", c["host.disk-home"]["result"])
        self.assertEqual("fail", c["boot.linger"]["result"])
        self.assertIn("loginctl enable-linger", c["boot.linger"]["summary"])
        self.assertEqual("fail", c["boot.smarthost-service"]["result"])
        self.assertEqual("fail", c["boot.last-recovery"]["result"])
        self.assertEqual("fail", c["container.postgres"]["result"])
        self.assertEqual("fail", c["container.delivery"]["result"], "a missing container is a FAIL")
        self.assertEqual("database", c["container.postgres"]["component"])
        self.assertEqual("fail", c["backup.recent"]["result"], "no backup at all")
        self.assertEqual("warn", c["backup.offhost"]["result"])
        self.assertIn("not off-host", c["backup.offhost"]["summary"])
        self.assertEqual("warn", c["backup.restore-rehearsal"]["result"])
        self.assertEqual("warn", c["tls.acme-renewal"]["result"])

    def test_secret_file_modes(self) -> None:
        env = Path(self.tmp.name) / ".env"
        env.write_text("X=1\n")
        env.chmod(0o644)
        old = os.environ.get("SMARTHOST_DOTENV")
        os.environ["SMARTHOST_DOTENV"] = str(env)
        try:
            c = self.by(self.agent.host_checks(facts(), {}, None))
            self.assertEqual("fail", c["security.mode-env"]["result"])
            env.chmod(0o600)
            self.assertEqual("pass", self.by(self.agent.host_checks(facts(), {}, None))["security.mode-env"]["result"])
        finally:
            if old is None:
                del os.environ["SMARTHOST_DOTENV"]
            else:
                os.environ["SMARTHOST_DOTENV"] = old

    def test_old_backups_warn(self) -> None:
        c = self.by(self.agent.backup_checks({"last_success_at": (NOW - dt.timedelta(hours=30)).isoformat(), "last_result": "ok"}))
        self.assertEqual("warn", c["backup.recent"]["result"])
        c = self.by(self.agent.backup_checks({"restore_rehearsal_at": NOW.isoformat(), "restore_rehearsal_result": "failed: checksum mismatch"}))
        self.assertEqual("fail", c["backup.restore-rehearsal"]["result"])


class RequestsTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.values = production_values()

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def agent(self, host: AgentHost, zone: dict | None = None) -> ag.Agent:
        return ag.Agent(self.values, host, Path(self.tmp.name), FakeResolver(zone or {}), now=lambda: NOW)

    def test_only_a_complete_collection_retires_checks(self) -> None:
        host = AgentHost(self.values)
        agent = self.agent(host)
        stub = {"host_facts": lambda: {}, "boot_report": lambda f: None, "dkim_keys": lambda: {},
                "host_checks": lambda f, b, boot: [{"key": "host.os", "component": "host", "title": "OS", "result": "pass", "summary": ""}]}
        for name, fn in stub.items():
            setattr(agent, name, fn)
        with mock.patch.object(ag.Preflight, "run", return_value=[]):
            agent.collect(preflight=False)
            agent.collect(preflight=True, sections=("dns",))
            agent.collect(preflight=True)
        self.assertEqual([i["complete"] for i in host.inputs if "checks" in i], [False, False, True])

    def test_claim_handle_finish(self) -> None:
        host = AgentHost(self.values)
        host.claim_answer = [{"id": "r1", "action": "backup.run", "params": {}, "requested_by": "admin@example.com", "note": None},
                             {"id": "r2", "action": "no.such", "params": {}, "requested_by": None, "note": None}]
        self.assertEqual(2, self.agent(host).process_requests())
        finishes = [c for c in host.calls if "finish" in c]
        self.assertEqual([("finish", "r1"), ("finish", "r2", "--failed")], [c[c.index("finish"):] for c in finishes])
        self.assertIn((ag.PROD, "backup", "--scheduled"), host.calls)

    def test_live_enable_is_audited_with_the_requester(self) -> None:
        host = AgentHost(self.values)
        ok, _, _ = self.agent(host).handle({"action": "delivery.live_enable", "params": {}, "requested_by": "admin@example.com", "note": "tests passed"})
        self.assertTrue(ok)
        self.assertIn((ag.PROD, "live-enable", "--operator", "admin@example.com", "--note", "tests passed"), host.calls)
        ok, why, _ = self.agent(host).handle({"action": "delivery.live_enable", "params": {}, "requested_by": None, "note": "x"})
        self.assertFalse(ok)

    def test_resume_refuses_while_the_stop_is_in_force(self) -> None:
        host = AgentHost(self.values, sql={"emergency_stop": [["t"]]})
        ok, why, _ = self.agent(host).handle({"action": "delivery.resume", "params": {}})
        self.assertFalse(ok)
        self.assertIn("emergency stop", why)

    def test_dkim_activation_needs_the_published_key(self) -> None:
        host = AgentHost(self.values, exec_={("opendkim", "smarthost-dkim-key", "pubkey"): (0, "MIIBkey\n")},
                         sql={"FROM sending_domains": [["11111111-1111-1111-1111-111111111111"]]})
        req = {"action": "dkim.activate", "params": {"domain": "news.example.org", "selector": "s1"}}
        ok, why, _ = self.agent(host).handle(req)
        self.assertFalse(ok)
        self.assertIn("publish the TXT record", why)
        self.assertNotIn((ag.PROD, "dkim", "activate", "news.example.org", "s1"), host.calls)
        ok, why, _ = self.agent(host, {("s1._domainkey.news.example.org", "TXT"): ["v=DKIM1; k=rsa; p=MIIBkey"]}).handle(req)
        self.assertTrue(ok, why)
        self.assertIn((ag.PROD, "dkim", "activate", "news.example.org", "s1"), host.calls)
        bad = self.agent(host).handle({"action": "dkim.generate", "params": {"domain": "x;rm", "selector": "s1"}})
        self.assertFalse(bad[0])

    def test_boot_report_once_per_boot(self) -> None:
        host = AgentHost(self.values)
        agent = self.agent(host)
        f = facts(boot_time=(NOW - dt.timedelta(minutes=20)).isoformat())
        report = agent.boot_report(f)
        self.assertIsNotNone(report)
        self.assertTrue(report["started_automatically"] and report["containers_healthy"] and report["ingress_active"])
        self.assertIsNone(agent.boot_report(f), "recorded once per boot")
        (Path(self.tmp.name) / "agent/last-boot.json").unlink()
        early = facts(boot_time=(NOW - dt.timedelta(minutes=2)).isoformat(), containers={})
        self.assertIsNone(self.agent(AgentHost(self.values)).boot_report(early), "containers still starting: look again later")


if __name__ == "__main__":
    unittest.main()
