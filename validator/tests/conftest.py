import asyncio
import contextlib

import pytest

from tests.fakes import fake_smtp


@pytest.fixture
async def smtp_server():
    """The shared fake SMTP server, in process, on a random loopback port."""
    fake_smtp.COMMANDS.clear()
    server = await fake_smtp.start("127.0.0.1", 0)
    port = server.sockets[0].getsockname()[1]
    yield port
    server.close()
    with contextlib.suppress(Exception):
        await asyncio.wait_for(server.wait_closed(), 2)


@pytest.fixture(autouse=True)
def no_data_ever():
    """Every test ends with proof that the fake server never received DATA/BDAT."""
    yield
    assert "DATA" not in fake_smtp.COMMANDS and "BDAT" not in fake_smtp.COMMANDS


async def scripted_server(script):  # type: ignore[no-untyped-def]
    """A tiny SMTP server driven by `script(reader, writer)` for edge cases."""
    server = await asyncio.start_server(script, "127.0.0.1", 0)
    return server, server.sockets[0].getsockname()[1]
