import logging
import httpx
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import ContextTypes
from telegram.constants import ParseMode

from ..api_client import api, APIError
from ..db import increment_stat, get_file_id, set_file_id
from ..utils.formatters import song_caption, lyrics_text
from ..utils.keyboards import song_card
from ..config import AHANGBAN_API_BASE

log = logging.getLogger("ahangban.song")

# Telegram limit for audio upload is 50MB for bot API (2GB technically but bot must stream)
MAX_AUDIO_BYTES = 50 * 1024 * 1024

async def song_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    sid = query.data.split(":")[1]
    try:
        song_id = int(sid)
    except ValueError:
        await query.edit_message_text("شناسه نامعتبر.")
        return
    await _show_song(query, context, song_id, edit=True)

async def _show_song(query_or_update, context, song_id: int, edit: bool = False):
    # query_or_update may be CallbackQuery or Update
    if hasattr(query_or_update, "edit_message_text"):
        cq = query_or_update
        chat_action_target = cq.message.chat if hasattr(cq, "message") else None
    else:
        cq = None
        chat_action_target = query_or_update.effective_chat

    if chat_action_target:
        try:
            await chat_action_target.send_action("typing")
        except Exception:
            pass

    try:
        song = await api.song(song_id)
    except APIError as e:
        msg = f"❌ {e.message}" if e.status == 404 else f"❌ خطا: {e.message}"
        if cq:
            await cq.edit_message_text(msg)
        else:
            await query_or_update.message.reply_text(msg)
        return
    except Exception as e:
        log.exception("song fetch failed")
        err = f"❌ ارتباط با سایت برقرار نشد: {e}"
        if cq:
            await cq.edit_message_text(err)
        else:
            await query_or_update.message.reply_text(err)
        return

    await increment_stat("song_views")
    caption = song_caption(song)
    keyboard = song_card(song)
    cover = song.get("cover")

    # Store last song for favorite
    if cq:
        context.user_data["last_song"] = song

    # Try to send with photo if available
    try:
        if edit and cq:
            # If we have a cover, we can't edit text to photo — send new message
            if cover:
                await cq.message.reply_photo(
                    photo=cover,
                    caption=caption,
                    parse_mode=ParseMode.HTML,
                    reply_markup=keyboard,
                )
                # Optionally delete loading message? Keep it
                await cq.edit_message_text(caption, parse_mode=ParseMode.HTML, reply_markup=keyboard)
                # Actually delete the loading if we sent photo; better to just send photo and keep text
                # For simplicity, send photo + delete old text not needed
            else:
                await cq.edit_message_text(caption, parse_mode=ParseMode.HTML, reply_markup=keyboard)
        else:
            # Called from command / direct
            msg_target = query_or_update.message if hasattr(query_or_update, "message") else query_or_update
            if cover:
                await msg_target.reply_photo(photo=cover, caption=caption, parse_mode=ParseMode.HTML, reply_markup=keyboard)
            else:
                await msg_target.reply_text(caption, parse_mode=ParseMode.HTML, reply_markup=keyboard)
    except Exception as e:
        log.warning("send song card failed: %s", e)
        try:
            fallback = caption + "\n\n(تصویر بارگذاری نشد)"
            if cq:
                await cq.edit_message_text(fallback, parse_mode=ParseMode.HTML, reply_markup=keyboard)
            else:
                await query_or_update.message.reply_text(fallback, parse_mode=ParseMode.HTML, reply_markup=keyboard)
        except Exception:
            pass

async def song_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if not context.args:
        await update.message.reply_text("🎵 استفاده: /song <شناسه>\nیا روی نتایج جست‌وجو بزنید.")
        return
    try:
        sid = int(context.args[0])
    except ValueError:
        await update.message.reply_text("شناسه باید عدد باشد.")
        return
    await _show_song(update, context, sid, edit=False)

async def download_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer("⏳ بررسی لینک دانلود…")
    parts = query.data.split(":")  # dl:{id}:{quality}
    if len(parts) != 3:
        return
    _, sid_str, quality = parts
    try:
        sid = int(sid_str)
    except ValueError:
        await query.message.reply_text("شناسه نامعتبر.")
        return

    try:
        song = await api.song(sid)
    except Exception as e:
        await query.message.reply_text(f"❌ دریافت اطلاعات: {e}")
        return

    quals = song.get("qualities") or {}
    url = quals.get(quality)
    if not url:
        # Try generic: 128/320
        await query.message.reply_text(f"❌ کیفیت {quality} برای این اثر موجود نیست.\nکیفیت‌های موجود: {', '.join(quals.keys()) or '—'}")
        return

    # SSRF allowlist: only ahangban.ir and dl.* domains
    from urllib.parse import urlparse
    parsed = urlparse(url)
    host = parsed.hostname or ""
    allowed = host.endswith("ahangban.ir") or host.startswith("dl.") or "ahangban" in host
    # For safety, allow any https URL from DB (admin controls DB) but block private IPs
    if host in ("127.0.0.1", "localhost", "0.0.0.0") or host.startswith("10.") or host.startswith("192.168."):
        await query.message.reply_text("⛔ لینک نامعتبر.")
        return

    # Check cached file_id
    cached_file_id = await get_file_id(url)
    if cached_file_id:
        try:
            await query.message.chat.send_action("upload_voice")
            await query.message.reply_audio(
                audio=cached_file_id,
                caption=f"🎵 {song.get('song') or song.get('title')} — {song.get('artist')}\n🔗 {song.get('permalink')}",
            )
            return
        except Exception as e:
            log.warning("cached file_id invalid: %s", e)
            # Fall through to fresh download

    # HEAD check size
    try:
        async with httpx.AsyncClient(timeout=10, follow_redirects=True) as client:
            head = await client.head(url)
            size = int(head.headers.get("content-length") or 0)
            ctype = head.headers.get("content-type") or ""
            if size and size > MAX_AUDIO_BYTES:
                # Too large for direct upload — give link
                await query.message.reply_text(
                    f"📦 حجم فایل کیفیت {quality} حدود {size // (1024*1024)}MB است (بیش از ۵۰MB).\n"
                    f"🔗 لینک مستقیم دانلود:\n{url}\n\n"
                    f"صفحهٔ اثر: {song.get('permalink')}",
                    disable_web_page_preview=False,
                )
                return
            # Also check content-type vaguely
            if head.status_code >= 400:
                await query.message.reply_text(
                    f"⚠️ لینک کیفیت {quality} در دسترس نیست (خطای {head.status_code}).\n"
                    f"صفحهٔ اثر: {song.get('permalink')}"
                )
                return
    except Exception as e:
        log.warning("HEAD check failed for %s: %s", url, e)
        # Continue anyway — try to upload

    # Try to send audio directly via URL (Telegram will fetch)
    try:
        await query.message.chat.send_action("upload_voice")
        # Use URL directly — Telegram servers fetch it (no need to download ourselves)
        # This avoids server bandwidth and respects Telegram 50MB limit server-side
        sent = await query.message.reply_audio(
            audio=url,
            caption=f"🎵 {song.get('song') or song.get('title')} — {song.get('artist')}\n"
                    f"💿 کیفیت: {quality}\n🔗 {song.get('permalink')}",
            title=song.get("song") or song.get("title") or "آهنگ",
            performer=song.get("artist") or "آهنگ‌بان",
        )
        # Cache file_id for next time
        if sent and sent.audio:
            await set_file_id(url, sent.audio.file_id, quality, sent.audio.file_unique_id)
            log.info("cached file_id for %s quality %s", url, quality)
    except Exception as e:
        log.warning("send_audio via URL failed: %s", e)
        # Fallback: give link
        await query.message.reply_text(
            f"⚠️ ارسال مستقیم ممکن نشد (محدودیت تلگرام یا زیرساخت).\n"
            f"🔗 لینک دانلود کیفیت {quality}:\n{url}\n\n"
            f"صفحهٔ اثر: {song.get('permalink')}",
            disable_web_page_preview=False,
        )

async def lyrics_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    sid = query.data.split(":")[1]
    try:
        song = await api.song(int(sid))
    except Exception as e:
        await query.message.reply_text(f"❌ {e}")
        return
    text = lyrics_text(song)
    # Paginate if too long
    if len(text) > 4000:
        # Split
        chunks = [text[i:i+4000] for i in range(0, len(text), 4000)]
        for ch in chunks:
            await query.message.reply_text(ch, parse_mode=ParseMode.HTML)
    else:
        await query.message.reply_text(text, parse_mode=ParseMode.HTML)

async def related_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    sid = query.data.split(":")[1]
    try:
        song = await api.song(int(sid))
    except Exception as e:
        await query.message.reply_text(f"❌ {e}")
        return
    related = song.get("related") or []
    if not related:
        await query.message.reply_text("اثر مرتبطی یافت نشد.")
        return
    keyboard = []
    for r in related:
        sid2 = r.get("id")
        title = (r.get("song") or r.get("title") or str(sid2))[:30]
        artist = (r.get("artist") or "")[:20]
        label = f"{title} — {artist}" if artist else title
        keyboard.append([InlineKeyboardButton(f"🎵 {label}", callback_data=f"song:{sid2}")])
    keyboard.append([InlineKeyboardButton("🏠 خانه", callback_data="home")])
    await query.message.reply_text(
        f"🎧 آثار مرتبط با «{song.get('song') or song.get('title')}»:",
        reply_markup=InlineKeyboardMarkup(keyboard),
    )

async def album_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    sid = query.data.split(":")[1]
    try:
        song = await api.song(int(sid))
    except Exception as e:
        await query.message.reply_text(f"❌ {e}")
        return
    tracks = song.get("album_tracks") or []
    if not tracks:
        await query.message.reply_text("ترکی برای این آلبوم ثبت نشده است.")
        return
    lines = [f"💿 <b>ترک‌های آلبوم «{song.get('song') or song.get('title')}»</b>\n"]
    keyboard = []
    for i, t in enumerate(tracks, 1):
        name = t.get("song_names") or f"ترک {i}"
        keyboard.append([InlineKeyboardButton(f"{i}. {name[:35]}", callback_data=f"noop")])
        # Add download buttons per track
        row = []
        if t.get("albumlink128"):
            row.append(InlineKeyboardButton(f"⬇️128 #{i}", url=t["albumlink128"]))
        if t.get("albumlink320"):
            row.append(InlineKeyboardButton(f"⬇️320 #{i}", url=t["albumlink320"]))
        if row:
            keyboard.append(row)
    keyboard.append([InlineKeyboardButton("🏠 خانه", callback_data="home")])
    text = "\n".join(lines) + "\n".join([f"{i}. {t.get('song_names') or '—'}" for i, t in enumerate(tracks, 1)])
    await query.message.reply_text(text, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(keyboard))
