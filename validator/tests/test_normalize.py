"""D-32: the Python normaliser passes exactly the shared language-neutral vectors."""
import json
from pathlib import Path

import pytest

from smarthost_validator.normalize import normalize, normalize_domain

VECTORS = json.loads((Path(__file__).parent / "contracts" / "address-normalization-vectors.json").read_text())


def test_vector_file_is_substantial():
    assert len(VECTORS["vectors"]) >= 80


@pytest.mark.parametrize("vector", VECTORS["vectors"], ids=lambda v: ascii(v["input"])[:40])
def test_shared_vector(vector):
    assert normalize(vector["input"]) == vector["normalized"]


def test_local_part_is_never_rewritten():
    for raw in ["Ünïcode.Local@Example.com", "\"a b\"@Example.com", "MiXeD+Tag@EXAMPLE.org"]:
        out = normalize(raw)
        assert out is not None and out.rsplit("@", 1)[0] == raw.rsplit("@", 1)[0]


def test_idna2008_library_rules_are_not_applied():
    # idna.encode() (IDNA2008) rejects a symbol domain and CONTEXTO cases; UTS #46 (and PHP/ICU) accept them.
    assert normalize_domain("❤.example") == "xn--qei.example"
    assert normalize_domain("l·l.example") == "xn--ll-0ea.example"
