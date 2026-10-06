"""Unit tests of the receiver's verification and idempotent processing (no network)."""
from __future__ import annotations

import hashlib
import hmac
import json
import time
import unittest

import receiver


def sign(body: bytes, secrets: list[str], t: int) -> str:
    parts = [f"t={t}"] + ["v1=" + hmac.new(s.encode(), f"{t}.".encode() + body, hashlib.sha256).hexdigest() for s in secrets]
    return ",".join(parts)


def event(eid: str, etype: str, created: str, subject: str = "j1") -> dict:
    return {"id": eid, "type": etype, "created_at": created, "data": {"id": subject, "status": etype}}


class VerifyTest(unittest.TestCase):
    body = json.dumps(event("e1", "send.completed", "2026-10-06T00:00:00.000000Z")).encode()

    def test_correct_signature(self) -> None:
        now = int(time.time())
        self.assertTrue(receiver.verify(self.body, sign(self.body, ["whsec_a"], now), ["whsec_a"], now))

    def test_altered_body(self) -> None:
        now = int(time.time())
        self.assertFalse(receiver.verify(self.body + b" ", sign(self.body, ["whsec_a"], now), ["whsec_a"], now))

    def test_altered_timestamp(self) -> None:
        now = int(time.time())
        header = sign(self.body, ["whsec_a"], now).replace(f"t={now}", f"t={now - 1}")
        self.assertFalse(receiver.verify(self.body, header, ["whsec_a"], now))

    def test_stale_timestamp(self) -> None:
        now = int(time.time())
        self.assertFalse(receiver.verify(self.body, sign(self.body, ["whsec_a"], now - 301), ["whsec_a"], now))

    def test_rotation_overlap_and_after(self) -> None:
        now = int(time.time())
        overlap = sign(self.body, ["whsec_new", "whsec_old"], now)
        self.assertTrue(receiver.verify(self.body, overlap, ["whsec_new"], now), "current secret")
        self.assertTrue(receiver.verify(self.body, overlap, ["whsec_old"], now), "previous secret during the overlap")
        after = sign(self.body, ["whsec_new"], now)
        self.assertFalse(receiver.verify(self.body, after, ["whsec_old"], now), "previous secret after the overlap")

    def test_garbage_header(self) -> None:
        self.assertFalse(receiver.verify(self.body, "v1=zz", ["whsec_a"], int(time.time())))


class ApplyTest(unittest.TestCase):
    def setUp(self) -> None:
        self.state = receiver.empty_state()

    def test_duplicate_event_has_one_effect(self) -> None:
        e = event("e1", "send.completed", "2026-10-06T00:00:01Z")
        self.assertTrue(receiver.apply(self.state, "a", e))
        self.assertFalse(receiver.apply(self.state, "a", e), "a retried delivery of the same event")
        self.assertEqual(1, len(self.state["effects"]))

    def test_unknown_event_type_is_recorded_without_effect(self) -> None:
        self.assertFalse(receiver.apply(self.state, "a", event("e9", "something.new", "2026-10-06T00:00:01Z")))
        self.assertEqual([], self.state["effects"])

    def test_out_of_order_never_regresses(self) -> None:
        newer = event("e2", "message.hard_bounced", "2026-10-06T00:00:05Z")
        older = event("e1", "send.completed", "2026-10-06T00:00:01Z")
        newer["data"] = {"message": {"id": "m1"}, "event": {}}
        older["data"] = {"message": {"id": "m1"}, "event": {}}
        receiver.apply(self.state, "a", newer)
        receiver.apply(self.state, "a", older)
        self.assertEqual("message.hard_bounced", self.state["subjects"]["a|message:m1"]["type"])
        self.assertEqual(2, len(self.state["effects"]), "both events are processed once each")


if __name__ == "__main__":
    unittest.main()
