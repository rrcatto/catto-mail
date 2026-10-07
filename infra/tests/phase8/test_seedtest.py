"""Phase 8: the owned seed-test tool refuses anything but an explicit, small, operator-owned test."""
from __future__ import annotations

import io
import tempfile
import unittest
from contextlib import redirect_stderr
from pathlib import Path

import fixtures  # noqa: F401  (puts infra/lib on the path)
import smarthost_seedtest as seed


class SeedTestGuardsTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.key = Path(self.tmp.name) / "key"
        self.key.write_text("not-a-real-key\n")

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def run_main(self, *args: str) -> str:
        with self.assertRaises(SystemExit) as ctx, redirect_stderr(io.StringIO()):
            seed.main(list(args))
        return str(ctx.exception.code)

    def test_requires_the_ownership_confirmation(self) -> None:
        out = self.run_main("send", "--api-key-file", str(self.key), "--from", "news@send.example.net", "--to", "me@example.net",
                            "--base-url", "https://192.0.2.1")
        self.assertIn("--i-own-these-addresses", out)

    def test_at_most_ten_addresses(self) -> None:
        args = ["send", "--api-key-file", str(self.key), "--from", "a@b.example", "--i-own-these-addresses", "--base-url", "https://192.0.2.1"]
        for i in range(11):
            args += ["--to", f"r{i}@example.net"]
        self.assertIn("at most 10", self.run_main(*args))

    def test_recipients_and_sender_are_mandatory(self) -> None:
        self.assertEqual("2", self.run_main("send", "--api-key-file", str(self.key), "--i-own-these-addresses"))


if __name__ == "__main__":
    unittest.main()
