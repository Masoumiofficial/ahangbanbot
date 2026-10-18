import time
import logging
from telegram import Update
from telegram.ext import ContextTypes
from telegram.constants import ParseMode

from ..config import is_admin
from ..api_client import api
from ..db import count_users, get_stats, set_blocked

log = logging.getLogger("ahangban.admin")

def _admin_only(func):
    async def wrapper(update: Update, context: ContextTypes.DEFAULT_TYPE):
        user = update.effective_user
        if not user or not is_admin(user.id):
            await update.message.reply_text("⛔ این دستور فقط برای ادمین مجاز است.")
            return
        return await func(update, context)
    return wrapper

@_admin_only
async def stats_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    users = await count_users()
    stats = await get_stats()
    try:
        health = await api.health()
        health_line = f"✅ WP {health.get('wp')} | PHP {health.get('php')} | آثار: {health.get('post_count')} | singer: {'فعال' if health.get('taxonomy_singer') else 'غیرفعال'}"
    except Exception as e:
        health_line = f"❌ خطا در health: {e}"

    text = (
        "📊 <b>آمار ربات آهنگ‌بان</b>\n\n"
        f"👥 کاربران کل: {users['total']}\n"
        f"🟢 فعال ۷ روز: {users['active_7d']}\n"
        f"🔍 جست‌وجوها: {stats['searches_total']}\n"
        f"🎵 بازدید آهنگ: {stats['song_views_total']}\n"
        f"🎤 بازدید خواننده: {stats['artist_views_total']}\n\n"
        f"{health_line}\n\n"
        f"🕐 زمان: {time.strftime('%Y-%m-%d %H:%M:%S')}"
    )
    await update.message.reply_text(text, parse_mode=ParseMode.HTML)

@_admin_only
async def health_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    try:
        h = await api.health()
        await update.message.reply_text(f"✅ سلامت:\n<pre>{h}</pre>", parse_mode=ParseMode.HTML)
    except Exception as e:
        await update.message.reply_text(f"❌ {e}")

@_admin_only
async def cache_clear_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    try:
        await api.cache_clear()
        api.invalidate()
        await update.message.reply_text("✅ کش وردپرس و کش ربات پاک شد.")
    except Exception as e:
        await update.message.reply_text(f"❌ {e}")

@_admin_only
async def block_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if not context.args:
        await update.message.reply_text("استفاده: /block <user_id>")
        return
    try:
        uid = int(context.args[0])
    except ValueError:
        await update.message.reply_text("شناسه عددی وارد کنید.")
        return
    await set_blocked(uid, True)
    await update.message.reply_text(f"⛔ کاربر {uid} مسدود شد.")

@_admin_only
async def unblock_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if not context.args:
        await update.message.reply_text("استفاده: /unblock <user_id>")
        return
    try:
        uid = int(context.args[0])
    except ValueError:
        await update.message.reply_text("شناسه عددی وارد کنید.")
        return
    await set_blocked(uid, False)
    await update.message.reply_text(f"✅ کاربر {uid} آزاد شد.")

@_admin_only
async def broadcast_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    text = " ".join(context.args) if context.args else ""
    if not text:
        await update.message.reply_text("استفاده: /broadcast <متن>\nمتن به همه کاربران ارسال می‌شود (HTML مجاز).")
        return
    # This is a placeholder — real broadcast iterates over users table
    await update.message.reply_text(f"📢 پیش‌نمایش پیام همگانی:\n\n{text}\n\n(برای ارسال واقعی، تأیید کنید: /broadcast_confirm)")
    context.user_data["broadcast_text"] = text

@_admin_only
async def broadcast_confirm_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    text = context.user_data.get("broadcast_text")
    if not text:
        await update.message.reply_text("هیچ پیامی برای ارسال نیست — ابتدا /broadcast را بزنید.")
        return
    import aiosqlite
    from ..db import DB_PATH
    count = 0
    failed = 0
    await update.message.reply_text("⏳ ارسال همگانی آغاز شد…")
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT user_id FROM users WHERE blocked=0") as cur:
            rows = await cur.fetchall()
            for r in rows:
                try:
                    await context.bot.send_message(chat_id=r["user_id"], text=text, parse_mode=ParseMode.HTML)
                    count += 1
                    # Telegram rate limit: ~30 msg/sec — throttle
                    import asyncio
                    await asyncio.sleep(0.05)
                except Exception as e:
                    log.warning("broadcast to %s failed: %s", r["user_id"], e)
                    failed += 1
    await update.message.reply_text(f"✅ ارسال تمام شد — موفق: {count} | ناموفق: {failed}")
    context.user_data.pop("broadcast_text", None)
