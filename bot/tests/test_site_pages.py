from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from bot.handlers import artist, site_pages
from bot.utils.formatters import artist_biography_text
from bot.utils.keyboards import artist_card, main_inline


@pytest.mark.asyncio
async def test_news_command_links_to_the_official_news_page():
    message = SimpleNamespace(reply_text=AsyncMock())
    update = SimpleNamespace(effective_message=message)

    await site_pages.news_handler(update, SimpleNamespace())

    message.reply_text.assert_awaited_once()
    text = message.reply_text.await_args.args[0]
    markup = message.reply_text.await_args.kwargs["reply_markup"]
    assert "اخبار آهنگ‌بان" in text
    assert markup.inline_keyboard[0][0].url == "https://ahangban.ir/news/"


def test_main_menu_has_news_and_biography_page_links():
    buttons = [button for row in main_inline().inline_keyboard for button in row]
    urls = {button.text: button.url for button in buttons if button.url}

    assert urls["📰 اخبار"] == "https://ahangban.ir/news/"
    assert urls["📖 بیوگرافی هنرمندان"] == "https://ahangban.ir/biography/"


def test_artist_card_has_full_biography_callback_and_site_link():
    buttons = [
        button
        for row in artist_card({"term_id": 9921, "link": "https://ahangban.ir/artist/example/"}, 2, 4, "old").inline_keyboard
        for button in row
    ]

    bio_button = next(button for button in buttons if button.callback_data == "bio:9921:2:old")
    site_button = next(button for button in buttons if button.url)
    assert bio_button.text == "📖 بیوگرافی کامل"
    assert site_button.url == "https://ahangban.ir/artist/example/"


def test_biography_formatter_strips_wordpress_html_and_escapes_text():
    result = artist_biography_text(
        {"name": "خواننده <نمونه>", "description": "<p>بند اول &amp; <b>متن</b></p><p>بند دوم</p>"}
    )

    assert result == "📖 <b>بیوگرافی خواننده &lt;نمونه&gt;</b>\n\nبند اول &amp; متن\n\nبند دوم"


@pytest.mark.asyncio
async def test_biography_command_without_name_opens_site_index():
    message = SimpleNamespace(reply_text=AsyncMock())
    update = SimpleNamespace(effective_message=message)

    await artist.artist_biography_handler(update, SimpleNamespace(args=[]))

    kwargs = message.reply_text.await_args.kwargs
    buttons = [button for row in kwargs["reply_markup"].inline_keyboard for button in row]
    assert any(button.url == "https://ahangban.ir/biography/" for button in buttons)
    assert "نام او را بعد از /biography" in message.reply_text.await_args.args[0]


@pytest.mark.asyncio
async def test_named_biography_command_uses_real_artist_endpoint(monkeypatch):
    message = SimpleNamespace(reply_text=AsyncMock())
    update = SimpleNamespace(effective_message=message)
    request = AsyncMock(
        return_value={
            "term": {
                "term_id": 91,
                "name": "هنرمند نمونه",
                "description": "<p>زندگی‌نامهٔ واقعی</p>",
                "link": "https://ahangban.ir/artist/sample/",
            }
        }
    )
    monkeypatch.setattr(artist.api, "artist", request)

    await artist.artist_biography_handler(update, SimpleNamespace(args=["هنرمند", "نمونه"]))

    request.assert_awaited_once_with("هنرمند نمونه", page=1, per_page=1, sort="new")
    text, kwargs = message.reply_text.await_args.args[0], message.reply_text.await_args.kwargs
    assert "زندگی‌نامهٔ واقعی" in text
    assert kwargs["parse_mode"] == "HTML"
    buttons = [button for row in kwargs["reply_markup"].inline_keyboard for button in row]
    assert any(button.url == "https://ahangban.ir/artist/sample/" for button in buttons)


@pytest.mark.asyncio
async def test_biography_callback_displays_wordpress_term_description(monkeypatch):
    message = SimpleNamespace(photo=None, reply_text=AsyncMock())
    query = SimpleNamespace(
        data="bio:91:2:old",
        message=message,
        answer=AsyncMock(),
        edit_message_text=AsyncMock(),
    )
    update = SimpleNamespace(callback_query=query, effective_message=message)
    request = AsyncMock(
        return_value={
            "term": {
                "term_id": 91,
                "name": "هنرمند نمونه",
                "description": "<p>متن کامل بیوگرافی</p>",
                "link": "https://ahangban.ir/artist/sample/",
            }
        }
    )
    monkeypatch.setattr(artist.api, "artist", request)

    await artist.artist_biography_callback(update, SimpleNamespace())

    query.answer.assert_awaited_once_with()
    request.assert_awaited_once_with("91", page=1, per_page=1, sort="new")
    text, kwargs = query.edit_message_text.await_args.args[0], query.edit_message_text.await_args.kwargs
    assert "متن کامل بیوگرافی" in text
    buttons = [button for row in kwargs["reply_markup"].inline_keyboard for button in row]
    assert any(button.callback_data == "artist:91:2:old" for button in buttons)
    assert any(button.url == "https://ahangban.ir/artist/sample/" for button in buttons)
