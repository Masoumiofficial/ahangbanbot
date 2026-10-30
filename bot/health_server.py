"""Minimal, secret-free HTTP health endpoint for Python web PaaS runtimes."""
from __future__ import annotations

import asyncio
import logging
import os

log = logging.getLogger("ahangban.health")

_HEALTH_BODY = b'{"status":"ok"}'
_NOT_FOUND_BODY = b'{"status":"not_found"}'
_BAD_REQUEST_BODY = b'{"status":"bad_request"}'


def application_port(raw_port: str | None = None) -> int:
    """Read the PaaS port and reject values outside the platform's supported range."""
    value = (raw_port if raw_port is not None else os.getenv("PORT", "8000")).strip()
    try:
        port = int(value)
    except (TypeError, ValueError) as exc:
        raise RuntimeError("PORT must be an integer between 1000 and 65535") from exc
    if not 1000 <= port <= 65535:
        raise RuntimeError("PORT must be between 1000 and 65535")
    return port


async def handle_health_request(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    """Serve only GET/HEAD / and /health; never expose configuration or secrets."""
    response_body = _BAD_REQUEST_BODY
    status = "400 Bad Request"
    method = ""

    try:
        loop = asyncio.get_running_loop()
        deadline = loop.time() + 3.0

        async def read_line() -> bytes:
            remaining = deadline - loop.time()
            if remaining <= 0:
                raise asyncio.TimeoutError
            return await asyncio.wait_for(reader.readline(), timeout=remaining)

        request_line = await read_line()
        parts = request_line.decode("ascii", "replace").strip().split()
        if len(parts) >= 2:
            method, target = parts[0].upper(), parts[1].split("?", 1)[0]
            # Drain a bounded number of headers; the endpoint does not use their values.
            for _ in range(64):
                header = await read_line()
                if header in (b"\r\n", b"\n", b""):
                    break
            else:
                raise ValueError("too many HTTP headers")

            if method in ("GET", "HEAD") and target in ("/", "/health"):
                status, response_body = "200 OK", _HEALTH_BODY
            else:
                status, response_body = "404 Not Found", _NOT_FOUND_BODY
    except (asyncio.TimeoutError, ValueError):
        status, response_body = "400 Bad Request", _BAD_REQUEST_BODY
    except (ConnectionError, OSError):
        status, response_body = "400 Bad Request", _BAD_REQUEST_BODY

    body = b"" if method == "HEAD" else response_body
    headers = (
        f"HTTP/1.1 {status}\r\n"
        "Content-Type: application/json; charset=utf-8\r\n"
        f"Content-Length: {len(response_body)}\r\n"
        "Cache-Control: no-store\r\n"
        "Connection: close\r\n\r\n"
    ).encode("ascii")
    try:
        writer.write(headers + body)
        await writer.drain()
    except (ConnectionError, OSError):
        pass
    finally:
        writer.close()
        try:
            await writer.wait_closed()
        except (ConnectionError, OSError):
            pass


async def start_health_server(port: int | None = None) -> asyncio.AbstractServer:
    """Bind the minimal health endpoint on all interfaces for PaaS port checks."""
    port = application_port() if port is None else application_port(str(port))
    server = await asyncio.start_server(
        handle_health_request,
        host="0.0.0.0",
        port=port,
        limit=8192,
    )
    log.info("Health endpoint listening on 0.0.0.0:%s", port)
    return server
