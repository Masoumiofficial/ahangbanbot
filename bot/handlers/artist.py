import asyncio
import html
import logging
import math
import uuid

from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from ..api_client import APIError, api
from ..db import (
    count_artist_followers,
    increment_stat,
    is_artist_followed,
    list_artist_follows_page,
    remove_artist_follow,
    toggle_artist_follow,
)
from ..utils.formatters import artist_caption, song_list_item
from ..utils.keyboards import artist_card, artists_list_pagination
from .song import _send_card_photo

log = logging.getLogger("ahangban.artist")

PER_PAGE_ARTISTS = 10
PER_PAGE_SONGS = 6

# ---- Artists list ----
async def artists_list_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    Can be called from message (reply keyboard) or callback.
    """
    # Extract page/letter from callback if present
    page = 1
    letter = ""
    q = ""
    query_token = ""
    if update.callback_query:
        data = update.callback_query.data
        # Formats: artists:page:2, artists:letter:م, artists:page:2:qref:TOKEN
        parts = data.split(":")
        if len(parts) >= 3 and parts[1] == "page":
            try:
                page = max(1, int(parts[2]))
            except ValueError:
                page = 1
            if ":qref:" in data:
                query_token = data.rsplit(":qref:", 1)[1].split(":l:")[0]
                q = context.user_data.get("artist_query_tokens", {}).get(query_token, "")
            if ":l:" in data:
                letter = data.rsplit(":l:", 1)[1]
        elif len(parts) >= 3 and parts[1] == "letter":
            letter = parts[2]
            page = 1
        if query_token and not q:
            await update.callback_query.answer("جست‌وجو منقضی شده است.", show_alert=True)
            await update.callback_query.edit_message_text("جست‌وجوی خواننده منقضی شده؛ دوباره نام را بنویسید.")
            return
        query = update.callback_query
        await query.answer()
        callback_message = query.message
        has_media = bool(
            callback_message
            and any(getattr(callback_message, kind, None) for kind in ("photo", "video", "audio", "document"))
        )
        if has_media:
            # Telegram cannot edit a media message's text. Keep the menu/card intact and
            # render the list in a new text reply instead of bubbling BadRequest globally.
            loading_message = await callback_message.reply_text("⏳ در حال بارگذاری خوانندگان…")

            async def send_fn(text, **kwargs):
                return await loading_message.edit_text(text, **kwargs)
        else:
            await query.edit_message_text("⏳ در حال بارگذاری خوانندگان…")

            async def send_fn(text, **kwargs):
                return await query.edit_message_text(text, **kwargs)
        chat = callback_message.chat if callback_message else update.effective_chat
    else:
        chat = update.effective_chat

        async def send_fn(text, **kwargs):
            return await update.message.reply_text(text, **kwargs)
        if chat:
            try:
                await chat.send_action("typing")
            except Exception:
                log.debug("could not send typing action for artist list")

    try:
        data_resp = await api.artists(q=q, letter=letter, sort="name", page=page, per_page=PER_PAGE_ARTISTS)
    except APIError as e:
        await send_fn(f"❌ خطا: {e.message}")
        return
    except Exception as e:
        log.exception("artists list failed")
        await send_fn(f"❌ ارتباط برقرار نشد: {e}")
        return

    results = data_resp.get("results") or []
    total = data_resp.get("total") or 0
    per_page = data_resp.get("per_page") or PER_PAGE_ARTISTS
    total_pages = max(1, math.ceil(total / per_page)) if total else 1

    if not results:
        await send_fn("😕 خواننده‌ای با این فیلتر یافت نشد.\nبرای بازگشت /start را بزنید.")
        return

    header = f"🎤 <b>فهرست خوانندگان</b> — {total:,} هنرمند".replace(",", "٬")
    if letter:
        header += f" (حرف «{html.escape(letter)}»)"
    if q:
        header += f" — جست‌وجو: «{html.escape(q)}»"
    header += f"\nصفحه {page} از {total_pages}\n"

    lines = []
    keyboard = []
    for item in results:
        count = int(item.get("count", 0) or 0)
        name = str(item.get("name") or "")
        identifier = item.get("term_id") or item.get("slug")
        lines.append(f"• <b>{html.escape(name)}</b> — {count} اثر")
        keyboard.append([InlineKeyboardButton(f"🎤 {name[:45]} ({count})", callback_data=f"artist:{identifier}")])

    text = header + "\n" + "\n".join(lines)

    if q:
        query_tokens = context.user_data.setdefault("artist_query_tokens", {})
        if not query_token:
            query_token = uuid.uuid4().hex[:10]
        query_tokens[query_token] = q
        while len(query_tokens) > 20:
            query_tokens.pop(next(iter(query_tokens)))
        nav = []
        if page > 1:
            nav.append(InlineKeyboardButton("◀️ قبلی", callback_data=f"artists:page:{page-1}:qref:{query_token}"))
        nav.append(InlineKeyboardButton(f"{page}/{total_pages}", callback_data="noop"))
        if page < total_pages:
            nav.append(InlineKeyboardButton("بعدی ▶️", callback_data=f"artists:page:{page+1}:qref:{query_token}"))
        pagination_rows = [nav, [InlineKeyboardButton("🏠 خانه", callback_data="home")]]
    else:
        pagination_kb = artists_list_pagination(page, total_pages, letter=letter)
        pagination_rows = list(pagination_kb.inline_keyboard)
    all_buttons = keyboard + pagination_rows

    await send_fn(text, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(all_buttons))
    await increment_stat("artist_views")

async def artist_search_prompt(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text("🎤 نام خواننده را بنویسید تا در فهرست جست‌وجو کنم:")

async def artist_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if not context.args:
        await update.message.reply_text("🎤 استفاده: /artist <نام یا اسلاگ>\nمثال: /artist محسن-یگانه")
        return
    identifier = " ".join(context.args).strip()
    await show_artist(update, context, identifier, page=1, sort="new")

# ---- Artist detail ----
async def artist_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    data = query.data  # artist:{slug}  or artist:{slug}:{page}:{sort}
    parts = data.split(":")
    if len(parts) == 2:
        identifier = parts[1]
        page = 1
        sort = "new"
    elif len(parts) == 4:
        identifier = parts[1]
        try:
            page = int(parts[2])
        except (TypeError, ValueError):
            page = 1
        sort = parts[3] if parts[3] in ("new", "old", "title") else "new"
    else:
        return
    # Use a fake update for show_artist
    await show_artist(update, context, identifier, page, sort, edit=True)

async def show_artist(update_or_query, context, identifier: str, page: int = 1, sort: str = "new", edit: bool = False):
    # Determine chat and send method
    if hasattr(update_or_query, "callback_query") and update_or_query.callback_query:
        cq = update_or_query.callback_query
        chat = cq.message.chat
        edit_mode = True
    elif hasattr(update_or_query, "effective_chat"):
        cq = None
        chat = update_or_query.effective_chat
        edit_mode = edit
    else:
        cq = update_or_query
        chat = cq.message.chat if hasattr(cq, "message") else None
        edit_mode = True

    if chat:
        try:
            await chat.send_action("typing")
        except Exception:
            log.debug("could not send typing action for artist detail")

    try:
        data = await api.artist(identifier, page=page, per_page=PER_PAGE_SONGS, sort=sort)
    except APIError as e:
        msg = f"❌ {e.message}"
        if cq and edit_mode:
            await cq.edit_message_text(msg)
        elif hasattr(update_or_query, "message"):
            await update_or_query.message.reply_text(msg)
        else:
            await cq.message.reply_text(msg)
        return
    except Exception as e:
        log.exception("artist detail failed")
        msg = f"❌ ارتباط برقرار نشد: {e}"
        if cq and edit_mode:
            await cq.edit_message_text(msg)
        else:
            await update_or_query.message.reply_text(msg)
        return

    term = data.get("term") or {}
    buckets = data.get("buckets") or {}
    results = data.get("results") or []
    total = data.get("total") or 0
    per_page = data.get("per_page") or PER_PAGE_SONGS
    total_pages = max(1, math.ceil(total / per_page)) if total else 1

    caption = artist_caption(term, buckets)
    if results:
        caption += f"\n\n🎵 <b>آثار (صفحه {page} از {total_pages}):</b>\n"
        for i, song in enumerate(results, start=(page - 1) * per_page + 1):
            caption += "\n" + song_list_item(song, i)
    else:
        caption += "\n\n😕 هنوز اثری برای این خواننده ثبت نشده است."

    keyboard = []
    # Song buttons
    for song in results:
        sid = song.get("id")
        title = (song.get("song") or song.get("title") or str(sid))[:30]
        keyboard.append([InlineKeyboardButton(f"🎵 {title}", callback_data=f"song:{sid}")])

    # Cache the real WordPress term ID/name for a quick follow toggle from this card.
    raw_term_id = term.get("term_id")
    term_id = int(raw_term_id) if str(raw_term_id or "").isdigit() and int(raw_term_id) > 0 else 0
    actor = cq.from_user if cq else getattr(update_or_query, "effective_user", None)
    if term_id:
        names = context.user_data.setdefault("artist_names", {})
        names[str(term_id)] = str(term.get("name") or "")[:250]
        while len(names) > 30:
            names.pop(next(iter(names)))
        follower_count = await count_artist_followers(term_id)
        followed = await is_artist_followed(actor.id, term_id) if actor else False
    else:
        follower_count = 0
        followed = False

    # Artist pagination, sort, and follow state.
    term_kb = artist_card(term, page, total_pages, sort, is_followed=followed, follower_count=follower_count)
    # python-telegram-bot exposes inline_keyboard as a tuple; normalize before merging lists.
    all_buttons = keyboard + list(term_kb.inline_keyboard)

    markup = InlineKeyboardMarkup(all_buttons)

    if edit_mode and cq:
        # Keep a photo card in place when Telegram's 1024-character caption limit allows it.
        if getattr(cq.message, "photo", None):
            if len(caption) <= 1000:
                try:
                    await cq.edit_message_caption(caption=caption, parse_mode=ParseMode.HTML, reply_markup=markup)
                    return
                except Exception as exc:
                    if "message is not modified" in str(exc).lower():
                        return
                    log.warning("editing artist photo caption failed; switching to text card: %s", exc)
            # Text pages have a larger limit; replace the photo rather than trying editMessageText on media.
            try:
                await cq.message.delete()
            except Exception:
                pass
            try:
                await cq.message.reply_text(caption, parse_mode=ParseMode.HTML, reply_markup=markup)
            except Exception as exc:
                log.warning("sending paginated artist text card failed: %s", exc)
        else:
            photo_status = await _send_card_photo(cq.message, term, caption, markup) if (term.get("image") or term.get("image_fallback")) else "missing"
            if "sent" == photo_status:
                try:
                    await cq.message.delete()
                except Exception:
                    try:
                        await cq.edit_message_text("✅ صفحهٔ کامل خواننده در پیام بعدی است.")
                    except Exception:
                        pass
            elif "unknown" == photo_status:
                pass  # Avoid a second send when Telegram's response timed out ambiguously.
            else:
                try:
                    await cq.edit_message_text(caption, parse_mode=ParseMode.HTML, reply_markup=markup)
                except Exception as exc:
                    log.warning("editing artist card failed: %s", exc)
    else:
        msg_target = update_or_query.message if hasattr(update_or_query, "message") else update_or_query
        photo_status = await _send_card_photo(msg_target, term, caption, markup) if (term.get("image") or term.get("image_fallback")) else "missing"
        if "missing" == photo_status or "rejected" == photo_status:
            try:
                await msg_target.reply_text(caption, parse_mode=ParseMode.HTML, reply_markup=markup)
            except Exception as exc:
                log.warning("sending text-only artist card failed: %s", exc)

    await increment_stat("artist_views")

async def artist_follow_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    user = update.effective_user
    try:
        _, term_id_text, page_text, sort = query.data.split(":", 3)
        term_id = int(term_id_text)
        page = max(1, int(page_text))
    except (AttributeError, ValueError):
        await query.answer("درخواست دنبال‌کردن نامعتبر است.", show_alert=True)
        return
    if term_id <= 0 or sort not in ("new", "old", "title"):
        await query.answer("شناسهٔ هنرمند نامعتبر است.", show_alert=True)
        return
    if not user:
        await query.answer("حساب کاربری در دسترس نیست.", show_alert=True)
        return
    await query.answer("در حال ثبت دنبال‌کردن…")

    artist_names = context.user_data.get("artist_names", {})
    artist_name = str(artist_names.get(str(term_id), "")) if isinstance(artist_names, dict) else ""
    if not artist_name:
        try:
            artist_data = await api.artist(term_id, page=1, per_page=1, sort="new")
            artist_name = str((artist_data.get("term") or {}).get("name") or "")[:250]
        except Exception:
            log.exception("could not load artist name for follow %s", term_id)
            if query.message:
                await query.message.reply_text("❌ اطلاعات هنرمند در دسترس نیست؛ کارت را دوباره از فهرست خوانندگان باز کنید.")
            return

    try:
        followed, followers = await toggle_artist_follow(user.id, term_id, artist_name)
    except Exception:
        log.exception("could not toggle artist follow for user %s", user.id)
        if query.message:
            await query.message.reply_text("❌ ثبت دنبال‌کردن انجام نشد؛ کمی بعد دوباره تلاش کنید.")
        return

    markup = getattr(query.message, "reply_markup", None) if query.message else None
    rows = []
    replaced = False
    if markup:
        for row in markup.inline_keyboard:
            new_row = []
            for button in row:
                data = getattr(button, "callback_data", None)
                if data and data.startswith(f"artist_follow:{term_id}:"):
                    label = "✅ دنبال‌شده" if followed else "➕ دنبال‌کردن"
                    if followers > 0:
                        label += f" · {followers}"
                    button = InlineKeyboardButton(label, callback_data=f"artist_follow:{term_id}:{page}:{sort}")
                    replaced = True
                new_row.append(button)
            rows.append(new_row)
    if replaced:
        try:
            await query.edit_message_reply_markup(reply_markup=InlineKeyboardMarkup(rows))
        except Exception as exc:
            log.info("artist follow saved but card markup could not be refreshed: %s", type(exc).__name__)
            if query.message:
                await query.message.reply_text("✅ وضعیت دنبال‌کردن هنرمند ذخیره شد؛ کارت را دوباره باز کنید تا به‌روز شود.")


async def _send_artist_follows_page(target, user_id: int, page: int = 1, *, edit: bool = False):
    data = await list_artist_follows_page(user_id, page=page, per_page=8)
    total = int(data["total"])
    total_pages = max(1, (total + data["per_page"] - 1) // data["per_page"])
    page = min(max(1, int(page)), total_pages)
    if page != data["page"]:
        data = await list_artist_follows_page(user_id, page=page, per_page=8)

    lines = [f"🔔 <b>هنرمندان دنبال‌شدهٔ شما</b> — {total:,} هنرمند".replace(",", "٬")]
    keyboard = []
    if data["results"]:
        lines.append(f"صفحهٔ {page} از {total_pages}:\n")
        valid_items = [item for item in data["results"] if int(item.get("term_id") or 0) > 0]
        semaphore = asyncio.Semaphore(4)

        async def load_current_artist(term_id: int):
            try:
                async with semaphore:
                    artist_data = await api.artist(term_id, page=1, per_page=1, sort="new")
                name = str((artist_data.get("term") or {}).get("name") or "").strip()
                return name, "ok" if name else "missing"
            except APIError as exc:
                return "", "missing" if exc.status == 404 else "unavailable"
            except Exception:
                log.info("could not refresh followed artist %s from WordPress", term_id)
                return "", "unavailable"

        term_ids = [int(item["term_id"]) for item in valid_items]
        current_artists = await asyncio.gather(*(load_current_artist(term_id) for term_id in term_ids))
        for item, (current_name, status) in zip(valid_items, current_artists):
            term_id = int(item["term_id"])
            name = current_name or f"هنرمند #{term_id}"
            status_note = " — دیگر در سایت موجود نیست" if status == "missing" else " — اطلاعات سایت فعلاً در دسترس نیست" if status == "unavailable" else ""
            lines.append(f"• <b>{html.escape(name[:100])}</b>{html.escape(status_note)}")
            keyboard.append([
                InlineKeyboardButton(f"🎤 {name[:44]}", callback_data=f"artist:{term_id}"),
                InlineKeyboardButton("🔕 لغو", callback_data=f"artist_unfollow:{term_id}:{page}"),
            ])
    else:
        lines.append("\nهنوز هنرمندی را دنبال نمی‌کنید. از کارت هنرمند روی «➕ دنبال‌کردن» بزنید.")

    navigation = []
    if page > 1:
        navigation.append(InlineKeyboardButton("◀️ قبلی", callback_data=f"artist_follows:page:{page - 1}"))
    if total_pages > 1:
        navigation.append(InlineKeyboardButton(f"{page}/{total_pages}", callback_data="noop"))
    if page < total_pages:
        navigation.append(InlineKeyboardButton("بعدی ▶️", callback_data=f"artist_follows:page:{page + 1}"))
    if navigation:
        keyboard.append(navigation)
    keyboard.append([InlineKeyboardButton("🎤 فهرست خوانندگان", callback_data="artists:page:1")])
    keyboard.append([InlineKeyboardButton("🏠 خانه", callback_data="home")])

    method = target.edit_text if edit else target.reply_text
    await method("\n".join(lines), parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(keyboard))


async def artist_follows_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user
    if user and update.effective_message:
        await _send_artist_follows_page(update.effective_message, user.id)


async def artist_follows_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    user = update.effective_user
    if not user:
        await query.answer("حساب کاربری در دسترس نیست.", show_alert=True)
        return
    try:
        page = max(1, int(query.data.rsplit(":", 1)[1]))
    except (AttributeError, ValueError):
        page = 1
    await query.answer()
    if query.message:
        await _send_artist_follows_page(query.message, user.id, page, edit=True)


async def artist_unfollow_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    user = update.effective_user
    try:
        _, term_id_text, page_text = query.data.split(":", 2)
        term_id = int(term_id_text)
        page = max(1, int(page_text))
    except (AttributeError, ValueError):
        await query.answer("درخواست نامعتبر است.", show_alert=True)
        return
    if not user or term_id <= 0:
        await query.answer("حساب یا شناسهٔ هنرمند نامعتبر است.", show_alert=True)
        return
    try:
        removed = await remove_artist_follow(user.id, term_id)
    except Exception:
        log.exception("could not remove artist follow for user %s", user.id)
        await query.answer("لغو دنبال‌کردن انجام نشد.", show_alert=True)
        return
    await query.answer("دنبال‌کردن لغو شد." if removed else "این هنرمند در فهرست نبود.")
    if query.message:
        await _send_artist_follows_page(query.message, user.id, page, edit=True)


async def artist_text_search(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    When user types a name and we are in artist search mode, search artists.
    For now, inline handled via main search — this is for /artist command fallback.
    """
    text = update.message.text.strip()
    try:
        data = await api.artists(q=text, page=1, per_page=PER_PAGE_ARTISTS)
    except APIError as exc:
        await update.message.reply_text(f"❌ جست‌وجوی خواننده انجام نشد: {exc.message}")
        return
    except Exception:
        log.exception("artist text search failed")
        await update.message.reply_text("❌ ارتباط با سایت برقرار نشد؛ کمی بعد دوباره امتحان کنید.")
        return
    results = data.get("results") or []
    if not results:
        await update.message.reply_text(
            f"😕 خواننده‌ای با نام «{text[:100]}» پیدا نشد. از فهرست الفبایی هم می‌توانید کمک بگیرید.",
            reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🎤 فهرست خوانندگان", callback_data="artists:page:1")], [InlineKeyboardButton("🏠 خانه", callback_data="home")]]),
        )
        return
    # Show as quick list
    keyboard = []
    for item in results[:8]:
        identifier = item.get("term_id") or item.get("slug")
        keyboard.append([InlineKeyboardButton(f"🎤 {str(item.get('name') or '')[:45]} ({item.get('count', 0)})", callback_data=f"artist:{identifier}")])
    query_token = uuid.uuid4().hex[:10]
    query_tokens = context.user_data.setdefault("artist_query_tokens", {})
    query_tokens[query_token] = text
    while len(query_tokens) > 20:
        query_tokens.pop(next(iter(query_tokens)))
    keyboard.append([InlineKeyboardButton("🔍 همهٔ نتایج خوانندگان", callback_data=f"artists:page:1:qref:{query_token}")])
    await update.message.reply_text(
        f"🎤 خوانندگان یافت‌شده برای «{text}»:\n" + "\n".join([f"• {r['name']} ({r['count']})" for r in results[:5]]),
        reply_markup=InlineKeyboardMarkup(keyboard),
    )
