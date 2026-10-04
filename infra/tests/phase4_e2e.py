"""Phase 4 end-to-end driver (runs in the verification tool image on the
smarthost-internal network; never part of a service image).

Creates send jobs through the real /v1 API (nginx -> PHP-FPM), waits for the Go
delivery daemon, and checks the outcome in Mailpit (headers, VERP envelope,
tracking, DKIM), in the Postfix log (no duplicate submission) and in PostgreSQL
(events, purge, usage, outbox, summary counts). Every command prints one JSON
line; "failures" lists what did not hold.

Environment: E2E_API_KEY, SMARTHOST_DB_OWNER_USER/PASSWORD, SMARTHOST_DB_NAME,
SMARTHOST_BOUNCE_DOMAIN, SMARTHOST_PUBLIC_BASE_URL; the observability volume is
mounted read-only at /obs; the DKIM public record at /t/key.txt.
"""
from __future__ import annotations

import email
import email.policy
import gzip
import json
import os
import re
import ssl
import sys
import time
import urllib.parse
import urllib.request
import uuid

import psycopg

API = "https://symfony-app/v1"  # every pod alias reaches nginx on :443 (shared network namespace)
MAILPIT = "http://mailpit:8025"
SENDER = "news@smarthost-dev.test"
CTX = ssl.create_default_context()
CTX.check_hostname, CTX.verify_mode = False, ssl.CERT_NONE  # disposable development certificate


def out(**kw: object) -> None:
    print(json.dumps(kw, default=str))


def db() -> psycopg.Connection:
    return psycopg.connect(host="postgres", dbname=os.environ["SMARTHOST_DB_NAME"], user=os.environ["SMARTHOST_DB_OWNER_USER"],
                           password=os.environ["SMARTHOST_DB_OWNER_PASSWORD"], autocommit=True)


def api(method: str, path: str, body: object | None = None) -> dict:
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(API + path, data=data, method=method, headers={
        "Authorization": "Bearer " + os.environ["E2E_API_KEY"], "Content-Type": "application/json",
        "Idempotency-Key": str(uuid.uuid4()), "Host": "smarthost.localhost"})
    try:
        with urllib.request.urlopen(req, context=CTX, timeout=120) as r:
            return json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        raise SystemExit(f"API {method} {path}: {e.code} {e.read()[:500]!r}")


# ---------------------------------------------------------------------------
def create(spec: dict) -> str:
    """spec: {class, list_id?, reply_to?, tracking?, recipients: [...]} or generate: {n, domains, tag}."""
    body = {"external_reference": spec.get("ref", "phase4-e2e"), "message_class": spec.get("class", "transactional"),
            "sender_identity": {"email": SENDER, "name": "Smarthost E2E"}}
    if spec.get("class") == "subscription":
        body["list_id"] = "E2E Weekly <weekly.smarthost-dev.test>"
    if spec.get("reply_to"):
        body["reply_to"] = {"email": spec["reply_to"], "name": "E2E Desk"}
    if spec.get("tracking"):
        body["tracking"] = spec["tracking"]
    job = api("POST", "/send-jobs", body)["id"]
    recips = spec.get("recipients")
    if recips is None:
        g = spec["generate"]
        recips = [{"external_recipient_reference": f"r{i}", "email_address": f"{g['tag']}-{i:05d}@{g['domains'][i % len(g['domains'])]}",
                   "subject": f"{g['tag']} message {i}", "text_body": f"Hello {i}\n.\nline after a lone dot"}
                  for i in range(g["n"])]
    for i in range(0, len(recips), 500):
        api("POST", f"/send-jobs/{job}/recipients", {"recipients": recips[i:i + 500]})
    api("POST", f"/send-jobs/{job}/submit")
    return job


def cmd_create(spec_json: str) -> None:
    out(job=create(json.loads(spec_json)))


def cmd_basic_create() -> None:
    tag = "p4e2e-" + uuid.uuid4().hex[:8]
    html = ('<html><body><p>Hello</p><a href="https://shop.example/offer?a=1&amp;b=2">offer</a> '
            '<a href="mailto:help@smarthost-dev.test">mail us</a> <a href="UNSUB">unsubscribe</a></body></html>')
    sub = [{"external_recipient_reference": f"s{i}", "email_address": a, "subject": s,
            "html_body": html.replace("UNSUB", f"https://app.example/u/{tag}-{i}"),
            "text_body": f"Text part {i} https://shop.example/plain", "unsubscribe_url": f"https://app.example/u/{tag}-{i}"}
           for i, (a, s) in enumerate([("Reader.One@Example.COM", f"{tag} Grüße aus Köln"), ("two@example.com", f"{tag} second"),
                                        ("three@example.org", f"{tag} third")])]
    j1 = create({"class": "subscription", "reply_to": "desk@smarthost-dev.test", "tracking": {"opens": True, "clicks": True}, "recipients": sub})
    txn = [{"external_recipient_reference": f"t{i}", "email_address": f"txn{i}@example.net", "subject": f"{tag} transactional {i}",
            "text_body": f"Transactional {i}"} for i in range(2)]
    j2 = create({"class": "transactional", "recipients": txn})
    out(tag=tag, subscription_job=j1, transactional_job=j2)


def wait_job(job: str, status: str, timeout: float) -> str:
    deadline = time.time() + timeout
    with db() as c:
        while time.time() < deadline:
            st = c.execute("SELECT status FROM send_jobs WHERE id = %s", (job,)).fetchone()[0]
            if st == status:
                return st
            time.sleep(1)
        return st


def cmd_wait(job: str, status: str, timeout: str) -> None:
    st = wait_job(job, status, float(timeout))
    out(job=job, status=st, ok=st == status)


def cmd_wait_sql(sql: str, expected: str, timeout: str) -> None:
    deadline = time.time() + float(timeout)
    got = None
    with db() as c:
        while time.time() < deadline:
            got = str(c.execute(sql).fetchone()[0])
            if got == expected:
                break
            time.sleep(1)
    out(ok=got == expected, got=got, expected=expected)


def cmd_sql(sql: str) -> None:
    with db() as c:
        out(rows=c.execute(sql).fetchall())


# ---------------------------------------------------------------------------
def mailpit_raw(subject_token: str) -> list[bytes]:
    q = urllib.parse.quote(f'subject:"{subject_token}"')
    with urllib.request.urlopen(f"{MAILPIT}/api/v1/search?query={q}&limit=50", timeout=20) as r:
        msgs = json.load(r).get("messages", [])
    raws = []
    for m in msgs:
        with urllib.request.urlopen(f"{MAILPIT}/api/v1/message/{m['ID']}/raw", timeout=20) as r:
            raws.append(r.read())
    return raws


def dkim_ok(raw: bytes) -> bool:
    import dkim  # dkimpy

    record = "".join(part.split('"')[1] for part in open("/t/key.txt").read().split("\n") if '"' in part)
    return bool(dkim.verify(raw, dnsfunc=lambda name, timeout=5: record.encode()))


def check_messages(job: str, tag: str, failures: list[str], expect_subscription: bool) -> int:
    bounce = os.environ["SMARTHOST_BOUNCE_DOMAIN"]
    base = os.environ["SMARTHOST_PUBLIC_BASE_URL"]
    by_mid = {}
    for raw in mailpit_raw(tag):
        m = email.message_from_bytes(raw, policy=email.policy.default)
        by_mid[m["Message-ID"]] = (raw, m)
    checked = 0
    with db() as c:
        rows = c.execute("""SELECT m.id::text, m.recipient_address, m.verp_token, m.tracking_token, m.postfix_queue_id, m.current_status,
                                   r.subject IS NULL AND r.text_body IS NULL AND r.html_body IS NULL, r.content_purged_at IS NOT NULL,
                                   r.content_sha256, r.subject
                              FROM messages m JOIN send_job_recipients r ON r.id = m.send_job_recipient_id
                             WHERE m.send_job_id = %s ORDER BY m.id""", (job,)).fetchall()
        for mid, rcpt, verp, token, qid, status, nulled, purged, sha, _ in rows:
            found = by_mid.get(f"<{mid}@{bounce}>")
            if not found:
                failures.append(f"{mid}: not in Mailpit")
                continue
            raw, msg = found
            checked += 1
            rp = msg.get("Return-Path", "")
            if rp.strip("<>") != f"bounce+{verp}@{bounce}":
                failures.append(f"{mid}: Return-Path {rp!r} is not the VERP address")
            if msg["X-Smarthost-Message-ID"] != mid or rcpt not in msg["To"]:
                failures.append(f"{mid}: X-Smarthost-Message-ID/To")
            if not dkim_ok(raw):
                failures.append(f"{mid}: DKIM signature does not verify")
            if "d=smarthost-dev.test" not in str(msg.get("DKIM-Signature", "")).replace(" ", ""):
                failures.append(f"{mid}: no DKIM signature for smarthost-dev.test")
            if expect_subscription:
                if msg["List-Unsubscribe-Post"] != "List-Unsubscribe=One-Click" or not str(msg["List-Unsubscribe"]).startswith("<https://app.example/u/") \
                        or msg["List-Id"] != "E2E Weekly <weekly.smarthost-dev.test>" or "desk@smarthost-dev.test" not in str(msg["Reply-To"]):
                    failures.append(f"{mid}: subscription headers")
                html = msg.get_body(("html",)).get_content()
                text = msg.get_body(("plain",)).get_content()
                if f"{base}/t/o/{token}.gif" not in html or f"{base}/t/c/{token}/1" not in html or "shop.example/offer" in html \
                        or "mailto:help@smarthost-dev.test" not in html or "https://app.example/u/" not in html:
                    failures.append(f"{mid}: HTML instrumentation")
                if "https://shop.example/plain" not in text:
                    failures.append(f"{mid}: text part was modified")
                link = c.execute("SELECT target_url FROM message_links WHERE message_id = %s AND link_index = 1", (mid,)).fetchone()
                if not link or link[0] != "https://shop.example/offer?a=1&b=2":
                    failures.append(f"{mid}: message_links {link}")
            else:
                for h in ("List-Unsubscribe", "List-Unsubscribe-Post", "List-Id"):
                    if msg[h] is not None:
                        failures.append(f"{mid}: transactional message carries {h}")
            if not qid or status != "remote_accepted":
                failures.append(f"{mid}: queue id {qid!r} status {status}")
            if not (nulled and purged and re.fullmatch(r"[0-9a-f]{64}", sha)):
                failures.append(f"{mid}: content not purged (or hash lost)")
            evs = [e for (e,) in c.execute("SELECT event_type || '/' || event_source FROM message_events WHERE message_id = %s ORDER BY occurred_at, recorded_at", (mid,))]
            for need in ("message_created/delivery_daemon", "message_queued/delivery_daemon", "submitted_to_postfix/postfix_submission",
                         "postfix_queued/postfix_log", "remote_accepted/postfix_log"):
                if need not in evs:
                    failures.append(f"{mid}: missing event {need} ({evs})")
            n = c.execute("SELECT coalesce(sum(quantity), 0) FROM usage_records WHERE reference_id = %s AND usage_type = 'message_submitted'", (mid,)).fetchone()[0]
            if n != 1:
                failures.append(f"{mid}: message_submitted usage {n}")
        job_checks(c, job, failures)
    return checked


def job_checks(c: psycopg.Connection, job: str, failures: list[str]) -> None:
    st, counts, done = c.execute("SELECT status, summary_counts_json, completed_at IS NOT NULL FROM send_jobs WHERE id = %s", (job,)).fetchone()
    real = dict(c.execute("SELECT current_status, count(*) FROM messages WHERE send_job_id = %s GROUP BY 1", (job,)).fetchall())
    if {k: v for k, v in counts.items() if v} != real:
        failures.append(f"job {job}: summary_counts {counts} != {real}")
    outbox = c.execute("SELECT count(*) FROM webhook_events WHERE subject_id = %s AND event_type = 'send.completed'", (job,)).fetchone()[0]
    if st == "completed" and (outbox != 1 or not done):
        failures.append(f"job {job}: send.completed outbox rows {outbox}")


def cmd_basic_verify(tag: str, j1: str, j2: str) -> None:
    failures: list[str] = []
    n = check_messages(j1, tag, failures, True) + check_messages(j2, tag, failures, False)
    out(checked=n, failures=failures)


def cmd_verify_single(job: str, tag: str) -> None:
    failures: list[str] = []
    n = check_messages(job, tag, failures, False)
    out(checked=n, failures=failures)


# ---------------------------------------------------------------------------
def log_message_ids(bounce: str) -> dict[str, set[str]]:
    """Message-ID (Smarthost uuid) -> distinct Postfix queue ids, from every retained log generation."""
    seen: dict[str, set[str]] = {}
    pat = re.compile(r"\]: ([0-9A-Za-z]+): message-id=<([0-9a-f-]{36})@" + re.escape(bounce) + ">")
    for name in sorted(os.listdir("/obs/log")):
        path = os.path.join("/obs/log", name)
        opener = gzip.open if name.endswith(".gz") else open
        with opener(path, "rt", errors="replace") as fh:
            for line in fh:
                m = pat.search(line)
                if m:
                    seen.setdefault(m.group(2), set()).add(m.group(1))
    return seen


def cmd_no_duplicates(job: str) -> None:
    """Every message of the job reached Postfix exactly once (one cleanup record / queue id)."""
    seen = log_message_ids(os.environ["SMARTHOST_BOUNCE_DOMAIN"])
    failures: list[str] = []
    with db() as c:
        rows = c.execute("SELECT id::text, postfix_queue_id, current_status FROM messages WHERE send_job_id = %s", (job,)).fetchall()
        recips = c.execute("SELECT count(*), count(DISTINCT email_address) FROM send_job_recipients WHERE send_job_id = %s", (job,)).fetchone()
        usage = c.execute("SELECT coalesce(sum(u.quantity), 0) FROM usage_records u JOIN messages m ON m.id = u.reference_id WHERE m.send_job_id = %s", (job,)).fetchone()[0]
        per_msg = c.execute("""SELECT count(*) FROM (SELECT reference_id FROM usage_records u JOIN messages m ON m.id = u.reference_id
                                WHERE m.send_job_id = %s GROUP BY 1 HAVING sum(quantity) <> 1) x""", (job,)).fetchone()[0]
        purge_mismatch = c.execute("""SELECT count(*) FROM send_job_recipients r JOIN messages m ON m.send_job_recipient_id = r.id
             WHERE m.send_job_id = %s AND (r.content_purged_at IS NOT NULL) <> (m.postfix_queue_id IS NOT NULL)""", (job,)).fetchone()[0]
        job_checks(c, job, failures)
    dup = {m: q for m, q in seen.items() if len(q) > 1}
    by_id = {r[0]: r for r in rows}
    missing = [m for m in by_id if m not in seen]
    wrong_qid = [m for m, r in by_id.items() if m in seen and r[1] not in seen[m]]
    if dup:
        failures.append(f"{len(dup)} messages submitted more than once: {list(dup.items())[:3]}")
    if missing:
        failures.append(f"{len(missing)} messages never reached Postfix")
    if wrong_qid:
        failures.append(f"{len(wrong_qid)} messages recorded with a queue id the log does not show")
    if len(rows) != recips[0] or recips[0] != recips[1]:
        failures.append(f"messages {len(rows)} for {recips[0]} recipients")
    if usage != len(rows) or per_msg:
        failures.append(f"usage {usage} for {len(rows)} messages ({per_msg} metered != 1)")
    if purge_mismatch:
        failures.append(f"{purge_mismatch} purge/acceptance mismatches")
    statuses: dict[str, int] = {}
    for r in rows:
        statuses[r[2]] = statuses.get(r[2], 0) + 1
    out(messages=len(rows), recipients=recips[0], usage=usage, statuses=statuses, failures=failures)


def cmd_mailpit_count(token: str) -> None:
    out(count=len(mailpit_raw(token)))


if __name__ == "__main__":
    cmd, args = sys.argv[1], sys.argv[2:]
    {"create": cmd_create, "basic-create": cmd_basic_create, "basic-verify": cmd_basic_verify, "verify-single": cmd_verify_single,
     "wait": cmd_wait, "wait-sql": cmd_wait_sql, "sql": cmd_sql, "no-duplicates": cmd_no_duplicates,
     "mailpit-count": cmd_mailpit_count}[cmd](*args)
