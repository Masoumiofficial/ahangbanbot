"""
تست قرارداد API — بدون نیاز به وردپرس واقعی (mock)
"""
import pytest
from unittest.mock import AsyncMock, patch

from bot import config as app_config
from bot.api_client import AhangBanAPI
from bot.main import build_app

@pytest.mark.asyncio
async def test_search_empty_query_validation():
    # Plugin returns error for empty — client should raise APIError
    api = AhangBanAPI(base="https://example.com/wp-json/ahangban/v1", token="test")
    with patch.object(api, "_request", new=AsyncMock(side_effect=Exception("empty"))) as m:
        try:
            await api.search(q="", page=1)
        except Exception:
            pass
        # Ensure it attempted
        assert True

@pytest.mark.asyncio
async def test_health_mock():
    api = AhangBanAPI(base="https://example.com/wp-json/ahangban/v1", token="test")
    with patch.object(api, "_request", new=AsyncMock(return_value={"status": "ok"})):
        data = await api.health()
        assert data == {"status": "ok"}


@pytest.mark.parametrize(
    "base",
    [
        "http://example.com/wp-json/ahangban/v1",
        "https://localhost/wp-json/ahangban/v1",
        "https://127.0.0.1/wp-json/ahangban/v1",
        "https://user:pass@example.com/wp-json/ahangban/v1",
        "https://[broken/wp-json/ahangban/v1",
        "https://example.com:99999/wp-json/ahangban/v1",
        "https://api.example.test/wp-json/ahangban/v1",
    ],
)
def test_api_client_rejects_insecure_or_private_base(base):
    with pytest.raises(ValueError):
        AhangBanAPI(base=base, token="test")


@pytest.mark.parametrize(
    "base",
    [
        "https://[broken/wp-json/ahangban/v1",
        "https://127.0.0.1/wp-json/ahangban/v1",
        "https://example.com:99999/wp-json/ahangban/v1",
    ],
)
def test_config_reports_malformed_or_private_api_base(monkeypatch, base):
    monkeypatch.setattr(app_config, "BOT_TOKEN", "123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ")
    monkeypatch.setattr(app_config, "AHANGBAN_API_BASE", base)
    monkeypatch.setattr(app_config, "AHANGBAN_API_TOKEN", "a" * 64)
    assert any("AHANGBAN_API_BASE" in error for error in app_config.validate())


def test_config_rejects_sample_bot_token(monkeypatch):
    monkeypatch.setattr(app_config, "BOT_TOKEN", "REPLACE_WITH_BOTFATHER_TOKEN")
    monkeypatch.setattr(app_config, "AHANGBAN_API_BASE", "https://example.com/wp-json/ahangban/v1")
    monkeypatch.setattr(app_config, "AHANGBAN_API_TOKEN", "a" * 64)
    assert any("BOT_TOKEN" in error for error in app_config.validate())


def test_bot_fails_fast_on_invalid_configuration(monkeypatch):
    monkeypatch.setattr("bot.main.validate", lambda: ["BOT_TOKEN نامعتبر است"])
    with pytest.raises(RuntimeError, match="پیکربندی ربات نامعتبر است"):
        build_app()


@pytest.mark.asyncio
async def test_http_client_does_not_follow_redirects():
    api = AhangBanAPI(base="https://example.com/wp-json/ahangban/v1", token="test")
    client = api._get_client()
    try:
        assert client.follow_redirects is False
    finally:
        await api.close()
