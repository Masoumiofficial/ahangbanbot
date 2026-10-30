"""Offline checks that account commands and plain-text linking are registered."""
from unittest.mock import patch

from telegram.ext import CommandHandler, MessageHandler

from bot.handlers.account import (
    account_feed_handler,
    account_follows_handler,
    account_link_code_handler,
    account_me_handler,
    account_saved_handler,
    account_unlink_handler,
)
from bot.handlers.browse import latest_handler
from bot.main import build_app
from bot.handlers.search import search_text_handler


def test_account_commands_and_plain_text_link_handler_are_registered_in_order():
    with patch("bot.main.validate", return_value=[]), patch(
        "bot.main.BOT_TOKEN", "123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ"
    ):
        app = build_app()

    commands = {
        next(iter(handler.commands)): handler.callback
        for handler in app.handlers[0]
        if isinstance(handler, CommandHandler)
    }
    assert commands["me"] is account_me_handler
    assert commands["saved"] is account_saved_handler
    assert commands["follows"] is account_follows_handler
    assert commands["latest"] is account_feed_handler
    assert commands["feed"] is account_feed_handler
    assert commands["unlink"] is account_unlink_handler
    assert commands["new"] is latest_handler

    text_handlers = [handler for handler in app.handlers[0] if isinstance(handler, MessageHandler)]
    assert len(text_handlers) == 2
    assert text_handlers[0].callback is account_link_code_handler
    assert text_handlers[1].callback is search_text_handler
