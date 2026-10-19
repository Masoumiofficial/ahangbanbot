import html
from datetime import datetime

def esc(text: str) -> str:
    return html.escape(text or "")

def song_caption(song: dict) -> str:
    """
    کپشن فارسی برای کارت آهنگ — HTML (parse_mode=HTML)
    """
    title = esc(song.get("title") or song.get("song") or "—")
    song_name = esc(song.get("song") or "")
    artist = esc(song.get("artist") or "")
    type_label = esc(song.get("type") or "")
    date = song.get("date_jalali") or ""
    views = song.get("views")
    permalink = song.get("permalink") or ""

    lines = []
    lines.append(f"🎵 <b>{title}</b>")
    if song_name and song_name != title:
        lines.append(f"🎶 اثر: <b>{song_name}</b>")
    if artist:
        lines.append(f"👤 خواننده: <b>{artist}</b>")
    if type_label:
        lines.append(f"🏷 نوع: {type_label}")
    if date:
        lines.append(f"📅 انتشار: {date}")
    if views:
        lines.append(f"👁 بازدید: {views:,}".replace(",", "٬"))
    if permalink:
        lines.append(f'🔗 <a href="{esc(permalink)}">صفحه در آهنگ‌بان</a>')

    return "\n".join(lines)

def song_list_item(song: dict, idx: int = 0) -> str:
    prefix = f"{idx}. " if idx else "• "
    title = esc(song.get("song") or song.get("title") or "—")
    artist = esc(song.get("artist") or "")
    type_label = esc(song.get("type") or "")
    if artist:
        return f"{prefix}<b>{title}</b> — {artist} <i>({type_label})</i>"
    return f"{prefix}<b>{title}</b> <i>({type_label})</i>"

def artist_caption(term: dict, buckets: dict = None) -> str:
    name = esc(term.get("name") or "—")
    count = term.get("count", 0)
    desc = term.get("description") or ""
    # Strip HTML for description preview
    if desc:
        import re
        desc = re.sub(r"<[^>]+>", "", desc).strip()
        if len(desc) > 300:
            desc = desc[:300] + "…"
        desc = esc(desc)

    lines = [f"🎤 <b>{name}</b>"]
    if count:
        lines.append(f"🎵 تعداد آثار: <b>{count}</b>")
    if buckets:
        parts = []
        for k, label in [("audio", "آهنگ"), ("album", "آلبوم"), ("video", "موزیک‌ویدیو"), ("other", "سایر")]:
            if buckets.get(k):
                parts.append(f"{buckets[k]} {label}")
        if parts:
            lines.append("📊 " + " · ".join(parts))
    if desc:
        lines.append(f"\n{desc}")
    if term.get("link"):
        lines.append(f'\n🔗 <a href="{esc(term["link"])}">صفحه در سایت</a>')
    return "\n".join(lines)

def search_header(query: str, total: int, page: int, per_page: int) -> str:
    q = esc(query)
    total_fa = f"{total:,}".replace(",", "٬")
    return f"🔍 نتایج برای «<b>{q}</b>» — {total_fa} مورد (صفحه {page})"

def no_results(query: str) -> str:
    q = esc(query)
    return (
        f"😕 نتیجه‌ای برای «<b>{q}</b>» یافت نشد.\n\n"
        "پیشنهادها:\n"
        "• املای نام خواننده/آهنگ را بررسی کنید (ی/ي، ک/ك یکسان‌سازی می‌شود)\n"
        "• فقط بخشی از نام را بنویسید (مثلاً «یگانه» به‌جای نام کامل)\n"
        "• از دکمهٔ «فهرست خوانندگان» برای مرور الفبایی استفاده کنید"
    )

def lyrics_text(song: dict) -> str:
    title = esc(song.get("song") or song.get("title") or "")
    artist = esc(song.get("artist") or "")
    lyrics = song.get("lyrics") or ""
    # Keep allowed HTML but escape
    # lyrics already wp_kses_post from API — we trust it but re-escape for safety
    # For Telegram HTML we need to convert <p><br> to \n and strip tags
    if not lyrics:
        return "متنی برای این اثر ثبت نشده است."
    import re
    # Simple tag strip for Telegram
    lyrics = re.sub(r"<br\s*/?>", "\n", lyrics, flags=re.I)
    lyrics = re.sub(r"</p>", "\n\n", lyrics, flags=re.I)
    lyrics = re.sub(r"<[^>]+>", "", lyrics)
    lyrics = html.unescape(lyrics).strip()
    lyrics = html.escape(lyrics)
    lyrics = re.sub(r"\n{3,}", "\n\n", lyrics)
    # Limit for Telegram (4096)
    header = f"📝 متن ترانهٔ «{title}»"
    if artist:
        header += f" از {artist}"
    full = header + "\n" + "─" * 20 + "\n" + lyrics
    if len(full) > 4000:
        full = full[:4000] + "…\n\n(متن کامل در سایت)"
    return full

def welcome_text(name: str = "") -> str:
    n = f" {esc(name)}" if name else ""
    return (
        f"سلام{n} عزیز! 👋\n\n"
        "به <b>ربات رسمی آهنگ‌بان</b> خوش آمدید 🎶\n"
        "اینجا می‌توانید جدیدترین آهنگ‌ها را جست‌وجو، گوش دهید و دانلود کنید — مستقیم از آرشیو واقعی سایت.\n\n"
        "✨ امکانات:\n"
        "• 🔍 جست‌وجو بر اساس نام آهنگ یا خواننده (با هوش فارسی: ی/ي و ک/ك فرقی نمی‌کند)\n"
        "• 🎤 مرور همهٔ خوانندگان به ترتیب الفبای فارسی\n"
        "• 🆕 جدیدترین و 🔥 محبوب‌ترین آثار\n"
        "• 💿 کیفیت‌های ۱۲۸ و ۳۲۰ + موزیک‌ویدیو\n"
        "• 📝 متن ترانه و آثار مرتبط\n\n"
        "برای شروع، نام آهنگ یا خواننده را بنویسید یا یکی از دکمه‌های زیر را بزنید."
    )

def help_text() -> str:
    return (
        "ℹ️ <b>راهنمای ربات آهنگ‌بان</b>\n\n"
        "🔍 <b>جست‌وجو:</b> کافیست نام آهنگ یا خواننده را تایپ کنید و ارسال کنید. "
        "ربات به‌صورت خودکار نرمال‌سازی فارسی (ی/ي، ک/ك، نیم‌فاصله) را انجام می‌دهد.\n\n"
        "🎤 <b>فهرست خوانندگان:</b> دکمهٔ «فهرست خوانندگان» را بزنید و با حرف الفبا فیلتر کنید.\n\n"
        "🆕 <b>جدیدترین / محبوب:</b> آخرین آثار منتشرشده یا پرشنونده‌ترین‌های ماه.\n\n"
        "🎵 <b>کارت آهنگ:</b> روی هر نتیجه بزنید تا کاور، کیفیت‌ها، متن ترانه و صفحهٔ سایت را ببینید. "
        "ارسال فایل در صورت مجاز بودن (حجم ≤۵۰MB) مستقیم در تلگرام انجام می‌شود؛ در غیر این صورت لینک دانلود داده می‌شود.\n\n"
        "📌 دستورات:\n"
        "/start — شروع و منوی اصلی\n"
        "/search &lt;عبارت&gt; — جست‌وجوی مستقیم\n"
        "/latest — جدیدترین‌ها\n"
        "/popular — محبوب‌ترین‌ها\n"
        "/artist &lt;نام&gt; — صفحهٔ خواننده\n"
        "/help — همین راهنما\n"
        "/ping — بررسی سلامت اتصال به سایت\n\n"
        "🔗 سایت: https://ahangban.ir\n"
        "💬 پشتیبانی: @ahangban_support"
    )
