"""Phase 8: production configuration rules and the rendered production topology."""
from __future__ import annotations

import os
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from fixtures import ROOT, production_values, render


def write_env(values: dict[str, str], directory: Path) -> Path:
    path = directory / "test.env"
    path.write_text("\n".join(f"{k}={v}" for k, v in values.items()) + "\n", encoding="utf-8")
    return path


class ProductionRulesTest(unittest.TestCase):
    def test_a_complete_production_configuration_is_valid(self) -> None:
        self.assertEqual([], render.validate(production_values()))

    def test_unsafe_production_configurations_fail_clearly(self) -> None:
        cases = {
            "unverified-domain bypass": ({"SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS": "true"}, "SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS"),
            "webhook private-host allowlist": ({"APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS": "webhook-receiver"}, "APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS"),
            "Mailpit relay with live delivery": ({"SMARTHOST_LIVE_DELIVERY_ENABLED": "true", "POSTFIX_RELAYHOST": "[mailpit]:1025"},
                                                "POSTFIX_RELAYHOST must be empty"),
            "Mailpit relay in held mode": ({"POSTFIX_RELAYHOST": "[mailpit]:1025"}, "POSTFIX_RELAYHOST must be empty"),
            "missing secret": ({"DELIVERY_DB_PASSWORD": ""}, "secret DELIVERY_DB_PASSWORD is missing"),
            "short secret": ({"APP_SECRET": "short"}, "APP_SECRET is shorter"),
            "bad keyring": ({"APP_ENCRYPTION_KEYS": "prod1:not-base64!"}, "APP_ENCRYPTION_KEYS"),
            "base URL host differs": ({"SMARTHOST_PUBLIC_BASE_URL": "https://other.cattomail-ops.net"}, "host must equal PROXY_SERVER_NAME"),
            "http base URL": ({"SMARTHOST_PUBLIC_BASE_URL": "http://mail.cattomail-ops.net"}, "must be https://"),
            "invalid bounce domain": ({"SMARTHOST_BOUNCE_DOMAIN": "bounce_domain"}, "SMARTHOST_BOUNCE_DOMAIN must be a fully qualified"),
            "placeholder bounce domain": ({"SMARTHOST_BOUNCE_DOMAIN": "bounce.example.com"}, "reserved/placeholder"),
            "bounce domain is the web host": ({"SMARTHOST_BOUNCE_DOMAIN": "mail.cattomail-ops.net"}, "dedicated domain"),
            "development kernel": ({"APP_ENV": "dev"}, "APP_ENV must be 'prod'"),
            "private public IP": ({"SMARTHOST_PUBLIC_IPV4": "10.0.0.5"}, "public IPv4"),
            "documentation public IP": ({"SMARTHOST_PUBLIC_IPV4": "203.0.113.10"}, "public IPv4"),
            "live without egress": ({"SMARTHOST_LIVE_DELIVERY_ENABLED": "true", "SMARTHOST_EGRESS_ENABLED": "false"}, "requires SMARTHOST_EGRESS_ENABLED=true"),
            "trusted forwarded headers": ({"TRUSTED_PROXIES": "10.89.20.0/24"}, "TRUSTED_PROXIES must be empty in production"),
            "overlapping subnets": ({"SMARTHOST_EGRESS_SUBNET": "10.89.20.0/24"}, "must not overlap"),
            "ingress subnet overlaps": ({"SMARTHOST_INGRESS_SUBNET": "10.89.21.0/24"}, "must not overlap"),
            "not a /24": ({"SMARTHOST_INTERNAL_SUBNET": "10.89.0.0/16"}, "must be a /24"),
            "ingress subnet not a /24": ({"SMARTHOST_INGRESS_SUBNET": "10.89.22.0/25"}, "SMARTHOST_INGRESS_SUBNET must be a /24"),
            "validator probes a fake route": ({"VALIDATOR_SMTP_ROUTE_OVERRIDE": "fake-smtp:2525"}, "VALIDATOR_SMTP_ROUTE_OVERRIDE"),
            "no SMTP listener": ({"POSTFIX_SMTP_BIND": ""}, "POSTFIX_SMTP_BIND must be IPv4-address:port"),
            "IPv6 HTTPS listener": ({"PROXY_HTTPS_BIND": "[::]:443"}, "PROXY_HTTPS_BIND must be IPv4-address:port"),
            "one listener for both": ({"POSTFIX_SMTP_BIND": "0.0.0.0:443"}, "must differ"),
            "service alias": ({"SMARTHOST_DB_HOST": "db.internal"}, "SMARTHOST_DB_HOST must be 'postgres'"),
            "connect timeout": ({"APP_WEBHOOK_CONNECT_TIMEOUT_SECONDS": "30"}, "APP_WEBHOOK_CONNECT_TIMEOUT_SECONDS"),
            "placeholder sign-in sender": ({"APP_MAIL_FROM": "no-reply@example.com"}, "APP_MAIL_FROM"),
        }
        for name, (override, expected) in cases.items():
            with self.subTest(name):
                errors = render.validate(production_values(**override))
                self.assertTrue(any(expected in e for e in errors), f"{name}: {errors}")

    def test_reserved_names_only_in_a_rehearsal_without_egress(self) -> None:
        names = {"SMARTHOST_PUBLIC_BASE_URL": "https://mail.r.test", "PROXY_SERVER_NAME": "mail.r.test", "POSTFIX_MYHOSTNAME": "mta.r.test",
                 "SMARTHOST_BOUNCE_DOMAIN": "bounce.r.test", "VALIDATOR_SMTP_HELO_HOSTNAME": "mta.r.test",
                 "VALIDATOR_SMTP_MAIL_FROM": "v@bounce.r.test", "APP_ADMIN_EMAIL": "a@r.test", "APP_MAIL_FROM": "n@r.test",
                 "SMARTHOST_PUBLIC_IPV4": "203.0.113.10"}
        self.assertEqual([], render.validate(production_values(SMARTHOST_EGRESS_ENABLED="false", **names)))
        errors = render.validate(production_values(SMARTHOST_EGRESS_ENABLED="true", **names))
        self.assertTrue(any("reserved/placeholder" in e for e in errors) and any("public IPv4" in e for e in errors), errors)
        self.assertTrue(any("requires SMARTHOST_EGRESS_ENABLED=true" in e for e in
                            render.validate(production_values(SMARTHOST_EGRESS_ENABLED="false", SMARTHOST_LIVE_DELIVERY_ENABLED="true", **names))))

    def test_the_production_template_is_safe_but_needs_the_operator(self) -> None:
        template = render.parse_dotenv(ROOT / "infra/production.env.example")
        self.assertEqual("production", template["SMARTHOST_ENV"])
        self.assertEqual("false", template["SMARTHOST_LIVE_DELIVERY_ENABLED"])
        errors = render.validate(template)
        # Unusable as is: placeholders and missing secrets are refused, never silently accepted.
        for needle in ("reserved/placeholder", "secret APP_SECRET is missing", "public IPv4"):
            self.assertTrue(any(needle in e for e in errors), (needle, errors))

    def test_development_rules_are_unchanged(self) -> None:
        dev = render.parse_dotenv(ROOT / "infra/.env.example")
        self.assertEqual([], [e for e in render.validate(dev) if "match the contract" not in e])
        dev["SMARTHOST_LIVE_DELIVERY_ENABLED"] = "true"
        self.assertTrue(any("only permitted with SMARTHOST_ENV=production" in e for e in render.validate(dev)))

    def test_init_env_generates_production_secrets_locally_and_never_overwrites(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / ".env"
            out = subprocess.run([sys.executable, str(ROOT / "infra/lib/smarthost_render.py"), "init-env", "--production"],
                                 env={"SMARTHOST_DOTENV": str(target), "PATH": os.environ.get("PATH", "/usr/bin:/bin")}, capture_output=True, text=True)
            self.assertEqual(0, out.returncode, out.stderr)
            values = render.parse_dotenv(target)
            self.assertEqual(0o600, target.stat().st_mode & 0o777)
            for row in render.contract():
                if row["secret"] and row["name"] != "MAILER_DSN":
                    self.assertGreaterEqual(len(values[row["name"]]), 32, row["name"])
            self.assertRegex(values["APP_ENCRYPTION_KEYS"], r"^prod1:[A-Za-z0-9+/]{43}=$")
            before = target.read_text()
            again = subprocess.run([sys.executable, str(ROOT / "infra/lib/smarthost_render.py"), "init-env", "--production"],
                                   env={"SMARTHOST_DOTENV": str(target), "PATH": os.environ.get("PATH", "/usr/bin:/bin")}, capture_output=True, text=True)
            self.assertIn("not overwriting", again.stdout)
            self.assertEqual(before, target.read_text())


class ProductionRenderingTest(unittest.TestCase):
    tmp: tempfile.TemporaryDirectory
    out: Path
    script: str

    @classmethod
    def setUpClass(cls) -> None:
        cls.tmp = tempfile.TemporaryDirectory()
        tmp = Path(cls.tmp.name)
        cls.out = tmp / "generated"
        render.render(write_env(production_values(), tmp), cls.out)
        cls.script = (cls.out / "podman/smarthost-production.sh").read_text()

    @classmethod
    def tearDownClass(cls) -> None:
        cls.tmp.cleanup()

    def create_block(self, service: str) -> str:
        m = re.search(rf"^    {re.escape(service)}\)\n(.*?);;", self.script, re.M | re.S)
        self.assertIsNotNone(m, service)
        return m.group(1)  # type: ignore[union-attr]

    def test_the_script_is_valid_bash_with_rendered_values(self) -> None:
        self.assertEqual(0, subprocess.run(["bash", "-n", str(self.out / "podman/smarthost-production.sh")]).returncode)
        self.assertIn("I=smarthost\n", self.script)
        self.assertIn("TAG=0.2.0\n", self.script)
        self.assertIn("EGRESS_ENABLED=true\n", self.script)
        self.assertNotRegex(self.script, r"\$\{[A-Z][A-Z0-9_]*\}", "every contract placeholder is rendered")

    def test_egress_only_for_the_services_that_need_the_internet(self) -> None:
        for service in ("postgres", "opendkim", "postfix", "symfony-app", "webhook-worker", "nginx", "validator", "delivery"):
            block = self.create_block(service)
            self.assertEqual(service in ("postfix", "symfony-app", "webhook-worker", "validator"), '"$(egress)"' in block, service)
            self.assertIn(f'"$(internal {service}', block)

    def test_nothing_is_published_the_ingress_socket_is_systemd_s(self) -> None:
        # Rootless port forwarding would hide client addresses: no container publishes a port.
        self.assertNotIn("--publish", self.script)
        socket_unit = (self.out / "systemd/smarthost-ingress.socket").read_text()
        self.assertEqual(["0.0.0.0:443", "0.0.0.0:25"], re.findall(r"(?m)^ListenStream=(.*)$", socket_unit))
        self.assertIn("Service=smarthost-ingress.service", socket_unit)
        service = (self.out / "systemd/smarthost-ingress.service").read_text()
        self.assertRegex(service, r"(?m)^ExecStart=/usr/bin/podman start --attach smarthost-nginx$")
        self.assertRegex(service, r"(?m)^ExecStop=/usr/bin/podman stop --time 10 smarthost-nginx$")
        self.assertRegex(service, r"(?m)^Restart=on-failure$")
        self.assertRegex(service, r"(?m)^UnsetEnvironment=CONTAINER_HOST", "local Podman: the API socket cannot pass descriptors")
        self.assertNotRegex(service, r"(?m)^Exec\w*=.*podman (rm|create|run)", "the unit never creates or removes the container")

    def test_reputation_timer_runs_the_evaluation_in_the_app_container(self) -> None:
        timer = (self.out / "systemd/smarthost-reputation-evaluate.timer").read_text()
        self.assertRegex(timer, r"(?m)^OnUnitActiveSec=15min$")
        self.assertIn("Unit=smarthost-reputation-evaluate.service", timer)
        service = (self.out / "systemd/smarthost-reputation-evaluate.service").read_text()
        self.assertRegex(service, r"(?m)^ExecStart=.*/podman/smarthost-production.sh app-exec php bin/console smarthost:reputation evaluate$")
        self.assertIn('cmd_app_exec "$@"', self.script)
        self.assertIn('INSTANCE_EXTRAS=("$UNIT_REPUTATION_TIMER" "$I-backup.timer" "$I-tls-renew.timer" "$I-host-agent.service")', self.script)
        self.assertIn('hostctl start "$u"', self.script)

    def test_host_agent_and_maintenance_timers(self) -> None:
        agent = (self.out / "systemd/smarthost-host-agent.service").read_text()
        self.assertRegex(agent, r"(?m)^ExecStart=.*/infra/bin/smarthostctl-prod agent run$")
        self.assertRegex(agent, r"(?m)^Restart=always$")
        self.assertRegex(agent, r"(?m)^Environment=SMARTHOST_DOTENV=/")
        backup = (self.out / "systemd/smarthost-backup.timer").read_text()
        self.assertRegex(backup, r"(?m)^OnCalendar=\*-\*-\* 03:15:00$")
        self.assertRegex(backup, r"(?m)^Persistent=true$")
        self.assertRegex((self.out / "systemd/smarthost-backup.service").read_text(), r"(?m)^ExecStart=.*smarthostctl-prod backup --scheduled$")
        self.assertRegex((self.out / "systemd/smarthost-tls-renew.service").read_text(), r"(?m)^ExecStart=.*smarthostctl-prod tls acme renew$")
        self.assertRegex((self.out / "systemd/smarthost-tls-renew.timer").read_text(), r"(?m)^OnCalendar=daily$")

    def test_nginx_inherits_the_sockets_and_restarts_only_through_systemd(self) -> None:
        nginx = self.create_block("nginx")
        self.assertIn("restart=no service nginx", nginx)
        self.assertIn('-e "NGINX=3;4;"', nginx)
        self.assertIn("NGINX_ENVSUBST_TEMPLATE_DIR=/etc/nginx/templates-production", nginx)
        start = self.script.split("cmd_start() {", 1)[1].split("\n}", 1)[0]
        self.assertIn('if [[ "$s" == nginx ]]; then ingress_start; else podman start', start)
        self.assertIn('hostctl start "$UNIT_SOCKET" "$UNIT_INGRESS"', self.script)
        self.assertIn("ip_unprivileged_port_start=25", self.script, "a failed bind names the host setting")

    def test_ingress_network_only_for_nginx_and_postfix(self) -> None:
        for service in ("postgres", "opendkim", "postfix", "symfony-app", "webhook-worker", "nginx", "validator", "delivery"):
            block = self.create_block(service)
            self.assertEqual(service in ("nginx", "postfix"), f'"$(ingress {service})"' in block, service)
            self.assertEqual(service in ("nginx", "postfix"), '"$(ingress_host)"' in block, service)
        self.assertIn('podman network create --internal --subnet "$INGRESS_SUBNET"', self.script)
        self.assertIn("INGRESS_SUBNET=10.89.22.0/24\n", self.script)

    def test_no_development_services_and_keys_stay_with_their_owner(self) -> None:
        self.assertNotIn("mailpit", self.script.split("SERVICES=")[1].split("\n")[0])
        self.assertNotIn("fake-smtp", self.script.split("SERVICES=")[1].split("\n")[0])
        self.assertEqual(["opendkim"], [s for s in ("postgres", "opendkim", "postfix", "symfony-app", "webhook-worker", "nginx", "validator", "delivery")
                                        if "opendkim-keys" in self.create_block(s)])
        self.assertIn("proxy-tls-key", self.create_block("nginx"))
        self.assertNotIn("tls-key", self.create_block("symfony-app"))

    def test_least_privilege_env_files(self) -> None:
        postfix = (self.out / "env/postfix.env").read_text()
        self.assertIn("APP_MAIL_FROM=no-reply@cattomail-ops.net", postfix)
        self.assertNotIn("APP_DB_PASSWORD", postfix)
        self.assertNotIn("APP_ENCRYPTION_KEYS", (self.out / "env/proxy.env").read_text())
        self.assertIn("SMARTHOST_PUBLIC_IPV4=", (self.out / "env/deployment.env").read_text())
        for f in (self.out / "env").iterdir():
            self.assertEqual(0o600, f.stat().st_mode & 0o777, f.name)

    def test_systemd_units_drive_the_production_script(self) -> None:
        unit = (self.out / "systemd/smarthost.service").read_text()
        self.assertIn("/podman/smarthost-production.sh start", unit)
        self.assertIn("/podman/smarthost-production.sh stop", unit)
        self.assertNotRegex(unit, r"(?m)^ExecStopPost=", "stopping never removes anything")
        timer_service = (self.out / "systemd/smarthost-postfix-queue-snapshot.service").read_text()
        self.assertIn("smarthost-production.sh postfix-exec smarthost-queue-snapshot", timer_service)

    def test_development_rendering_keeps_the_pod(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            dev = render.parse_dotenv(ROOT / "infra/.env.example")
            for row in render.contract():
                if row["secret"]:
                    dev[row["name"]] = "dev-secret"
            out = Path(tmp) / "g"
            render.render(write_env(dev, Path(tmp)), out)
            self.assertIn("/podman/smarthost-pod.sh start", (out / "systemd/smarthost.service").read_text())
            self.assertEqual([], sorted(p.name for p in (out / "systemd").glob("*ingress*")), "no ingress units in development")
            self.assertEqual([], sorted(p.name for p in (out / "systemd").glob("*reputation*")), "no reputation timer in development")
            self.assertIn("PROXY_HTTPS_BIND=127.0.0.1:8443", (out / "env/proxy.env").read_text())

    def test_unsafe_configuration_is_never_rendered(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            env = write_env(production_values(APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS="x"), Path(tmp))
            p = subprocess.run([sys.executable, str(ROOT / "infra/lib/smarthost_render.py"), "render", "--env", str(env), "--out", f"{tmp}/g"],
                               capture_output=True, text=True)
            self.assertNotEqual(0, p.returncode)
            self.assertIn("APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS", p.stderr)
            self.assertFalse(Path(f"{tmp}/g/podman").exists())


if __name__ == "__main__":
    unittest.main()
