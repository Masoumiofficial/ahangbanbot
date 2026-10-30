import asyncio

import pytest

from bot.health_server import application_port, handle_health_request


def test_application_port_accepts_paas_port_and_rejects_invalid_values():
    assert application_port("8000") == 8000
    assert application_port("10000") == 10000
    for value in ("", "abc", "999", "65536"):
        with pytest.raises(RuntimeError):
            application_port(value)


def test_health_endpoint_returns_only_generic_status():
    async def run_request(path: str, method: str = "GET") -> bytes:
        server = await asyncio.start_server(handle_health_request, "127.0.0.1", 0)
        port = server.sockets[0].getsockname()[1]
        try:
            reader, writer = await asyncio.open_connection("127.0.0.1", port)
            writer.write(f"{method} {path} HTTP/1.1\r\nHost: test\r\n\r\n".encode("ascii"))
            await writer.drain()
            response = await reader.read()
            writer.close()
            await writer.wait_closed()
            return response
        finally:
            server.close()
            await server.wait_closed()

    health = asyncio.run(run_request("/health"))
    assert health.startswith(b"HTTP/1.1 200 OK\r\n")
    assert health.endswith(b'{"status":"ok"}')
    assert b"BOT_TOKEN" not in health
    assert b"AHANGBAN_API_TOKEN" not in health

    root = asyncio.run(run_request("/"))
    assert root.startswith(b"HTTP/1.1 200 OK\r\n")

    missing = asyncio.run(run_request("/private"))
    assert missing.startswith(b"HTTP/1.1 404 Not Found\r\n")

    head = asyncio.run(run_request("/health", method="HEAD"))
    assert head.startswith(b"HTTP/1.1 200 OK\r\n")
    assert head.endswith(b"\r\n\r\n")
