import socket
from types import SimpleNamespace

import pytest
from telegram.ext import ApplicationHandlerStop

from bot.handlers.song import _is_public_https_url
from bot.main import rate_limit_middleware
from bot import db


def test_public_https_media_url(monkeypatch):
    monkeypatch.setattr(
        socket,
        "getaddrinfo",
        lambda *args, **kwargs: [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("8.8.8.8", 443))],
    )
    assert _is_public_https_url("https://cdn.example.org/audio.mp3")


def test_media_url_rejects_plain_http_and_credentials():
    assert not _is_public_https_url("http://cdn.example.org/audio.mp3")
    assert not _is_public_https_url("https://user:pass@cdn.example.org/audio.mp3")


def test_media_url_rejects_private_and_local_addresses():
    assert not _is_public_https_url("https://127.0.0.1/private.mp3")
    assert not _is_public_https_url("https://10.2.3.4/audio.mp3")
    assert not _is_public_https_url("https://localhost/audio.mp3")


@pytest.mark.asyncio
async def test_set_blocked_creates_unknown_user_and_can_unblock(monkeypatch, tmp_path):
    monkeypatch.setattr(db, "DB_PATH", tmp_path / "bot.db")
    await db.init_db()
    await db.set_blocked(456789, True)
    assert await db.is_blocked(456789)
    await db.set_blocked(456789, False)
    assert not await db.is_blocked(456789)


@pytest.mark.asyncio
async def test_global_middleware_stops_blocked_user(monkeypatch):
    from bot import main

    async def blocked(_user_id):
        return True

    monkeypatch.setattr(main, "is_blocked", blocked)
    update = SimpleNamespace(
        effective_user=SimpleNamespace(id=123),
        callback_query=None,
        effective_message=None,
        effective_chat=None,
    )
    context = SimpleNamespace(user_data={})
    with pytest.raises(ApplicationHandlerStop):
        await rate_limit_middleware(update, context)
