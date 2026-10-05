"""Phase 5 end-to-end driver (runs in the verification tool image on the
smarthost-internal network; never part of a service image).

Sends real messages through /v1 -> Go -> Postfix -> Mailpit, then injects
synthetic inbound DSNs and ARF complaints over SMTP to Postfix port 25 (the
production path: Postfix virtual(8) -> DSN Maildir spool -> Go), and checks the
outcome in PostgreSQL. No message leaves the internal network. Every command
prints one JSON line.

Environment: E2E_API_KEY (default client), SMARTHOST_DB_OWNER_USER/PASSWORD,
SMARTHOST_DB_NAME, SMARTHOST_BOUNCE_DOMAIN; the DSN fixtures of
delivery/internal/dsn/testdata are mounted at /fixtures.
"""
from __future__ import annotations

import base64
import json
import os
import smtplib
import ssl
import sys
import time
import urllib.error
import urllib.request
import uuid

import psycopg

API = "https://symfony-app/v1"
SENDER = "news@smarthost-dev.test"
BOUNCE = os.environ.get("SMARTHOST_BOUNCE_DOMAIN", "")
CTX = ssl.create_default_context()
CTX.check_hostname, CTX.verify_mode = False, ssl.CERT_NONE  # disposable development certificate


def out(**kw: object) -> None:
    print(json.dumps(kw, default=str))


def db() -> psycopg.Connection:
    return psycopg.connect(host="postgres", dbname=os.environ["SMARTHOST_DB_NAME"], user=os.environ["SMARTHOST_DB_OWNER_USER"],
                           password=os.environ["SMARTHOST_DB_OWNER_PASSWORD"], autocommit=True)


def api(key: str, method: str, path: str, body: object | None = None, idem: str | None = None) -> tuple[int, dict]:
    data = None if body is None else json.dumps(body).encode()
    headers = {"Authorization": "Bearer " + key, "Host": "smarthost.localhost"}
    if data is not None:
        headers["Content-Type"] = "application/json"
        headers["Idempotency-Key"] = idem or str(uuid.uuid4())
    req = urllib.request.Request(API + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, context=CTX, timeout=120) as r:
            return r.status, json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"{}")


def must(code_body: tuple[int, dict], *ok: int) -> dict:
    code, body = code_body
    if code not in ok:
        raise SystemExit(f"API answered {code}: {json.dumps(body)[:500]}")
    return body


# ---------------------------------------------------------------------------
def cmd_send(key: str, *addresses: str) -> None:
    """One transactional job of the key's client; waits until no message is unresolved."""
    tag = "p5e2e-" + uuid.uuid4().hex[:8]
    job = must(api(key, "POST", "/send-jobs", {"external_reference": tag, "message_class": "transactional",
                                                "sender_identity": {"email": SENDER, "name": "Smarthost E2E"}}), 201)["id"]
    must(api(key, "POST", f"/send-jobs/{job}/recipients", {"recipients": [
        {"external_recipient_reference": f"r{i}", "email_address": a, "subject": f"{tag} {i}", "text_body": f"Phase 5 e2e {i}"}
        for i, a in enumerate(addresses)]}), 201)
    must(api(key, "POST", f"/send-jobs/{job}/submit"), 202)
    deadline = time.time() + 180
    with db() as c:
        while time.time() < deadline:
            st = c.execute("SELECT status FROM send_jobs WHERE id = %s", (job,)).fetchone()[0]
            if st == "completed":
                break
            time.sleep(1)
        rows = c.execute("""SELECT m.id::text, m.recipient_address, m.current_status, COALESCE(m.postfix_queue_id, '')
                              FROM messages m JOIN send_job_recipients r ON r.id = m.send_job_recipient_id
                             WHERE m.send_job_id = %s ORDER BY r.id""", (job,)).fetchall()
    out(job=job, status=st, tag=tag, messages=[{"id": r[0], "address": r[1], "status": r[2], "queue_id": r[3]} for r in rows])


def fixture_vars(c: psycopg.Connection, message_id: str, mode: str) -> tuple[dict, str]:
    """Placeholder values for a message, and the envelope recipient for the injection.
    mode: full (every identifier), verp, envid, msgid, qid, recipient (recipient + sender
    only), none (no identifier: unmatchable), fbl (sent to the base FBL address)."""
    row = c.execute("""SELECT m.return_path, m.id::text, COALESCE(m.postfix_queue_id, ''), m.recipient_address, j.sender_email
                         FROM messages m JOIN send_jobs j ON j.id = m.send_job_id WHERE m.id = %s""", (message_id,)).fetchone()
    rp, mid, qid, rcpt, sender = row
    v = {"VERP": rp, "VERP_SENDER": rp, "ENVID": mid, "MSGID": mid, "QID": qid, "RCPT": rcpt, "FROM": sender, "BOUNCE": BOUNCE,
         "OTHER": "other-" + uuid.uuid4().hex[:6] + "@example.org",
         "B64_STATUS": base64.b64encode(f"Reporting-MTA: dns; mx.example.org\n\nFinal-Recipient: rfc822; {rcpt}\nAction: failed\n"
                                        f"Status: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 unknown\n".encode()).decode()}
    envelope = rp
    if mode != "full":
        keep = {"verp": ["VERP"], "envid": ["ENVID"], "msgid": ["MSGID"], "qid": ["QID"], "recipient": ["FROM"],
                "none": [], "fbl": ["MSGID", "FROM"]}[mode]
        blank = {"VERP": "postmaster@" + BOUNCE, "VERP_SENDER": "bounce@" + BOUNCE, "ENVID": "none", "MSGID": "none",
                 "QID": "NOQUEUEID00", "FROM": "nobody@unrelated.example"}
        for k, x in blank.items():
            if k not in keep:
                v[k] = x
        envelope = rp if mode == "verp" else ("bounce@" + BOUNCE if mode == "fbl" else "postmaster@" + BOUNCE)
    return v, envelope


def cmd_inject(fixture: str, message_id: str, mode: str = "full") -> None:
    """Sends a rendered fixture over SMTP to Postfix :25, like a remote MTA or FBL."""
    with db() as c:
        v, envelope = fixture_vars(c, message_id, mode)
    raw = open(f"/fixtures/{fixture}", encoding="utf-8", errors="surrogateescape").read()
    for k, x in v.items():
        raw = raw.replace("{{" + k + "}}", x)
    data = raw.replace("\r\n", "\n").replace("\n", "\r\n").encode("utf-8", errors="surrogateescape")
    try:
        with smtplib.SMTP("postfix", 25, timeout=30) as s:
            s.ehlo("mx.remote.example")
            s.sendmail("", [envelope], data)
        out(result="accepted", envelope=envelope, fixture=fixture, mode=mode)
    except smtplib.SMTPException as exc:
        out(result="rejected", error=str(exc), envelope=envelope)


def cmd_optout(key: str, address: str, idem: str = "") -> None:
    code, body = api(key, "POST", "/global-suppressions", {"email_address": address, "external_reference": "e2e"}, idem or None)
    out(code=code, body=body)


def cmd_lift(key: str, suppression_id: str) -> None:
    code, body = api(key, "POST", f"/global-suppressions/{suppression_id}/lift")
    out(code=code, body=body)


def cmd_sql(sql: str) -> None:
    with db() as c:
        cur = c.execute(sql)
        out(rows=cur.fetchall() if cur.description else [])


def cmd_wait_sql(sql: str, expected: str, timeout: str) -> None:
    deadline = time.time() + float(timeout)
    got = None
    with db() as c:
        while time.time() < deadline:
            r = c.execute(sql).fetchone()
            got = None if r is None else str(r[0])
            if got == expected:
                break
            time.sleep(1)
    out(ok=got == expected, got=got, expected=expected)


if __name__ == "__main__":
    cmd, args = sys.argv[1], sys.argv[2:]
    {"send": cmd_send, "inject": cmd_inject, "optout": cmd_optout, "lift": cmd_lift, "sql": cmd_sql,
     "wait-sql": cmd_wait_sql}[cmd](*args)
