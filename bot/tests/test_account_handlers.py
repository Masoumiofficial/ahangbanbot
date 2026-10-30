"""Offline tests for secure account-linking and account command responses."""
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

import pytest

from bot.api_client import APIError
from bot.handlers.account import (
    account_link_code_handler,
    account_me_handler,
    account_saved_handler,
)


def make_update(text="", *, chat_type="private", user_id=12345):
    message = SimpleNamespace(text=text, reply_text=AsyncMock())
    user = SimpleNamespace(id=user_id, username="test_user", first_name="Test")
    chat = SimpleNamespace(type=chat_type)
    update = SimpleNamespace(effective_message=message, effective_user=user, effective_chat=chat)
    return update, message


@pytest.mark.asyncio
async def test_plain_text_link_code_links_unlinked_account():
    update, message = make_update(" abcd2345 ")
    summary = {
        "name": "شنونده",
        "url": "https://example.com/account/",
        "counts": {"likes": 2, "playlists": 1, "follows": 3, "tracks": 4, "history": 5, "unread": 0},
    }
    unlinked = APIError(404, "not linked", code="tara_unlinked")

    with patch("bot.handlers.account.api.account_me", new=AsyncMock(side_effect=[unlinked, summary])) as account_me, patch(
        "bot.handlers.account.api.account_link", new=AsyncMock(return_value={"ok": True, "user": summary})
    ) as account_link:
        await account_link_code_handler(update, SimpleNamespace())

    account_link.assert_awaited_once_with(
        code="ABCD2345",
        telegram_id=12345,
        username="test_user",
        first_name="Test",
    )
    assert account_me.await_count == 2
    reply = message.reply_text.await_args.kwargs
    assert "حساب سایت به تلگرام وصل شد" in message.reply_text.await_args.args[0]
    assert "ABCD2345" not in message.reply_text.await_args.args[0]
    assert reply["parse_mode"] == "HTML"


@pytest.mark.asyncio
async def test_invalid_plain_text_is_not_consumed_as_a_link_code():
    update, message = make_update("not-a-code")
    with patch("bot.handlers.account.api.account_link", new=AsyncMock()) as account_link, patch(
        "bot.handlers.account.api.account_me", new=AsyncMock()
    ) as account_me:
        await account_link_code_handler(update, SimpleNamespace())

    account_link.assert_not_awaited()
    account_me.assert_not_awaited()
    message.reply_text.assert_not_awaited()


@pytest.mark.asyncio
async def test_link_code_in_group_is_rejected_without_calling_wordpress():
    update, message = make_update("ABCD2345", chat_type="group")
    with patch("bot.handlers.account.api.account_link", new=AsyncMock()) as account_link, patch(
        "bot.handlers.account.api.account_me", new=AsyncMock()
    ) as account_me:
        await account_link_code_handler(update, SimpleNamespace())

    account_link.assert_not_awaited()
    account_me.assert_not_awaited()
    assert "گفتگوی خصوصی" in message.reply_text.await_args.args[0]


@pytest.mark.asyncio
async def test_existing_link_is_not_replaced_by_a_new_code():
    update, message = make_update("ABCD2345")
    current = {"name": "حساب فعلی", "counts": {"likes": 7}}
    with patch("bot.handlers.account.api.account_me", new=AsyncMock(return_value=current)), patch(
        "bot.handlers.account.api.account_link", new=AsyncMock()
    ) as account_link:
        await account_link_code_handler(update, SimpleNamespace())

    account_link.assert_not_awaited()
    assert "قبلاً به حساب سایت وصل است" in message.reply_text.await_args.args[0]


@pytest.mark.asyncio
async def test_authentication_error_is_generic_and_never_echoes_secret():
    update, message = make_update()
    secret = "a" * 64
    with patch(
        "bot.handlers.account.api.account_me",
        new=AsyncMock(side_effect=APIError(403, f"rejected token {secret}", code="auth_error")),
    ):
        await account_me_handler(update, SimpleNamespace())

    response = message.reply_text.await_args.args[0]
    assert secret not in response
    assert "سرویس حساب سایت" in response


@pytest.mark.asyncio
async def test_saved_command_shows_site_count_without_fabricating_a_song_list():
    update, message = make_update()
    summary = {"counts": {"likes": 9}}
    with patch("bot.handlers.account.api.account_me", new=AsyncMock(return_value=summary)):
        await account_saved_handler(update, SimpleNamespace())

    response = message.reply_text.await_args.args[0]
    assert "<b>9</b>" in response
    assert "فهرست کامل" in response
    assert "آهنگ آزمایشی" not in response
