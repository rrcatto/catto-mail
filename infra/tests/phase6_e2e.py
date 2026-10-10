"""Phase 6 end-to-end driver (runs in the verification tool image on the
smarthost-internal network; never part of a service image).

Against the running pod, through nginx -> PHP-FPM exactly as a mail client or a
browser would:

  T  a tracked subscription job goes /v1 -> Go -> Postfix -> OpenDKIM -> Mailpit;
     the pixel and the rewritten links are taken from the delivered HTML and
     requested through nginx: recorded open and click events, the redirect goes
     exactly to the stored target, open-redirect attempts fail, the unsubscribe
     link is not tracked, unknown tokens get the same pixel / 404;
  D  dashboard: passwordless sign-in (emailed link from Mailpit), client pages, message timeline with the
     recorded events, CSV export, tenant isolation (client B's user gets 404 for
     client A's pages), operator-only area (403 for a client user), operator
     pages, the unmatched-DSN workflow (match request recorded, no event made),
     suppression lifting with a note, the audit log, POST-only CSRF sign-out.

Sign-in is passwordless (specification 2.7): the driver submits the email form,
fetches the sign-in email from Mailpit (it travelled through Postfix submission
and OpenDKIM like any other mail) and opens the link.

Every check prints "PASS ..." or "FAIL ..."; the last line is a JSON summary.
Environment: E2E_KEY_A, E2E_CLIENT_A, E2E_CLIENT_B, E2E_USER_A, E2E_USER_B,
E2E_OPERATOR, E2E_ADMIN (APP_ADMIN_EMAIL), SMARTHOST_DB_*.
"""
from __future__ import annotations

import email
import email.policy
import html
import http.cookiejar
import json
import os
import re
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

import psycopg

BASE = "https://symfony-app"
HOST = "smarthost.localhost"
MAILPIT = "http://mailpit:8025"
SENDER = "news@smarthost-dev.test"
CTX = ssl.create_default_context()
CTX.check_hostname, CTX.verify_mode = False, ssl.CERT_NONE  # disposable development certificate
RESULTS = {"pass": 0, "fail": 0}


def check(ok: bool, text: str) -> bool:
    RESULTS["pass" if ok else "fail"] += 1
    print(("  PASS  " if ok else "  FAIL  ") + text, flush=True)
    return ok


def db() -> psycopg.Connection:
    return psycopg.connect(host="postgres", dbname=os.environ["SMARTHOST_DB_NAME"], user=os.environ["SMARTHOST_DB_OWNER_USER"],
                           password=os.environ["SMARTHOST_DB_OWNER_PASSWORD"], autocommit=True)


def one(sql: str, *args: object) -> object:
    with db() as c:
        row = c.execute(sql, args).fetchone()
        return None if row is None else row[0]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):  # type: ignore[no-untyped-def]
        return None


class Browser:
    """A cookie-keeping HTTP client that never follows redirects."""

    def __init__(self) -> None:
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=CTX), urllib.request.HTTPCookieProcessor(self.jar),
                                                  NoRedirect())

    def request(self, method: str, path: str, form: dict[str, str] | None = None, headers: dict[str, str] | None = None
                ) -> tuple[int, dict[str, str], bytes]:
        data = None if form is None else urllib.parse.urlencode(form).encode()
        h = {"Host": HOST, **(headers or {})}
        if data is not None:
            h["Content-Type"] = "application/x-www-form-urlencoded"
        req = urllib.request.Request(BASE + path, data=data, method=method, headers=h)
        try:
            with self.opener.open(req, timeout=60) as r:
                return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read()
        except urllib.error.HTTPError as e:
            return e.code, {k.lower(): v for k, v in e.headers.items()}, e.read()

    def get(self, path: str, **kw: object) -> tuple[int, dict[str, str], bytes]:
        return self.request("GET", path, **kw)  # type: ignore[arg-type]

    def login(self, email_: str) -> bool:
        """Requests a sign-in link, takes it from Mailpit and opens it."""
        link = request_link(self, email_)
        if link is None:
            return False
        status, headers, _ = self.get(link)
        return status == 302 and "/dashboard/login" not in headers.get("location", "")


def mailpit_messages(query: str) -> list[dict]:
    q = urllib.parse.quote(query)
    with urllib.request.urlopen(f"{MAILPIT}/api/v1/search?query={q}&limit=20", timeout=20) as r:
        return json.load(r).get("messages", [])


def request_link(b: Browser, email_: str) -> str | None:
    """Submits the sign-in form and returns the path of the emailed link (None if no email arrives)."""
    seen = {m["ID"] for m in mailpit_messages(f'to:"{email_}" subject:"sign-in link"')}
    _, _, body = b.get("/dashboard/login")
    status, headers, _ = b.request("POST", "/dashboard/login", {"email": email_, "_csrf_token": field(body, "_csrf_token")})
    if status != 302 or not headers.get("location", "").endswith("/dashboard/login/sent"):
        return None
    for _ in range(30):
        new = [m for m in mailpit_messages(f'to:"{email_}" subject:"sign-in link"') if m["ID"] not in seen]
        if new:
            with urllib.request.urlopen(f"{MAILPIT}/api/v1/message/{new[0]['ID']}", timeout=20) as r:
                msg = json.load(r)
            m = re.search(r"https://[^\s\"<>]+/dashboard/login/verify\?token=[A-Za-z0-9_-]{43}", msg.get("Text", ""))
            return urllib.parse.urlsplit(m.group(0)).path + "?" + urllib.parse.urlsplit(m.group(0)).query if m else None
        time.sleep(1)
    return None


def field(body: bytes, name: str, action: str | None = None) -> str:
    text = body.decode()
    if action is not None:
        m = re.search(r'<form[^>]*action="' + re.escape(action) + r'".*?</form>', text, re.S)
        text = m.group(0) if m else ""
    m = re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', text)
    return html.unescape(m.group(1)) if m else ""


def api(key: str, method: str, path: str, body: object | None = None) -> tuple[int, dict]:
    data = None if body is None else json.dumps(body).encode()
    headers = {"Authorization": "Bearer " + key, "Host": HOST}
    if data is not None:
        headers["Content-Type"] = "application/json"
        headers["Idempotency-Key"] = str(uuid.uuid4())
    req = urllib.request.Request(BASE + "/v1" + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, context=CTX, timeout=120) as r:
            return r.status, json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"{}")


def mailpit_html(tag: str) -> str:
    q = urllib.parse.quote(f'subject:"{tag}"')
    for _ in range(60):
        with urllib.request.urlopen(f"{MAILPIT}/api/v1/search?query={q}&limit=5", timeout=20) as r:
            msgs = json.load(r).get("messages", [])
        if msgs:
            with urllib.request.urlopen(f"{MAILPIT}/api/v1/message/{msgs[0]['ID']}/raw", timeout=20) as r:
                m = email.message_from_bytes(r.read(), policy=email.policy.default)
            part = m.get_body(preferencelist=("html",))
            return part.get_content() if part is not None else ""
        time.sleep(1)
    return ""


def local(url: str) -> str:
    """The path of a tracking URL written with SMARTHOST_PUBLIC_BASE_URL."""
    return urllib.parse.urlsplit(url).path


# ---------------------------------------------------------------------------
def tracking(key: str) -> dict[str, str]:
    print("== T tracking through nginx (real delivered message)")
    tag = "p6e2e-" + uuid.uuid4().hex[:8]
    unsub = f"https://app.example/unsubscribe/{tag}"
    target = f"https://shop.example/offer?campaign={tag}&x=1"
    body_html = (f'<html><body><p>Hello</p><a href="{html.escape(target)}">offer</a> '
                 f'<a href="{unsub}">unsubscribe</a></body></html>')
    code, job = api(key, "POST", "/send-jobs", {"external_reference": tag, "message_class": "subscription", "list_id": f"Phase6 <{tag}.example>",
                                                "sender_identity": {"email": SENDER}, "tracking": {"opens": True, "clicks": True}})
    check(code == 201, f"tracked subscription job created ({code})")
    rid = job["id"]
    code, _ = api(key, "POST", f"/send-jobs/{rid}/recipients", {"recipients": [{
        "external_recipient_reference": "reader-1", "email_address": f"Reader.{tag}@Example.COM", "subject": f"{tag} news",
        "html_body": body_html, "text_body": f"Plain {target}", "unsubscribe_url": unsub}]})
    check(code == 201, "recipient batch accepted")
    check(api(key, "POST", f"/send-jobs/{rid}/submit")[0] == 202, "job submitted")
    delivered = mailpit_html(tag)
    check(bool(delivered), "message delivered to Mailpit")
    mid = one("SELECT id::text FROM messages WHERE send_job_id = %s", rid)
    token = one("SELECT tracking_token FROM messages WHERE id = %s", mid)
    pixel = re.search(r'src="([^"]*/t/o/[^"]+\.gif)"', delivered)
    links = re.findall(r'href="([^"]*/t/c/[^"]+)"', delivered)
    check(pixel is not None and token in pixel.group(1), "delivered HTML carries the pixel with the message's token")
    check(len(links) == 1 and unsub in delivered, "one tracked link; the unsubscribe link is not rewritten")
    check("@" not in (pixel.group(1) if pixel else "@") and all("@" not in u for u in links), "no email address in tracking URLs")
    for _ in range(60):
        if one("SELECT postfix_queue_id IS NOT NULL FROM messages WHERE id = %s", mid):
            break
        time.sleep(1)

    b = Browser()
    s, h, body = b.get(local(pixel.group(1)) if pixel else "/t/o/x.gif")
    check(s == 200 and h.get("content-type") == "image/gif" and len(body) == 43, "pixel served (200 image/gif, 43 bytes)")
    check("no-store" in h.get("cache-control", "") and "set-cookie" not in h, "pixel not cacheable, no cookie")
    check(one("SELECT count(*) FROM message_events WHERE message_id = %s AND event_type = 'open_recorded' AND event_source = 'tracking_endpoint'", mid) == 1,
          "one open_recorded event")
    b.get(local(pixel.group(1)) if pixel else "/")
    check(one("SELECT count(*) FROM message_events WHERE message_id = %s AND event_type = 'open_recorded'", mid) == 1,
          "an immediate repeat is answered but not recorded")
    s2, h2, body2 = b.get("/t/o/" + "A" * 43 + ".gif")
    check(s2 == 200 and body2 == body, "unknown token gets the identical pixel")

    click = local(links[0]) if links else "/t/c/x/1"
    s, h, _ = b.get(click)
    check(s == 302 and h.get("location") == target, f"click redirects exactly to the stored target ({h.get('location')})")
    check(one("SELECT count(*) FROM message_events WHERE message_id = %s AND event_type = 'click_recorded' AND metadata_json->>'link_index' = '1'", mid) == 1,
          "one click_recorded event with link_index 1")
    evil = "https://evil.example/phish"
    s, h, _ = b.get(click + "?url=" + urllib.parse.quote(evil), headers={"Referer": evil})
    check(s == 302 and h.get("location") == target, "query string and Referer cannot choose the target")
    for path in (click.rsplit("/", 1)[0] + "/2", click.rsplit("/", 1)[0] + "/0", "/t/c/" + "B" * 43 + "/1", click + "/" + urllib.parse.quote(evil, safe=""),
                 "/t/c/" + urllib.parse.quote(evil, safe="") + "/1"):
        s, h, _ = b.get(path)
        check(s == 404 and "location" not in h, f"{path[:60]}… -> 404 without redirect")
    check(one("SELECT count(*) FROM message_links WHERE message_id = %s AND target_url LIKE %s", mid, "%unsubscribe%") == 0,
          "no link mapping for the unsubscribe URL")
    return {"tag": tag, "job": rid, "message": mid, "token": token or "", "target": target}


def dashboard(t: dict[str, str]) -> None:
    print("== D dashboards through nginx")
    ca, cb = os.environ["E2E_CLIENT_A"], os.environ["E2E_CLIENT_B"]
    anon = Browser()
    s, h, _ = anon.get(f"/dashboard/c/{ca}")
    check(s == 302 and h.get("location", "").endswith("/dashboard/login"), "anonymous -> login")
    check(request_link(Browser(), f"nobody.{uuid.uuid4().hex[:8]}@example.org") is None, "an unknown address gets the same answer and no email")
    a = Browser()
    check(a.login(os.environ["E2E_USER_A"]), "client A user signs in with an emailed link (Postfix -> Mailpit)")
    raw = mailpit_messages(f'to:"{os.environ["E2E_USER_A"]}" subject:"sign-in link"')
    if raw:
        with urllib.request.urlopen(f"{MAILPIT}/api/v1/message/{raw[0]['ID']}/raw", timeout=20) as r:
            check(b"DKIM-Signature:" in r.read(), "the sign-in email is DKIM-signed by OpenDKIM")
    s, h, body = a.get(f"/dashboard/c/{ca}")
    check(s == 200 and b"Remote accepted" in body and b"does not prove that a person read" in body, "client overview with caveat text")
    csp = h.get("content-security-policy", "")
    check("nonce-" in csp and "frame-ancestors 'none'" in csp and h.get("x-frame-options") == "DENY", "dashboard security headers")
    for path in ("/validation-jobs", "/send-jobs", "/suppressions", "/sending-domains", "/usage", f"/send-jobs/{t['job']}"):
        s, _, body = a.get(f"/dashboard/c/{ca}{path}")
        check(s == 200, f"client page {path}")
    s, _, body = a.get(f"/dashboard/c/{ca}/messages/{t['message']}")
    text = body.decode()
    check(s == 200 and "Recorded open" in text and "Recorded click" in text and "Remote accepted" in text, "message timeline shows the recorded events")
    check(t["token"] not in text and not re.search(r"\b(Delivered|Read|Opened)\b", re.sub(r"<[^>]+>", " ", text)),
          "no token, no delivery/read claims on the timeline")
    s, _, body = a.get(f"/dashboard/c/{ca}/send-jobs/{t['job']}")
    check(t["target"] in html.unescape(body.decode()), "clicks per stored link on the job page")
    code, vjob = api(os.environ["E2E_KEY_A"], "POST", "/validation-jobs", {"addresses": [{"address": "first@example.org"}, {"address": "=cmd@example.org"}]})
    check(code == 202, "validation job accepted through /v1 (202)")
    s, _, body = a.get(f"/dashboard/c/{ca}/validation-jobs/{vjob.get('id')}")
    check(s == 200 and b"first@example.org" in body, "validation job detail lists its addresses")
    s, h, body = a.get(f"/dashboard/c/{ca}/validation-jobs/{vjob.get('id')}/results.csv")
    check(s == 200 and h.get("content-type", "").startswith("text/csv") and body.startswith(b"id,external_address_reference,original_address")
          and b"'=cmd@example.org" in body, "validation CSV export (API fields, formula cell neutralised)")
    s, _, _ = a.get("/dashboard/operator")
    check(s == 403, "client user gets 403 for the operator area")
    s, _, _ = a.get(f"/dashboard/c/{cb}")
    check(s == 404, "client A user gets 404 for client B")

    bb = Browser()
    check(bb.login(os.environ["E2E_USER_B"]), "client B user signs in")
    for path in ("", "/send-jobs", f"/send-jobs/{t['job']}", f"/messages/{t['message']}", "/sending-domains", "/usage", "/suppressions"):
        s, _, body = bb.get(f"/dashboard/c/{ca}{path}")
        check(s == 404 and t["tag"].encode() not in body, f"client B user: client A {path or 'overview'} -> 404")
    s, _, body = bb.get(f"/dashboard/c/{cb}/messages/{t['message']}")
    check(s == 404, "client A's message under client B's id -> 404")

    used = Browser()
    link = request_link(used, os.environ["E2E_USER_B"])
    check(link is not None and used.get(link)[0] == 302 and Browser().get(link)[1].get("location", "").endswith("/dashboard/login"),
          "a sign-in link works once")
    op = Browser()
    check(op.login(os.environ["E2E_OPERATOR"]), "operator signs in")
    s, _, _ = op.get("/dashboard/operator/roles")
    check(s == 403, "an OPERATOR cannot open roles & permissions (ADMIN only)")
    s, _, _ = op.get("/dashboard/operator/users")
    check(s == 200, "an OPERATOR manages users")
    adm = Browser()
    check(adm.login(os.environ["E2E_ADMIN"]), "the APP_ADMIN_EMAIL address signs in (account created on first use)")
    s, _, body = adm.get("/dashboard/operator/roles")
    check(s == 200 and b"ADMIN" in body, "the administrator holds ADMIN and sees roles & permissions")
    for path in ("", "/clients", "/unmatched-dsns", "/suppressions", "/audit", "/webhooks", f"/clients/{ca}"):
        s, _, body = op.get(f"/dashboard/operator{path}")
        check(s == 200, f"operator page /dashboard/operator{path}")
    s, _, body = op.get("/dashboard/operator")
    check(b"Workers and queues" in body and b"Postfix queue" in body and b'data-chart-kind-value="trend"' in body,
          "operator overview shows worker health from the database and its KPI charts")

    # Unmatched DSN workflow: the dashboard records the request; no event is made here.
    dsn = str(uuid.uuid4())
    with db() as c:
        c.execute("""INSERT INTO unmatched_dsns (id, received_at, spool_ingest_key, content_sha256, classification, final_recipient, raw_message, detail_json)
                     VALUES (%s, now(), %s, repeat('e', 64), 'hard_bounce', 'nobody@example.org', 'From: MAILER-DAEMON', %s)""",
                  (dsn, "p6e2e-" + dsn, json.dumps({"candidates": [{"message_id": t["message"], "sender_matches_returned_from": True}]})))
    page = f"/dashboard/operator/unmatched-dsns/{dsn}"
    s, _, body = op.get(page)
    check(s == 200 and t["message"].encode() in body, "unmatched DSN detail lists the candidate")
    events = one("SELECT count(*) FROM message_events WHERE message_id = %s AND event_source = 'unmatched_dsn_resolution'", t["message"])
    s, _, _ = op.request("POST", page + "/dismiss", {"reason": "end-to-end dismissal", "_token": field(body, "_token", page + "/dismiss")})
    check(s == 302 and one("SELECT status FROM unmatched_dsns WHERE id = %s", dsn) == "dismissed", "dismissal with a written reason")
    check(one("SELECT count(*) FROM message_events WHERE message_id = %s AND event_source = 'unmatched_dsn_resolution'", t["message"]) == events,
          "the dashboard created no transport event")
    check(one("SELECT count(*) FROM audit_log WHERE action = 'unmatched_dsn.dismissed' AND target_id = %s", dsn) == 1, "dismissal audited")

    # Operator block, then lift with a note (the row is kept).
    addr = f"blocked.{uuid.uuid4().hex[:8]}@example.org"
    s, _, body = op.get("/dashboard/operator/suppressions")
    op.request("POST", "/dashboard/operator/suppressions", {"value": addr, "scope_type": "address", "reason": "operator_block", "client_id": "",
                                                             "expires_in_days": "", "note": "end-to-end block",
                                                             "_token": field(body, "_token", "/dashboard/operator/suppressions")})
    sid = one("SELECT id::text FROM suppressions WHERE address_or_domain = %s", addr)
    check(sid is not None, "operator block created from the dashboard")
    s, _, body = op.get("/dashboard/operator/suppressions?address=" + urllib.parse.quote(addr))
    action = f"/dashboard/operator/suppressions/{sid}/lift"
    op.request("POST", action, {"note": "end-to-end lift", "_token": field(body, "_token", action)})
    check(one("SELECT lifted_at IS NOT NULL FROM suppressions WHERE id = %s", sid) is True, "suppression lifted with a note, row kept")
    s, _, body = op.get("/dashboard/operator/audit?action=suppression.operator_lifted")
    check(s == 200 and sid.encode() in body, "audit log shows the lift")

    # Sign-out is a POST with a CSRF token; GET does nothing.
    s, _, _ = a.get("/dashboard/logout")
    check(s in (403, 405) and a.get(f"/dashboard/c/{ca}")[0] == 200, "GET /dashboard/logout does not sign out")
    _, _, body = a.get(f"/dashboard/c/{ca}")
    s, h, _ = a.request("POST", "/dashboard/logout", {"_csrf_token": field(body, "_csrf_token")})
    check(s == 302 and a.get(f"/dashboard/c/{ca}")[0] == 302, "POST sign-out with CSRF ends the session")


def main() -> None:
    t = tracking(os.environ["E2E_KEY_A"])
    dashboard(t)
    print(json.dumps({"token": t["token"], **RESULTS}))


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "sql":
        with db() as conn:
            print(json.dumps({"rows": conn.execute(sys.argv[2]).fetchall()}, default=str))
    else:
        main()
