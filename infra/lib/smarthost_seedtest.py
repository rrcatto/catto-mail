#!/usr/bin/env python3
"""catto-mail owned seed test (Phase 8, specification 2.9). Stdlib only.

Operator-triggered, never automatic: sends a tiny send job through the PUBLIC API to
addresses the operator owns and names explicitly, then reports what happened to each
message (Smarthost status and events: Postfix acceptance, remote acceptance, deferrals,
bounces). Run it only after live activation (`smarthostctl prod live-enable`).

    smarthostctl prod seed-test send --api-key-file FILE --from ADDRESS --to ADDRESS [--to ...]
                                     [--expect delivered|bounce] [--wait 600] --i-own-these-addresses
    smarthostctl prod seed-test status JOB_ID --api-key-file FILE

Paths it proves:
  delivered: API -> send job -> Go -> Postfix -> OpenDKIM -> the recipient's MX
             (status remote_accepted; then check the copy in the mailbox: DKIM pass,
             SPF pass for the bounce domain, DMARC pass, inbox placement)
  bounce:    the same to an address of the operator's own domain that does not exist,
             so that its MX answers 5xx and returns a DSN: Internet -> Postfix :25 ->
             DSN spool -> Go -> hard_bounce event and the hard_bounced status

The API key belongs to a dedicated seed-test client (runbook). It is read from a file
and never printed.
"""
from __future__ import annotations

import argparse
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FINAL = {"remote_accepted", "hard_bounced", "soft_bounced", "complained", "suppressed", "failed", "outcome_unknown"}


def base_url() -> str:
    env = Path(os.environ.get("SMARTHOST_DOTENV") or ROOT / "infra/.env")
    for line in env.read_text(encoding="utf-8").splitlines():
        if line.startswith("SMARTHOST_PUBLIC_BASE_URL="):
            return line.split("=", 1)[1].rstrip("/")
    sys.exit("SMARTHOST_PUBLIC_BASE_URL not found")


class Api:
    def __init__(self, base: str, key: str, insecure: bool) -> None:
        self.base, self.key = base.rstrip("/") + "/v1", key
        self.ctx = ssl.create_default_context()
        if insecure:   # a rehearsal with a self-signed certificate only
            self.ctx.check_hostname, self.ctx.verify_mode = False, ssl.CERT_NONE

    def call(self, method: str, path: str, body: object | None = None) -> tuple[int, dict]:
        data = None if body is None else json.dumps(body).encode()
        headers = {"Authorization": "Bearer " + self.key, "Accept": "application/json"}
        if data is not None:
            headers.update({"Content-Type": "application/json", "Idempotency-Key": str(uuid.uuid4())})
        req = urllib.request.Request(self.base + path, data=data, method=method, headers=headers)
        try:
            with urllib.request.urlopen(req, context=self.ctx, timeout=60) as r:
                return r.status, json.loads(r.read() or b"{}")
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read() or b"{}")

    def must(self, method: str, path: str, body: object | None = None, *ok: int) -> dict:
        code, out = self.call(method, path, body)
        if code not in ok:
            sys.exit(f"{method} {path}: HTTP {code} {json.dumps(out)[:300]}")
        return out


def report(api: Api, job_id: str) -> tuple[str, list[dict]]:
    job = api.must("GET", f"/send-jobs/{job_id}", None, 200)
    messages = api.must("GET", f"/send-jobs/{job_id}/messages?limit=100", None, 200)["data"]
    for m in messages:
        events = api.must("GET", f"/messages/{m['id']}/events?limit=100", None, 200)["data"]
        m["events"] = [f"{e['occurred_at']} {e['event_type']}" + (f" {e.get('smtp_code')}" if e.get("smtp_code") else "")
                       + (f" {e.get('enhanced_status_code')}" if e.get("enhanced_status_code") else "") for e in events]
    return job["status"], messages


def print_report(job_id: str, status: str, messages: list[dict]) -> None:
    print(f"send job {job_id}: {status}")
    for m in messages:
        print(f"  {m['recipient_address']}: {m['current_status']}")
        for e in m["events"]:
            print(f"    {e}")


def cmd_send(a: argparse.Namespace) -> int:
    if not a.i_own_these_addresses:
        sys.exit("refusing: a seed test goes only to addresses you own and name; add --i-own-these-addresses")
    api = Api(a.base_url or base_url(), Path(a.api_key_file).read_text(encoding="utf-8").strip(), a.insecure)
    tag = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
    job = api.must("POST", "/send-jobs", {"external_reference": f"seed-test-{tag}", "message_class": "transactional",
                                          "sender_identity": {"email": a.sender, "name": "catto-mail seed test"}}, 201)
    recipients = [{"external_recipient_reference": f"seed-{i}", "email_address": to,
                   "subject": f"catto-mail seed test {tag} ({i})",
                   "text_body": f"Seed test {tag} from {a.sender} to {to}.\nCheck the headers: DKIM, SPF and DMARC must pass.\n",
                   "html_body": f"<p>Seed test <b>{tag}</b> from {a.sender} to {to}.</p><p>Check the headers: DKIM, SPF and DMARC must pass.</p>"}
                  for i, to in enumerate(a.to)]
    api.must("POST", f"/send-jobs/{job['id']}/recipients", {"recipients": recipients}, 201)
    api.must("POST", f"/send-jobs/{job['id']}/submit", None, 202)
    print(f"submitted seed test job {job['id']} to {len(a.to)} address(es); waiting up to {a.wait} s")
    deadline = time.time() + a.wait
    status, messages = report(api, job["id"])
    while time.time() < deadline and not all(m["current_status"] in FINAL for m in messages):
        time.sleep(10)
        status, messages = report(api, job["id"])
    print_report(job["id"], status, messages)
    want = "remote_accepted" if a.expect == "delivered" else "hard_bounced"
    ok = bool(messages) and all(m["current_status"] == want for m in messages)
    print(f"seed test {'PASS' if ok else 'NOT YET / FAIL'}: expected every message {want}")
    return 0 if ok else 1


def cmd_status(a: argparse.Namespace) -> int:
    api = Api(a.base_url or base_url(), Path(a.api_key_file).read_text(encoding="utf-8").strip(), a.insecure)
    status, messages = report(api, a.job_id)
    print_report(a.job_id, status, messages)
    return 0


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description="catto-mail owned seed test (operator-triggered)")
    sub = p.add_subparsers(dest="cmd", required=True)
    for name in ("send", "status"):
        s = sub.add_parser(name)
        s.add_argument("--api-key-file", required=True)
        s.add_argument("--base-url", default="")
        s.add_argument("--insecure", action="store_true", help="accept a self-signed certificate (rehearsal only)")
        if name == "send":
            s.add_argument("--from", dest="sender", required=True, help="an address of a verified, DKIM-active sending domain")
            s.add_argument("--to", action="append", required=True, help="an address you own (repeatable, at most 10)")
            s.add_argument("--expect", choices=("delivered", "bounce"), default="delivered")
            s.add_argument("--wait", type=int, default=600)
            s.add_argument("--i-own-these-addresses", action="store_true")
        else:
            s.add_argument("job_id")
    a = p.parse_args(argv)
    if a.cmd == "send" and len(a.to) > 10:
        sys.exit("a seed test is at most 10 addresses")
    return cmd_send(a) if a.cmd == "send" else cmd_status(a)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
