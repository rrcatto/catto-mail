"""Phase 1 verification client.

Runs inside a throwaway container (image localhost/smarthost-testtools:dev) on
the smarthost-internal network. Every command prints one JSON object on stdout
and exits 0 when the observed behaviour matches the expectation the caller
asks about (the shell suite decides PASS/FAIL from the JSON).

Commands:
  submit <subject> <from> <to>          authenticated submission on postfix:587 (STARTTLS);
                                        reports the final reply incl. the Postfix queue id
  inbound <rcpt> <tag>                  plain SMTP to postfix:25 with a synthetic DSN body
  mailpit-find <subject>                look the message up through the Mailpit API
  dkim-verify <subject> <txt-file>      verify the DKIM signature of that Mailpit message using
                                        the public key record (no DNS; dnsfunc override)
  fake-smtp                             exercise the deterministic fake SMTP scenarios
  pg-roles                              connect as every Smarthost role and probe privileges
  inotify <dir> <seconds>               report inotify events in <dir> for <seconds>
"""
from __future__ import annotations

import json
import os
import smtplib
import socket
import ssl
import sys
import time
import urllib.parse
import urllib.request
from email.message import EmailMessage

MAILPIT = "http://mailpit:8025"


def out(**kw: object) -> None:
    print(json.dumps(kw), flush=True)


def submit(subject: str, sender: str, rcpt: str) -> None:
    msg = EmailMessage()
    msg["From"], msg["To"], msg["Subject"] = sender, rcpt, subject
    msg["Message-ID"] = f"<{subject}@phase1.test>"
    msg.set_content("Smarthost Phase 1 controlled development message.\n")
    ctx = ssl.create_default_context()
    ctx.check_hostname, ctx.verify_mode = False, ssl.CERT_NONE  # disposable self-signed dev cert
    stage = "connect"
    try:
        with smtplib.SMTP("postfix", 587, timeout=30) as s:
            stage = "starttls"
            s.starttls(context=ctx)
            stage = "auth"
            s.login(os.environ["SMARTHOST_SUBMISSION_USERNAME"], os.environ["SMARTHOST_SUBMISSION_PASSWORD"])
            stage = "mail"
            code, reply = s.mail(sender)
            if code >= 400:
                return out(result="rejected", stage=stage, code=code, reply=reply.decode(errors="replace"))
            stage = "rcpt"
            code, reply = s.rcpt(rcpt)
            if code >= 400:
                return out(result="rejected", stage=stage, code=code, reply=reply.decode(errors="replace"))
            stage = "data"
            code, reply = s.data(msg.as_bytes())
            text = reply.decode(errors="replace")
            if code >= 400:
                return out(result="rejected", stage=stage, code=code, reply=text)
            queue_id = text.rsplit(" ", 1)[-1] if "queued as" in text else None
            out(result="accepted", code=code, reply=text, queue_id=queue_id, subject=subject)
    except smtplib.SMTPResponseException as exc:
        out(result="rejected", stage=stage, code=exc.smtp_code, reply=exc.smtp_error.decode(errors="replace"))


def inbound(rcpt: str, tag: str) -> None:
    body = (
        f"From: MAILER-DAEMON@remote.example\r\nTo: {rcpt}\r\nMessage-ID: <{tag}@remote.example>\r\n"
        f"Subject: Undelivered Mail Returned to Sender ({tag})\r\n"
        "MIME-Version: 1.0\r\n"
        "Content-Type: multipart/report; report-type=delivery-status; boundary=b1\r\n\r\n"
        "--b1\r\nContent-Type: text/plain\r\n\r\nsynthetic Phase 1 DSN\r\n"
        "--b1\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; remote.example\r\n\r\n"
        "Final-Recipient: rfc822; someone@example.com\r\nAction: failed\r\nStatus: 5.1.1\r\n--b1--\r\n"
    )
    try:
        with smtplib.SMTP("postfix", 25, timeout=30) as s:
            s.ehlo("remote.example")
            s.sendmail("", [rcpt], body)
        out(result="accepted", rcpt=rcpt)
    except smtplib.SMTPRecipientsRefused as exc:
        (code, reply), = exc.recipients.values()
        out(result="rejected", code=code, reply=reply.decode(errors="replace"), rcpt=rcpt)


def mailpit_search(subject: str) -> list[dict]:
    q = urllib.parse.quote(f'subject:"{subject}"')
    with urllib.request.urlopen(f"{MAILPIT}/api/v1/search?query={q}", timeout=10) as r:
        return json.load(r).get("messages", [])


def mailpit_find(subject: str) -> None:
    for _ in range(30):
        msgs = mailpit_search(subject)
        if msgs:
            m = msgs[0]
            with urllib.request.urlopen(f"{MAILPIT}/api/v1/message/{m['ID']}/headers", timeout=10) as r:
                headers = json.load(r)
            return out(result="found", count=len(msgs), id=m["ID"], to=[t["Address"] for t in m["To"]],
                       dkim_signature=next((v[0] for k, v in headers.items() if k.lower() == "dkim-signature"), None),
                       received=headers.get("Received", []))
        time.sleep(1)
    out(result="not-found", subject=subject)


def dkim_verify(subject: str, txt_file: str) -> None:
    import dkim  # dkimpy

    # The .txt file is the public DNS record produced by opendkim-genkey.
    raw_record = open(txt_file).read()
    record = "".join(part.split('"')[1] for part in raw_record.split("\n") if '"' in part)
    msgs = mailpit_search(subject)
    if not msgs:
        return out(result="not-found")
    with urllib.request.urlopen(f"{MAILPIT}/api/v1/message/{msgs[0]['ID']}/raw", timeout=10) as r:
        raw = r.read()
    queried: list[str] = []

    def dnsfunc(name: bytes, timeout: int = 5) -> bytes:
        queried.append(name.decode() if isinstance(name, bytes) else name)
        return record.encode()

    import email

    ok = dkim.DKIM(raw).verify(dnsfunc=dnsfunc)
    header = email.message_from_bytes(raw).get("DKIM-Signature", "")
    tags = {}
    for part in "".join(header.split()).split(";"):
        if "=" in part:
            key, value = part.split("=", 1)
            tags[key] = value
    out(result="valid" if ok else "invalid", queried=queried, d=tags.get("d"), s=tags.get("s"), a=tags.get("a"),
        signed_headers=tags.get("h"))


def fake_smtp() -> None:
    port = int(os.environ.get("FAKE_SMTP_PORT", "2525"))
    results: dict[str, object] = {}
    for rcpt, expect in [("accept-1@x.test", 250), ("reject-550@x.test", 550), ("tempfail-450@x.test", 450),
                         ("tempfail-451@x.test", 451), ("unavailable-421@x.test", 421)]:
        with smtplib.SMTP("fake-smtp", port, timeout=10) as s:
            s.ehlo("probe.test")
            s.mail("probe@x.test")
            code, _ = s.rcpt(rcpt)
            results[rcpt] = {"code": code, "expected": expect, "ok": code == expect}
            if code == 421:
                continue
            try:
                s.quit()
            except smtplib.SMTPServerDisconnected:
                pass
    # DATA is accepted and discarded
    with smtplib.SMTP("fake-smtp", port, timeout=10) as s:
        s.ehlo("probe.test"); s.mail("probe@x.test"); s.rcpt("accept-2@x.test")
        code, _ = s.data(b"Subject: x\r\n\r\nbody\r\n")
        results["DATA"] = {"code": code, "expected": 250, "ok": code == 250}
    # timeout scenario: the server never answers RCPT
    try:
        with smtplib.SMTP("fake-smtp", port, timeout=3) as s:
            s.ehlo("probe.test"); s.mail("probe@x.test"); s.rcpt("timeout@x.test")
        results["timeout"] = {"ok": False, "detail": "server answered"}
    except (socket.timeout, TimeoutError, smtplib.SMTPServerDisconnected):
        results["timeout"] = {"ok": True, "detail": "no reply within 3 s"}
    out(result="ok" if all(v["ok"] for v in results.values()) else "mismatch", scenarios=results)


def pg_roles() -> None:
    import psycopg
    from psycopg import errors

    host, db = os.environ["SMARTHOST_DB_HOST"], os.environ["SMARTHOST_DB_NAME"]
    roles = {
        "owner": (os.environ["SMARTHOST_DB_OWNER_USER"], os.environ["SMARTHOST_DB_OWNER_PASSWORD"], True),
        "app": (os.environ["APP_DB_USER"], os.environ["APP_DB_PASSWORD"], False),
        "webhook": (os.environ["APP_WEBHOOK_DB_USER"], os.environ["APP_WEBHOOK_DB_PASSWORD"], False),
        "validator": (os.environ["VALIDATOR_DB_USER"], os.environ["VALIDATOR_DB_PASSWORD"], False),
        "delivery": (os.environ["DELIVERY_DB_USER"], os.environ["DELIVERY_DB_PASSWORD"], False),
    }
    report: dict[str, object] = {}
    for name, (user, password, may_ddl) in roles.items():
        with psycopg.connect(host=host, dbname=db, user=user, password=password, connect_timeout=5) as conn:
            attrs = conn.execute("SELECT rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = current_user").fetchone()
            can_ddl = True
            try:
                with conn.transaction():
                    conn.execute("CREATE TABLE phase1_ddl_probe (id int)")
                    raise RuntimeError("rollback")
            except errors.InsufficientPrivilege:
                can_ddl = False
            except RuntimeError:
                pass
            report[name] = {"user": user, "superuser": attrs[0], "createdb": attrs[1], "createrole": attrs[2],
                            "can_create_table": can_ddl, "expected_ddl": may_ddl,
                            "ok": can_ddl == may_ddl and not any(attrs)}
    try:
        psycopg.connect(host=host, dbname=db, user=roles["app"][0], password="definitely-wrong", connect_timeout=5)
        report["wrong_password"] = {"ok": False, "detail": "connected"}
    except psycopg.OperationalError as exc:
        report["wrong_password"] = {"ok": True, "detail": str(exc).strip().splitlines()[-1]}
    out(result="ok" if all(v["ok"] for v in report.values()) else "violation", roles=report)


def inotify(directory: str, seconds: str) -> None:
    from inotify_simple import INotify, flags

    ino = INotify()
    ino.add_watch(directory, flags.CREATE | flags.MOVED_TO | flags.MODIFY | flags.CLOSE_WRITE)
    events = []
    deadline = time.time() + float(seconds)
    while time.time() < deadline:
        for ev in ino.read(timeout=500):
            events.append({"name": ev.name, "flags": [f.name for f in flags.from_mask(ev.mask)]})
    out(result="watched", directory=directory, events=events)


if __name__ == "__main__":
    cmd, args = sys.argv[1], sys.argv[2:]
    {
        "submit": lambda: submit(*args), "inbound": lambda: inbound(*args),
        "mailpit-find": lambda: mailpit_find(*args), "dkim-verify": lambda: dkim_verify(*args),
        "fake-smtp": fake_smtp, "pg-roles": pg_roles, "inotify": lambda: inotify(*args),
    }[cmd]()
