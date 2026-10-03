"""SMTP probing against the deterministic fake SMTP server. The autouse fixture in
conftest.py additionally fails any test after which the server saw DATA."""
import asyncio
import inspect
import re

import pytest

from smarthost_validator import smtp_probe
from smarthost_validator.smtp_probe import ALLOWED_VERBS, ForbiddenCommand, Reply, SmtpProber, _Session, classify_rcpt
from tests.conftest import scripted_server
from tests.fakes import fake_smtp


def prober(port: int, command_timeout: float = 2.0) -> SmtpProber:
    return SmtpProber("validator.test", "validator@bounce.test", 2.0, command_timeout, ("127.0.0.1", port))


@pytest.mark.parametrize("candidate,status,code", [
    ("someone@example.test", "accepted", "smtp.rcpt.2.1.5"),
    ("reject-550-x@example.test", "rejected", "smtp.rcpt.5.1.1"),
    ("tempfail-450-x@example.test", "temporary_failure", "smtp.rcpt.4.2.1"),
    ("tempfail-451-x@example.test", "temporary_failure", "smtp.rcpt.4.3.0"),
    ("unavailable-421-x@example.test", "temporary_failure", "smtp.rcpt.4.3.2"),
    ("throttle-421-x@example.test", "temporary_failure", "smtp.rcpt.4.7.0"),
    ("block-554-x@example.test", "blocked", "smtp.rcpt.5.7.1"),
    ("anyone@block-all.test", "blocked", "smtp.rcpt.5.7.1"),
])
async def test_rcpt_scenarios(smtp_server, candidate, status, code):
    r = await prober(smtp_server).probe(candidate, candidate.rsplit("@", 1)[1], ("mx.example.test",), False)
    assert (r.status, r.code) == (status, code)
    assert r.host == "mx.example.test"  # logical MX recorded even though the override was used
    kinds = [e.evidence_type for e in r.evidence]
    assert kinds[:4] == ["smtp_connect", "smtp_ehlo", "smtp_mail_from", "smtp_rcpt_to"]
    # 421 (service unavailable / too many connections), 4.7.x and policy blocks all mean "back off".
    assert r.provider_throttling == (status == "blocked" or "4.7" in code or "-421" in candidate)


async def test_accept_all_detection(smtp_server):
    p = prober(smtp_server)
    normal = await p.probe("someone@example.test", "example.test", ("mx",), True)
    assert normal.status == "accepted" and normal.accept_all is False
    catch_all = await p.probe("someone@accept-all.test", "accept-all.test", ("mx",), True)
    assert catch_all.status == "accepted" and catch_all.accept_all is True
    probe_ev = [e for e in catch_all.evidence if e.evidence_type == "smtp_accept_all_probe"]
    assert len(probe_ev) == 1 and probe_ev[0].detail["result"] == "accepted"
    rejected = await p.probe("reject-550@example.test", "example.test", ("mx",), True)
    assert rejected.accept_all is False and not any(e.evidence_type == "smtp_accept_all_probe" for e in rejected.evidence)


async def test_timeout(smtp_server):
    r = await prober(smtp_server, command_timeout=0.5).probe("timeout-x@example.test", "example.test", ("mx",), False)
    assert (r.status, r.code) == ("timeout", "smtp.command_timeout")


async def test_connection_refused_tries_two_hosts():
    import socket
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        closed = s.getsockname()[1]
    r = await SmtpProber("v.test", "v@b.test", 1.0, 1.0, ("127.0.0.1", closed)).probe("a@x.test", "x.test", ("mx1", "mx2", "mx3"), False)
    assert r.status == "connection_failed"
    assert [e.provider_host for e in r.evidence] == ["mx1", "mx2"]


async def test_session_refuses_data_and_bdat():
    reader = asyncio.StreamReader()
    class W:
        def write(self, b): raise AssertionError("nothing may be written")
        async def drain(self): pass
    s = _Session(reader, W(), 1)  # type: ignore[arg-type]
    for line in ["DATA", "data", "BDAT 10 LAST", "MAIL FROM:<a@b>\r\nDATA"]:
        with pytest.raises(ForbiddenCommand):
            await s.command(line)
    assert "DATA" not in ALLOWED_VERBS and "BDAT" not in ALLOWED_VERBS


def test_no_code_path_sends_data():
    source = inspect.getsource(smtp_probe)
    sent = re.findall(r'command\(f?"([A-Z]+)', source)
    assert set(sent) <= {"EHLO", "HELO", "MAIL", "RCPT"} and "DATA" not in sent
    assert 'b"DATA' not in source and "b'DATA" not in source


async def test_fake_server_log_has_no_data_after_many_probes(smtp_server):
    p = prober(smtp_server)
    for c in ["a@example.test", "reject-550@example.test", "x@accept-all.test", "block-554@example.test"]:
        await p.probe(c, c.rsplit("@", 1)[1], ("mx",), True)
    assert {"EHLO", "MAIL", "RCPT", "QUIT"} <= set(fake_smtp.COMMANDS)
    assert "DATA" not in fake_smtp.COMMANDS and "DATA" not in p.commands_sent


async def test_helo_fallback_and_no_smtputf8():
    async def script(reader, writer):
        writer.write(b"220 old.test ready\r\n")
        while line := await reader.readline():
            verb = line.split(b" ")[0].strip().upper()
            writer.write({b"EHLO": b"502 5.5.2 not implemented\r\n", b"HELO": b"250 old.test\r\n",
                          b"QUIT": b"221 bye\r\n"}.get(verb, b"250 ok\r\n"))
            await writer.drain()
            if verb == b"QUIT":
                break
        writer.close()
    server, port = await scripted_server(script)
    try:
        p = prober(port)
        ok = await p.probe("someone@x.test", "x.test", ("mx",), False)
        assert ok.status == "accepted"
        assert ok.evidence[1].detail == {"text": "old.test", "helo_fallback": True, "smtputf8": False}
        eai = await p.probe("jöhn@x.test", "x.test", ("mx",), False)
        assert (eai.status, eai.code) == ("inconclusive", "smtp.smtputf8_unsupported")
        assert p.commands_sent.count("MAIL") == 1  # nothing sent for the EAI address after HELO
    finally:
        server.close()


async def test_greeting_refusal_is_a_probe_block():
    async def script(reader, writer):
        writer.write(b"554 5.7.1 no probes from you\r\n")
        await writer.drain()
        await reader.readline()
        writer.close()
    server, port = await scripted_server(script)
    try:
        r = await prober(port).probe("a@x.test", "x.test", ("mx",), False)
        assert r.status == "blocked" and r.provider_throttling
    finally:
        server.close()


@pytest.mark.parametrize("code,text,status", [
    (250, "2.1.5 ok", "accepted"), (251, "will forward", "accepted"), (252, "cannot verify", "inconclusive"),
    (550, "5.1.1 user unknown", "rejected"), (550, "user unknown", "rejected"), (553, "mailbox name invalid", "rejected"),
    (551, "5.1.6 moved", "rejected"), (550, "5.7.1 rejected by policy", "blocked"), (550, "blocked by spamhaus", "blocked"),
    (554, "transaction failed", "blocked"), (552, "5.2.2 mailbox full", "inconclusive"), (450, "4.2.1 try later", "temporary_failure"),
    (451, "4.7.1 greylisted", "temporary_failure"), (421, "4.3.2 closing", "temporary_failure"), (500, "syntax", "inconclusive"),
])
def test_classify_rcpt(code, text, status):
    assert classify_rcpt(Reply(code, text))[0] == status
