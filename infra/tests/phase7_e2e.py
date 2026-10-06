"""Phase 7 external-client driver (test fixture).

Plays the first client application against the running Smarthost: it uses ONLY
the public HTTPS API (an API key) and the signed webhooks its receiver
(tests/webhook-receiver) accepted. It has no Smarthost database access. Reading
Mailpit stands in for a recipient opening the delivered mail (tracking).

Every command prints one JSON line. Environment: CLIENT_API_KEY,
RECEIVER_URL (control API of the receiver), RECEIVER_ENDPOINT (endpoint name),
CLIENT_WEBHOOK_ENDPOINT_ID (the id the client was given when its endpoint was set up).
"""
from __future__ import annotations

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
from collections.abc import Callable

API = "https://symfony-app/v1"
HOST = "localhost"
MAILPIT = "http://mailpit:8025"
SENDER = "news@smarthost-dev.test"
CTX = ssl.create_default_context()
CTX.check_hostname, CTX.verify_mode = False, ssl.CERT_NONE  # disposable development certificate


def out(**kw: object) -> None:
    print(json.dumps(kw, default=str), flush=True)


def api(method: str, path: str, body: object | None = None, idem: str | None = None) -> tuple[int, dict]:
    """Authenticated API call; the key never appears in output or errors."""
    data = None if body is None else json.dumps(body).encode()
    headers = {"Authorization": "Bearer " + os.environ["CLIENT_API_KEY"], "Host": HOST}
    if data is not None:
        headers["Content-Type"] = "application/json"
        headers["Idempotency-Key"] = idem or str(uuid.uuid4())
    req = urllib.request.Request(API + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, context=CTX, timeout=120) as r:
            return r.status, json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"{}")


def must(result: tuple[int, dict], *ok: int) -> dict:
    code, body = result
    if code not in ok:
        raise SystemExit(f"API answered {code}: {json.dumps(body)[:400]}")
    return body


def receiver_state() -> dict:
    with urllib.request.urlopen(os.environ["RECEIVER_URL"] + "/_control/state", timeout=20) as r:
        return json.load(r)


def receiver_control(action: str, payload: dict) -> None:
    req = urllib.request.Request(os.environ["RECEIVER_URL"] + "/_control/" + action, data=json.dumps(payload).encode(), method="POST",
                                 headers={"Content-Type": "application/json"})
    urllib.request.urlopen(req, timeout=20).read()


def await_webhook(event_type: str, subject: str, timeout: float) -> dict | None:
    """Waits until the receiver accepted (verified, de-duplicated) an event of this type for this subject."""
    deadline = time.time() + timeout
    endpoint = os.environ["RECEIVER_ENDPOINT"]
    while time.time() < deadline:
        state = receiver_state()
        for eid, ev in state["events"].get(endpoint, {}).items():
            if ev["type"] == event_type and ev["subject"].endswith(":" + subject):
                return {"event_id": eid, **ev}
        time.sleep(1)
    return None


def pages(path: str, limit: int) -> list[dict]:
    """All items of a cursor-paginated collection."""
    items, cursor = [], None
    while True:
        q = f"?limit={limit}" + (f"&cursor={urllib.parse.quote(cursor)}" if cursor else "")
        body = must(api("GET", path + q), 200)
        items += body["data"]
        cursor = body["pagination"]["next_cursor"]
        if not cursor:
            return items


# ---------------------------------------------------------------------------
def cmd_validate(tag: str) -> None:
    """Validation integration: submit, receive validation.completed, page the results, map external references."""
    addresses = [("subscriber-17", f"reader.{tag}@example.com"), ("subscriber-18", f"typo.{tag}@gmial.com"), ("subscriber-19", "not an address")]
    job = must(api("POST", "/validation-jobs", {"external_reference": f"import-{tag}",
                                                 "addresses": [{"address": a, "external_address_reference": r} for r, a in addresses]}), 202)
    hook = await_webhook("validation.completed", job["id"], 300)
    final = must(api("GET", f"/validation-jobs/{job['id']}"), 200)
    results = pages(f"/validation-jobs/{job['id']}/addresses", 2)
    mapping = {r["external_address_reference"]: {"classification": r["overall_classification"], "suggested": r["suggested_address"]} for r in results}
    out(job_id=job["id"], webhook=hook is not None, status=final["status"], results=len(results), mapping=mapping,
        refs_preserved=sorted(mapping) == ["subscriber-17", "subscriber-18", "subscriber-19"])


def recipients(tag: str, n: int, start: int = 0) -> list[dict]:
    html = '<html><body><p>Hello {i}</p><a href="https://shop.example/offer?c={tag}">offer</a> <a href="{unsub}">unsubscribe</a></body></html>'
    out_ = []
    for i in range(start, start + n):
        unsub = f"https://client.example/unsubscribe/{tag}/{i}"
        out_.append({"external_recipient_reference": f"member-{i}", "email_address": f"member{i}.{tag}@example.org", "subject": f"{tag} issue {i}",
                     "html_body": html.format(i=i, tag=tag, unsub=unsub), "text_body": f"Hello {i}", "unsubscribe_url": unsub})
    return out_


def cmd_send(tag: str, count: str = "501") -> None:
    """Rendered batched send: create, batches of at most 500 (one replayed), submit, receive send.completed, reconcile."""
    n = int(count)
    job = must(api("POST", "/send-jobs", {"external_reference": f"campaign-{tag}", "message_class": "subscription",
                                          "list_id": f"Newsletter <newsletter.{tag}.client.example>", "sender_identity": {"email": SENDER, "name": "Client"},
                                          "tracking": {"opens": True, "clicks": True}}), 201)
    keys = []
    for start in range(0, n, 500):
        key = str(uuid.uuid4())
        keys.append(key)
        must(api("POST", f"/send-jobs/{job['id']}/recipients", {"recipients": recipients(tag, min(500, n - start), start)}, key), 201)
    # A network retry of the first batch with the same Idempotency-Key never duplicates recipients.
    code, replay = api("POST", f"/send-jobs/{job['id']}/recipients", {"recipients": recipients(tag, min(500, n), 0)}, keys[0])
    must(api("POST", f"/send-jobs/{job['id']}/submit"), 202)
    hook = await_webhook("send.completed", job["id"], 600)
    final = must(api("GET", f"/send-jobs/{job['id']}"), 200)
    messages = pages(f"/send-jobs/{job['id']}/messages", 200)
    refs = {m["external_recipient_reference"]: m["id"] for m in messages}
    out(job_id=job["id"], batches=len(keys), replay_status=code, total_recipients=final["total_recipients"], status=final["status"],
        webhook=hook is not None, messages=len(messages), refs_complete=len(refs) == n, first_message=refs.get("member-0"),
        second_message=refs.get("member-1"), summary=final["summary_counts"])


def cmd_await(event_type: str, subject: str, timeout: str = "120") -> None:
    hook = await_webhook(event_type, subject, float(timeout))
    out(ok=hook is not None, hook=hook)


def cmd_await_id(event_id: str, timeout: str = "180") -> None:
    deadline = time.time() + float(timeout)
    while time.time() < deadline:
        if event_id in receiver_state()["events"].get(os.environ["RECEIVER_ENDPOINT"], {}):
            out(ok=True)
            return
        time.sleep(1)
    out(ok=False)


def cmd_received(event_id: str, timeout: str = "60") -> None:
    """Waits until the receiver has seen at least one request (verified or not) for the event."""
    deadline = time.time() + float(timeout)
    while time.time() < deadline:
        if any(r["event_id"] == event_id for r in receiver_state()["receipts"]):
            out(ok=True)
            return
        time.sleep(0.5)
    out(ok=False)


def cmd_message(message_id: str) -> None:
    """Polling fallback: current message state and events through the API."""
    events = must(api("GET", f"/messages/{message_id}/events?limit=100"), 200)["data"]
    out(events=[e["event_type"] for e in events])


def cmd_track(tag: str, message_id: str) -> None:
    """The recipient opens the delivered mail (Mailpit) and follows its tracked link; the client sees the events via the API."""
    q = urllib.parse.quote(f'subject:"{tag} issue 0"')
    with urllib.request.urlopen(f"{MAILPIT}/api/v1/search?query={q}&limit=1", timeout=20) as r:
        mid = json.load(r)["messages"][0]["ID"]
    with urllib.request.urlopen(f"{MAILPIT}/api/v1/message/{mid}", timeout=20) as r:
        html = json.load(r)["HTML"]
    pixel = re.search(r'src="https://[^/"]+(/t/o/[^"]+\.gif)"', html)
    click = re.search(r'href="https://[^/"]+(/t/c/[^"]+)"', html)
    unsub_tracked = f'href="https://client.example/unsubscribe/{tag}/0"' not in html
    for path in (pixel.group(1) if pixel else None, click.group(1) if click else None):
        if path:
            req = urllib.request.Request("https://symfony-app" + path, headers={"Host": HOST})
            try:
                urllib.request.build_opener(urllib.request.HTTPSHandler(context=CTX), NoRedirect()).open(req, timeout=20).read()
            except urllib.error.HTTPError:
                pass
    time.sleep(1)
    events = must(api("GET", f"/messages/{message_id}/events?limit=100"), 200)["data"]
    types = [e["event_type"] for e in events]
    out(pixel=pixel is not None, click=click is not None, unsubscribe_tracked=unsub_tracked,
        open_recorded="open_recorded" in types, click_recorded="click_recorded" in types)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):  # type: ignore[no-untyped-def]
        return None


def cmd_test_webhook() -> None:
    body = must(api("POST", "/webhooks/test", {"webhook_endpoint_id": os.environ["CLIENT_WEBHOOK_ENDPOINT_ID"]}), 202)
    out(event_id=body["webhook_event_id"])


def cmd_test_webhook_without_id() -> None:
    """The endpoint id is required: a request without one is rejected and records nothing."""
    code, body = api("POST", "/webhooks/test", {})
    out(code=code, type=body.get("type"))


def cmd_reconcile(job_id: str, timeout: str = "300") -> None:
    """Without the send.completed webhook: poll the send job until it is final."""
    deadline = time.time() + float(timeout)
    status = None
    while time.time() < deadline:
        status = must(api("GET", f"/send-jobs/{job_id}"), 200)["status"]
        if status in ("completed", "failed", "cancelled"):
            break
        time.sleep(2)
    out(status=status)


def cmd_send_small(tag: str) -> None:
    """A small transactional job, returned without waiting (for the lost-webhook scenario)."""
    job = must(api("POST", "/send-jobs", {"external_reference": f"receipt-{tag}", "message_class": "transactional",
                                          "sender_identity": {"email": SENDER}}), 201)
    recipient = {"external_recipient_reference": "order-1", "email_address": f"buyer.{tag}@example.org",
                 "subject": f"{tag} receipt", "text_body": "Thanks"}
    must(api("POST", f"/send-jobs/{job['id']}/recipients", {"recipients": [recipient]}), 201)
    must(api("POST", f"/send-jobs/{job['id']}/submit"), 202)
    out(job_id=job["id"])


def cmd_opt_out(address: str) -> None:
    """An explicit recipient-wide do-not-contact request (D-30), not an ordinary unsubscribe."""
    code, body = api("POST", "/global-suppressions", {"email_address": address, "external_reference": "dnc-request-1"})
    out(code=code, id=body.get("id"))


def cmd_state() -> None:
    s = receiver_state()
    endpoint = os.environ["RECEIVER_ENDPOINT"]
    out(receipts=len([r for r in s["receipts"] if r["endpoint"] == endpoint]), rejected=len(s["rejected"]),
        events=len(s["events"].get(endpoint, {})), effects=len([e for e in s["effects"] if e["endpoint"] == endpoint]),
        by_event={eid: len([r for r in s["receipts"] if r["event_id"] == eid]) for eid in s["events"].get(endpoint, {})},
        effects_by_event={eid: len([e for e in s["effects"] if e["event_id"] == eid]) for eid in s["events"].get(endpoint, {})})


def cmd_control(action: str, payload: str) -> None:
    receiver_control(action, json.loads(payload))
    out(ok=True)


if __name__ == "__main__":
    command, *args = sys.argv[1:]
    commands: dict[str, Callable[..., None]] = {"validate": cmd_validate, "send": cmd_send, "await": cmd_await, "message": cmd_message, "track": cmd_track,
     "test-webhook": cmd_test_webhook, "test-webhook-without-id": cmd_test_webhook_without_id, "await-id": cmd_await_id,
     "received": cmd_received, "reconcile": cmd_reconcile,
     "send-small": cmd_send_small, "opt-out": cmd_opt_out, "state": cmd_state, "control": cmd_control}
    commands[command](*args)
