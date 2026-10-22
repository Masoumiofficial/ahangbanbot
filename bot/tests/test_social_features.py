from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

import pytest

from bot import db
from bot.handlers.admin import admin_users_callback
from bot.handlers.artist import _send_artist_follows_page, artist_follow_callback
from bot.handlers.song import _send_favorites_page, favorite_callback
from bot.utils.keyboards import artist_card, main_inline, song_card


@pytest.mark.asyncio
async def test_users_page_reads_numeric_ids_and_block_state_from_sqlite(monkeypatch, tmp_path):
    monkeypatch.setattr(db, "DB_PATH", tmp_path / "bot.db")
    await db.init_db()
    await db.upsert_user(123456789, username="sample_user", first_name="نام", last_name="کاربر")
    await db.set_blocked(123456789, True)

    page = await db.list_users_page(page=1, per_page=10)
    assert page["total"] == 1
    assert page["results"][0]["user_id"] == 123456789
    assert page["results"][0]["blocked"] == 1
    assert page["results"][0]["username"] == "sample_user"


@pytest.mark.asyncio
async def test_song_like_toggle_count_and_pagination(monkeypatch, tmp_path):
    monkeypatch.setattr(db, "DB_PATH", tmp_path / "bot.db")
    await db.init_db()

    liked, count = await db.toggle_favorite(101, 731, "آهنگ آزمایشی", "هنرمند")
    assert (liked, count) == (True, 1)
    assert await db.favorite_state(101, 731) == (True, 1)

    liked, count = await db.toggle_favorite(202, 731, "آهنگ آزمایشی", "هنرمند")
    assert (liked, count) == (True, 2)
    assert await db.favorite_state(101, 731) == (True, 2)

    page = await db.list_favorites_page(101, page=1, per_page=8)
    assert page["total"] == 1
    assert page["results"] == [{"song_id": 731, "song_title": "آهنگ آزمایشی", "artist": "هنرمند"}]

    liked, count = await db.toggle_favorite(101, 731, "آهنگ آزمایشی", "هنرمند")
    assert (liked, count) == (False, 1)
    assert await db.favorite_state(101, 731) == (False, 1)


@pytest.mark.asyncio
async def test_artist_follow_uses_real_term_id_and_can_be_removed(monkeypatch, tmp_path):
    monkeypatch.setattr(db, "DB_PATH", tmp_path / "bot.db")
    await db.init_db()

    followed, count = await db.toggle_artist_follow(101, 9921, "خوانندهٔ واقعی")
    assert (followed, count) == (True, 1)
    assert await db.is_artist_followed(101, 9921)
    assert await db.count_artist_followers(9921) == 1
    assert await db.list_artist_follows_page(101) == {
        "results": [{"term_id": 9921, "artist_name": "خوانندهٔ واقعی"}],
        "total": 1,
        "page": 1,
        "per_page": 8,
    }

    followed, count = await db.toggle_artist_follow(202, 9921, "خوانندهٔ واقعی")
    assert (followed, count) == (True, 2)
    assert await db.remove_artist_follow(101, 9921)
    assert not await db.is_artist_followed(101, 9921)
    assert await db.count_artist_followers(9921) == 1


def test_song_and_artist_cards_expose_persistent_preference_callbacks():
    song_callbacks = {
        button.callback_data: button.text
        for row in song_card({"id": 731, "song": "نمونه"}, is_favorite=True, like_count=7).inline_keyboard
        for button in row
        if button.callback_data
    }
    assert "favorite:731" in song_callbacks
    assert "❤️ پسندیدم" in song_callbacks["favorite:731"]
    assert "7" in song_callbacks["favorite:731"]

    artist_callbacks = {
        button.callback_data: button.text
        for row in artist_card(
            {"term_id": 9921, "slug": "artist"},
            page=2,
            total_pages=4,
            sort="old",
            is_followed=True,
            follower_count=3,
        ).inline_keyboard
        for button in row
        if button.callback_data
    }
    assert "artist_follow:9921:2:old" in artist_callbacks
    assert "دنبال‌شده" in artist_callbacks["artist_follow:9921:2:old"]
    main_callbacks = {
        button.callback_data
        for row in main_inline().inline_keyboard
        for button in row
        if button.callback_data
    }
    assert "favorites:page:1" in main_callbacks
    assert "artist_follows:page:1" in main_callbacks


@pytest.mark.asyncio
async def test_favorite_callback_toggles_and_refreshes_like_count():
    class Query:
        data = "favorite:731"

        def __init__(self):
            self.answers = []
            self.edits = []
            self.message = SimpleNamespace(
                reply_markup=song_card({"id": 731}, is_favorite=False, like_count=0),
                reply_text=AsyncMock(),
            )

        async def answer(self, text="", show_alert=False):
            self.answers.append((text, show_alert))

        async def edit_message_reply_markup(self, **kwargs):
            self.edits.append(kwargs["reply_markup"])

    query = Query()
    update = SimpleNamespace(callback_query=query, effective_user=SimpleNamespace(id=101))
    context = SimpleNamespace(user_data={"song_metadata": {"731": {"title": "آهنگ", "artist": "خواننده"}}})
    with patch("bot.handlers.song.toggle_favorite", new=AsyncMock(return_value=(True, 1))) as toggle:
        await favorite_callback(update, context)
    toggle.assert_awaited_once_with(101, 731, "آهنگ", "خواننده")
    assert query.edits
    button = next(
        button
        for row in query.edits[-1].inline_keyboard
        for button in row
        if button.callback_data == "favorite:731"
    )
    assert "❤️ پسندیدم" in button.text
    assert "1" in button.text


@pytest.mark.asyncio
async def test_artist_follow_callback_toggles_and_refreshes_button():
    class Query:
        data = "artist_follow:9921:2:old"

        def __init__(self):
            self.answers = []
            self.edits = []
            self.message = SimpleNamespace(
                reply_markup=artist_card(
                    {"term_id": 9921}, page=2, total_pages=4, sort="old", follower_count=0
                ),
                reply_text=AsyncMock(),
            )

        async def answer(self, text="", show_alert=False):
            self.answers.append((text, show_alert))

        async def edit_message_reply_markup(self, **kwargs):
            self.edits.append(kwargs["reply_markup"])

    query = Query()
    update = SimpleNamespace(callback_query=query, effective_user=SimpleNamespace(id=101))
    context = SimpleNamespace(user_data={"artist_names": {"9921": "خوانندهٔ واقعی"}})
    with patch("bot.handlers.artist.toggle_artist_follow", new=AsyncMock(return_value=(True, 2))) as toggle:
        await artist_follow_callback(update, context)
    toggle.assert_awaited_once_with(101, 9921, "خوانندهٔ واقعی")
    assert query.edits
    button = next(
        button
        for row in query.edits[-1].inline_keyboard
        for button in row
        if button.callback_data == "artist_follow:9921:2:old"
    )
    assert "دنبال‌شده" in button.text
    assert "2" in button.text


@pytest.mark.asyncio
async def test_favorites_list_uses_current_wordpress_song_data_not_sqlite_snapshot():
    target = SimpleNamespace(reply_text=AsyncMock())
    rows = {
        "results": [{"song_id": 731, "song_title": "عنوان قدیمی", "artist": "خوانندهٔ قدیمی"}],
        "total": 1,
        "page": 1,
        "per_page": 8,
    }
    with patch("bot.handlers.song.list_favorites_page", new=AsyncMock(return_value=rows)), patch(
        "bot.handlers.song.api.song",
        new=AsyncMock(return_value={"id": 731, "song": "عنوان تازه", "artist": "خوانندهٔ تازه"}),
    ) as load_song:
        await _send_favorites_page(target, 101)
    load_song.assert_awaited_once_with(731)
    text = target.reply_text.await_args.args[0]
    assert "عنوان تازه" in text and "خوانندهٔ تازه" in text
    assert "عنوان قدیمی" not in text


@pytest.mark.asyncio
async def test_followed_artists_list_uses_current_wordpress_term_data():
    target = SimpleNamespace(reply_text=AsyncMock())
    rows = {
        "results": [{"term_id": 9921, "artist_name": "نام ذخیره‌شدهٔ قدیمی"}],
        "total": 1,
        "page": 1,
        "per_page": 8,
    }
    with patch("bot.handlers.artist.list_artist_follows_page", new=AsyncMock(return_value=rows)), patch(
        "bot.handlers.artist.api.artist",
        new=AsyncMock(return_value={"term": {"term_id": 9921, "name": "نام زنده از WordPress"}}),
    ) as load_artist:
        await _send_artist_follows_page(target, 101)
    load_artist.assert_awaited_once_with(9921, page=1, per_page=1, sort="new")
    text = target.reply_text.await_args.args[0]
    assert "نام زنده از WordPress" in text
    assert "نام ذخیره‌شدهٔ قدیمی" not in text


@pytest.mark.asyncio
async def test_admin_users_page_shows_numeric_id_and_blocked_state():
    class Query:
        data = "admin:users:page:1"
        message = SimpleNamespace(photo=None, video=None, audio=None, document=None)

        def __init__(self):
            self.answers = []
            self.edits = []

        async def answer(self, text="", show_alert=False):
            self.answers.append((text, show_alert))

        async def edit_message_text(self, text, **kwargs):
            self.edits.append((text, kwargs))

    query = Query()
    update = SimpleNamespace(callback_query=query, effective_user=SimpleNamespace(id=900))
    context = SimpleNamespace(user_data={})
    page_data = {
        "results": [{
            "user_id": 123456789,
            "username": "sample_user",
            "first_name": "نام",
            "last_name": "کاربر",
            "blocked": 1,
            "is_admin": 0,
            "last_seen": 1700000000,
        }],
        "total": 1,
        "page": 1,
        "per_page": 10,
    }
    with patch("bot.handlers.admin.is_admin", return_value=True), patch(
        "bot.handlers.admin.list_users_page", new=AsyncMock(return_value=page_data)
    ) as list_users:
        await admin_users_callback(update, context)

    list_users.assert_awaited_once_with(page=1, per_page=10)
    text, markup = query.edits[-1]
    assert "123456789" in text
    assert "@sample_user" in text
    assert "مسدود" in text
    assert "/block 123456789" in text
    assert "/unblock 123456789" in text
    assert markup["parse_mode"] == "HTML"
