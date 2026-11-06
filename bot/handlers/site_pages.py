"""Simple entry points to public AhangBan website sections."""
from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update
from telegram.ext import ContextTypes

from ..config import NEWS_PAGE_URL


async def news_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Open the official news section without guessing WordPress post schemas."""
    markup = InlineKeyboardMarkup(
        [[InlineKeyboardButton("📰 مشاهدهٔ اخبار آهنگ‌بان", url=NEWS_PAGE_URL)]]
    )
    await update.effective_message.reply_text(
        "📰 اخبار آهنگ‌بان\n\n"
        "برای دیدن خبرهای منتشرشده، بخش رسمی اخبار سایت را باز کنید.",
        reply_markup=markup,
    )
