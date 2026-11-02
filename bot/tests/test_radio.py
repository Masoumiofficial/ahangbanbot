from types import SimpleNamespace
import json
from unittest.mock import AsyncMock, patch

import httpx
import pytest

from bot.api_client import AhangBanAPI
from bot.handlers import radio
from bot.utils.keyboards import main_inline
from bot.utils.radio_stream import build_ffmpeg_command


@pytest.mark.asyncio
async def test_radio_link_api_posts_once_to_authenticated_wordpress_route():
    requests = []

    async def handler(request):
        requests.append(request)
        return httpx.Response(200, json={"status": "sent", "message_id": 55}, request=request)

    client = httpx.AsyncClient(transport=httpx.MockTransport(handler))
    api = AhangBanAPI(base="https://example.org/wp-json/ahangban/v1", token="test-token")
    api._client = client
    try:
        result = await api.publish_radio_link()
        assert result == {"status": "sent", "message_id": 55}
        assert len(requests) == 1
        request = requests[0]
        assert request.method == "POST"
        assert request.url.path.endswith("/channel/radio")
        assert request.headers["Authorization"] == "Bearer test-token"
        assert json.loads(await request.aread()) == {}
    finally:
        await api.close()


@pytest.mark.asyncio
async def test_radio_command_links_to_the_configured_page(monkeypatch):
    monkeypatch.setattr(radio, "RADIO_PAGE_URL", "https://ahangban.ir/radio/")
    message = SimpleNamespace(reply_text=AsyncMock())
    update = SimpleNamespace(effective_message=message)

    await radio.radio_handler(update, SimpleNamespace())

    message.reply_text.assert_awaited_once()
    text, kwargs = message.reply_text.await_args.args[0], message.reply_text.await_args.kwargs
    assert "رادیوی آنلاین آهنگ‌بان" in text
    button = kwargs["reply_markup"].inline_keyboard[0][0]
    assert button.url == "https://ahangban.ir/radio/"
    assert kwargs["disable_web_page_preview"] is True


def test_main_menu_has_an_online_radio_button():
    buttons = [button for row in main_inline().inline_keyboard for button in row]
    radio_button = next(button for button in buttons if button.text == "📻 رادیوی آنلاین")
    assert radio_button.url == "https://ahangban.ir/radio/"


@pytest.mark.asyncio
async def test_admin_radio_post_uses_the_wordpress_channel_target():
    message = SimpleNamespace(reply_text=AsyncMock())
    update = SimpleNamespace(
        effective_message=message,
        effective_user=SimpleNamespace(id=123),
    )
    publish = AsyncMock(return_value={"status": "sent", "message_id": 55})

    with patch("bot.handlers.radio.is_admin", return_value=True), patch(
        "bot.handlers.radio.api.publish_radio_link", new=publish
    ):
        await radio.radio_post_handler(update, SimpleNamespace())

    publish.assert_awaited_once_with()
    assert "در کانال تنظیم‌شدهٔ WordPress منتشر شد" in message.reply_text.await_args.args[0]


@pytest.mark.asyncio
async def test_non_admin_cannot_publish_radio_link():
    message = SimpleNamespace(reply_text=AsyncMock())
    update = SimpleNamespace(
        effective_message=message,
        effective_user=SimpleNamespace(id=123),
    )
    publish = AsyncMock()

    with patch("bot.handlers.radio.is_admin", return_value=False), patch(
        "bot.handlers.radio.api.publish_radio_link", new=publish
    ):
        await radio.radio_post_handler(update, SimpleNamespace())

    publish.assert_not_awaited()
    assert "فقط برای ادمین" in message.reply_text.await_args.args[0]


def test_radio_stream_command_uses_https_source_and_encoded_rtmp_key():
    command = build_ffmpeg_command(
        "ffmpeg",
        "https://radio.example.org/live.m3u8?channel=main",
        "rtmps://ingest.example.net/live",
        "demo key",
    )

    assert command[0] == "ffmpeg"
    assert command[command.index("-i") + 1] == "https://radio.example.org/live.m3u8?channel=main"
    assert "-c:v" in command and "libx264" in command
    assert "-c:a" in command and "aac" in command
    assert command[-1] == "rtmps://ingest.example.net/live/demo%20key"
    assert "-f" in command and command[-2] == "flv"


@pytest.mark.parametrize(
    ("source", "server", "key"),
    [
        ("http://radio.example.org/live.mp3", "rtmps://ingest.example.net/live", "key"),
        ("https://127.0.0.1/live.mp3", "rtmps://ingest.example.net/live", "key"),
        ("https://radio.example.org/live.mp3", "https://ingest.example.net/live", "key"),
        ("https://radio.example.org/live.mp3", "rtmps://localhost/live", "key"),
    ],
)
def test_radio_stream_rejects_insecure_or_private_endpoints(source, server, key):
    with pytest.raises(ValueError):
        build_ffmpeg_command("ffmpeg", source, server, key)
