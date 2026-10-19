from telegram import InlineKeyboardButton, InlineKeyboardMarkup, ReplyKeyboardMarkup

# ---------- Main menu (ReplyKeyboard for easy access) ----------
def main_reply_keyboard(is_admin: bool = False) -> ReplyKeyboardMarkup:
    keyboard = [
        ["🔍 جست‌وجوی آهنگ", "👤 جست‌وجوی خواننده"],
        ["🆕 جدیدترین‌ها", "🔥 محبوب‌ترین‌ها"],
        ["🎤 فهرست خوانندگان", "ℹ️ راهنما"],
    ]
    if is_admin:
        keyboard.append(["🛠 مدیریت"])
    return ReplyKeyboardMarkup(
        keyboard,
        resize_keyboard=True,
        one_time_keyboard=False,
        input_field_placeholder="نام آهنگ یا خواننده را بنویسید…",
    )

# ---------- Inline main menu ----------
def main_inline(is_admin: bool = False) -> InlineKeyboardMarkup:
    rows = [
        [InlineKeyboardButton("🔍 جست‌وجوی آهنگ", callback_data="prompt:search")],
        [
            InlineKeyboardButton("🆕 جدیدترین‌ها", callback_data="browse:latest:1"),
            InlineKeyboardButton("🔥 محبوب‌ترین‌ها", callback_data="browse:popular:1"),
        ],
        [InlineKeyboardButton("🎤 همهٔ خوانندگان", callback_data="artists:page:1")],
        [InlineKeyboardButton("ℹ️ راهنما", callback_data="help")],
    ]
    if is_admin:
        rows.append([InlineKeyboardButton("🛠 مدیریت ربات", callback_data="admin:menu")])
    return InlineKeyboardMarkup(rows)

def back_to_home() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[InlineKeyboardButton("🏠 بازگشت به خانه", callback_data="home")]])

def search_type_choice() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([
        [
            InlineKeyboardButton("🎵 آهنگ", callback_data="search_type:audio"),
            InlineKeyboardButton("🎬 موزیک‌ویدیو", callback_data="search_type:video"),
            InlineKeyboardButton("💿 آلبوم", callback_data="search_type:album"),
        ],
        [InlineKeyboardButton("🔎 همه", callback_data="search_type:")],
        [InlineKeyboardButton("🏠 خانه", callback_data="home")],
    ])

def pagination(prefix: str, page: int, total_pages: int, extra: str = "") -> InlineKeyboardMarkup:
    buttons = []
    row = []
    if page > 1:
        row.append(InlineKeyboardButton("◀️ قبلی", callback_data=f"{prefix}:{page-1}{extra}"))
    row.append(InlineKeyboardButton(f"{page} / {total_pages}", callback_data="noop"))
    if page < total_pages:
        row.append(InlineKeyboardButton("بعدی ▶️", callback_data=f"{prefix}:{page+1}{extra}"))
    if row:
        buttons.append(row)
    buttons.append([InlineKeyboardButton("🏠 خانه", callback_data="home")])
    return InlineKeyboardMarkup(buttons)

def song_card(song: dict, show_qualities: bool = True) -> InlineKeyboardMarkup:
    sid = song.get("id")
    rows = []
    # Qualities
    quals = song.get("qualities") or {}
    if show_qualities and quals:
        qrow = []
        for label in ("128", "320"):
            if label in quals:
                qrow.append(InlineKeyboardButton(f"⬇️ {label}", callback_data=f"dl:{sid}:{label}"))
        if qrow:
            rows.append(qrow)
        # Album qualities
        for label in ("album128", "album320"):
            if label in quals:
                rows.append([InlineKeyboardButton(f"💿 دانلود {label}", callback_data=f"dl:{sid}:{label}")])
        # Video
        for label in ("480", "720", "1080"):
            if label in quals:
                rows.append([InlineKeyboardButton(f"🎬 {label}p", callback_data=f"dl:{sid}:{label}")])
        # If has album tracks, show them
        if song.get("album_tracks"):
            rows.append([InlineKeyboardButton("💿 ترک‌های آلبوم", callback_data=f"album:{sid}")])

    # Artists
    artists = song.get("artists") or []
    if artists:
        for a in artists[:3]:
            artist_id = a.get("term_id")
            if artist_id:
                rows.append([InlineKeyboardButton(f"👤 {str(a.get('name') or 'هنرمند')[:48]}", callback_data=f"artist:{artist_id}")])

    # Lyrics
    if song.get("lyrics"):
        rows.append([InlineKeyboardButton("📝 متن ترانه", callback_data=f"lyrics:{sid}")])

    # Related
    if song.get("related"):
        rows.append([InlineKeyboardButton("🎧 آثار مرتبط", callback_data=f"related:{sid}")])

    # Permalink + Share
    if song.get("permalink"):
        rows.append([InlineKeyboardButton("🔗 صفحه در سایت", url=song["permalink"])])
    # Back to artist or search
    rows.append([
        InlineKeyboardButton("🔍 جست‌وجوی جدید", callback_data="prompt:search"),
        InlineKeyboardButton("🏠 خانه", callback_data="home"),
    ])
    return InlineKeyboardMarkup(rows)

def artist_card(term: dict, page: int, total_pages: int, sort: str = "new") -> InlineKeyboardMarkup:
    slug = term.get("term_id") or term.get("slug")
    rows = []
    # Sort options
    rows.append([
        InlineKeyboardButton("🕐 جدیدترین" + (" ✅" if sort == "new" else ""), callback_data=f"artist:{slug}:1:new"),
        InlineKeyboardButton("📅 قدیمی‌ترین" + (" ✅" if sort == "old" else ""), callback_data=f"artist:{slug}:1:old"),
    ])
    # Pagination
    if total_pages > 1:
        nav = []
        if page > 1:
            nav.append(InlineKeyboardButton("◀️ قبلی", callback_data=f"artist:{slug}:{page-1}:{sort}"))
        nav.append(InlineKeyboardButton(f"{page}/{total_pages}", callback_data="noop"))
        if page < total_pages:
            nav.append(InlineKeyboardButton("بعدی ▶️", callback_data=f"artist:{slug}:{page+1}:{sort}"))
        rows.append(nav)
    if term.get("link"):
        rows.append([InlineKeyboardButton("🔗 صفحه در سایت", url=term["link"])])
    rows.append([InlineKeyboardButton("🏠 خانه", callback_data="home")])
    return InlineKeyboardMarkup(rows)

def artists_list_pagination(page: int, total_pages: int, query: str = "", letter: str = "") -> InlineKeyboardMarkup:
    extra = ""
    if query: extra += f":q:{query}"
    if letter: extra += f":l:{letter}"
    buttons = []
    # Letter bar (quick jump) — only show if not filtered
    if not letter and not query:
        letters = list("ابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی")
        row = []
        for ch in letters[:8]:
            row.append(InlineKeyboardButton(ch, callback_data=f"artists:letter:{ch}"))
            if len(row) == 4:
                buttons.append(row)
                row = []
        # Add more rows for remaining letters (in 2 rows)
        for i in range(8, len(letters), 6):
            chunk = letters[i:i+6]
            buttons.append([InlineKeyboardButton(c, callback_data=f"artists:letter:{c}") for c in chunk])

    # Pagination
    nav = []
    if page > 1:
        nav.append(InlineKeyboardButton("◀️ قبلی", callback_data=f"artists:page:{page-1}{extra}"))
    nav.append(InlineKeyboardButton(f"{page}/{total_pages}", callback_data="noop"))
    if page < total_pages:
        nav.append(InlineKeyboardButton("بعدی ▶️", callback_data=f"artists:page:{page+1}{extra}"))
    buttons.append(nav)
    buttons.append([InlineKeyboardButton("🏠 خانه", callback_data="home")])
    return InlineKeyboardMarkup(buttons)
