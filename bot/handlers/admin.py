import asyncio
import html
import logging
import time

import aiosqlite
from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from ..api_client import api
from ..config import is_admin
from ..db import DB_PATH, count_users, get_stats, set_blocked

log = logging.getLogger("ahangban.admin")


def _admin_only(func):
    async def wrapper(update: Update, context: ContextTypes.DEFAULT_TYPE):
        user = update.effective_user
        if not user or not is_admin(user.id):
            if update.effective_message:
                await update.effective_message.reply_text("⛔ این دستور فقط برای ادمین مجاز است.")
            return
        return await func(update, context)
    wrapper.__name__ = func.__name__
    return wrapper


def _admin_markup() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([
        [InlineKeyboardButton("📊 آمار ربات", callback_data="admin:stats"), InlineKeyboardButton("🩺 سلامت سایت", callback_data="admin:health")],
        [InlineKeyboardButton("🧹 پاک‌سازی کش", callback_data="admin:cache_confirm")],
        [InlineKeyboardButton("👥 مدیریت کاربران", callback_data="admin:users"), InlineKeyboardButton("📢 پیام همگانی", callback_data="admin:broadcast_help")],
        [InlineKeyboardButton("🏠 خانه", callback_data="home")],
    ])


def _authorized_callback(update: Update) -> bool:
    user = update.effective_user
    return bool(user and is_admin(user.id))


async def admin_menu_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not _authorized_callback(update):
        await query.answer("⛔ دسترسی ادمین لازم است.", show_alert=True)
        return
    await query.answer()
    text = (
        "🛠 <b>پنل مدیریت آهنگ‌بان</b>\n\n"
        "از گزینه‌ها برای دیدن آمار، بررسی اتصال، پاک‌سازی کش و مدیریت کاربران استفاده کنید.\n"
        "برای مسدود/آزاد کردن شناسهٔ عددی: <code>/block ID</code> یا <code>/unblock ID</code>."
    )
    await query.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=_admin_markup())


@_admin_only
async def stats_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await _send_stats(update.effective_message.reply_text, context)


async def admin_stats_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not _authorized_callback(update):
        await query.answer("⛔ دسترسی ادمین لازم است.", show_alert=True)
        return
    await query.answer()
    users = await count_users()
    stats = await get_stats()
    try:
        health = await api.health()
        health_line = (
            f"✅ WP {html.escape(str(health.get('wp', '—')))} | "
            f"PHP {html.escape(str(health.get('php', '—')))} | "
            f"آثار: {int(health.get('post_count') or 0):,} | "
            f"artist: {'فعال' if health.get('taxonomy_artist') else 'غیرفعال'}"
        )
    except Exception as exc:
        health_line = f"⚠️ Health: {html.escape(str(exc)[:160])}"
    text = (
        "📊 <b>آمار ربات آهنگ‌بان</b>\n\n"
        f"👥 کاربران ثبت‌شده: {int(users['total']):,}\n"
        f"🟢 فعال در ۷ روز: {int(users['active_7d']):,}\n"
        f"🔍 جست‌وجوها: {int(stats['searches_total']):,}\n"
        f"🎵 بازدید آهنگ: {int(stats['song_views_total']):,}\n"
        f"🎤 بازدید خواننده: {int(stats['artist_views_total']):,}\n\n"
        f"{health_line}\n🕐 {time.strftime('%Y-%m-%d %H:%M:%S')}"
    )
    await query.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=_admin_markup())


async def _send_stats(reply, context: ContextTypes.DEFAULT_TYPE):
    users = await count_users()
    stats = await get_stats()
    try:
        health = await api.health()
        health_line = (
            f"✅ WP {html.escape(str(health.get('wp', '—')))} | "
            f"PHP {html.escape(str(health.get('php', '—')))} | "
            f"آثار: {int(health.get('post_count') or 0):,} | "
            f"artist: {'فعال' if health.get('taxonomy_artist') else 'غیرفعال'}"
        )
    except Exception as exc:
        health_line = f"⚠️ Health: {html.escape(str(exc)[:160])}"
    text = (
        "📊 <b>آمار ربات آهنگ‌بان</b>\n\n"
        f"👥 کاربران ثبت‌شده: {int(users['total']):,}\n"
        f"🟢 فعال در ۷ روز: {int(users['active_7d']):,}\n"
        f"🔍 جست‌وجوها: {int(stats['searches_total']):,}\n"
        f"🎵 بازدید آهنگ: {int(stats['song_views_total']):,}\n"
        f"🎤 بازدید خواننده: {int(stats['artist_views_total']):,}\n\n"
        f"{health_line}\n🕐 {time.strftime('%Y-%m-%d %H:%M:%S')}"
    )
    await reply(text, parse_mode=ParseMode.HTML, reply_markup=_admin_markup())


@_admin_only
async def health_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    try:
        health = await api.health()
        text = "✅ سلامت سایت:\n<pre>" + html.escape(str(health)) + "</pre>"
        await update.effective_message.reply_text(text, parse_mode=ParseMode.HTML)
    except Exception as exc:
        await update.effective_message.reply_text("❌ " + html.escape(str(exc)[:300]), parse_mode=ParseMode.HTML)


async def admin_health_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not _authorized_callback(update):
        await query.answer("⛔ دسترسی ادمین لازم است.", show_alert=True)
        return
    await query.answer()
    try:
        health = await api.health()
        text = "✅ سلامت سایت:\n<pre>" + html.escape(str(health)) + "</pre>"
    except Exception as exc:
        text = "❌ خطا در Health: " + html.escape(str(exc)[:300])
    await query.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=_admin_markup())


@_admin_only
async def cache_clear_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    try:
        await api.cache_clear()
        api.invalidate()
        await update.effective_message.reply_text("✅ کش وردپرس و کش محلی ربات پاک شد.")
    except Exception as exc:
        await update.effective_message.reply_text("❌ پاک‌سازی کش ناموفق بود: " + html.escape(str(exc)[:240]), parse_mode=ParseMode.HTML)


async def admin_cache_confirm_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not _authorized_callback(update):
        await query.answer("⛔ دسترسی ادمین لازم است.", show_alert=True)
        return
    await query.answer()
    markup = InlineKeyboardMarkup([[
        InlineKeyboardButton("✅ تأیید پاک‌سازی", callback_data="admin:cache_clear"),
        InlineKeyboardButton("لغو", callback_data="admin:menu"),
    ]])
    await query.edit_message_text("🧹 کش نتایج آهنگ‌بان پاک شود؟ داده‌های اصلی وردپرس تغییر نمی‌کنند.", reply_markup=markup)


async def admin_cache_clear_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not _authorized_callback(update):
        await query.answer("⛔ دسترسی ادمین لازم است.", show_alert=True)
        return
    await query.answer("در حال پاک‌سازی…")
    try:
        await api.cache_clear()
        api.invalidate()
        await query.edit_message_text("✅ کش افزونه و ربات پاک شد.", reply_markup=_admin_markup())
    except Exception as exc:
        await query.edit_message_text("❌ خطا در پاک‌سازی: " + html.escape(str(exc)[:240]), parse_mode=ParseMode.HTML, reply_markup=_admin_markup())


async def admin_users_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not _authorized_callback(update):
        await query.answer("⛔ دسترسی ادمین لازم است.", show_alert=True)
        return
    await query.answer()
    text = (
        "👥 <b>مدیریت کاربران</b>\n\n"
        "مسدودکردن شناسه: <code>/block 123456789</code>\n"
        "رفع مسدودی: <code>/unblock 123456789</code>\n\n"
        "شناسه باید عددی باشد. کاربران مسدودشده از ربات و پیام‌های همگانی محروم می‌شوند."
    )
    await query.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=_admin_markup())


async def admin_broadcast_help_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not _authorized_callback(update):
        await query.answer("⛔ دسترسی ادمین لازم است.", show_alert=True)
        return
    await query.answer()
    await query.edit_message_text(
        "📢 برای ساخت پیش‌نمایش بفرستید:\n<code>/broadcast متن پیام</code>\n\n"
        "ارسال عمومی فقط پس از تأیید دکمه انجام می‌شود و به کاربران مسدودشده فرستاده نمی‌شود.",
        parse_mode=ParseMode.HTML,
        reply_markup=_admin_markup(),
    )


@_admin_only
async def block_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if not context.args or not context.args[0].isdigit() or int(context.args[0]) <= 0:
        await update.effective_message.reply_text("استفاده: /block <شناسهٔ عددی کاربر>")
        return
    user_id = int(context.args[0])
    if is_admin(user_id):
        await update.effective_message.reply_text("⚠️ ادمین را نمی‌توان مسدود کرد.")
        return
    await set_blocked(user_id, True)
    await update.effective_message.reply_text(f"⛔ کاربر {user_id} مسدود شد.", reply_markup=_admin_markup())


@_admin_only
async def unblock_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if not context.args or not context.args[0].isdigit() or int(context.args[0]) <= 0:
        await update.effective_message.reply_text("استفاده: /unblock <شناسهٔ عددی کاربر>")
        return
    user_id = int(context.args[0])
    await set_blocked(user_id, False)
    await update.effective_message.reply_text(f"✅ کاربر {user_id} آزاد شد.", reply_markup=_admin_markup())


async def broadcast_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user
    if not user or not is_admin(user.id):
        await update.effective_message.reply_text("⛔ این دستور فقط برای ادمین مجاز است.")
        return
    text = " ".join(context.args) if context.args else ""
    if not text:
        await update.effective_message.reply_text("استفاده: /broadcast <متن>\nپیش‌نمایش ارسال می‌شود و نیاز به تأیید دارد.")
        return
    text = text[:3200]
    context.user_data["broadcast_text"] = text
    markup = InlineKeyboardMarkup([[
        InlineKeyboardButton("📢 تأیید ارسال", callback_data="admin:broadcast_confirm"),
        InlineKeyboardButton("لغو", callback_data="admin:broadcast_cancel"),
    ]])
    await update.effective_message.reply_text(
        "📢 پیش‌نمایش پیام همگانی:\n\n" + text + "\n\nبا دکمهٔ زیر تأیید کنید.",
        reply_markup=markup,
    )


async def _send_broadcast(context: ContextTypes.DEFAULT_TYPE, admin_chat_id: int, text: str):
    sent = 0
    failed = 0
    await context.bot.send_message(chat_id=admin_chat_id, text="⏳ ارسال همگانی شروع شد…")
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT user_id FROM users WHERE blocked=0 ORDER BY user_id") as cursor:
            async for row in cursor:
                try:
                    await context.bot.send_message(chat_id=row["user_id"], text=text, disable_web_page_preview=True)
                    sent += 1
                    await asyncio.sleep(0.05)
                except Exception as exc:
                    log.warning("broadcast to %s failed: %s", row["user_id"], exc)
                    failed += 1
    await context.bot.send_message(chat_id=admin_chat_id, text=f"✅ ارسال پایان یافت — موفق: {sent} | ناموفق: {failed}")


@_admin_only
async def broadcast_confirm_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    text = context.user_data.get("broadcast_text")
    if not text:
        await update.effective_message.reply_text("پیش‌نمایش منقضی شده — ابتدا /broadcast را بزنید.")
        return
    context.user_data.pop("broadcast_text", None)
    await _send_broadcast(context, update.effective_chat.id, text)


async def admin_broadcast_confirm_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    user = update.effective_user
    if not user or not is_admin(user.id):
        await query.answer("⛔ دسترسی ادمین لازم است.", show_alert=True)
        return
    text = context.user_data.pop("broadcast_text", None)
    if not text:
        await query.answer("پیش‌نمایش منقضی شده است.", show_alert=True)
        return
    await query.answer("ارسال تأیید شد.")
    await query.edit_message_text("📢 ارسال تأیید شد؛ پیام به کاربران ثبت‌شده فرستاده می‌شود.")
    await _send_broadcast(context, update.effective_chat.id, text)


async def admin_broadcast_cancel_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if not _authorized_callback(update):
        await query.answer("⛔ دسترسی ادمین لازم است.", show_alert=True)
        return
    context.user_data.pop("broadcast_text", None)
    await query.answer("ارسال لغو شد.")
    await query.edit_message_text("✅ پیام همگانی لغو شد.", reply_markup=_admin_markup())
