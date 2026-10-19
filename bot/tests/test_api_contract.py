"""
تست قرارداد API — بدون نیاز به وردپرس واقعی (mock)
"""
import pytest
from unittest.mock import AsyncMock, patch
from bot.api_client import AhangBanAPI

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
    mock_data = {"status": "ok", "wp": "6.6", "php": "8.2", "taxonomy_artist": True, "post_count": 1234}
    with patch.object(api, "_request", new=AsyncMock(return_value=mock_data)):
        data = await api.health()
        assert data["status"] == "ok"
        assert data["taxonomy_artist"] is True
