"""Offline tests for the Taranehyar account REST client."""
import logging
import time
from unittest.mock import AsyncMock, Mock

import httpx
import pytest

from bot import config as app_config
from bot.api_client import APIError, AhangBanAPI
from bot.config import _derive_tara_api_base


API_BASE = "https://example.com/wp-json/ahangban/v1"


def test_telegram_bot_token_prefers_canonical_env_and_keeps_legacy_alias(monkeypatch):
    monkeypatch.setenv("TELEGRAM_BOT_TOKEN", "canonical-token")
    monkeypatch.setenv("BOT_TOKEN", "legacy-token")
    assert app_config._telegram_bot_token() == "canonical-token"

    monkeypatch.delenv("TELEGRAM_BOT_TOKEN")
    assert app_config._telegram_bot_token() == "legacy-token"


def test_account_base_is_derived_from_connector_namespace():
    assert _derive_tara_api_base(API_BASE) == "https://example.com/wp-json/tara/v1"
    assert _derive_tara_api_base("https://example.com/wp-json/tara/v1") == "https://example.com/wp-json/tara/v1"
    assert _derive_tara_api_base("https://example.com") == "https://example.com/wp-json/tara/v1"


def test_user_agent_and_auth_header_contract():
    client = AhangBanAPI(base=API_BASE, token="x" * 64)
    assert client._headers()["User-Agent"] == "AhangBanBot/1.0"
    assert client._headers()["Authorization"] == f"Bearer {'x' * 64}"
    assert "Authorization" not in client._headers(authenticated=False)
    assert client._headers(authenticated=False)["User-Agent"] == "AhangBanBot/1.0"


@pytest.mark.parametrize("value", [True, False, 0, -12, 1.5, "1.5", "-10", None, object()])
def test_telegram_id_rejects_non_positive_or_non_integer_values(value):
    with pytest.raises(ValueError):
        AhangBanAPI._telegram_id(value)


@pytest.mark.parametrize(("value", "expected"), [(1, 1), ("123", 123), (" 42 ", 42)])
def test_telegram_id_accepts_positive_integers(value, expected):
    assert AhangBanAPI._telegram_id(value) == expected


@pytest.mark.asyncio
async def test_public_health_uses_tara_route_without_authorization_and_accepts_ok_shape():
    client = AhangBanAPI(base=API_BASE, token="x" * 64)
    client._request = AsyncMock(return_value={"ok": True, "version": "3.11.0"})

    result = await client.health()

    assert result == {"ok": True, "version": "3.11.0", "status": "ok"}
    client._request.assert_awaited_once_with(
        "GET",
        "health",
        use_cache=False,
        max_attempts=1,
        request_base="https://example.com/wp-json/tara/v1",
        authenticated=False,
    )


@pytest.mark.asyncio
async def test_link_posts_expected_payload_and_invalidates_only_matching_account_cache():
    client = AhangBanAPI(base=API_BASE, token="x" * 64)
    client._request = AsyncMock(return_value={"ok": True})
    own_key = client._cache_key("me", {"telegram_id": 42}, client.account_base)
    other_key = client._cache_key("me", {"telegram_id": 99}, client.account_base)
    client._cache[own_key] = (time.time() + 60, {"name": "old"})
    client._cache[other_key] = (time.time() + 60, {"name": "other"})

    result = await client.account_link("abcdefgh", 42, "user_name", "نام")

    assert result == {"ok": True}
    client._request.assert_awaited_once_with(
        "POST",
        "link",
        use_cache=False,
        json_body={"telegram_id": 42, "code": "ABCDEFGH", "username": "user_name", "first_name": "نام"},
        max_attempts=1,
        request_base="https://example.com/wp-json/tara/v1",
    )
    assert own_key not in client._cache
    assert other_key in client._cache


@pytest.mark.asyncio
async def test_uncertain_mutation_failure_also_invalidates_account_cache():
    client = AhangBanAPI(base=API_BASE, token="x" * 64)
    client._request = AsyncMock(side_effect=APIError(502, "temporary failure"))
    key = client._cache_key("me", {"telegram_id": 42}, client.account_base)
    client._cache[key] = (time.time() + 60, {"name": "possibly stale"})

    with pytest.raises(APIError):
        await client.account_unlink(42)

    assert key not in client._cache


@pytest.mark.asyncio
async def test_account_get_uses_tara_namespace_and_positive_telegram_id():
    client = AhangBanAPI(base=API_BASE, token="x" * 64)
    client._request = AsyncMock(return_value={"id": 1})

    assert await client.account_me("123") == {"id": 1}
    client._request.assert_awaited_once_with(
        "GET", "me", {"telegram_id": 123}, request_base="https://example.com/wp-json/tara/v1"
    )


@pytest.mark.asyncio
async def test_api_token_is_redacted_from_error_log_and_message(caplog):
    secret = "b" * 64
    client = AhangBanAPI(base=API_BASE, token=secret)
    response = httpx.Response(
        400,
        json={"code": "invalid_request", "message": f"Rejected key {secret}"},
        request=httpx.Request("GET", f"{API_BASE}/me"),
    )
    fake_client = Mock()
    fake_client.request = AsyncMock(return_value=response)
    client._get_client = Mock(return_value=fake_client)

    with caplog.at_level(logging.ERROR, logger="ahangban.api"):
        with pytest.raises(APIError) as caught:
            await client._request("GET", "me", use_cache=False)

    assert secret not in caught.value.message
    assert secret not in caplog.text
    assert "[redacted]" in caught.value.message
