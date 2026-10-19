import math
import logging
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import ContextTypes
from telegram.constants import ParseMode

from ..api_client import api, APIError
from ..db import log_search, increment_stat, upsert_user, is_blocked
from ..config import is_admin
from ..utils.normalize import normalize, is_valid_query
from ..utils.formatters import search_header, no_results, song_list_item
from ..utils.keyboards import pagination, main_inline

log = logging.getLogger("ahangban.search")

PER_PAGE = 5

async def search_text_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    Every text message is treated as a search unless it's a command or known reply-keyboard text.
    """
    if not update.message or not update.message.text:
        return
    text = update.message.text.strip()

    # Ignore reply-keyboard shortcuts — delegate
    shortcuts = {
        "🔍 جست‌وجوی آهنگ": "search_prompt",
        "👤 جست‌وجوی خواننده": "artist_prompt",
        "🆕 جدیدترین‌ها": "latest",
        "🔥 محبوب‌ترین‌ها": "popular",
        "🎤 فهرست خوانندگان": "artists",
        "ℹ️ راهنما": "help",
    }
    if text in shortcuts:
        action = shortcuts[text]
        if action == "search_prompt":
            await update.message.reply_text("🎵 نام آهنگ موردنظر را بنویسید (مثلاً: بی‌تاب محسن یگانه):")
            return
        elif action == "artist_prompt":
            await update.message.reply_text("🎤 نام خواننده را بنویسید (مثلاً: معین، گوگوش، سیاوش قمیشی):")
            return
        elif action == "latest":
            from .browse import latest_handler
            await latest_handler(update, context)
            return
        elif action == "popular":
            from .browse import popular_handler
            await popular_handler(update, context)
            return
        elif action == "artists":
            from .artist import artists_list_handler
            await artists_list_handler(update, context)
            return
        elif action == "help":
            from .start import help_handler
            await help_handler(update, context)
            return

    # Commands already handled elsewhere
    if text.startswith("/"):
        return

    user = update.effective_user
    if user and await is_blocked(user.id):
        return

    # Validate
    ok, err = is_valid_query(text)
    if not ok:
        await update.message.reply_text(f"⚠️ {err}\n\nلطفاً عبارت کوتاه و مشخص وارد کنید.")
        return

    # Rate limiting is handled globally in main.py middleware; here also safe
    await update.message.chat.send_action("typing")
    try:
        data = await api.search(q=text, page=1, per_page=PER_PAGE)
    except APIError as e:
        if e.status == 429:
            await update.message.reply_text("⏳ درخواست زیاد — لطفاً چند ثانیه صبر کنید.")
        elif e.status in (401, 403):
            await update.message.reply_text("🔧 خطای احراز هویت به سایت — مدیر را مطلع کنید.")
        else:
            await update.message.reply_text(f"❌ خطا در جست‌وجو: {e.message}")
        return
    except Exception as e:
        log.exception("search failed")
        await update.message.reply_text("❌ ارتباط با سایت暂时 برقرار نشد — دوباره تلاش کنید.")
        return

    results = data.get("results") or []
    total = data.get("total") or 0
    if user:
        await log_search(user.id, text, len(results))
        await increment_stat("searches")
        await upsert_user(user.id, user.username or "", user.first_name or "", user.last_name or "", is_admin=is_admin(user.id))

    if not results:
        await update.message.reply_text(no_results(text), parse_mode=ParseMode.HTML, reply_markup=main_inline())
        return

    await _send_search_page(update, context, text, data, page=1)

async def search_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    /search <query> [type]  — e.g. /search محسن یگانه یا /search type:video قمیشی
    """
    args = context.args or []
    if not args:
        await update.message.reply_text("🔍 استفاده: /search <نام آهنگ یا خواننده>\nمثال: /search معین آسمون")
        return
    q = " ".join(args)
    # Fake update for reuse
    update.message.text = q
    await search_text_handler(update, context)

async def _send_search_page(update, context, query: str, data: dict, page: int):
    results = data.get("results") or []
    total = data.get("total") or 0
    per_page = data.get("per_page") or PER_PAGE
    total_pages = max(1, math.ceil(total / per_page)) if total else 1

    header = search_header(query, total, page, per_page)
    body_lines = []
    for i, item in enumerate(results, start=(page - 1) * per_page + 1):
        body_lines.append(song_list_item(item, i))

    text = header + "\n\n" + "\n\n".join(body_lines)
    if total > per_page:
        text += f"\n\n📄 صفحه {page} از {total_pages} — برای دیدن بقیه «بعدی» را بزنید."

    # Build inline buttons: each result → song detail
    keyboard = []
    for item in results:
        sid = item.get("id")
        title = (item.get("song") or item.get("title") or str(sid))[:30]
        artist = (item.get("artist") or "")[:20]
        label = f"{title} — {artist}" if artist else title
        keyboard.append([InlineKeyboardButton(f"🎵 {label}", callback_data=f"song:{sid}")])

    # Pagination
    extra = f":{query}"
    nav = []
    if page > 1:
        nav.append(InlineKeyboardButton("◀️ قبلی", callback_data=f"search:{page-1}{extra}"))
    nav.append(InlineKeyboardButton(f"{page}/{total_pages}", callback_data="noop"))
    if page < total_pages:
        nav.append(InlineKeyboardButton("بعدی ▶️", callback_data=f"search:{page+1}{extra}"))
    keyboard.append(nav)
    keyboard.append([InlineKeyboardButton("🏠 خانه", callback_data="home")])

    markup = InlineKeyboardMarkup(keyboard)

    # Save context for pagination
    context.user_data["last_query"] = query
    context.user_data["last_search_data"] = data

    if update.callback_query:
        try:
            await update.callback_query.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=markup)
        except Exception:
            await update.callback_query.message.reply_text(text, parse_mode=ParseMode.HTML, reply_markup=markup)
    else:
        await update.message.reply_text(text, parse_mode=ParseMode.HTML, reply_markup=markup)

async def search_pagination_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    data = query.data  # search:{page}:{query}
    # Extract: search:2:query text (query may contain colons)
    parts = data.split(":", 2)
    if len(parts) < 2:
        return
    try:
        page = int(parts[1])
    except ValueError:
        return
    # Query is after second colon
    q = parts[2] if len(parts) > 2 else context.user_data.get("last_query", "")
    if not q:
        await query.edit_message_text("⛔ عبارت جست‌وجو منقضی شد — دوباره جست‌وجو کنید.", reply_markup=main_inline())
        return
    await query.edit_message_text("⏳ در حال بارگذاری…")
    try:
        result = await api.search(q=q, page=page, per_page=PER_PAGE)
    except Exception as e:
        await query.edit_message_text(f"❌ خطا: {e}")
        return
    # Need to mock update with callback_query
    fake_update = update
    fake_update._effective_message = query.message
    await _send_search_page(fake_update, context, q, result, page)
