"""Phase 8: preflight logic with DNS, host and TLS fixtures (no network)."""
from __future__ import annotations

import base64
import datetime as dt
import re
import subprocess
import tempfile
import unittest
from unittest import mock
from pathlib import Path

from fixtures import PUBLIC_IP, ROOT, FakeHost, FakeResolver, pf, production_values, response


# ------------------------------------------------------------------- DNS wire
class DnsWireTest(unittest.TestCase):
    def transport(self, answers_by_server: dict[str, object]):
        calls: list[tuple[str, bool]] = []

        def send(server: str, payload: bytes, tcp: bool, timeout: float) -> bytes:
            calls.append((server, tcp))
            behaviour = answers_by_server[server]
            if isinstance(behaviour, Exception):
                raise behaviour
            return behaviour(payload, tcp)  # type: ignore[operator]
        return send, calls

    def test_answers_txt_mx_ptr_and_compression(self) -> None:
        def answer(q: bytes, tcp: bool) -> bytes:
            return response(q, [("x.test", "TXT", ["v=spf1 ", "ip4:192.0.2.1 -all"]), ("x.test", "MX", (10, "mx.x.test")),
                                ("x.test", "A", "192.0.2.7")], compress=True)
        send, _ = self.transport({"192.0.2.53": answer})
        r = pf.Resolver(["192.0.2.53"], transport=send)
        self.assertEqual(["v=spf1 ip4:192.0.2.1 -all"], r.query("x.test", "TXT"), "TXT strings are concatenated")
        self.assertEqual([(10, "mx.x.test")], r.query("x.test", "MX"))
        self.assertEqual(["192.0.2.7"], r.query("x.test", "A"))

    def test_nxdomain_is_empty_and_servfail_tries_the_next_server(self) -> None:
        send, calls = self.transport({"192.0.2.1": lambda q, tcp: response(q, [], rcode=2),
                                      "192.0.2.2": lambda q, tcp: response(q, [], rcode=3)})
        self.assertEqual([], pf.Resolver(["192.0.2.1", "192.0.2.2"], transport=send).query("gone.test", "A"))
        self.assertEqual(["192.0.2.1", "192.0.2.2"], [c[0] for c in calls])

    def test_truncation_retries_over_tcp(self) -> None:
        send, calls = self.transport({"192.0.2.1": lambda q, tcp: response(q, [("big.test", "TXT", "x" * 200)], tc=not tcp)})
        self.assertEqual(["x" * 200], pf.Resolver(["192.0.2.1"], transport=send).query("big.test", "TXT"))
        self.assertEqual([False, True], [c[1] for c in calls])

    def test_unreachable_resolvers_raise_instead_of_answering(self) -> None:
        send, _ = self.transport({"192.0.2.1": OSError("timed out")})
        with self.assertRaises(pf.DnsError):
            pf.Resolver(["192.0.2.1"], transport=send).query("x.test", "A")

    def test_reverse_name(self) -> None:
        self.assertEqual("25.100.51.198.in-addr.arpa", pf.reverse_name("198.51.100.25"))


# ------------------------------------------------------------------------ SPF
class SpfTest(unittest.TestCase):
    IP = "192.0.2.10"

    def check(self, zone: dict, domain: str = "send.test") -> pf.SpfResult:
        return pf.spf_check(self.IP, domain, FakeResolver(zone))

    def test_mechanisms(self) -> None:
        cases: dict[str, tuple[dict, str]] = {
            "ip4": ({("send.test", "TXT"): ["v=spf1 ip4:192.0.2.0/24 -all"]}, "pass"),
            "ip4 miss": ({("send.test", "TXT"): ["v=spf1 ip4:198.51.100.0/24 -all"]}, "fail"),
            "softfail": ({("send.test", "TXT"): ["v=spf1 ~all"]}, "softfail"),
            "a": ({("send.test", "TXT"): ["v=spf1 a:mta.test -all"], ("mta.test", "A"): [self.IP]}, "pass"),
            "a cidr": ({("send.test", "TXT"): ["v=spf1 a:mta.test/28 -all"], ("mta.test", "A"): ["192.0.2.1"]}, "pass"),
            "mx": ({("send.test", "TXT"): ["v=spf1 mx -all"], ("send.test", "MX"): [(10, "mx.test")], ("mx.test", "A"): [self.IP]}, "pass"),
            "include": ({("send.test", "TXT"): ["v=spf1 include:prov.test -all"], ("prov.test", "TXT"): ["v=spf1 ip4:192.0.2.10 ~all"]}, "pass"),
            "include miss": ({("send.test", "TXT"): ["v=spf1 include:prov.test -all"], ("prov.test", "TXT"): ["v=spf1 -all"]}, "fail"),
            "redirect": ({("send.test", "TXT"): ["v=spf1 redirect=other.test"], ("other.test", "TXT"): ["v=spf1 ip4:192.0.2.10 -all"]}, "pass"),
            "none": ({("send.test", "TXT"): ["google-site-verification=x"]}, "none"),
            "two records": ({("send.test", "TXT"): ["v=spf1 -all", "v=spf1 ~all"]}, "permerror"),
            "macro": ({("send.test", "TXT"): ["v=spf1 exists:%{i}.x.test -all"]}, "unsupported"),
            "neutral": ({("send.test", "TXT"): ["v=spf1 ip4:198.51.100.1"]}, "neutral"),
        }
        for name, (zone, want) in cases.items():
            with self.subTest(name):
                self.assertEqual(want, self.check(zone).result)

    def test_lookup_limit(self) -> None:
        zone = {("send.test", "TXT"): ["v=spf1 " + " ".join(f"a:h{i}.test" for i in range(11)) + " -all"]}
        self.assertEqual("permerror", self.check(zone).result)

    def test_dns_failure_is_temperror(self) -> None:
        self.assertEqual("temperror", pf.spf_check(self.IP, "send.test", FakeResolver({}, broken={"send.test"})).result)


# ------------------------------------------------------------- DKIM / DMARC
class RecordParsingTest(unittest.TestCase):
    def test_dkim_key_from_split_txt(self) -> None:
        self.assertEqual("MIIBabc+/=", pf.dkim_public_key(["v=DKIM1; k=rsa; p=MIIB abc+/="]))
        self.assertIsNone(pf.dkim_public_key(["v=spf1 -all"]))

    def test_dmarc(self) -> None:
        self.assertEqual("quarantine", pf.dmarc_policy(["v=DMARC1; p=quarantine; rua=mailto:r@x.test"])["p"])  # type: ignore[index]
        self.assertIsNone(pf.dmarc_policy(["v=spf1 -all"]))
        self.assertIsNone(pf.dmarc_policy(["v=DMARC1; p=none", "v=DMARC1; p=reject"]), "two DMARC records are invalid")
        self.assertEqual("example.co", pf.org_domain("mail.news.example.co"))


# -------------------------------------------------------------------- TLS
class CertificateTest(unittest.TestCase):
    def test_cert_info_and_names(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "30", "-subj", "/CN=mail.example.net",
                            "-addext", "subjectAltName=DNS:mail.example.net,DNS:*.example.org", "-keyout", f"{tmp}/k", "-outform", "DER",
                            "-out", f"{tmp}/c"], check=True, capture_output=True)
            info = pf.cert_info(Path(f"{tmp}/c").read_bytes())
        self.assertEqual(["mail.example.net", "*.example.org"], info.names)
        self.assertTrue(dt.timedelta(days=29) < info.not_after - dt.datetime.now(dt.UTC) < dt.timedelta(days=31))
        self.assertTrue(pf.name_matches("mail.example.net", info.names))
        self.assertTrue(pf.name_matches("a.example.org", info.names))
        self.assertFalse(pf.name_matches("a.b.example.org", info.names), "a wildcard covers one label")
        self.assertFalse(pf.name_matches("other.example.net", info.names))
        with self.assertRaises(ValueError):
            pf.cert_info(None)


# ------------------------------------------------------------ grant matrix
class GrantMatrixTest(unittest.TestCase):
    def test_grants_check_counts_column_privileges(self) -> None:
        values = production_values()
        roles = {"app": values["APP_DB_USER"], "webhook": values["APP_WEBHOOK_DB_USER"],
                 "validator": values["VALIDATOR_DB_USER"], "delivery": values["DELIVERY_DB_USER"]}
        names = {"S": "SELECT", "I": "INSERT", "U": "UPDATE", "D": "DELETE"}
        rows = [[t, roles[r], names[p]] for t, per in pf.grant_matrix((ROOT / "docs/schema/schema.md").read_text()).items()
                for r, privs in per.items() for p in privs]
        host = FakeHost(values, sql={"role_table_grants": rows})
        p = pf.Preflight(values, host, FakeResolver({}))
        p.check_grants()
        self.assertEqual("PASS", p.results[-1].level, p.results[-1].detail)
        missing = [r for r in rows if not (r[0] == "send_job_recipients" and r[2] == "UPDATE")]
        p2 = pf.Preflight(values, FakeHost(values, sql={"role_table_grants": missing}), FakeResolver({}))
        p2.check_grants()
        self.assertEqual("FAIL", p2.results[-1].level)
        self.assertIn("send_job_recipients", p2.results[-1].detail)

    def test_schema_md_matrix(self) -> None:
        m = pf.grant_matrix((ROOT / "docs/schema/schema.md").read_text())
        self.assertEqual(39, len(m))
        self.assertEqual({"S", "I", "U"}, m["delivery_heartbeats"]["delivery"])
        self.assertEqual({"S", "I"}, m["client_notes"]["app"])
        self.assertEqual(set(), m["client_alerts"]["delivery"])
        self.assertEqual({"S"}, m["delivery_heartbeats"]["app"])
        self.assertEqual(set(), m["delivery_heartbeats"]["webhook"])


# ---------------------------------------------------------------- the checks
def good_zone(values: dict[str, str], dkim_key: str) -> dict:
    ip, web, mta, bounce = PUBLIC_IP, values["PROXY_SERVER_NAME"], values["POSTFIX_MYHOSTNAME"], values["SMARTHOST_BOUNCE_DOMAIN"]
    return {
        (web, "A"): [ip], (mta, "A"): [ip], (pf.reverse_name(ip), "PTR"): [mta],
        (bounce, "MX"): [(10, mta)], (bounce, "TXT"): [f"v=spf1 ip4:{ip} -all"], (mta, "TXT"): [f"v=spf1 ip4:{ip} -all"],
        ("_dmarc.client-a.net", "TXT"): ["v=DMARC1; p=none; rua=mailto:d@client-a.net"],
        ("_dmarc.cattomail-ops.net", "TXT"): ["v=DMARC1; p=quarantine"],
        ("s1._domainkey.client-a.net", "TXT"): [f"v=DKIM1; k=rsa; p={dkim_key}"],
        ("ops1._domainkey.cattomail-ops.net", "TXT"): [f"v=DKIM1; k=rsa; p={dkim_key}"],
    }


KEY = base64.b64encode(b"public-key-bytes").decode()


def host_for(values: dict[str, str], **kw) -> FakeHost:
    exec_: dict[tuple[str, ...], tuple[int, str]] = {
        ("opendkim", "smarthost-dkim-key", "list"): (0, "domain\tselector\tactive\tkey\tbits\n"
                                                        "client-a.net\ts1\tyes\tpresent\t2048\n"
                                                        "cattomail-ops.net\tops1\tyes\tpresent\t2048\n"),
        ("opendkim", "smarthost-dkim-key", "pubkey"): (0, KEY + "\n"),
    }
    exec_.update(kw.pop("exec_", {}))
    sql = {"FROM sending_domains": [["client-a.net", "active", "s1", "Client A"]]}
    sql.update(kw.pop("sql", {}))
    return FakeHost(values, sql=sql, exec_=exec_, **kw)


def levels(results: list[pf.Check], section: str | None = None) -> dict[str, str]:
    return {r.name: r.level for r in results if section is None or r.section == section}


class DnsAndDkimChecksTest(unittest.TestCase):
    def run_pf(self, zone: dict, activation: bool = False, host: FakeHost | None = None, sections=("dns", "dkim")) -> list[pf.Check]:
        values = production_values()
        p = pf.Preflight(values, host or host_for(values), FakeResolver(zone), activation=activation)
        return p.run(sections)

    def test_a_correct_identity_passes(self) -> None:
        values = production_values()
        res = self.run_pf(good_zone(values, KEY))
        self.assertEqual([], [(r.name, r.detail) for r in res if r.level not in ("PASS", "INFO", "SKIP")])
        names = {r.name for r in res}
        for expected in (f"PTR {PUBLIC_IP}", "forward-confirmed reverse DNS", "MX bounce.cattomail-ops.net (bounce domain)",
                         "SPF bounce.cattomail-ops.net (bounce domain (envelope sender))", "DMARC client-a.net",
                         "client-a.net selector s1", "cattomail-ops.net selector ops1"):
            self.assertIn(expected, names)

    def test_identity_failures_are_reported(self) -> None:
        values = production_values()
        zone = good_zone(values, KEY)
        zone[(pf.reverse_name(PUBLIC_IP), "PTR")] = ["static-100-42-42-42.isp.example"]
        zone[("bounce.cattomail-ops.net", "TXT")] = ["v=spf1 -all"]
        zone[("bounce.cattomail-ops.net", "MX")] = [(10, "mx.elsewhere.net")]
        zone[("s1._domainkey.client-a.net", "TXT")] = ["v=DKIM1; k=rsa; p=" + base64.b64encode(b"another key").decode()]
        del zone[("_dmarc.client-a.net", "TXT")]
        lv = levels(self.run_pf(zone))
        self.assertEqual("FAIL", lv[f"PTR {PUBLIC_IP}"])
        self.assertEqual("FAIL", lv["forward-confirmed reverse DNS"])
        self.assertEqual("FAIL", lv["SPF bounce.cattomail-ops.net (bounce domain (envelope sender))"])
        self.assertEqual("FAIL", lv["MX bounce.cattomail-ops.net (bounce domain)"])
        self.assertEqual("FAIL", lv["client-a.net selector s1"], "published key differs from the installed one")
        self.assertEqual("WARN", lv["DMARC client-a.net"], "missing DMARC is a warning outside activation")

    def test_activation_escalates_required_warnings(self) -> None:
        values = production_values()
        zone = good_zone(values, KEY)
        del zone[("_dmarc.client-a.net", "TXT")]
        lv = levels(self.run_pf(zone, activation=True))
        self.assertEqual("FAIL", lv["DMARC client-a.net"])

    def test_dkim_configuration_gaps(self) -> None:
        values = production_values()
        zone = good_zone(values, KEY)
        cases = {
            "DKIM not active": ([["client-a.net", "pending_dns", "s1", "A"]], None, "client-a.net"),
            "selector not in OpenDKIM": ([["client-a.net", "active", "s9", "A"]], None, "client-a.net selector s9"),
            "OpenDKIM signs with another selector": ([["client-a.net", "active", "s1", "A"]],
                                                     "domain\tselector\tactive\tkey\tbits\nclient-a.net\ts1\tno\tpresent\t2048\nclient-a.net\ts2\tyes\tpresent\t2048\n",
                                                     "client-a.net selector s1"),
            "key file missing": ([["client-a.net", "active", "s1", "A"]],
                                 "domain\tselector\tactive\tkey\tbits\nclient-a.net\ts1\tyes\tmissing\t-\n", "client-a.net selector s1"),
        }
        for name, (rows, listing, check_name) in cases.items():
            with self.subTest(name):
                ex = {} if listing is None else {("opendkim", "smarthost-dkim-key", "list"): (0, listing)}
                res = self.run_pf(zone, host=host_for(values, sql={"FROM sending_domains": rows}, exec_=ex), sections=("dkim",))
                self.assertEqual("FAIL", levels(res)[check_name], [(r.name, r.detail) for r in res])

    def test_unreachable_dns_is_a_warning_not_a_pass(self) -> None:
        values = production_values()
        res = pf.Preflight(values, host_for(values), FakeResolver({}, broken={values["PROXY_SERVER_NAME"]})).run(("dns",))
        self.assertEqual("WARN", levels(res)[f"A {values['PROXY_SERVER_NAME']}"])


class PostfixDeliveryExposureChecksTest(unittest.TestCase):
    POSTCONF = ["myhostname", "relayhost", "mydestination", "mynetworks", "inet_protocols", "smtpd_relay_restrictions",
                "virtual_mailbox_domains", "default_transport", "defer_transports", "smtpd_tls_cert_file",
                "smtpd_sender_login_maps", "recipient_delimiter", "message_size_limit", "disable_vrfy_command"]

    def postconf(self, values: dict[str, str], **override: str) -> str:
        pc = {"myhostname": values["POSTFIX_MYHOSTNAME"], "relayhost": "", "mydestination": "", "mynetworks": "127.0.0.0/8",
              "inet_protocols": "ipv4", "smtpd_relay_restrictions": "reject_unauth_destination",
              "virtual_mailbox_domains": values["SMARTHOST_BOUNCE_DOMAIN"], "default_transport": "retry:live delivery is not activated",
              "defer_transports": "", "smtpd_tls_cert_file": values["POSTFIX_TLS_CERT_FILE"],
              "smtpd_sender_login_maps": "regexp:/etc/postfix/smarthost_sender_logins", "recipient_delimiter": "+",
              "message_size_limit": "10240000", "disable_vrfy_command": "yes"}
        pc.update(override)
        return "\n".join(pc[n] for n in self.POSTCONF) + "\n"

    def run_postfix(self, values: dict[str, str], postconf: str) -> dict[str, str]:
        host = FakeHost(values, exec_={("postfix", "postconf", "-h"): (0, postconf),
                                       ("postfix", "postconf", "-P"): (0, f"submission/inet/smtpd_milters = {values['SMARTHOST_OPENDKIM_MILTER_ADDRESS']}\n")})
        return levels(pf.Preflight(values, host, FakeResolver({})).run(("postfix",)))

    def test_held_and_live_modes(self) -> None:
        values = production_values(POSTFIX_SMTP_BIND="")   # no SMTP connection in unit tests
        lv = self.run_postfix(values, self.postconf(values))
        self.assertEqual("PASS", lv["delivery mode"])
        self.assertEqual("PASS", lv["relayhost"])
        live = production_values(POSTFIX_SMTP_BIND="", SMARTHOST_LIVE_DELIVERY_ENABLED="true")
        self.assertEqual("FAIL", self.run_postfix(live, self.postconf(live))["delivery mode"], "live config but held Postfix")
        self.assertEqual("PASS", self.run_postfix(live, self.postconf(live, default_transport="smtp"))["delivery mode"])

    def test_dangerous_postfix_settings_fail(self) -> None:
        values = production_values(POSTFIX_SMTP_BIND="")
        lv = self.run_postfix(values, self.postconf(values, relayhost="[mailpit]:1025", mynetworks="0.0.0.0/0",
                                                    smtpd_relay_restrictions="permit_mynetworks", defer_transports="smtp"))
        self.assertEqual("FAIL", lv["relayhost"])
        self.assertEqual("FAIL", lv["mynetworks"])
        self.assertEqual("FAIL", lv["relay restrictions"])
        self.assertEqual("WARN", lv["emergency pause"])

    def test_delivery_heartbeat_state(self) -> None:
        values = production_values()
        host = FakeHost(values, sql={"FROM delivery_heartbeats": [["f", "t", "f", "t", "t", "5", "0.1.7"]]})
        lv = levels(pf.Preflight(values, host, FakeResolver({})).run(("delivery",)))
        self.assertEqual({"delivery daemon": "PASS", "delivery state": "PASS", "queue snapshots": "PASS"},
                         {k: lv[k] for k in ("delivery daemon", "delivery state", "queue snapshots")})
        stale = FakeHost(values, sql={"FROM delivery_heartbeats": [["f", "t", "f", "f", "f", "5", "0.1.7"]]})
        lv = levels(pf.Preflight(values, stale, FakeResolver({})).run(("delivery",)))
        self.assertEqual("FAIL", lv["delivery daemon"])
        self.assertEqual("WARN", lv["queue snapshots"])

    def container(self, service: str, values: dict[str, str], ports=None, networks=None, extra: str = "") -> dict:
        i = values["SMARTHOST_INSTANCE"]
        nets = networks if networks is not None else (
            [f"{i}-internal"] + ([f"{i}-egress"] if service in pf.EGRESS_SERVICES else [])
            + ([f"{i}-ingress"] if service in pf.INGRESS_SERVICES else []))
        return {"State": {"Health": {"Status": "healthy"}}, "Mounts": extra,
                "NetworkSettings": {"Ports": ports or {}, "Networks": {n: {} for n in nets}}}

    def test_exposure(self) -> None:
        values = production_values()
        i = values["SMARTHOST_INSTANCE"]
        base = {s: self.container(s, values) for s in pf.SERVICES}
        base["nginx"] = self.container("nginx", values, extra=f"{i}-proxy-tls-key")
        base["postfix"] = self.container("postfix", values, extra=f"{i}-postfix-tls-key")
        base["opendkim"] = self.container("opendkim", values, extra=f"{i}-opendkim-keys")
        nets = {f"{i}-internal": "true", f"{i}-ingress": "true", f"{i}-egress": "false"}
        lv = levels(pf.Preflight(values, FakeHost(values, inspect=base, networks=nets), FakeResolver({})).run(("exposure",)))
        self.assertEqual([], [k for k, v in lv.items() if v == "FAIL"], lv)
        self.assertEqual("PASS", lv["published container ports"])
        bad = dict(base)
        # The Phase 8 design before the networking closeout: rootless port forwarding.
        bad["nginx"] = self.container("nginx", values, {"443/tcp": [{"HostIp": "0.0.0.0", "HostPort": "443"}]}, extra=f"{i}-proxy-tls-key")
        bad["delivery"] = self.container("delivery", values, networks=[f"{i}-internal", f"{i}-egress"])
        bad["symfony-app"] = self.container("symfony-app", values, networks=[f"{i}-internal", f"{i}-egress", f"{i}-ingress"],
                                            extra=f"{i}-opendkim-keys")
        lv = levels(pf.Preflight(values, FakeHost(values, inspect=bad, networks=nets), FakeResolver({})).run(("exposure",)))
        self.assertEqual("FAIL", lv["published container ports"])
        self.assertEqual("FAIL", lv["networks of delivery"])
        self.assertEqual("FAIL", lv["networks of symfony-app"], "only nginx and Postfix share the ingress network")
        self.assertEqual("FAIL", lv[f"private key {i}-opendkim-keys"])
        lv = levels(pf.Preflight(values, FakeHost(values, inspect=base, networks={**nets, f"{i}-ingress": "false"}),
                                 FakeResolver({})).run(("exposure",)))
        self.assertEqual("FAIL", lv[f"network {i}-ingress"])


class LowPortHost(FakeHost):
    def __init__(self, values: dict[str, str], start: str | None, persisted: list[str]) -> None:
        super().__init__(values, remote=False)
        self.start, self.persisted = start, persisted

    def sysctl(self, name: str) -> str | None:
        return self.start

    def sysctl_persisted(self, name: str) -> list[str]:
        return self.persisted


class HostPrerequisitesTest(unittest.TestCase):
    def low_ports(self, values: dict[str, str], start: str | None, persisted: list[str] | None = None, remote: bool = False) -> list[pf.Check]:
        host = LowPortHost(values, start, persisted or [])
        p = pf.Preflight(values, host, FakeResolver({}))
        p.check_low_ports(remote)
        return p.results

    def test_ports_25_and_443_need_the_host_setting(self) -> None:
        values = production_values()
        ok = self.low_ports(values, "25", ["/etc/sysctl.d/60-catto-mail-ports.conf: net.ipv4.ip_unprivileged_port_start=25"])
        self.assertEqual(["PASS", "PASS"], [r.level for r in ok])
        default = self.low_ports(values, "1024")
        self.assertEqual("FAIL", default[0].level)
        self.assertIn("cannot bind port 25", default[0].detail)
        self.assertIn("sudo tee /etc/sysctl.d/60-catto-mail-ports.conf", default[0].detail, "the remediation is spelled out")
        self.assertEqual("FAIL", self.low_ports(values, "80")[0].level, "443 would bind but 25 would not")
        runtime_only = self.low_ports(values, "25")
        self.assertEqual(["PASS", "WARN"], [r.level for r in runtime_only])
        self.assertIn("set at runtime only", runtime_only[1].detail)
        stale = self.low_ports(values, "25", ["/etc/sysctl.d/99-old.conf: net.ipv4.ip_unprivileged_port_start=1024"])
        self.assertEqual("WARN", stale[1].level, "the persisted value would undo it at the next boot")

    def test_unprivileged_rehearsal_ports_and_remote_engines(self) -> None:
        rehearsal = production_values(PROXY_HTTPS_BIND="127.0.0.1:18443", POSTFIX_SMTP_BIND="127.0.0.1:12525")
        self.assertEqual("PASS", self.low_ports(rehearsal, "1024")[0].level)
        self.assertEqual("SKIP", self.low_ports(production_values(), None, remote=True)[0].level)


class IngressHost(FakeHost):
    """The ingress as seen from the host: `mode` is how client addresses arrive."""

    def __init__(self, values: dict[str, str], mode: str = "preserve", inherited: bool = True,
                 units: tuple[str, str] | None = ("active", "active"), proxy_listener: bool = True) -> None:
        i = values["SMARTHOST_INSTANCE"]
        master = ("127.0.0.1:smtp inet n - n - - smtpd\n" + ("postfix-ingress:smtp inet n - n - - smtpd -o smtpd_upstream_proxy_protocol=haproxy\n"
                  if proxy_listener else "smtp inet n - n - - smtpd\n") + "submission inet n - n - - smtpd\n")
        super().__init__(values, remote=True, exec_={("postfix", "postconf", "-M"): (0, master)})
        self.mode, self.units, self.i = mode, units, i
        self.http_log: list[str] = ['2026/10/06 17:00:00 [notice] 1#1: using inherited sockets from "3;4;"' if inherited else
                                    '2026/10/06 17:00:00 [emerg] 1#1: invalid socket number "3" in NGINX environment variable']
        self.smtp_log: list[str] = []

    def seen(self, source: str) -> str:
        return source if self.mode == "preserve" else self.mode.split(":", 1)[1]

    def systemctl(self, *args: str) -> tuple[int, str] | None:
        if self.units is None:
            return None
        if args[0] == "is-active":
            return 0, "\n".join(self.units)
        return 0, f"{self.v['PROXY_HTTPS_BIND']} (Stream)\n{self.v['POSTFIX_SMTP_BIND']} (Stream)\n"

    def https_probe(self, source: str, addr: str, port: int, sni: str, path: str) -> str:
        self.http_log.append(f'{self.seen(source)} - - [06/Oct/2026:17:00:00 +0000] "GET {path} HTTP/1.1" 404 0 "-" "catto-mail-preflight"')
        return "HTTP/1.1 404 Not Found"

    def smtp_probe(self, source: str, addr: str, port: int, helo: str) -> str:
        self.smtp_log.append(f"Oct  6 17:00:00 mta postfix/smtpd[7]: connect from unknown[{self.seen(source)}]")
        return "220 mta ESMTP"

    def logs(self, service: str, since: str) -> str:
        return "\n".join(self.http_log)

    def started_at(self, service: str) -> str | None:
        return "2026-10-06T17:00:00Z"

    def exec(self, service: str, *cmd: str, user: str | None = None) -> tuple[int, str]:
        if service == "postfix" and cmd[:2] == ("sh", "-c"):
            return 0, "\n".join(self.smtp_log)
        return super().exec(service, *cmd, user=user)


class IngressSourceAddressTest(unittest.TestCase):
    def run_ingress(self, host: IngressHost, values: dict[str, str], activation: bool = False) -> dict[str, pf.Check]:
        with mock.patch.object(pf.time, "sleep"):
            results = pf.Preflight(values, host, FakeResolver({}), activation=activation).run(("ingress",))
        return {r.name: r for r in results}

    def test_preserved_client_addresses_pass(self) -> None:
        values = production_values()
        host = IngressHost(values)
        r = self.run_ingress(host, values, activation=True)
        self.assertEqual([], [f"{k}: {c.detail}" for k, c in r.items() if c.level != "PASS"])
        http = r["nginx (HTTPS, Symfony REMOTE_ADDR) sees each client's own address"]
        sources = re.findall(r"127\.\d+\.\d+\.[23]", http.detail)
        self.assertEqual(2, len(set(sources)), "two distinct test sources")
        self.assertTrue(all(src.split(".")[:3] == sources[0].split(".")[:3] for src in sources))
        self.assertEqual(sorted(set(sources)), sorted(ln.split()[0] for ln in host.http_log if "GET" in ln))

    def test_collapsed_client_addresses_fail_the_activation(self) -> None:
        values = production_values()
        r = self.run_ingress(IngressHost(values, mode="collapse:10.89.22.15"), values, activation=True)
        for name in ("nginx (HTTPS, Symfony REMOTE_ADDR) sees each client's own address", "Postfix (SMTP peer) sees each client's own address"):
            self.assertEqual("FAIL", r[name].level, name)
            self.assertIn("collapsed to 10.89.22.15", r[name].detail)

    def test_structural_failures(self) -> None:
        values = production_values()
        r = self.run_ingress(IngressHost(values, inherited=False, units=("inactive", "inactive"), proxy_listener=False), values)
        self.assertEqual("FAIL", r["ingress units"].level)
        self.assertEqual("FAIL", r["nginx holds the inherited sockets"].level, "started without the socket unit")
        self.assertEqual("FAIL", r["Postfix port 25 takes the PROXY protocol from nginx only"].level)
        trusted = production_values(TRUSTED_PROXIES="10.89.20.0/24")
        self.assertEqual("FAIL", self.run_ingress(IngressHost(trusted), trusted)["application client address"].level)

    def test_unreachable_systemd_blocks_only_the_activation(self) -> None:
        values = production_values()
        self.assertEqual("WARN", self.run_ingress(IngressHost(values, units=None), values)["ingress units"].level)
        self.assertEqual("FAIL", self.run_ingress(IngressHost(values, units=None), values, activation=True)["ingress units"].level)


class GeneratedTextTest(unittest.TestCase):
    def test_checklist_and_firewall(self) -> None:
        values = production_values()
        text = pf.dns_checklist(values, ["name:  s1._domainkey.client-a.net", "value: v=DKIM1; k=rsa; p=AAA"])
        for needle in (f"PTR for {PUBLIC_IP} -> mta1.cattomail-ops.net", "bounce.cattomail-ops.net.  MX  10 mta1.cattomail-ops.net.",
                       f"v=spf1 ip4:{PUBLIC_IP} -all", "s1._domainkey.client-a.net", "_dmarc.<sending-domain>"):
            self.assertIn(needle, text)
        rules = pf.firewall_ruleset(values, 1001)
        self.assertIn("tcp dport { 22, 25, 443 } ct state new accept", rules)
        self.assertIn("meta skuid 1001 tcp dport { 25, 53, 80, 443 } accept", rules)
        self.assertIn("policy drop", rules)


if __name__ == "__main__":
    unittest.main()
