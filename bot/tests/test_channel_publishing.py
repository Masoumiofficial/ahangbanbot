import json
import time
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

import httpx
import pytest

from bot.api_client import AhangBanAPI, APIError
from bot.handlers.admin import (
    _admin_markup,
    _channel_publish_preview,
    admin_channel_publish_confirm_callback,
    admin_channel_publish_menu_callback,
    admin_channel_publish_prepare_callback,
    admin_menu_callback,
)
from bot.utils.keyboards import song_card


def _callback_update(data: str, user_id: int = 100):
    class Query:
        def __init__(self):
            self.data = data
            self.message = SimpleNamespace(photo=None, video=None, audio=None, document=None)
            self.answers = []
            self.edits = []

        async def answer(self, text="", show_alert=False):
            self.answers.append((text, show_alert))

        async def edit_message_text(self, text, **kwargs):
            self.edits.append((text, kwargs))

        async def edit_message_caption(self, caption, **kwargs):
            self.edits.append((caption, kwargs))

    query = Query()
    update = SimpleNamespace(
        callback_query=query,
        effective_user=SimpleNamespace(id=user_id),
    )
    return update, query


def test_admin_menu_exposes_manual_channel_publishing():
    callbacks = {
        button.callback_data
        for row in _admin_markup().inline_keyboard
        for button in row
        if button.callback_data
    }
    assert "admin:channel_publish" in callbacks


@pytest.mark.asyncio
async def test_admin_navigation_edits_captions_when_opened_from_song_photo():
    for callback, handler, expected in (
        ("admin:menu", admin_menu_callback, "پنل مدیریت آهنگ‌بان"),
        ("admin:channel_publish", admin_channel_publish_menu_callback, "انتشار دستی در کانال"),
    ):
        update, query = _callback_update(callback)
        query.message.photo = [object()]
        with patch("bot.handlers.admin.is_admin", return_value=True):
            await handler(update, SimpleNamespace())
        assert query.edits
        assert expected in query.edits[-1][0]


def test_song_card_has_publish_button_only_for_admins():
    song = {"id": 731, "song": "آهنگ نمونه", "qualities": {}}
    normal_callbacks = {
        button.callback_data
        for row in song_card(song).inline_keyboard
        for button in row
        if button.callback_data
    }
    admin_callbacks = {
        button.callback_data
        for row in song_card(song, is_admin=True).inline_keyboard
        for button in row
        if button.callback_data
    }
    assert "admin:channel_publish_prepare:731" not in normal_callbacks
    assert "admin:channel_publish_prepare:731" in admin_callbacks


def test_channel_publish_preview_uses_requested_layout_and_escapes_post_content():
    preview = _channel_publish_preview({
        "id": 12,
        "song": '<b>عنوان</b>',
        "artist": "A&B",
        "artists": [{"name": "مهدی جهانی"}],
        "permalink": "https://example.org/song/12?a=1&b=2",
    })
    assert "&lt;b&gt;عنوان&lt;/b&gt;" in preview
    assert "A&amp;B" in preview
    assert "🎤 #مهدی_جهانی" in preview
    assert 'href="https://example.org/song/12?a=1&amp;b=2"' in preview
    assert "🔗 <a" in preview
    assert "🆔️ @Ahangban_ir 💙" in preview
    assert "اگر پیش‌نمایش ویس در تنظیمات WordPress روشن باشد" in preview
    assert "<b>عنوان</b>" not in preview


@pytest.mark.asyncio
async def test_publish_channel_post_uses_authenticated_json_post_once():
    requests = []

    async def handler(request):
        requests.append(request)
        return httpx.Response(200, json={"status": "sent", "post_id": 731}, request=request)

    client = httpx.AsyncClient(transport=httpx.MockTransport(handler))
    api = AhangBanAPI(base="https://example.org/wp-json/ahangban/v1", token="test-token")
    api._client = client
    try:
        result = await api.publish_channel_post(731)
        assert result == {"status": "sent", "post_id": 731}
        assert len(requests) == 1
        request = requests[0]
        assert request.method == "POST"
        assert request.url.path.endswith("/channel/publish")
        assert request.headers["Authorization"] == "Bearer test-token"
        assert json.loads(await request.aread()) == {"post_id": 731}
    finally:
        await api.close()


@pytest.mark.asyncio
async def test_publish_channel_post_does_not_retry_ambiguous_server_error():
    calls = 0

    async def handler(request):
        nonlocal calls
        calls += 1
        return httpx.Response(502, json={"message": "unknown delivery"}, request=request)

    client = httpx.AsyncClient(transport=httpx.MockTransport(handler))
    api = AhangBanAPI(base="https://example.org/wp-json/ahangban/v1", token="test-token")
    api._client = client
    try:
        with pytest.raises(APIError) as exc:
            await api.publish_channel_post(731)
        assert exc.value.status == 502
        assert calls == 1
    finally:
        await api.close()


@pytest.mark.asyncio
async def test_non_admin_cannot_prepare_channel_publish():
    update, query = _callback_update("admin:channel_publish_prepare:731", user_id=555)
    context = SimpleNamespace(user_data={})
    with patch("bot.handlers.admin.is_admin", return_value=False), patch(
        "bot.handlers.admin.api.song", new=AsyncMock()
    ) as song:
        await admin_channel_publish_prepare_callback(update, context)
    assert query.answers[-1][1] is True
    song.assert_not_awaited()


@pytest.mark.asyncio
async def test_channel_publish_confirmation_is_consumed_before_sending():
    update, query = _callback_update("admin:channel_publish_confirm:731", user_id=100)
    context = SimpleNamespace(user_data={
        "channel_publish_pending": {"post_id": 731, "created_at": time.time()},
    })
    publish = AsyncMock(return_value={
        "status": "sent",
        "post_id": 731,
        "message_id": 99,
        "preview_status": "queued",
        "preview_duration": 60,
    })
    voice_fallback = AsyncMock()
    with patch("bot.handlers.admin.is_admin", return_value=True), patch(
        "bot.handlers.admin.api.publish_channel_post", new=publish
    ), patch("bot.handlers.admin.send_python_voice_preview", new=voice_fallback):
        await admin_channel_publish_confirm_callback(update, context)
        await admin_channel_publish_confirm_callback(update, context)
    publish.assert_awaited_once_with(731)
    voice_fallback.assert_not_awaited()
    assert "channel_publish_pending" not in context.user_data
    assert any("در کانال منتشر شد" in text for text, _ in query.edits)
    assert any("صف WP-Cron" in text for text, _ in query.edits)
    assert any("ویس 60 ثانیه‌ای" in text for text, _ in query.edits)


@pytest.mark.asyncio
async def test_missing_channel_message_id_is_ambiguous_and_never_falls_back():
    update, query = _callback_update("admin:channel_publish_confirm:731", user_id=100)
    context = SimpleNamespace(user_data={
        "channel_publish_pending": {"post_id": 731, "created_at": time.time()},
    }, bot=SimpleNamespace())
    publish = AsyncMock(return_value={
        "status": "sent",
        "post_id": 731,
        "preview_status": "unavailable",
        "channel_target": "@Ahangban_ir",
    })
    fallback = AsyncMock()
    with patch("bot.handlers.admin.is_admin", return_value=True), patch(
        "bot.handlers.admin.api.publish_channel_post", new=publish
    ), patch("bot.handlers.admin.send_python_voice_preview", new=fallback):
        await admin_channel_publish_confirm_callback(update, context)

    publish.assert_awaited_once_with(731)
    fallback.assert_not_awaited()
    assert any("نتیجهٔ ارسال قطعی نیست" in text for text, _ in query.edits)
    assert not any("در کانال منتشر شد" in text for text, _ in query.edits)


@pytest.mark.asyncio
async def test_channel_publish_confirmation_reports_disabled_voice_preview():
    update, query = _callback_update("admin:channel_publish_confirm:731", user_id=100)
    context = SimpleNamespace(user_data={
        "channel_publish_pending": {"post_id": 731, "created_at": time.time()},
    })
    publish = AsyncMock(return_value={
        "status": "sent",
        "post_id": 731,
        "message_id": 99,
        "preview_status": "disabled",
        "preview_duration": 0,
    })
    with patch("bot.handlers.admin.is_admin", return_value=True), patch(
        "bot.handlers.admin.api.publish_channel_post", new=publish
    ):
        await admin_channel_publish_confirm_callback(update, context)
    publish.assert_awaited_once_with(731)
    assert any("پیش‌نمایش ویس از تنظیمات پیشخوان خاموش است" in text for text, _ in query.edits)


@pytest.mark.asyncio
async def test_unavailable_wordpress_preview_uses_python_fallback_with_current_sources():
    update, query = _callback_update("admin:channel_publish_confirm:731", user_id=100)
    context = SimpleNamespace(user_data={
        "channel_publish_pending": {
            "post_id": 731,
            "created_at": time.time(),
            "preview_sources": {"128": "https://cdn.example.org/old.mp3"},
        },
    }, bot=SimpleNamespace())
    publish = AsyncMock(return_value={
        "status": "sent",
        "post_id": 731,
        "message_id": 99,
        "channel_target": "@Ahangban_ir",
        "preview_status": "unavailable",
        "preview_duration": 45,
        "preview_sources": {"128": "https://cdn.example.org/current.mp3"},
        "preview_caption": "🩵 @AhangBan_ir 🩵\n\n🎧 آهنگ بان | موسیقی همیشه همراهته 🎵",
    })
    fallback = AsyncMock(return_value={"status": "sent", "message_id": 100})
    with patch("bot.handlers.admin.is_admin", return_value=True), patch(
        "bot.handlers.admin.api.publish_channel_post", new=publish
    ), patch("bot.handlers.admin.send_python_voice_preview", new=fallback):
        await admin_channel_publish_confirm_callback(update, context)

    fallback.assert_awaited_once()
    assert fallback.await_args.kwargs["channel_target"] == "@Ahangban_ir"
    assert fallback.await_args.kwargs["reply_to_message_id"] == 99
    assert fallback.await_args.kwargs["sources"] == {"128": "https://cdn.example.org/current.mp3"}
    assert fallback.await_args.kwargs["duration"] == 45
    assert fallback.await_args.kwargs["caption"] == "🩵 @AhangBan_ir 🩵\n\n🎧 آهنگ بان | موسیقی همیشه همراهته 🎵"
    assert any("با ربات Python" in text and "100" in text for text, _ in query.edits)
