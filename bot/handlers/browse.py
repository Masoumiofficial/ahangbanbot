import math
import logging
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import ContextTypes
from telegram.constants import ParseMode

from ..api_client import api
from ..utils.formatters import song_list_item

log = logging.getLogger("ahangban.browse")
PER_PAGE = 5

async def _browse(update: Update, context: ContextTypes.DEFAULT_TYPE, kind: str, page: int = 1):
    """
    kind: latest / popular
    """
    # Determine chat target
    if update.callback_query:
        cq = update.callback_query
        await cq.answer()
        await cq.edit_message_text("⏳ در حال بارگذاری…")
        send_edit = True
        chat = cq.message.chat
    else:
        send_edit = False
        chat = update.effective_chat

    if chat:
        try: await chat.send_action("typing")
        except: pass

    try:
        if kind == "latest":
            data = await api.latest(page=page, per_page=PER_PAGE)
            title = "🆕 جدیدترین آهنگ‌ها"
        else:
            data = await api.popular(page=page, per_page=PER_PAGE)
            title = "🔥 محبوب‌ترین‌های ماه"
    except Exception as e:
        log.exception("browse failed")
        err = f"❌ خطا: {e}"
        if send_edit:
            await update.callback_query.edit_message_text(err)
        else:
            await update.message.reply_text(err)
        return

    results = data.get("results") or []
    total = data.get("total") or len(results)
    per_page = data.get("per_page") or PER_PAGE
    total_pages = max(1, math.ceil(total / per_page)) if total else 1

    if not results:
        msg = "فعلاً موردی یافت نشد."
        if send_edit:
            await update.callback_query.edit_message_text(msg)
        else:
            await update.message.reply_text(msg)
        return

    header = f"{title} — صفحه {page} از {total_pages}\n"
    lines = [song_list_item(r, (page - 1) * per_page + i + 1) for i, r in enumerate(results)]
    text = header + "\n" + "\n\n".join(lines)

    keyboard = []
    for r in results:
        sid = r.get("id")
        t = (r.get("song") or r.get("title") or str(sid))[:30]
        a = (r.get("artist") or "")[:20]
        label = f"{t} — {a}" if a else t
        keyboard.append([InlineKeyboardButton(f"🎵 {label}", callback_data=f"song:{sid}")])

    nav = []
    if page > 1:
        nav.append(InlineKeyboardButton("◀️ قبلی", callback_data=f"browse:{kind}:{page-1}"))
    nav.append(InlineKeyboardButton(f"{page}/{total_pages}", callback_data="noop"))
    if page < total_pages:
        nav.append(InlineKeyboardButton("بعدی ▶️", callback_data=f"browse:{kind}:{page+1}"))
    keyboard.append(nav)
    keyboard.append([InlineKeyboardButton("🏠 خانه", callback_data="home")])

    markup = InlineKeyboardMarkup(keyboard)
    if send_edit:
        await update.callback_query.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=markup)
    else:
        await update.message.reply_text(text, parse_mode=ParseMode.HTML, reply_markup=markup)

async def latest_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    args = context.args if hasattr(context, "args") else []
    page = 1
    if args and args[0].isdigit():
        page = int(args[0])
    await _browse(update, context, "latest", page)

async def popular_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    args = context.args if hasattr(context, "args") else []
    page = 1
    if args and args[0].isdigit():
        page = int(args[0])
    await _browse(update, context, "popular", page)

async def browse_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    # data: browse:latest:2
    parts = update.callback_query.data.split(":")
    if len(parts) < 3:
        return
    kind = parts[1]
    try: page = int(parts[2])
    except: page = 1
    await _browse(update, context, kind, page)
