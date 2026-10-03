"""SMTP mailbox probe (pipeline stage 7). NEVER sends DATA.

Sequence per attempt: connect -> greeting -> EHLO (HELO fallback on 500/502) ->
MAIL FROM:<VALIDATOR_SMTP_MAIL_FROM> -> RCPT TO:<candidate> -> optionally one
accept-all RCPT for an improbable random local part on the same domain -> QUIT.

`_Session.command()` accepts only the verbs in ALLOWED_VERBS; DATA and BDAT are
refused before anything is written to the socket (tests assert this, and the
fake SMTP server records every command it receives).

With VALIDATOR_SMTP_ROUTE_OVERRIDE set, every connection goes to that host:port
(the fake SMTP service) while the logical MX host is still used for the per-MX
concurrency key, evidence and provider back-off.

Classification of the RCPT reply (smtp_status vocabulary):
  2xx (250/251)                         accepted   (evidence only, not proof of a person)
  252                                   inconclusive (server will not verify)
  5xx with 5.1.x, or 550/551/553 without
      a policy indication               rejected
  5xx with 5.7.x or policy wording, 554 blocked    (says nothing about the mailbox)
  5xx with 5.2.x (e.g. mailbox full)    inconclusive
  4xx                                   temporary_failure (4.7.x / 421 also mark the
                                        provider as throttling: back off)
  no reply in time                      timeout
  refused/unreachable on every host     connection_failed
  anything else                         inconclusive
"""
from __future__ import annotations

import asyncio
import re
import secrets
from dataclasses import dataclass, field

from .models import Evidence

ALLOWED_VERBS = frozenset({"EHLO", "HELO", "MAIL", "RCPT", "RSET", "QUIT"})
_ENHANCED = re.compile(r"^([245])\.(\d{1,3})\.(\d{1,3})\b")
_POLICY_WORDS = re.compile(r"block|blacklist|blocklist|spamhaus|policy|reputation|denied|not allowed|"
                           r"rate limit|too many|prohibited|banned|refused|abuse|spam", re.I)
_TEXT_LIMIT = 200
MAX_HOSTS_TRIED = 2
ACCEPT_ALL_PREFIX = "smarthost-probe-"


class ForbiddenCommand(RuntimeError):
    """Raised when code tries to send a command outside ALLOWED_VERBS (e.g. DATA)."""


@dataclass(frozen=True)
class Reply:
    code: int
    text: str

    @property
    def enhanced(self) -> str | None:
        m = _ENHANCED.match(self.text)
        return m.group(0) if m else None


@dataclass
class ProbeResult:
    status: str  # smtp_status vocabulary
    code: str  # diagnostic code
    text: str
    host: str | None = None
    reply: Reply | None = None
    accept_all: bool | None = None
    provider_throttling: bool = False
    evidence: list[Evidence] = field(default_factory=list)

    @property
    def retryable(self) -> bool:
        return self.status in ("temporary_failure", "timeout", "connection_failed", "blocked")


def classify_rcpt(reply: Reply) -> tuple[str, str, bool]:
    """(smtp_status, diagnostic code, provider_throttling) for a RCPT reply."""
    code, enh, text = reply.code, reply.enhanced, reply.text
    diag = f"smtp.rcpt.{enh}" if enh else f"smtp.rcpt.{code}"
    if code in (250, 251):
        return "accepted", diag, False
    if code == 252:
        return "inconclusive", diag, False
    if 400 <= code < 500:
        throttling = code == 421 or (enh or "").startswith("4.7")
        return "temporary_failure", diag, throttling
    if 500 <= code < 600:
        if enh:
            if enh.startswith("5.1."):
                return "rejected", diag, False
            if enh.startswith("5.7."):
                return "blocked", diag, True
            if enh.startswith("5.2."):
                return "inconclusive", diag, False
        if _POLICY_WORDS.search(text) or code == 554:
            return "blocked", diag, True
        if code in (550, 551, 553):
            return "rejected", diag, False
        return "inconclusive", diag, False
    return "inconclusive", diag, False


class _Session:
    def __init__(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter, timeout: float) -> None:
        self.reader, self.writer, self.timeout = reader, writer, timeout
        self.sent: list[str] = []

    async def read_reply(self) -> Reply:
        lines: list[str] = []
        while True:
            raw = await asyncio.wait_for(self.reader.readline(), self.timeout)
            if not raw:
                raise ConnectionResetError("connection closed by server")
            line = raw.decode("utf-8", errors="replace").rstrip("\r\n")
            if len(line) < 3 or not line[:3].isdigit():
                raise ValueError("malformed SMTP reply")
            lines.append(line[4:])
            if len(line) == 3 or line[3] == " ":
                return Reply(int(line[:3]), " ".join(lines)[:2000])
            if len(lines) > 100:
                raise ValueError("SMTP reply too long")

    async def command(self, line: str) -> Reply:
        verb = line.split(" ", 1)[0].split(":", 1)[0].upper()
        if verb not in ALLOWED_VERBS:
            raise ForbiddenCommand(f"SMTP command {verb!r} is never sent by the validator")
        if "\r" in line or "\n" in line:
            raise ForbiddenCommand("line breaks are not allowed in SMTP commands")
        self.sent.append(verb)
        self.writer.write(line.encode("utf-8") + b"\r\n")
        await asyncio.wait_for(self.writer.drain(), self.timeout)
        return await self.read_reply()

    async def close(self) -> None:
        try:
            if not self.writer.is_closing():
                self.sent.append("QUIT")
                self.writer.write(b"QUIT\r\n")
                await asyncio.wait_for(self.writer.drain(), 2)
                try:
                    await asyncio.wait_for(self.reader.readline(), 2)
                except (asyncio.TimeoutError, ConnectionError):
                    pass
        except (ConnectionError, asyncio.TimeoutError, OSError):
            pass
        finally:
            self.writer.close()
            try:
                await asyncio.wait_for(self.writer.wait_closed(), 2)
            except (ConnectionError, asyncio.TimeoutError, OSError):
                pass


class SmtpProber:
    def __init__(self, helo_hostname: str, mail_from: str, connect_timeout: float, command_timeout: float,
                 route_override: tuple[str, int] | None = None) -> None:
        self.helo, self.mail_from = helo_hostname, mail_from
        self.connect_timeout, self.command_timeout = connect_timeout, command_timeout
        self.route_override = route_override
        self.commands_sent: list[str] = []  # every verb ever sent (tests assert DATA never appears)

    async def probe(self, candidate: str, domain: str, hosts: tuple[str, ...], accept_all_probe: bool) -> ProbeResult:
        evidence: list[Evidence] = []
        last: ProbeResult | None = None
        for host in hosts[:MAX_HOSTS_TRIED]:
            target = self.route_override or (host, 25)
            try:
                reader, writer = await asyncio.wait_for(asyncio.open_connection(*target), self.connect_timeout)
            except asyncio.TimeoutError:
                evidence.append(Evidence("smtp_connect", {"result": "timeout"}, host))
                last = ProbeResult("timeout", "smtp.connect_timeout", "Connecting to the mail host timed out.", host)
                continue
            except OSError as exc:
                evidence.append(Evidence("smtp_connect", {"result": "connection_failed", "error": type(exc).__name__}, host))
                last = ProbeResult("connection_failed", "smtp.connection_failed", "The mail host refused or could not be reached.", host)
                continue
            session = _Session(reader, writer, self.command_timeout)
            try:
                result = await self._converse(session, candidate, domain, host, accept_all_probe, evidence)
            finally:
                await session.close()
                self.commands_sent.extend(session.sent)
            result.evidence = evidence
            return result
        assert last is not None, "no hosts"
        last.evidence = evidence
        return last

    async def _converse(self, s: _Session, candidate: str, domain: str, host: str, accept_all_probe: bool,
                        evidence: list[Evidence]) -> ProbeResult:
        try:
            greeting = await s.read_reply()
            evidence.append(_ev("smtp_connect", host, greeting))
            if greeting.code != 220:
                status, code, throttling = classify_rcpt(greeting)
                if status == "accepted":
                    status = "inconclusive"
                return ProbeResult(status, "smtp.greeting." + str(greeting.code), "The server did not greet with 220.",
                                   host, greeting, provider_throttling=throttling or status == "blocked")
            ehlo = await s.command(f"EHLO {self.helo}")
            helo_fallback = False
            if ehlo.code in (500, 502):
                ehlo = await s.command(f"HELO {self.helo}")
                helo_fallback = True
            smtputf8 = (not helo_fallback) and any(x.split(" ")[0].upper() == "SMTPUTF8" for x in ehlo.text.split(" "))
            evidence.append(_ev("smtp_ehlo", host, ehlo, {"helo_fallback": helo_fallback, "smtputf8": smtputf8}))
            if ehlo.code != 250:
                return self._non_rcpt_failure("ehlo", ehlo, host)
            eai = not candidate.isascii()
            if eai and not smtputf8:
                return ProbeResult("inconclusive", "smtp.smtputf8_unsupported",
                                   "The address needs SMTPUTF8, which the server does not offer.", host)
            mail = await s.command(f"MAIL FROM:<{self.mail_from}>" + (" SMTPUTF8" if eai else ""))
            evidence.append(_ev("smtp_mail_from", host, mail))
            if mail.code != 250:
                return self._non_rcpt_failure("mail_from", mail, host)
            rcpt = await s.command(f"RCPT TO:<{candidate}>")
            evidence.append(_ev("smtp_rcpt_to", host, rcpt))
            status, code, throttling = classify_rcpt(rcpt)
            result = ProbeResult(status, code, _describe(status), host, rcpt, provider_throttling=throttling)
            if status == "rejected":
                result.accept_all = False  # the server rejects at least some recipients
            if status == "accepted" and accept_all_probe:
                probe_local = ACCEPT_ALL_PREFIX + secrets.token_hex(8)
                aa = await s.command(f"RCPT TO:<{probe_local}@{domain}>")
                aa_status, _, aa_throttle = classify_rcpt(aa)
                evidence.append(_ev("smtp_accept_all_probe", host, aa, {"result": aa_status}))
                result.accept_all = True if aa_status == "accepted" else (False if aa_status == "rejected" else None)
                result.provider_throttling = result.provider_throttling or aa_throttle
            return result
        except asyncio.TimeoutError:
            return ProbeResult("timeout", "smtp.command_timeout", "The server did not answer in time.", host)
        except (ConnectionError, OSError):
            return ProbeResult("temporary_failure", "smtp.connection_dropped", "The server closed the connection.", host)
        except ValueError:
            return ProbeResult("inconclusive", "smtp.protocol_error", "The server's reply was not valid SMTP.", host)

    @staticmethod
    def _non_rcpt_failure(stage: str, reply: Reply, host: str) -> ProbeResult:
        if 400 <= reply.code < 500:
            return ProbeResult("temporary_failure", f"smtp.{stage}.{reply.code}", f"Temporary failure at {stage}.", host, reply,
                               provider_throttling=reply.code == 421 or (reply.enhanced or "").startswith("4.7"))
        if 500 <= reply.code < 600:
            # A refusal before RCPT concerns the probe, not the mailbox.
            return ProbeResult("blocked", f"smtp.{stage}.{reply.enhanced or reply.code}", f"The server refused the probe at {stage}.",
                               host, reply, provider_throttling=True)
        return ProbeResult("inconclusive", f"smtp.{stage}.{reply.code}", f"Unexpected reply at {stage}.", host, reply)


def _describe(status: str) -> str:
    return {
        "accepted": "The server accepted the recipient at RCPT (evidence, not proof of a mailbox).",
        "rejected": "The server permanently rejected the recipient.",
        "temporary_failure": "The server deferred the recipient (4xx).",
        "blocked": "The server refused the probe by policy; this says nothing about the mailbox.",
        "inconclusive": "The server's answer does not establish the mailbox state.",
    }.get(status, status)


def _ev(kind: str, host: str, reply: Reply, extra: dict | None = None) -> Evidence:
    return Evidence(kind, {"text": reply.text[:_TEXT_LIMIT], **(extra or {})}, host, reply.code, reply.enhanced)
