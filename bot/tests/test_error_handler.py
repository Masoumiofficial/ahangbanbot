from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from bot.main import error_handler


@pytest.mark.asyncio
async def test_global_error_handler_logs_traceback_without_echoing_update_content(caplog):
    try:
        raise ValueError("safe test failure")
    except ValueError as exc:
        error = exc

    message = SimpleNamespace(reply_text=AsyncMock())
    update = SimpleNamespace(update_id=123, effective_message=message)
    context = SimpleNamespace(error=error)

    with caplog.at_level("ERROR", logger="ahangban"):
        await error_handler(update, context)

    assert any(record.exc_info and record.exc_info[0] is ValueError for record in caplog.records)
    assert "update_id=123" in caplog.text
    message.reply_text.assert_awaited_once_with(
        "❌ خطای غیرمنتظره — لطفاً دوباره تلاش کنید.\nاگر تکرار شد، /ping را امتحان کنید."
    )
