"""Handlers for the Taranehyar website account connected to a Telegram user."""
import html
import logging
import re
from urllib.parse import urlsplit

from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from ..api_client import APIError, api

log = logging.getLogger("ahangban.account")
LINK_CODE_RE = re.compile(r"^[A-HJ-NP-Z2-9]{8}$", re.IGNORECASE)


def _telegram_id(update: Update) -> int:
    user = update.effective_user
    user_id = getattr(user, "id", 0) if user else 0
    return int(user_id) if isinstance(user_id, int) and user_id > 0 else 0


async def _require_private(update: Update, message) -> bool:
    chat = update.effective_chat
    if chat and chat.type == "private":
        return True
    await message.reply_text("🔒 برای امنیت اطلاعات حساب، این کار را در گفتگوی خصوصی با ربات انجام بده.")
    return False


def _safe_count(value) -> int:
    try:
        return max(0, int(value or 0))
    except (TypeError, ValueError):
        return 0


def _telegram_text_length(value: str) -> int:
    return len(value.encode("utf-16-le", errors="ignore")) // 2


def _safe_https_url(value) -> str:
    url = str(value or "").strip()
    try:
        parsed = urlsplit(url)
        if parsed.scheme == "https" and parsed.hostname and not parsed.username and not parsed.password:
            return url
    except ValueError:
        pass
    return ""


def _profile_markup(summary: dict) -> InlineKeyboardMarkup | None:
    profile_url = _safe_https_url(summary.get("url"))
    if not profile_url:
        return None
    return InlineKeyboardMarkup([[InlineKeyboardButton("🌐 حساب کاربری در سایت", url=profile_url)]])


def _summary_text(summary: dict) -> str:
    counts = summary.get("counts") if isinstance(summary.get("counts"), dict) else {}
    name = html.escape(str(summary.get("name") or "شنونده")[:120])
    return (
        f"👤 <b>خلاصهٔ حساب سایت</b>\n{name}\n\n"
        f"❤️ پسندیده‌ها: <b>{_safe_count(counts.get('likes'))}</b>\n"
        f"📚 پلی‌لیست‌ها: <b>{_safe_count(counts.get('playlists'))}</b>\n"
        f"🎤 خوانندگان دنبال‌شده: <b>{_safe_count(counts.get('follows'))}</b>\n"
        f"🎵 آهنگ‌های پلی‌لیست‌ها: <b>{_safe_count(counts.get('tracks'))}</b>\n"
        f"🕘 تاریخچه: <b>{_safe_count(counts.get('history'))}</b>\n"
        f"🔔 پیام‌های خوانده‌نشده: <b>{_safe_count(counts.get('unread'))}</b>"
    )


def _account_error_text(error: APIError, *, include_server_message: bool = False) -> str:
    if error.status in (401, 403, 503):
        return "⚠️ سرویس حساب سایت فعلاً آماده نیست؛ مدیر را مطلع کنید."
    if error.status == 429:
        return "⏳ کمی آرام‌تر! چند ثانیه بعد دوباره امتحان کن."
    if error.status == 404 and error.code == "tara_unlinked":
        return "🔗 اول حساب سایت را وصل کن؛ از پنل حساب سایت کد اتصال بگیر و آن را همین‌جا به‌صورت پیام متنی بفرست."
    if error.status == 400 and include_server_message:
        return "❌ " + html.escape(error.message[:300])
    if error.status == 404:
        log.warning("Taranehyar account API route/resource returned 404 (code=%s)", error.code or "unknown")
        return "⚠️ مسیر حساب کاربری سایت پیدا نشد؛ پیکربندی API سایت باید بررسی شود."
    if error.status == 0 or error.status >= 500:
        return "❌ الان نمی‌توانم به سایت وصل شوم؛ چند لحظه بعد دوباره تلاش کن."
    return "❌ درخواست حساب سایت انجام نشد؛ کمی بعد دوباره تلاش کن."


async def _reply_api_error(message, error: APIError, *, include_server_message: bool = False):
    await message.reply_text(
        _account_error_text(error, include_server_message=include_server_message),
        parse_mode=ParseMode.HTML,
    )


async def account_link_code_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Consume an eight-character website code sent as a plain text message."""
    message = update.effective_message
    user = update.effective_user
    if not message or not user or not message.text:
        return
    code = message.text.strip().upper()
    if not LINK_CODE_RE.fullmatch(code):
        return
    if not await _require_private(update, message):
        return

    telegram_id = _telegram_id(update)
    if telegram_id <= 0:
        await message.reply_text("❌ حساب تلگرام برای اتصال در دسترس نیست.")
        return

    # A linked Telegram account must not silently be moved to another website account.
    try:
        current = await api.account_me(telegram_id)
    except APIError as error:
        if not (error.status == 404 and error.code == "tara_unlinked"):
            await _reply_api_error(message, error)
            return
    else:
        await message.reply_text(
            "✅ این تلگرام قبلاً به حساب سایت وصل است.\n\n" + _summary_text(current),
            parse_mode=ParseMode.HTML,
            reply_markup=_profile_markup(current),
        )
        return

    try:
        result = await api.account_link(
            code=code,
            telegram_id=telegram_id,
            username=str(user.username or ""),
            first_name=str(user.first_name or ""),
        )
    except APIError as error:
        await _reply_api_error(message, error, include_server_message=True)
        return
    except ValueError:
        await message.reply_text("❌ شناسهٔ تلگرام یا کد اتصال معتبر نیست.")
        return

    # POST /link already returns a summary; refresh /me so the message reflects the same
    # canonical read endpoint that /me uses. If that read fails, retain the successful link.
    summary = result.get("user") if isinstance(result, dict) else None
    try:
        refreshed = await api.account_me(telegram_id)
        if isinstance(refreshed, dict):
            summary = refreshed
    except APIError as error:
        log.warning("link succeeded but the follow-up account summary could not be loaded (%s)", error.code or error.status)

    text = "✅ حساب سایت به تلگرام وصل شد. برای دیدن خلاصهٔ حساب از /me استفاده کن."
    if isinstance(summary, dict):
        text += "\n\n" + _summary_text(summary)
        markup = _profile_markup(summary)
    else:
        markup = None
    await message.reply_text(text, parse_mode=ParseMode.HTML, reply_markup=markup)


async def account_me_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    message = update.effective_message
    telegram_id = _telegram_id(update)
    if not message or telegram_id <= 0:
        return
    if not await _require_private(update, message):
        return
    try:
        summary = await api.account_me(telegram_id)
    except APIError as error:
        await _reply_api_error(message, error)
        return
    await message.reply_text(
        _summary_text(summary),
        parse_mode=ParseMode.HTML,
        reply_markup=_profile_markup(summary),
    )


async def account_saved_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show the site's saved count; the theme currently exposes no REST list of liked posts."""
    message = update.effective_message
    telegram_id = _telegram_id(update)
    if not message or telegram_id <= 0:
        return
    if not await _require_private(update, message):
        return
    try:
        summary = await api.account_me(telegram_id)
    except APIError as error:
        await _reply_api_error(message, error)
        return

    counts = summary.get("counts") if isinstance(summary.get("counts"), dict) else {}
    count = _safe_count(counts.get("likes"))
    text = (
        f"❤️ در حساب سایت <b>{count}</b> آهنگ پسندیده‌شده داری.\n"
        "برای دیدن یا مدیریت فهرست کامل، حساب سایت را باز کن."
    )
    await message.reply_text(
        text,
        parse_mode=ParseMode.HTML,
        reply_markup=_profile_markup(summary),
    )


async def account_follows_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    message = update.effective_message
    telegram_id = _telegram_id(update)
    if not message or telegram_id <= 0:
        return
    if not await _require_private(update, message):
        return
    try:
        data = await api.account_follows(telegram_id)
    except APIError as error:
        await _reply_api_error(message, error)
        return

    follows = data.get("follows") if isinstance(data, dict) else []
    follows = follows if isinstance(follows, list) else []
    if not follows:
        await message.reply_text("🎤 هنوز خواننده‌ای را در حساب سایت دنبال نمی‌کنی.")
        return

    lines = [f"🎤 <b>خوانندگان دنبال‌شده در سایت</b> — {len(follows)} مورد", ""]
    shown_count = 0
    for item in follows[:25]:
        if not isinstance(item, dict):
            continue
        name = html.escape(str(item.get("name") or "هنرمند")[:120])
        count = _safe_count(item.get("count"))
        artist_url = _safe_https_url(item.get("url"))
        if artist_url:
            name = f'<a href="{html.escape(artist_url, quote=True)}">{name}</a>'
        line = f"• {name} — {count} اثر"
        if _telegram_text_length("\n".join([*lines, line])) > 3600:
            break
        lines.append(line)
        shown_count += 1
    if len(follows) > shown_count:
        lines.append(f"\nو {len(follows) - shown_count} مورد دیگر؛ فهرست کامل در حساب سایت است.")
    await message.reply_text("\n".join(lines), parse_mode=ParseMode.HTML)


async def account_feed_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show the latest published tracks from artists followed on the website."""
    message = update.effective_message
    telegram_id = _telegram_id(update)
    if not message or telegram_id <= 0:
        return
    if not await _require_private(update, message):
        return
    try:
        data = await api.account_feed(telegram_id, limit=10)
    except APIError as error:
        await _reply_api_error(message, error)
        return

    items = data.get("items") if isinstance(data, dict) else []
    items = items if isinstance(items, list) else []
    if not items:
        await message.reply_text("🆕 فعلاً آهنگ تازه‌ای از خوانندگان دنبال‌شده پیدا نشد.")
        return

    lines = ["🆕 <b>تازه‌های خوانندگان دنبال‌شده</b>", ""]
    keyboard = []
    for item in items[:10]:
        if not isinstance(item, dict):
            continue
        title = str(item.get("title") or "آهنگ")[:140]
        artist = str(item.get("artist") or "")[:100]
        line = f"• <b>{html.escape(title)}</b>" + (f" — {html.escape(artist)}" if artist else "")
        if _telegram_text_length("\n".join([*lines, line])) > 3600:
            break
        lines.append(line)
        buttons = []
        post_id = _safe_count(item.get("id"))
        if post_id:
            buttons.append(InlineKeyboardButton("🎵 بازکردن در ربات", callback_data=f"song:{post_id}"))
        link = _safe_https_url(item.get("link"))
        if link:
            buttons.append(InlineKeyboardButton("🌐 سایت", url=link))
        if buttons:
            keyboard.append(buttons)

    await message.reply_text(
        "\n".join(lines),
        parse_mode=ParseMode.HTML,
        reply_markup=InlineKeyboardMarkup(keyboard) if keyboard else None,
    )


async def account_unlink_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    message = update.effective_message
    telegram_id = _telegram_id(update)
    if not message or telegram_id <= 0:
        return
    if not await _require_private(update, message):
        return
    try:
        await api.account_unlink(telegram_id)
    except APIError as error:
        await _reply_api_error(message, error)
        return
    await message.reply_text("✅ اتصال تلگرام از حساب سایت قطع شد.")
