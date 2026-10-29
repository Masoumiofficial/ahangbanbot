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
    ApplicationHandlerStop,
    CallbackQueryHandler,
    CommandHandler,
    MessageHandler,
    TypeHandler,
    filters,
)

from .config import BOT_TOKEN, LOG_LEVEL, RATE_LIMIT_PER_MINUTE, is_admin, validate
from .db import check_rate_limit, init_db, is_blocked, upsert_user
from .health_server import start_health_server
from .handlers.admin import (
    admin_broadcast_cancel_callback,
    admin_broadcast_confirm_callback,
    admin_broadcast_help_callback,
    admin_cache_clear_callback,
    admin_cache_confirm_callback,
    admin_channel_publish_cancel_callback,
    admin_channel_publish_confirm_callback,
    admin_channel_publish_menu_callback,
    admin_channel_publish_prepare_callback,
    admin_health_callback,
    admin_menu_callback,
    admin_stats_callback,
    admin_users_callback,
    block_handler,
    broadcast_confirm_handler,
    broadcast_handler,
    cache_clear_handler,
    health_handler,
    stats_handler,
    unblock_handler,
)
from .handlers.artist import (
    artist_callback,
    artist_command,
    artist_follow_callback,
    artist_follows_callback,
    artist_follows_command,
    artist_unfollow_callback,
    artists_list_handler,
)
from .handlers.browse import browse_callback, latest_handler, popular_handler
from .handlers.search import (
    search_command,
    search_pagination_callback,
    search_text_handler,
)
from .handlers.song import (
    album_callback,
    download_callback,
    favorite_callback,
    favorite_remove_callback,
    favorites_callback,
    favorites_command,
    lyrics_callback,
    related_callback,
    song_callback,
    song_command,
)
from .handlers.start import (
    artist_search_prompt_callback,
    help_callback,
    help_handler,
    home_callback,
    noop_callback,
    ping_handler,
    search_prompt_callback,
    start_handler,
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
    error = context.error
    update_id = getattr(update, "update_id", None)
    if error:
        log.error(
            "Unhandled bot error (update_id=%s, exception=%s)",
            update_id,
            type(error).__name__,
            exc_info=(type(error), error, error.__traceback__),
        )
    else:
        log.error("Unhandled bot error (update_id=%s, no exception object)", update_id)
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
    app.add_handler(CommandHandler("favorites", favorites_command))
    app.add_handler(CommandHandler("following", artist_follows_command))
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
    app.add_handler(CallbackQueryHandler(admin_users_callback, pattern=r"^admin:users(?::page:\d+)?$"))
    app.add_handler(CallbackQueryHandler(admin_broadcast_help_callback, pattern=r"^admin:broadcast_help$"))
    app.add_handler(CallbackQueryHandler(admin_broadcast_confirm_callback, pattern=r"^admin:broadcast_confirm$"))
    app.add_handler(CallbackQueryHandler(admin_broadcast_cancel_callback, pattern=r"^admin:broadcast_cancel$"))
    app.add_handler(CallbackQueryHandler(admin_channel_publish_menu_callback, pattern=r"^admin:channel_publish$"))
    app.add_handler(CallbackQueryHandler(admin_channel_publish_prepare_callback, pattern=r"^admin:channel_publish_prepare:\d+$"))
    app.add_handler(CallbackQueryHandler(admin_channel_publish_confirm_callback, pattern=r"^admin:channel_publish_confirm:\d+$"))
    app.add_handler(CallbackQueryHandler(admin_channel_publish_cancel_callback, pattern=r"^admin:channel_publish_cancel:\d+$"))
    app.add_handler(CallbackQueryHandler(search_prompt_callback, pattern=r"^prompt:search$"))
    app.add_handler(CallbackQueryHandler(artist_search_prompt_callback, pattern=r"^prompt:artist$"))
    app.add_handler(CallbackQueryHandler(song_callback, pattern=r"^song:\d+$"))
    app.add_handler(CallbackQueryHandler(favorite_callback, pattern=r"^favorite:\d+$"))
    app.add_handler(CallbackQueryHandler(favorite_remove_callback, pattern=r"^favorite_remove:\d+:\d+$"))
    app.add_handler(CallbackQueryHandler(favorites_callback, pattern=r"^favorites:page:\d+$"))
    app.add_handler(CallbackQueryHandler(download_callback, pattern=r"^dl:\d+:.+$"))
    app.add_handler(CallbackQueryHandler(lyrics_callback, pattern=r"^lyrics:\d+$"))
    app.add_handler(CallbackQueryHandler(related_callback, pattern=r"^related:\d+$"))
    app.add_handler(CallbackQueryHandler(album_callback, pattern=r"^album:\d+$"))
    app.add_handler(CallbackQueryHandler(artist_follow_callback, pattern=r"^artist_follow:\d+:\d+:(new|old|title)$"))
    app.add_handler(CallbackQueryHandler(artist_follows_callback, pattern=r"^artist_follows:page:\d+$"))
    app.add_handler(CallbackQueryHandler(artist_unfollow_callback, pattern=r"^artist_unfollow:\d+:\d+$"))
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
    # PaaS platforms may require an HTTP port even though Telegram updates use polling.
    # This endpoint returns only a generic health status and never exposes configuration.
    health_server = await start_health_server()

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
    health_server.close()
    await health_server.wait_closed()
    await app.updater.stop()
    await app.stop()
    await app.shutdown()
    from .api_client import api
    await api.close()

if __name__ == "__main__":
    asyncio.run(main())
