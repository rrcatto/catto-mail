"""Deterministic fake SMTP server for Smarthost tests (stdlib only).

The response to RCPT TO is chosen solely by the recipient's local-part prefix,
so tests are reproducible without any real mailbox provider:

    reject-550...      550 5.1.1 mailbox does not exist
    tempfail-450...    450 4.2.1 mailbox temporarily unavailable
    tempfail-451...    451 4.3.0 local error in processing
    unavailable-421... 421 4.3.2 service not available, connection closed
    timeout...         no reply (the connection is held open until the client gives up)
    anything else      250 2.1.5 ok  (accept-all behaviour)

DATA is accepted and the content discarded: the server never stores, relays or
forwards mail. Connection refusal is simulated by addressing a closed port.
Phase 3/4 tests may extend the rule table; Phase 1 provides this foundation.
Run `python fake_smtp.py --check` as a readiness probe (expects a 220 greeting).
"""
from __future__ import annotations

import asyncio
import os
import socket
import sys

HOSTNAME = "fake-smtp.smarthost.test"
RULES: list[tuple[str, str | None, bool]] = [
    # (local-part prefix, reply or None for no reply, close connection after reply)
    ("reject-550", "550 5.1.1 fake-smtp: mailbox does not exist", False),
    ("tempfail-450", "450 4.2.1 fake-smtp: mailbox temporarily unavailable", False),
    ("tempfail-451", "451 4.3.0 fake-smtp: local error in processing", False),
    ("unavailable-421", "421 4.3.2 fake-smtp: service not available", True),
    ("timeout", None, False),
]


def rcpt_reply(address: str) -> tuple[str | None, bool]:
    local = address.strip().strip("<>").split("@", 1)[0].lower()
    for prefix, reply, close in RULES:
        if local.startswith(prefix):
            return reply, close
    return "250 2.1.5 fake-smtp: ok", False


async def session(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    def send(line: str) -> None:
        writer.write((line + "\r\n").encode())

    send(f"220 {HOSTNAME} ESMTP deterministic test server")
    try:
        while True:
            await writer.drain()
            raw = await reader.readline()
            if not raw:
                return
            line = raw.decode(errors="replace").rstrip("\r\n")
            verb = line.split(" ", 1)[0].upper()
            if verb == "EHLO":
                send(f"250-{HOSTNAME}")
                send("250 8BITMIME")
            elif verb == "HELO":
                send(f"250 {HOSTNAME}")
            elif verb in ("MAIL", "RSET", "NOOP"):
                send("250 2.0.0 fake-smtp: ok")
            elif verb == "RCPT":
                reply, close = rcpt_reply(line.split(":", 1)[-1])
                if reply is None:
                    await asyncio.sleep(3600)  # timeout scenario: never answer
                    return
                send(reply)
                if close:
                    await writer.drain()
                    return
            elif verb == "DATA":
                send("354 fake-smtp: send data, end with <CRLF>.<CRLF>")
                await writer.drain()
                while (chunk := await reader.readline()) and chunk not in (b".\r\n", b".\n"):
                    pass  # content discarded; never stored or relayed
                send("250 2.0.0 fake-smtp: accepted and discarded")
            elif verb == "QUIT":
                send("221 2.0.0 fake-smtp: bye")
                await writer.drain()
                return
            else:
                send("502 5.5.2 fake-smtp: command not implemented")
    except (ConnectionError, asyncio.IncompleteReadError):
        return
    finally:
        writer.close()


def check(port: int) -> int:
    with socket.create_connection(("127.0.0.1", port), timeout=3) as s:
        return 0 if s.recv(512).startswith(b"220 ") else 1


async def main(port: int) -> None:
    server = await asyncio.start_server(session, host="0.0.0.0", port=port)
    print(f'{{"service":"fake-smtp","msg":"listening","port":{port}}}', flush=True)
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    smtp_port = int(os.environ.get("FAKE_SMTP_PORT", "2525"))
    if "--check" in sys.argv:
        sys.exit(check(smtp_port))
    asyncio.run(main(smtp_port))
