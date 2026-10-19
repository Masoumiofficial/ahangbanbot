import html
from telegram import Update
from telegram.ext import ContextTypes
from ..config import is_admin
from ..db import upsert_user, is_blocked
from ..utils.formatters import welcome_text, help_text
from ..utils.keyboards import main_reply_keyboard, main_inline

async def start_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user
    if not user:
        return
    if await is_blocked(user.id):
        await update.message.reply_text("⛔ شما مسدود شده‌اید. برای پشتیبانی پیام دهید: @ahangban_support")
        return
    if update.effective_chat and update.effective_chat.type == "private":
        await upsert_user(user.id, user.username or "", user.first_name or "", user.last_name or "", is_admin=is_admin(user.id))
    name = user.first_name or ""
    admin = is_admin(user.id)
    await update.message.reply_text(
        welcome_text(name),
        parse_mode="HTML",
        reply_markup=main_reply_keyboard(admin),
    )
    await update.message.reply_text(
        "⬇️ میانبر سریع:",
        reply_markup=main_inline(admin),
    )

async def help_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user
    if user and await is_blocked(user.id):
        return
    await update.message.reply_text(
        help_text(),
        parse_mode="HTML",
        reply_markup=main_inline(is_admin(user.id) if user else False),
    )

async def ping_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    from ..api_client import api
    try:
        data = await api.health()
        await update.message.reply_text(
            f"✅ اتصال به آهنگ‌بان برقرار است\n"
            f"🔧 وردپرس: {data.get('wp')}\n"
            f"🐍 PHP: {data.get('php')}\n"
            f"🎤 تاکسونومی artist: {'فعال' if data.get('taxonomy_artist') else 'غیرفعال'}\n"
            f"🎵 آثار منتشرشده: {data.get('post_count')}\n"
            f"⏰ زمان سرور: {data.get('time')}"
        )
    except Exception as e:
        await update.message.reply_text(
            f"❌ خطا در اتصال به سایت:\n<code>{html.escape(str(e)[:300])}</code>",
            parse_mode="HTML",
        )

async def help_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    user = update.effective_user
    await query.edit_message_text(
        help_text(),
        parse_mode="HTML",
        reply_markup=main_inline(is_admin(user.id) if user else False),
    )

async def home_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    user = update.effective_user
    text = "🏠 <b>منوی اصلی آهنگ‌بان</b>\n\nنام آهنگ یا خواننده را بنویسید یا یکی از گزینه‌ها را انتخاب کنید."
    markup = main_inline(is_admin(user.id) if user else False)
    try:
        await query.edit_message_text(text, parse_mode="HTML", reply_markup=markup)
    except Exception:
        # The home button may belong to a photo/video card, which cannot be edited as text.
        if query.message:
            await query.message.reply_text(text, parse_mode="HTML", reply_markup=markup)


async def search_prompt_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    if query.message:
        await query.message.reply_text("🎵 نام آهنگ یا خواننده را بفرستید؛ مثلاً: محسن یگانه — بی‌تاب")

async def noop_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.callback_query.answer("—")
