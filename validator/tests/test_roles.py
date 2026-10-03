import pytest

from smarthost_validator.roles import ROLE_LOCAL_PARTS, is_role


@pytest.mark.parametrize("local", ["info", "INFO", "Sales", "admin", "support", "abuse", "postmaster", "support+eu", "no-reply"])
def test_role(local):
    assert is_role(local)


@pytest.mark.parametrize("local", ["john", "information", "salesperson", '"info"', "info.desk"])
def test_not_role(local):
    assert not is_role(local)


def test_list_contains_the_specified_examples():
    assert {"info", "admin", "sales", "support", "abuse", "postmaster"} <= ROLE_LOCAL_PARTS
