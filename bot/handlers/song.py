import asyncio
import html
import ipaddress
import logging
import os
import re
import socket
import tempfile
from pathlib import Path
from urllib.parse import urljoin, urlparse

import httpx
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup, InputFile
from telegram.error import BadRequest
from telegram.ext import ContextTypes
from telegram.constants import ParseMode

from ..api_client import api, APIError
from ..config import is_admin
from ..db import increment_stat, get_file_id, set_file_id
from ..utils.formatters import song_caption, lyrics_text
from ..utils.keyboards import song_card

log = logging.getLogger("ahangban.song")

# Keep below the hosted Bot API upload ceiling to leave room for multipart overhead.
MAX_AUDIO_BYTES = 49 * 1024 * 1024


def _is_public_https_url(url: str) -> bool:
    """Reject credentials, non-HTTPS URLs, private/reserved IPs and unsafe DNS answers."""
    try:
        parsed = urlparse(str(url).strip())
        if parsed.scheme.lower() != "https" or not parsed.hostname or parsed.username or parsed.password:
            return False
        if parsed.port not in (None, 443):
            return False
        host = parsed.hostname.rstrip(".").lower()
        if host in {"localhost", "localhost.localdomain"} or host.endswith((".localhost", ".local", ".internal", ".test", ".invalid")):
            return False
        try:
            addresses = {ipaddress.ip_address(host)}
        except ValueError:
            addresses = {
                ipaddress.ip_address(item[4][0].split("%", 1)[0])
                for item in socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)
            }
        return bool(addresses) and all(address.is_global for address in addresses)
    except (ValueError, OSError, socket.gaierror):
        return False


async def _download_public_media(url: str):
    """Stream a bounded HTTPS media file to disk; redirects are revalidated at every hop."""
    current = url
    timeout = httpx.Timeout(18.0, connect=7.0)
    async with httpx.AsyncClient(timeout=timeout, follow_redirects=False) as client:
        for _ in range(4):
            if not await asyncio.to_thread(_is_public_https_url, current):
                raise ValueError("Unsafe media URL or redirect")
            async with client.stream("GET", current) as response:
                if response.status_code in (301, 302, 303, 307, 308):
                    location = response.headers.get("location")
                    if not location:
                        raise ValueError("Redirect without Location")
                    current = urljoin(current, location)
                    continue
                response.raise_for_status()
                content_type = response.headers.get("content-type", "").split(";", 1)[0].lower()
                if content_type.startswith("text/") or "json" in content_type or "xml" in content_type:
                    raise ValueError("Source returned a web page, not media")
                declared_size = int(response.headers.get("content-length", "0") or 0)
                if declared_size > MAX_AUDIO_BYTES:
                    raise ValueError("Media file exceeds upload limit")

                suffix = Path(urlparse(current).path).suffix.lower()
                if suffix not in {".mp3", ".m4a", ".aac", ".ogg", ".mp4", ".m4v", ".mov", ".webm", ".zip", ".rar", ".7z"}:
                    suffix = ".bin"
                fd, temp_path = tempfile.mkstemp(prefix="ahangban-media-", suffix=suffix)
                size = 0
                try:
                    with os.fdopen(fd, "wb") as output:
                        async for chunk in response.aiter_bytes(64 * 1024):
                            size += len(chunk)
                            if size > MAX_AUDIO_BYTES:
                                raise ValueError("Media file exceeds upload limit")
                            output.write(chunk)
                    if size == 0:
                        raise ValueError("Media file is empty")
                    raw_name = os.path.basename(urlparse(current).path) or "ahangban-media" + suffix
                    filename = re.sub(r"[^A-Za-z0-9._-]+", "_", raw_name)[:100] or "ahangban-media" + suffix
                    return temp_path, content_type, filename
                except Exception:
                    try:
                        os.unlink(temp_path)
                    except OSError:
                        pass
                    raise
    raise ValueError("Too many media redirects")


def _media_caption(song: dict, quality: str) -> str:
    title = song.get("song") or song.get("title") or "آهنگ آهنگ‌بان"
    artist = song.get("artist") or "آهنگ‌بان"
    return f"🎵 {title} — {artist}\n💿 کیفیت: {quality}\n🔗 {song.get('permalink') or ''}"[:950]


def _file_id_from_message(message):
    for attr in ("audio", "video", "document"):
        item = getattr(message, attr, None)
        if item:
            return item
    return None


async def _send_card_photo(message, item: dict, caption: str, keyboard):
    """Send a large cover first, use the WordPress medium fallback only on a definite rejection."""
    candidates = []
    for value in (item.get("cover") or item.get("image"), item.get("cover_fallback") or item.get("image_fallback")):
        url = str(value or "").strip()
        if url and url not in candidates and await asyncio.to_thread(_is_public_https_url, url):
            candidates.append(url)

    if not candidates:
        return "missing"
    for url in candidates:
        try:
            await message.reply_photo(
                photo=url,
                caption=caption,
                parse_mode=ParseMode.HTML,
                reply_markup=keyboard,
            )
            return "sent"
        except BadRequest as exc:
            # BadRequest is a definitive rejection (e.g. large image exceeds Telegram's limit),
            # so trying the lower-resolution WordPress variant cannot create a duplicate.
            log.info("Telegram rejected cover variant; trying fallback: %s", exc)
        except Exception as exc:
            # Network failures are ambiguous: Telegram may have accepted the send before timeout.
            log.warning("cover send has ambiguous outcome; suppressing duplicate fallback: %s", exc)
            return "unknown"
    return "rejected"


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
    actor = cq.from_user if cq else getattr(query_or_update, "effective_user", None)
    keyboard = song_card(song, is_admin=bool(actor and is_admin(actor.id)))
    if cq and edit:
        photo_status = await _send_card_photo(cq.message, song, caption, keyboard)
        if "sent" == photo_status:
            # Remove the old search/list message to avoid the previous duplicate text + photo card.
            try:
                await cq.message.delete()
            except Exception:
                try:
                    await cq.edit_message_text("✅ کارت کامل اثر در پیام بعدی است.")
                except Exception:
                    pass
            return
        if "unknown" == photo_status:
            return  # Never send a second card when Telegram's first response timed out ambiguously.
        try:
            await cq.edit_message_text(caption, parse_mode=ParseMode.HTML, reply_markup=keyboard)
        except Exception as exc:
            log.warning("editing song card failed: %s", exc)
        return

    msg_target = query_or_update.message if hasattr(query_or_update, "message") else query_or_update
    photo_status = await _send_card_photo(msg_target, song, caption, keyboard)
    if "sent" == photo_status or "unknown" == photo_status:
        return
    try:
        await msg_target.reply_text(caption, parse_mode=ParseMode.HTML, reply_markup=keyboard)
    except Exception as exc:
        log.warning("sending text-only song card failed: %s", exc)

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

    url = str(url).strip()
    if not await asyncio.to_thread(_is_public_https_url, url):
        keyboard = []
        permalink = song.get("permalink")
        if permalink and await asyncio.to_thread(_is_public_https_url, str(permalink)):
            keyboard.append([InlineKeyboardButton("🔗 صفحهٔ اثر در آهنگ‌بان", url=permalink)])
        await query.message.reply_text(
            "⛔ لینک فایل امن یا HTTPS عمومی نیست؛ از صفحهٔ رسمی اثر استفاده کنید.",
            reply_markup=InlineKeyboardMarkup(keyboard) if keyboard else None,
        )
        return

    parsed = urlparse(url)
    extension = Path(parsed.path).suffix.lower()
    is_video = quality in {"480", "720", "1080"} or extension in {".mp4", ".m4v", ".mov", ".webm"}
    is_archive = extension in {".zip", ".rar", ".7z"}
    caption = _media_caption(song, quality)
    timeout_args = {"read_timeout": 25, "write_timeout": 25, "connect_timeout": 8, "pool_timeout": 8}

    # Telegram file_ids are reusable for audio files and avoid repeated file transfers.
    cached_file_id = await get_file_id(url) if not is_video and not is_archive else None
    if cached_file_id:
        try:
            await query.message.chat.send_action("upload_audio")
            await query.message.reply_audio(audio=cached_file_id, caption=caption, **timeout_args)
            return
        except Exception as exc:
            log.info("cached Telegram file_id expired; retrying source: %s", exc)

    # Fast path: let Telegram fetch the original URL. If it fails, the bounded local-upload path follows.
    try:
        await query.message.chat.send_action("upload_video" if is_video else "upload_document" if is_archive else "upload_audio")
        if is_video:
            sent = await query.message.reply_video(video=url, caption=caption, supports_streaming=True, **timeout_args)
        elif is_archive:
            sent = await query.message.reply_document(document=url, caption=caption, **timeout_args)
        else:
            sent = await query.message.reply_audio(
                audio=url,
                caption=caption,
                title=(song.get("song") or song.get("title") or "آهنگ")[:64],
                performer=(song.get("artist") or "آهنگ‌بان")[:64],
                **timeout_args,
            )
        media = _file_id_from_message(sent)
        if media and getattr(sent, "audio", None):
            await set_file_id(url, media.file_id, quality, media.file_unique_id)
        return
    except Exception as exc:
        log.info("Telegram could not fetch media URL; trying bounded server upload: %s", exc)

    temp_path = None
    try:
        temp_path, content_type, filename = await _download_public_media(url)
        suffix = Path(filename).suffix.lower()
        is_video = is_video or content_type.startswith("video/") or suffix in {".mp4", ".m4v", ".mov", ".webm"}
        is_archive = is_archive or suffix in {".zip", ".rar", ".7z"} or "zip" in content_type or "rar" in content_type
        with open(temp_path, "rb") as file_handle:
            upload = InputFile(file_handle, filename=filename, read_file_handle=False)
            if is_video:
                sent = await query.message.reply_video(video=upload, caption=caption, supports_streaming=True, **timeout_args)
            elif is_archive:
                sent = await query.message.reply_document(document=upload, caption=caption, **timeout_args)
            else:
                try:
                    sent = await query.message.reply_audio(
                        audio=upload,
                        caption=caption,
                        title=(song.get("song") or song.get("title") or "آهنگ")[:64],
                        performer=(song.get("artist") or "آهنگ‌بان")[:64],
                        **timeout_args,
                    )
                except BadRequest as exc:
                    log.info("Telegram rejected audio metadata; retrying as a document: %s", exc)
                    with open(temp_path, "rb") as document_handle:
                        document = InputFile(document_handle, filename=filename, read_file_handle=False)
                        sent = await query.message.reply_document(document=document, caption=caption, **timeout_args)
        media = _file_id_from_message(sent)
        if media and getattr(sent, "audio", None):
            await set_file_id(url, media.file_id, quality, media.file_unique_id)
            log.info("cached Telegram audio file_id for quality %s", quality)
    except Exception as exc:
        log.warning("bounded media download/upload failed for song %s: %s", sid, exc)
        keyboard = [[InlineKeyboardButton("🌐 دریافت فایل از منبع", url=url)]]
        permalink = song.get("permalink")
        if permalink and urlparse(permalink).scheme == "https":
            keyboard.append([InlineKeyboardButton("🔗 صفحهٔ اثر در آهنگ‌بان", url=permalink)])
        await query.message.reply_text(
            "⚠️ تلگرام نتوانست فایل را مستقیم ارسال کند و بارگذاری امن از هاست هم ناموفق شد. از یکی از پیوندهای رسمی زیر استفاده کنید.",
            reply_markup=InlineKeyboardMarkup(keyboard),
        )
    finally:
        if temp_path:
            try:
                os.unlink(temp_path)
            except OSError:
                pass

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
    album_title = html.escape(str(song.get("song") or song.get("title") or "آلبوم"))
    lines = [f"💿 <b>ترک‌های آلبوم «{album_title}»</b>"]
    keyboard = []
    for i, track in enumerate(tracks[:30], 1):
        name = str(track.get("song_names") or f"ترک {i}")
        lines.append(f"{i}. {html.escape(name)}")
        keyboard.append([InlineKeyboardButton(f"{i}. {name[:35]}", callback_data="noop")])
        row = []
        for field, label in (("albumlink128", "⬇️128"), ("albumlink320", "⬇️320")):
            link = track.get(field)
            if link and await asyncio.to_thread(_is_public_https_url, str(link)):
                row.append(InlineKeyboardButton(f"{label} #{i}", url=link))
        if row:
            keyboard.append(row)
    keyboard.append([InlineKeyboardButton("🏠 خانه", callback_data="home")])
    await query.message.reply_text("\n".join(lines), parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(keyboard))
