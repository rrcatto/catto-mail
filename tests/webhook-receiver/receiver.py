"""Deterministic external-client webhook receiver (test fixture, Phase 7).

Stands in for a client application's webhook endpoint. It is deliberately
outside Smarthost: its own process and container, no Smarthost code, no
database access. It verifies every request independently of the sender:

  1. read the raw body;
  2. parse `Smarthost-Signature: t=<unix>,v1=<hex>[,v1=<hex>]`, reject a timestamp
     more than TOLERANCE seconds from the local clock;
  3. accept when any v1 equals HMAC-SHA256(secret, "<t>.<raw body>") for any of the
     endpoint's configured secrets (current, plus previous during a rotation),
     compared in constant time;
  4. only then parse the JSON;
  5. de-duplicate on the event id (`Smarthost-Event-Id` / body `id`): a repeated
     event is acknowledged again but has no second effect;
  6. persist, then acknowledge with 2xx quickly.

Integration state is applied per subject and keeps the newest event by
`created_at`, so out-of-order arrival never regresses it. Unknown event types
are acknowledged and recorded without effect.

Test control (not part of any client contract): POST /_control/secrets,
/_control/script (scripted responses: 200, 400, 429[:retry-after], 500,
timeout[:seconds], drop, delay[:seconds]), /_control/reset; GET
/_control/state. State survives restarts in STATE_FILE.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import socket
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

TOLERANCE = 300
KNOWN_TYPES = {"validation.completed", "validation.failed", "send.completed", "send.failed",
               "message.hard_bounced", "message.complained", "webhook.test"}
STATE_FILE = os.environ.get("RECEIVER_STATE_FILE", "/data/state.json")
LOCK = threading.Lock()


def empty_state() -> dict[str, Any]:
    return {"secrets": {}, "script": {}, "receipts": [], "events": {}, "effects": [], "rejected": [], "subjects": {}}


def load() -> dict[str, Any]:
    try:
        with open(STATE_FILE, encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return empty_state()


STATE = load()


def save() -> None:
    tmp = STATE_FILE + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(STATE, fh)
    os.replace(tmp, STATE_FILE)


def verify(body: bytes, header: str, secrets: list[str], now: int, tolerance: int = TOLERANCE) -> bool:
    """The receiver-side check of the Smarthost signature contract."""
    timestamp: int | None = None
    signatures: list[str] = []
    for item in header.split(","):
        key, _, value = item.strip().partition("=")
        if key == "t" and value.isdigit():
            timestamp = int(value)
        elif key == "v1" and len(value) == 64:
            signatures.append(value)
    if timestamp is None or not signatures or abs(now - timestamp) > tolerance:
        return False
    signed = str(timestamp).encode() + b"." + body
    for secret in secrets:
        expected = hmac.new(secret.encode(), signed, hashlib.sha256).hexdigest()
        if any(hmac.compare_digest(expected, s) for s in signatures):
            return True
    return False


def subject_of(event: dict[str, Any]) -> str:
    data = event.get("data") or {}
    if "message" in data:
        return "message:" + str(data["message"].get("id"))
    return str(event.get("type", "")).split(".")[0] + ":" + str(data.get("id", "-"))


def apply(state: dict[str, Any], name: str, event: dict[str, Any]) -> bool:
    """Idempotent, order-tolerant processing. Returns True when this is the first effect of the event."""
    event_id = str(event.get("id"))
    seen = state["events"].setdefault(name, {})
    if event_id in seen:
        return False
    seen[event_id] = {"type": event.get("type"), "created_at": event.get("created_at"), "subject": subject_of(event)}
    if event.get("type") not in KNOWN_TYPES:
        return False
    state["effects"].append({"endpoint": name, "event_id": event_id, "type": event.get("type"), "subject": subject_of(event)})
    key = name + "|" + subject_of(event)
    current = state["subjects"].get(key)
    if current is None or str(event.get("created_at")) >= str(current["created_at"]):
        state["subjects"][key] = {"type": event.get("type"), "created_at": event.get("created_at"), "event_id": event_id, "data": event.get("data")}
    return True


class Handler(BaseHTTPRequestHandler):
    server_version = "client-webhook-receiver"

    def log_message(self, fmt: str, *args: Any) -> None:  # quiet; state is the log
        pass

    def _json(self, code: int, payload: Any) -> None:
        data = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self) -> None:  # noqa: N802
        if self.path.startswith("/_control/state"):
            with LOCK:
                self._json(200, STATE)
        else:
            self._json(404, {"error": "not found"})

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(min(length, 2_000_000))
        if self.path.startswith("/_control/"):
            return self._control(self.path, json.loads(body or b"{}"))
        if not self.path.startswith("/hooks/"):
            return self._json(404, {"error": "not found"})
        name = self.path.split("/")[2].split("?")[0]
        with LOCK:
            script = STATE["script"].get(name) or []
            step = script.pop(0) if script else "200"
            secrets = list(STATE["secrets"].get(name, []))
        ok = verify(body, self.headers.get("Smarthost-Signature", ""), secrets, int(time.time()))
        receipt = {"endpoint": name, "at": time.time(), "event_id": self.headers.get("Smarthost-Event-Id"),
                   "event_type": self.headers.get("Smarthost-Event-Type"), "attempt": self.headers.get("Smarthost-Delivery-Attempt"),
                   "signature_valid": ok, "user_agent": self.headers.get("User-Agent"), "step": step,
                   "signatures": self.headers.get("Smarthost-Signature", "").count("v1="),
                   # Retained for tests: every header and the raw body (bounded; test data only).
                   "headers": dict(self.headers.items()), "body": body[:65536].decode("utf-8", "replace")}
        with LOCK:
            STATE["receipts"].append(receipt)
            if not ok:
                STATE["rejected"].append(receipt)
                save()
        if not ok:
            return self._json(401, {"error": "invalid signature"})
        event = json.loads(body)  # parsed only after the signature check
        if event.get("id") != receipt["event_id"]:
            return self._json(400, {"error": "event id mismatch"})
        with LOCK:
            first = apply(STATE, name, event) if step in ("200", "drop") or step.startswith("delay") else False
            save()
        kind, _, arg = step.partition(":")
        if kind == "drop":
            self.connection.shutdown(socket.SHUT_RDWR)
            return None
        if kind in ("delay", "timeout"):
            time.sleep(float(arg or "5"))
            if kind == "timeout":
                return None
        if kind in ("400", "500"):
            return self._json(int(kind), {"error": "scripted"})
        if kind == "429":
            data = b'{"error": "slow down"}'
            self.send_response(429)
            self.send_header("Retry-After", arg or "1")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            return None
        return self._json(200, {"received": True, "first": first})

    def _control(self, path: str, data: dict[str, Any]) -> None:
        with LOCK:
            if path.startswith("/_control/secrets"):
                STATE["secrets"][data["endpoint"]] = list(data["secrets"])
            elif path.startswith("/_control/script"):
                STATE["script"][data["endpoint"]] = list(data["responses"])
            elif path.startswith("/_control/reset"):
                STATE.clear()
                STATE.update(empty_state())
            else:
                return self._json(404, {"error": "unknown control"})
            save()
        return self._json(200, {"ok": True})


def main() -> None:
    port = int(os.environ.get("RECEIVER_PORT", "8080"))
    ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()


if __name__ == "__main__":
    main()
