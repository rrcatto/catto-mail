"""Phase 1 test client, run inside a throwaway container on smarthost-internal.

Commands (all print one JSON object):
  submit <subject> <from> <to>       authenticated submission on postfix:587 (STARTTLS)
  inbound <rcpt>                     plain SMTP to postfix:25 with a synthetic DSN body
  pg-wrong-password                  attempt PostgreSQL login with a wrong password
"""
from __future__ import annotations

import json
import os
import smtplib
import ssl
import sys
from email.message import EmailMessage


def out(**kw: object) -> None:
    print(json.dumps(kw), flush=True)


def submit(subject: str, sender: str, rcpt: str) -> None:
    msg = EmailMessage()
    msg["From"], msg["To"], msg["Subject"] = sender, rcpt, subject
    msg.set_content("Smarthost Phase 1 controlled development message.\n")
    ctx = ssl.create_default_context()
    ctx.check_hostname, ctx.verify_mode = False, ssl.CERT_NONE  # disposable self-signed dev cert
    try:
        with smtplib.SMTP("postfix", 587, timeout=30) as s:
            s.starttls(context=ctx)
            s.login(os.environ["SMARTHOST_SUBMISSION_USERNAME"], os.environ["SMARTHOST_SUBMISSION_PASSWORD"])
            s.send_message(msg)
            code, reply = s.noop()
        out(result="accepted", subject=subject)
    except smtplib.SMTPResponseException as exc:
        out(result="rejected", code=exc.smtp_code, reply=exc.smtp_error.decode(errors="replace"))
    except smtplib.SMTPRecipientsRefused as exc:
        (code, reply), = exc.recipients.values()
        out(result="rejected", code=code, reply=reply.decode(errors="replace"))


def inbound(rcpt: str) -> None:
    body = (
        "From: MAILER-DAEMON@remote.example\r\nTo: " + rcpt + "\r\n"
        "Subject: Undelivered Mail Returned to Sender (Phase 1 synthetic DSN)\r\n"
        "Content-Type: multipart/report; report-type=delivery-status; boundary=b1\r\n\r\n"
        "--b1\r\nContent-Type: text/plain\r\n\r\nsynthetic\r\n"
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


def pg_wrong_password() -> None:
    import psycopg
    try:
        psycopg.connect(host="postgres", dbname="smarthost", user="smarthost_app", password="definitely-wrong", connect_timeout=5)
        out(result="connected")
    except psycopg.OperationalError as exc:
        out(result="refused", error=str(exc).strip().splitlines()[-1])


if __name__ == "__main__":
    cmd, args = sys.argv[1], sys.argv[2:]
    {"submit": lambda: submit(*args), "inbound": lambda: inbound(*args), "pg-wrong-password": pg_wrong_password}[cmd]()
