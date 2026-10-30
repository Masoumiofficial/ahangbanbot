from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from bot.main import error_handler


@pytest.mark.asyncio
async def test_global_error_handler_logs_stack_without_exception_text_or_secrets(caplog):
    secret = "a" * 64
    try:
        raise ValueError(f"safe test failure; token={secret}")
    except ValueError as exc:
        error = exc

    message = SimpleNamespace(reply_text=AsyncMock())
    update = SimpleNamespace(update_id=123, effective_message=message)
    context = SimpleNamespace(error=error)

    with caplog.at_level("ERROR", logger="ahangban"):
        await error_handler(update, context)

    assert "update_id=123" in caplog.text
    assert "ValueError" in caplog.text
    assert "safe test failure" not in caplog.text
    assert secret not in caplog.text
    assert all(not record.exc_info for record in caplog.records)
    message.reply_text.assert_awaited_once_with(
        "❌ خطای غیرمنتظره — لطفاً دوباره تلاش کنید.\nاگر تکرار شد، /ping را امتحان کنید."
    )
