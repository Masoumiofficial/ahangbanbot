from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest
from telegram import InlineKeyboardMarkup
from telegram.error import BadRequest

from bot import db
from bot import main
from bot.handlers import artist, song, start
from bot.utils.keyboards import main_inline


@pytest.mark.asyncio
async def test_large_cover_retries_wordpress_medium_variant_only_after_bad_request(monkeypatch):
    monkeypatch.setattr(song, "_is_public_https_url", lambda _url: True)
    message = SimpleNamespace(
        reply_photo=AsyncMock(side_effect=[BadRequest("photo too large"), SimpleNamespace(message_id=2)])
    )
    item = {
        "cover": "https://cdn.example.org/large.jpg",
        "cover_fallback": "https://cdn.example.org/medium.jpg",
    }

    result = await song._send_card_photo(message, item, "caption", InlineKeyboardMarkup([]))

    assert result == "sent"
    assert message.reply_photo.await_count == 2
    assert message.reply_photo.await_args_list[0].kwargs["photo"] == item["cover"]
    assert message.reply_photo.await_args_list[1].kwargs["photo"] == item["cover_fallback"]


@pytest.mark.asyncio
async def test_ambiguous_cover_timeout_does_not_send_a_duplicate(monkeypatch):
    monkeypatch.setattr(song, "_is_public_https_url", lambda _url: True)
    message = SimpleNamespace(reply_photo=AsyncMock(side_effect=TimeoutError("response timed out")))

    result = await song._send_card_photo(
        message,
        {
            "cover": "https://cdn.example.org/large.jpg",
            "cover_fallback": "https://cdn.example.org/medium.jpg",
        },
        "caption",
        InlineKeyboardMarkup([]),
    )

    assert result == "unknown"
    assert message.reply_photo.await_count == 1


@pytest.mark.asyncio
async def test_rate_limit_is_atomic_across_concurrent_updates(monkeypatch, tmp_path):
    monkeypatch.setattr(db, "DB_PATH", tmp_path / "atomic-rate-limit.db")
    await db.init_db()

    results = await __import__("asyncio").gather(*(db.check_rate_limit(12345, 20) for _ in range(30)))

    assert sum(results) == 20


def test_main_menu_exposes_artist_search_as_a_real_inline_action():
    menu = main_inline()
    callback_data = {
        button.callback_data
        for row in menu.inline_keyboard
        for button in row
        if button.callback_data
    }
    assert "prompt:artist" in callback_data


@pytest.mark.asyncio
async def test_channel_deep_link_opens_the_song_without_duplicate_welcome(monkeypatch):
    user = SimpleNamespace(id=123, first_name="کاربر", username="", last_name="")
    message = SimpleNamespace(reply_text=AsyncMock())
    update = SimpleNamespace(
        effective_user=user,
        effective_chat=SimpleNamespace(type="private"),
        message=message,
    )
    context = SimpleNamespace(args=["song_456"], user_data={})
    monkeypatch.setattr(start, "is_blocked", AsyncMock(return_value=False))
    show_song = AsyncMock()
    monkeypatch.setattr(song, "_show_song", show_song)

    await start.start_handler(update, context)

    show_song.assert_awaited_once_with(update, context, 456, edit=False)
    message.reply_text.assert_not_awaited()


@pytest.mark.asyncio
async def test_artist_list_from_media_callback_replies_in_text_instead_of_editing_media(monkeypatch):
    loading_message = SimpleNamespace(edit_text=AsyncMock())
    message = SimpleNamespace(
        photo=[object()],
        video=None,
        audio=None,
        document=None,
        chat=SimpleNamespace(),
        reply_text=AsyncMock(return_value=loading_message),
    )
    query = SimpleNamespace(
        data="artists:page:1",
        message=message,
        answer=AsyncMock(),
        edit_message_text=AsyncMock(),
    )
    update = SimpleNamespace(callback_query=query, effective_chat=SimpleNamespace())
    context = SimpleNamespace(user_data={})
    monkeypatch.setattr(artist.api, "artists", AsyncMock(return_value={
        "results": [{"name": "خوانندهٔ نمونه", "count": 1, "term_id": 7}],
        "total": 1,
        "per_page": 10,
    }))
    monkeypatch.setattr(artist, "increment_stat", AsyncMock())

    await artist.artists_list_handler(update, context)

    query.answer.assert_awaited_once()
    query.edit_message_text.assert_not_awaited()
    message.reply_text.assert_awaited_once_with("⏳ در حال بارگذاری خوانندگان…")
    loading_message.edit_text.assert_awaited_once()
    assert "فهرست خوانندگان" in loading_message.edit_text.await_args.args[0]


@pytest.mark.asyncio
async def test_artist_detail_callback_merges_keyboard_tuple_into_markup(monkeypatch):
    user = SimpleNamespace(id=12345)
    chat = SimpleNamespace(send_action=AsyncMock())
    message = SimpleNamespace(photo=None, video=None, audio=None, document=None, chat=chat)
    query = SimpleNamespace(
        data="artist:7",
        message=message,
        from_user=user,
        answer=AsyncMock(),
        edit_message_text=AsyncMock(),
    )
    update = SimpleNamespace(callback_query=query, effective_user=user)
    context = SimpleNamespace(user_data={})
    monkeypatch.setattr(artist.api, "artist", AsyncMock(return_value={
        "term": {"term_id": 7, "name": "خوانندهٔ نمونه", "count": 1},
        "buckets": {},
        "results": [],
        "total": 0,
        "per_page": 6,
    }))
    monkeypatch.setattr(artist, "count_artist_followers", AsyncMock(return_value=0))
    monkeypatch.setattr(artist, "is_artist_followed", AsyncMock(return_value=False))
    monkeypatch.setattr(artist, "increment_stat", AsyncMock())

    await artist.artist_callback(update, context)

    query.answer.assert_awaited_once()
    query.edit_message_text.assert_awaited_once()
    assert "خوانندهٔ نمونه" in query.edit_message_text.await_args.args[0]
    assert query.edit_message_text.await_args.kwargs["reply_markup"].inline_keyboard


@pytest.mark.asyncio
async def test_artist_search_empty_state_offers_alphabetical_list(monkeypatch):
    message = SimpleNamespace(text="نام ناشناس", reply_text=AsyncMock())
    update = SimpleNamespace(message=message)
    context = SimpleNamespace(user_data={})
    monkeypatch.setattr(artist.api, "artists", AsyncMock(return_value={"results": []}))

    await artist.artist_text_search(update, context)

    message.reply_text.assert_awaited_once()
    args, kwargs = message.reply_text.await_args
    assert "پیدا نشد" in args[0]
    markup = kwargs["reply_markup"]
    callbacks = {
        button.callback_data
        for row in markup.inline_keyboard
        for button in row
        if button.callback_data
    }
    assert "artists:page:1" in callbacks
    assert "home" in callbacks


@pytest.mark.asyncio
async def test_unrelated_inline_action_cancels_pending_artist_search(monkeypatch):
    user = SimpleNamespace(id=12345, username="u", first_name="User", last_name="")
    update = SimpleNamespace(
        effective_user=user,
        effective_chat=SimpleNamespace(type="private"),
        callback_query=SimpleNamespace(data="home"),
        effective_message=None,
    )
    context = SimpleNamespace(user_data={"search_mode": "artist", "search_mode_expires_at": 9999999999})
    monkeypatch.setattr(main, "is_blocked", AsyncMock(return_value=False))
    monkeypatch.setattr(main, "check_rate_limit", AsyncMock(return_value=True))
    monkeypatch.setattr(main, "upsert_user", AsyncMock())

    await main.rate_limit_middleware(update, context)

    assert "search_mode" not in context.user_data
    assert "search_mode_expires_at" not in context.user_data
