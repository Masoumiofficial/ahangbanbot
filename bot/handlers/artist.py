import math
import logging
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import ContextTypes
from telegram.constants import ParseMode

from ..api_client import api, APIError
from ..db import increment_stat
from ..utils.formatters import artist_caption, song_list_item
from ..utils.keyboards import artist_card, artists_list_pagination

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
    if update.callback_query:
        data = update.callback_query.data
        # formats: artists:page:2  or artists:letter:م  or artists:page:1:q:...:l:...
        parts = data.split(":")
        if len(parts) >= 3 and parts[1] == "page":
            try: page = int(parts[2])
            except: pass
            # extra params :q:xxx :l:xxx
            if ":q:" in data:
                q = data.split(":q:")[1].split(":l:")[0]
            if ":l:" in data:
                letter = data.split(":l:")[-1]
        elif len(parts) >= 3 and parts[1] == "letter":
            letter = parts[2]
            page = 1
        await update.callback_query.answer()
        await update.callback_query.edit_message_text("⏳ در حال بارگذاری خوانندگان…")
        chat = update.callback_query.message.chat
        send_fn = lambda text, **kw: update.callback_query.edit_message_text(text, **kw)
    else:
        chat = update.effective_chat
        send_fn = lambda text, **kw: update.message.reply_text(text, **kw)
        if chat:
            try: await chat.send_action("typing")
            except: pass

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
        header += f" (حرف «{letter}»)"
    if q:
        header += f" — جست‌وجو: «{q}»"
    header += f"\nصفحه {page} از {total_pages}\n"

    lines = []
    keyboard = []
    for item in results:
        count = item.get("count", 0)
        name = item.get("name") or ""
        slug = item.get("slug") or item.get("term_id")
        # Persian digits?
        lines.append(f"• <b>{name}</b> — {count} اثر")
        keyboard.append([InlineKeyboardButton(f"🎤 {name} ({count})", callback_data=f"artist:{slug}")])

    text = header + "\n" + "\n".join(lines)

    pagination_kb = artists_list_pagination(page, total_pages, query=q, letter=letter)
    # Merge result buttons + pagination
    all_buttons = keyboard + pagination_kb.inline_keyboard

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
        try: page = int(parts[2])
        except: page = 1
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
        try: await chat.send_action("typing")
        except: pass

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

    # Artist pagination + sort
    term_kb = artist_card(term, page, total_pages, sort)
    # Merge
    all_buttons = keyboard + term_kb.inline_keyboard

    cover = term.get("image")

    try:
        if edit_mode and cq:
            # If cover, send photo as new message (can't edit photo)
            if cover and page == 1:
                await cq.message.reply_photo(photo=cover, caption=caption, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(all_buttons))
                # Also edit old to simple text to avoid duplicate
                await cq.edit_message_text(caption, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(all_buttons))
            else:
                await cq.edit_message_text(caption, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(all_buttons))
        else:
            msg_target = update_or_query.message if hasattr(update_or_query, "message") else cq.message
            if cover:
                await msg_target.reply_photo(photo=cover, caption=caption, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(all_buttons))
            else:
                await msg_target.reply_text(caption, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(all_buttons))
    except Exception as e:
        log.warning("show artist failed: %s", e)
        try:
            if cq and edit_mode:
                await cq.edit_message_text(caption, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(all_buttons))
            else:
                await update_or_query.message.reply_text(caption, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(all_buttons))
        except Exception:
            pass

    await increment_stat("artist_views")

async def artist_text_search(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    When user types a name and we are in artist search mode, search artists.
    For now, inline handled via main search — this is for /artist command fallback.
    """
    text = update.message.text.strip()
    try:
        data = await api.artists(q=text, page=1, per_page=PER_PAGE_ARTISTS)
    except Exception:
        return
    results = data.get("results") or []
    if not results:
        return
    # Show as quick list
    keyboard = []
    for item in results[:8]:
        keyboard.append([InlineKeyboardButton(f"🎤 {item['name']} ({item['count']})", callback_data=f"artist:{item['slug']}")])
    keyboard.append([InlineKeyboardButton("🔍 همهٔ نتایج خوانندگان", callback_data="artists:page:1:q:" + text)])
    await update.message.reply_text(
        f"🎤 خوانندگان یافت‌شده برای «{text}»:\n" + "\n".join([f"• {r['name']} ({r['count']})" for r in results[:5]]),
        reply_markup=InlineKeyboardMarkup(keyboard),
    )
