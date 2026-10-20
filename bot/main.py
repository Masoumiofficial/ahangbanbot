"""
نقطهٔ ورود ربات آهنگ‌بان — polling async
"""
import asyncio
import logging
import signal
import time
from telegram import Update
from telegram.ext import (
    Application,
    CommandHandler,
    MessageHandler,
    CallbackQueryHandler,
    filters,
    TypeHandler,
    ApplicationHandlerStop,
)

from .config import BOT_TOKEN, LOG_LEVEL, validate, RATE_LIMIT_PER_MINUTE, is_admin
from .db import init_db, is_blocked, check_rate_limit, upsert_user
from .handlers.start import start_handler, help_handler, ping_handler, help_callback, home_callback, noop_callback, search_prompt_callback, artist_search_prompt_callback
from .handlers.search import search_text_handler, search_command, search_pagination_callback
from .handlers.song import song_callback, download_callback, lyrics_callback, related_callback, album_callback, song_command
from .handlers.artist import artists_list_handler, artist_callback, artist_command
from .handlers.browse import latest_handler, popular_handler, browse_callback
from .handlers.admin import (
    stats_handler, health_handler, cache_clear_handler, block_handler, unblock_handler,
    broadcast_handler, broadcast_confirm_handler, admin_menu_callback, admin_stats_callback,
    admin_health_callback, admin_cache_confirm_callback, admin_cache_clear_callback,
    admin_users_callback, admin_broadcast_help_callback, admin_broadcast_confirm_callback,
    admin_broadcast_cancel_callback,
)

logging.basicConfig(
    format="%(asctime)s | %(levelname)s | %(name)s | %(message)s",
    level=getattr(logging, LOG_LEVEL.upper(), logging.INFO),
)
log = logging.getLogger("ahangban")
# Reduce noisy libs
logging.getLogger("httpx").setLevel(logging.WARNING)
logging.getLogger("httpcore").setLevel(logging.WARNING)

# ---- Middleware: rate limit + block check ----
async def rate_limit_middleware(update: Update, context):
    """Pre-dispatch guard for every update; stops blocked/flooding users before handlers run."""
    user = update.effective_user
    if not user:
        return

    if await is_blocked(user.id):
        if update.callback_query:
            await update.callback_query.answer("⛔ دسترسی شما محدود شده است.", show_alert=True)
        elif update.effective_message:
            last_notice = context.user_data.get("blocked_notice_at", 0)
            if time.time() - last_notice >= 60:
                await update.effective_message.reply_text("⛔ دسترسی شما به ربات محدود شده است.")
                context.user_data["blocked_notice_at"] = time.time()
        raise ApplicationHandlerStop

    # The artist prompt is a one-shot, short-lived state; unrelated buttons/commands cancel it.
    if update.callback_query:
        if update.callback_query.data != "prompt:artist":
            context.user_data.pop("search_mode", None)
            context.user_data.pop("search_mode_expires_at", None)
    elif update.effective_message and (update.effective_message.text or "").startswith("/"):
        context.user_data.pop("search_mode", None)
        context.user_data.pop("search_mode_expires_at", None)

    allowed = await check_rate_limit(user.id, RATE_LIMIT_PER_MINUTE)
    if not allowed:
        if update.callback_query:
            await update.callback_query.answer("⏳ کمی صبر کنید — درخواست زیاد است.", show_alert=False)
        elif update.effective_message:
            await update.effective_message.reply_text("⏳ لطفاً کمی صبر کنید؛ تعداد درخواست‌ها بیش از حد است.")
        raise ApplicationHandlerStop

    chat = update.effective_chat
    if chat and chat.type == "private":
        await upsert_user(
            user.id,
            user.username or "",
            user.first_name or "",
            user.last_name or "",
            is_admin=is_admin(user.id),
        )

# Global error handler
async def error_handler(update, context):
    log.exception("Unhandled error for update %s: %s", update, context.error)
    # Try to notify user politely (no stack trace)
    try:
        if update and update.effective_message:
            await update.effective_message.reply_text(
                "❌ خطای غیرمنتظره — لطفاً دوباره تلاش کنید.\nاگر تکرار شد، /ping را امتحان کنید."
            )
    except Exception:
        pass

def build_app() -> Application:
    errs = validate()
    if errs:
        for error in errs:
            log.error("Config error: %s", error)
        raise RuntimeError("پیکربندی ربات نامعتبر است؛ متغیرهای محیطی الزامی را بررسی کنید.")

    app = Application.builder().token(BOT_TOKEN).build()

    # Global guard runs in an earlier group and stops dispatch for blocked/flooding users.
    app.add_handler(TypeHandler(Update, rate_limit_middleware), group=-1)

    # Commands
    app.add_handler(CommandHandler("start", start_handler))
    app.add_handler(CommandHandler("help", help_handler))
    app.add_handler(CommandHandler("ping", ping_handler))
    app.add_handler(CommandHandler("search", search_command))
    app.add_handler(CommandHandler("song", song_command))
    app.add_handler(CommandHandler("latest", latest_handler))
    app.add_handler(CommandHandler("new", latest_handler))
    app.add_handler(CommandHandler("popular", popular_handler))
    app.add_handler(CommandHandler("artist", artist_command))
    app.add_handler(CommandHandler("artists", artists_list_handler))
    # Admin
    app.add_handler(CommandHandler("stats", stats_handler))
    app.add_handler(CommandHandler("admin", stats_handler))
    app.add_handler(CommandHandler("health", health_handler))
    app.add_handler(CommandHandler("cache_clear", cache_clear_handler))
    app.add_handler(CommandHandler("block", block_handler))
    app.add_handler(CommandHandler("unblock", unblock_handler))
    app.add_handler(CommandHandler("broadcast", broadcast_handler))
    app.add_handler(CommandHandler("broadcast_confirm", broadcast_confirm_handler))

    # Callback queries — order matters: specific before generic
    app.add_handler(CallbackQueryHandler(admin_menu_callback, pattern=r"^admin:menu$"))
    app.add_handler(CallbackQueryHandler(admin_stats_callback, pattern=r"^admin:stats$"))
    app.add_handler(CallbackQueryHandler(admin_health_callback, pattern=r"^admin:health$"))
    app.add_handler(CallbackQueryHandler(admin_cache_confirm_callback, pattern=r"^admin:cache_confirm$"))
    app.add_handler(CallbackQueryHandler(admin_cache_clear_callback, pattern=r"^admin:cache_clear$"))
    app.add_handler(CallbackQueryHandler(admin_users_callback, pattern=r"^admin:users$"))
    app.add_handler(CallbackQueryHandler(admin_broadcast_help_callback, pattern=r"^admin:broadcast_help$"))
    app.add_handler(CallbackQueryHandler(admin_broadcast_confirm_callback, pattern=r"^admin:broadcast_confirm$"))
    app.add_handler(CallbackQueryHandler(admin_broadcast_cancel_callback, pattern=r"^admin:broadcast_cancel$"))
    app.add_handler(CallbackQueryHandler(search_prompt_callback, pattern=r"^prompt:search$"))
    app.add_handler(CallbackQueryHandler(artist_search_prompt_callback, pattern=r"^prompt:artist$"))
    app.add_handler(CallbackQueryHandler(song_callback, pattern=r"^song:\d+$"))
    app.add_handler(CallbackQueryHandler(download_callback, pattern=r"^dl:\d+:.+$"))
    app.add_handler(CallbackQueryHandler(lyrics_callback, pattern=r"^lyrics:\d+$"))
    app.add_handler(CallbackQueryHandler(related_callback, pattern=r"^related:\d+$"))
    app.add_handler(CallbackQueryHandler(album_callback, pattern=r"^album:\d+$"))
    app.add_handler(CallbackQueryHandler(artist_callback, pattern=r"^artist:.+"))
    app.add_handler(CallbackQueryHandler(artists_list_handler, pattern=r"^artists:(page|letter):.*"))
    app.add_handler(CallbackQueryHandler(browse_callback, pattern=r"^browse:.*"))
    app.add_handler(CallbackQueryHandler(search_pagination_callback, pattern=r"^search:\d+:.*"))
    app.add_handler(CallbackQueryHandler(help_callback, pattern=r"^help$"))
    app.add_handler(CallbackQueryHandler(home_callback, pattern=r"^home$"))
    app.add_handler(CallbackQueryHandler(noop_callback, pattern=r"^noop$"))

    # Text messages (search) — must be last among message handlers
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, search_text_handler))

    # Error
    app.add_error_handler(error_handler)

    return app

async def main():
    app = build_app()
    await init_db()

    # Graceful shutdown
    stop = asyncio.Event()
    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            asyncio.get_running_loop().add_signal_handler(sig, stop.set)
        except NotImplementedError:
            pass  # Windows

    log.info("🚀 AhangBan Bot starting — polling…")
    # Use run_polling in a task so we can await stop
    # PTB's run_polling is blocking; we use initialize/start + updater
    await app.initialize()
    await app.start()
    await app.updater.start_polling(
        allowed_updates=Update.ALL_TYPES,
        drop_pending_updates=False,
    )
    log.info("✅ Bot is polling. Press Ctrl+C to stop.")
    await stop.wait()
    log.info("Shutting down…")
    await app.updater.stop()
    await app.stop()
    await app.shutdown()
    from .api_client import api
    await api.close()

if __name__ == "__main__":
    asyncio.run(main())
