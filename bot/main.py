"""
نقطهٔ ورود ربات آهنگ‌بان — polling async
"""
import asyncio
import logging
import signal
from telegram import Update
from telegram.ext import (
    Application,
    CommandHandler,
    MessageHandler,
    CallbackQueryHandler,
    filters,
)

from .config import BOT_TOKEN, LOG_LEVEL, validate, RATE_LIMIT_PER_MINUTE
from .db import init_db, is_blocked, check_rate_limit
from .handlers.start import start_handler, help_handler, ping_handler, help_callback, home_callback, noop_callback
from .handlers.search import search_text_handler, search_command, search_pagination_callback
from .handlers.song import song_callback, download_callback, lyrics_callback, related_callback, album_callback, song_command
from .handlers.artist import artists_list_handler, artist_callback, artist_command
from .handlers.browse import latest_handler, popular_handler, browse_callback
from .handlers.admin import stats_handler, health_handler, cache_clear_handler, block_handler, unblock_handler, broadcast_handler, broadcast_confirm_handler

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
    user = update.effective_user
    if not user:
        return
    if await is_blocked(user.id):
        # Silently ignore but answer callback queries
        if update.callback_query:
            await update.callback_query.answer("⛔ مسدود شده‌اید.")
        return
    allowed = await check_rate_limit(user.id, RATE_LIMIT_PER_MINUTE)
    if not allowed:
        if update.callback_query:
            await update.callback_query.answer("⏳ کمی صبر کنید — درخواست زیاد است.", show_alert=False)
        elif update.message:
            await update.message.reply_text("⏳ لطفاً چند ثانیه صبر کنید — درخواست‌های شما بیش از حد است.")
        return

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
        for e in errs:
            log.error("Config error: %s", e)
        log.warning("ربات با پیکربندی ناقص اجرا می‌شود — برخی قابلیت‌ها کار نمی‌کنند.")

    app = Application.builder().token(BOT_TOKEN).build()

    # Type handlers for all updates: rate limit via Job? We use a handler for that
    # We implement rate limit as a pre-check in each text/callback handler wrapper
    # For global coverage, add a MessageHandler with low priority that does rate check
    # But simplest: add a handler that runs first and checks
    # PTB supports `Application.add_handler` order; we insert a handler for all messages that blocks if needed
    # We'll just add a check inside each handler — plus a generic middleware via `add_handler` with group -1
    # PTB v21 supports `filters.ALL` — we use it as guard

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
    await init_db()
    app = build_app()

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
