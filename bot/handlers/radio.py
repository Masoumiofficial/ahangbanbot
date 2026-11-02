"""Public radio shortcut and an admin-only channel link publisher."""

import logging

from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from ..api_client import APIError, api
from ..config import RADIO_PAGE_URL, is_admin

log = logging.getLogger("ahangban.radio")


def radio_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        [[InlineKeyboardButton("▶️ شنیدن رادیوی آنلاین", url=RADIO_PAGE_URL)]]
    )


async def radio_handler(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Send the official web radio link; the bot cannot play a continuous stream in chat."""
    message = update.effective_message
    if not message:
        return
    await message.reply_text(
        "📻 <b>رادیوی آنلاین آهنگ‌بان</b>\n\n"
        "برای شنیدن پخش زنده، صفحهٔ رادیو را باز کنید. اگر پخش آغاز نشد، دکمهٔ پخش را در صفحه بزنید.",
        parse_mode=ParseMode.HTML,
        reply_markup=radio_keyboard(),
        disable_web_page_preview=True,
    )


async def radio_post_handler(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Ask the authenticated WordPress connector to post the radio link to its configured channel."""
    message = update.effective_message
    user = update.effective_user
    if not message:
        return
    if not user or not is_admin(user.id):
        await message.reply_text("⛔ این دستور فقط برای ادمین مجاز است.")
        return

    try:
        result = await api.publish_radio_link()
    except APIError as exc:
        log.warning("radio link publish failed (API status=%s)", exc.status)
        if exc.status == 0 or exc.status >= 500:
            await message.reply_text(
                "⚠️ نتیجهٔ ارسال نامشخص است؛ کانال را بررسی کنید و برای جلوگیری از پیام تکراری /radio_post را دوباره اجرا نکنید."
            )
        else:
            await message.reply_text(
                "❌ لینک رادیو در کانال ارسال نشد. افزونهٔ WordPress v1.6.7، اتصال API و تنظیم کانال را بررسی کنید."
            )
        return
    except Exception as exc:
        log.warning("radio link publish failed (%s)", type(exc).__name__)
        await message.reply_text("❌ ارسال لینک رادیو ناموفق بود؛ بعداً دوباره تلاش کنید.")
        return

    try:
        message_id = int(result.get("message_id") or 0)
    except (TypeError, ValueError):
        message_id = 0
    if result.get("status") != "sent" or message_id <= 0:
        await message.reply_text(
            "⚠️ پاسخ سایت ارسال لینک رادیو را تأیید نکرد؛ کانال را بررسی کنید و از ارسال تکراری خودداری کنید."
        )
        return

    await message.reply_text(
        "✅ پیوند رادیوی آنلاین در کانال تنظیم‌شدهٔ WordPress منتشر شد. "
        "برای دسترسی همیشگی، این پیام را از تلگرام سنجاق کنید."
    )
