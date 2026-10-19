import html
import time
from telegram import Update
from telegram.ext import ContextTypes
from ..config import is_admin
from ..db import is_blocked
from ..utils.formatters import welcome_text, help_text
from ..utils.keyboards import main_inline

async def start_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user
    if not user:
        return
    context.user_data.pop("search_mode", None)
    context.user_data.pop("search_mode_expires_at", None)
    if await is_blocked(user.id):
        await update.message.reply_text("⛔ شما مسدود شده‌اید. برای پشتیبانی پیام دهید: @ahangban_support")
        return
    # Channel posts can deep-link directly to a real, published WordPress item.
    if context.args and len(context.args) == 1:
        import re
        match = re.fullmatch(r"song_([1-9][0-9]*)", context.args[0])
        if match:
            from .song import _show_song
            await _show_song(update, context, int(match.group(1)), edit=False)
            return

    name = user.first_name or ""
    admin = is_admin(user.id)
    # Keep /start to one polished message; the previous two-message menu was redundant.
    await update.message.reply_text(
        welcome_text(name),
        parse_mode="HTML",
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
        if query.message and any(getattr(query.message, kind, None) for kind in ("photo", "video", "audio", "document")):
            await query.edit_message_caption(caption=text, parse_mode="HTML", reply_markup=markup)
        else:
            await query.edit_message_text(text, parse_mode="HTML", reply_markup=markup)
    except Exception:
        if query.message:
            await query.message.reply_text(text, parse_mode="HTML", reply_markup=markup)


async def search_prompt_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    if query.message:
        context.user_data.pop("search_mode", None)
        context.user_data.pop("search_mode_expires_at", None)
        await query.message.reply_text("🎵 نام آهنگ یا خواننده را بفرستید؛ مثلاً: محسن یگانه — بی‌تاب")

async def artist_search_prompt_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    context.user_data["search_mode"] = "artist"
    context.user_data["search_mode_expires_at"] = time.time() + 600
    if query.message:
        await query.message.reply_text("🎤 نام خواننده را بفرستید؛ مثلاً: معین یا گوگوش")

async def noop_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.callback_query.answer("—")
