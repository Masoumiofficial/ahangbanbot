"""
SQLite محلی ربات: کاربران، تاریخچه، rate-limit و کش file_id تلگرام
"""
import logging
import os
import time
from pathlib import Path

import aiosqlite

log = logging.getLogger("ahangban.db")

DB_PATH = Path(__file__).parent / "ahangban.db"
# Allow override via env
if os.getenv("AHANGBAN_DB_PATH"):
    DB_PATH = Path(os.getenv("AHANGBAN_DB_PATH"))

SCHEMA = """
CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY,
    username TEXT,
    first_name TEXT,
    last_name TEXT,
    lang TEXT DEFAULT 'fa',
    created_at INTEGER NOT NULL,
    last_seen INTEGER NOT NULL,
    blocked INTEGER DEFAULT 0,
    is_admin INTEGER DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_users_last_seen
    ON users (last_seen DESC, user_id DESC);

CREATE TABLE IF NOT EXISTS searches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    query TEXT NOT NULL,
    results_count INTEGER DEFAULT 0,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS favorites (
    user_id INTEGER NOT NULL,
    song_id INTEGER NOT NULL,
    song_title TEXT,
    artist TEXT,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (user_id, song_id)
);
CREATE INDEX IF NOT EXISTS idx_favorites_user_created
    ON favorites (user_id, created_at DESC, song_id DESC);
CREATE INDEX IF NOT EXISTS idx_favorites_song_likes
    ON favorites (song_id);

CREATE TABLE IF NOT EXISTS artist_follows (
    user_id INTEGER NOT NULL,
    term_id INTEGER NOT NULL,
    artist_name TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL,
    PRIMARY KEY (user_id, term_id)
);
CREATE INDEX IF NOT EXISTS idx_artist_follows_user_created
    ON artist_follows (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_artist_follows_term_id
    ON artist_follows (term_id);

CREATE TABLE IF NOT EXISTS rate_limits (
    user_id INTEGER PRIMARY KEY,
    count INTEGER DEFAULT 0,
    window_start INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS file_cache (
    url TEXT PRIMARY KEY,
    file_id TEXT NOT NULL,
    file_unique_id TEXT,
    quality TEXT,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS stats_daily (
    date TEXT PRIMARY KEY,
    searches INTEGER DEFAULT 0,
    song_views INTEGER DEFAULT 0,
    artist_views INTEGER DEFAULT 0,
    users_active INTEGER DEFAULT 0
);
"""

async def init_db():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    async with aiosqlite.connect(DB_PATH) as db:
        await db.executescript(SCHEMA)
        await db.commit()
    log.info("DB initialized at %s", DB_PATH)

async def get_db():
    db = await aiosqlite.connect(DB_PATH)
    db.row_factory = aiosqlite.Row
    return db

# --- Users ---
async def upsert_user(user_id: int, username: str = "", first_name: str = "", last_name: str = "", is_admin: bool = False):
    now = int(time.time())
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("""
            INSERT INTO users (user_id, username, first_name, last_name, created_at, last_seen, is_admin)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(user_id) DO UPDATE SET
                username=excluded.username,
                first_name=excluded.first_name,
                last_name=excluded.last_name,
                last_seen=excluded.last_seen,
                is_admin=excluded.is_admin
        """, (user_id, username or "", first_name or "", last_name or "", now, now, 1 if is_admin else 0))
        await db.commit()

async def is_blocked(user_id: int) -> bool:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT blocked FROM users WHERE user_id=?", (user_id,)) as cur:
            row = await cur.fetchone()
            return bool(row and row["blocked"])

async def set_blocked(user_id: int, blocked: bool):
    now = int(time.time())
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("""
            INSERT INTO users (user_id, created_at, last_seen, blocked)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(user_id) DO UPDATE SET blocked=excluded.blocked
        """, (user_id, now, now, 1 if blocked else 0))
        await db.commit()

async def count_users(active_days: int = 7) -> dict:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT COUNT(*) as c FROM users WHERE blocked=0") as cur:
            total = (await cur.fetchone())["c"]
        cutoff = int(time.time()) - active_days * 86400
        async with db.execute("SELECT COUNT(*) as c FROM users WHERE last_seen >= ? AND blocked=0", (cutoff,)) as cur:
            active = (await cur.fetchone())["c"]
        return {"total": total, "active_7d": active}

# --- Rate limit ---
async def check_rate_limit(user_id: int, limit_per_min: int = 20) -> bool:
    """Atomically enforce a per-user sliding window across concurrent bot updates."""
    now = int(time.time())
    window = 60
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        # Serialize the read/modify/write so two simultaneous updates cannot both pass the limit.
        await db.execute("BEGIN IMMEDIATE")
        async with db.execute("SELECT count, window_start FROM rate_limits WHERE user_id=?", (user_id,)) as cur:
            row = await cur.fetchone()
        if not row:
            await db.execute("INSERT INTO rate_limits (user_id, count, window_start) VALUES (?, 1, ?)", (user_id, now))
            await db.commit()
            return True
        if now - int(row["window_start"]) >= window:
            await db.execute("UPDATE rate_limits SET count=1, window_start=? WHERE user_id=?", (now, user_id))
            await db.commit()
            return True
        if int(row["count"]) >= limit_per_min:
            await db.rollback()
            return False
        await db.execute("UPDATE rate_limits SET count=count+1 WHERE user_id=?", (user_id,))
        await db.commit()
        return True

# --- Searches ---
async def log_search(user_id: int, query: str, results_count: int = 0):
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("INSERT INTO searches (user_id, query, results_count, created_at) VALUES (?, ?, ?, ?)",
                         (user_id, query, results_count, int(time.time())))
        await db.commit()

async def recent_searches(user_id: int, limit: int = 5) -> list[str]:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT query FROM searches WHERE user_id=? ORDER BY created_at DESC LIMIT ?", (user_id, limit)) as cur:
            rows = await cur.fetchall()
            return [r["query"] for r in rows]

# --- Song likes / personal favorites ---
async def add_favorite(user_id: int, song_id: int, title: str = "", artist: str = "") -> bool:
    if int(user_id) <= 0 or int(song_id) <= 0:
        return False
    try:
        async with aiosqlite.connect(DB_PATH) as db:
            cursor = await db.execute(
                "INSERT OR IGNORE INTO favorites (user_id, song_id, song_title, artist, created_at) VALUES (?, ?, ?, ?, ?)",
                (int(user_id), int(song_id), str(title or "")[:250], str(artist or "")[:250], int(time.time())),
            )
            await db.commit()
            return cursor.rowcount == 1
    except aiosqlite.Error:
        log.exception("could not add song favorite")
        return False

async def toggle_favorite(user_id: int, song_id: int, title: str = "", artist: str = "") -> tuple[bool, int]:
    """Atomically toggle one user's like and return (liked_by_user, total_likes)."""
    if int(user_id) <= 0 or int(song_id) <= 0:
        return False, 0
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("BEGIN IMMEDIATE")
        async with db.execute("SELECT 1 FROM favorites WHERE user_id=? AND song_id=?", (int(user_id), int(song_id))) as cur:
            exists = await cur.fetchone() is not None
        if exists:
            await db.execute("DELETE FROM favorites WHERE user_id=? AND song_id=?", (int(user_id), int(song_id)))
            liked = False
        else:
            await db.execute(
                "INSERT OR IGNORE INTO favorites (user_id, song_id, song_title, artist, created_at) VALUES (?, ?, ?, ?, ?)",
                (int(user_id), int(song_id), str(title or "")[:250], str(artist or "")[:250], int(time.time())),
            )
            liked = True
        async with db.execute("SELECT COUNT(*) FROM favorites WHERE song_id=?", (int(song_id),)) as cur:
            row = await cur.fetchone()
            likes = int(row[0] or 0)
        await db.commit()
        return liked, likes

async def remove_favorite(user_id: int, song_id: int) -> bool:
    if int(user_id) <= 0 or int(song_id) <= 0:
        return False
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute("DELETE FROM favorites WHERE user_id=? AND song_id=?", (int(user_id), int(song_id)))
        await db.commit()
        return cursor.rowcount > 0

async def list_favorites(user_id: int, limit: int = 20) -> list[dict]:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute(
            "SELECT song_id, song_title, artist FROM favorites WHERE user_id=? ORDER BY created_at DESC, song_id DESC LIMIT ?",
            (int(user_id), max(1, min(100, int(limit)))),
        ) as cur:
            rows = await cur.fetchall()
            return [dict(r) for r in rows]

async def list_favorites_page(user_id: int, page: int = 1, per_page: int = 8) -> dict:
    page = max(1, min(1000000, int(page)))
    per_page = max(1, min(20, int(per_page)))
    offset = (page - 1) * per_page
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT COUNT(*) AS total FROM favorites WHERE user_id=?", (int(user_id),)) as cur:
            total = int((await cur.fetchone())["total"] or 0)
        async with db.execute(
            "SELECT song_id, song_title, artist FROM favorites WHERE user_id=? ORDER BY created_at DESC, song_id DESC LIMIT ? OFFSET ?",
            (int(user_id), per_page, offset),
        ) as cur:
            rows = await cur.fetchall()
    return {"results": [dict(row) for row in rows], "total": total, "page": page, "per_page": per_page}

async def favorite_state(user_id: int, song_id: int) -> tuple[bool, int]:
    async with aiosqlite.connect(DB_PATH) as db, db.execute(
        "SELECT EXISTS(SELECT 1 FROM favorites WHERE user_id=? AND song_id=?) AS liked, "
        "(SELECT COUNT(*) FROM favorites WHERE song_id=?) AS likes",
        (int(user_id), int(song_id), int(song_id)),
    ) as cur:
        row = await cur.fetchone()
        return bool(row[0]), int(row[1] or 0)

async def is_favorite(user_id: int, song_id: int) -> bool:
    liked, _ = await favorite_state(user_id, song_id)
    return liked

# --- Artist follows ---
async def add_artist_follow(user_id: int, term_id: int, artist_name: str = "") -> bool:
    if int(user_id) <= 0 or int(term_id) <= 0:
        return False
    try:
        async with aiosqlite.connect(DB_PATH) as db:
            cursor = await db.execute(
                "INSERT OR IGNORE INTO artist_follows (user_id, term_id, artist_name, created_at) VALUES (?, ?, ?, ?)",
                (int(user_id), int(term_id), str(artist_name or "")[:250], int(time.time())),
            )
            await db.commit()
            return cursor.rowcount == 1
    except aiosqlite.Error:
        log.exception("could not follow artist")
        return False

async def toggle_artist_follow(user_id: int, term_id: int, artist_name: str = "") -> tuple[bool, int]:
    """Atomically toggle a follow and return (followed_by_user, follower_count)."""
    if int(user_id) <= 0 or int(term_id) <= 0:
        return False, 0
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("BEGIN IMMEDIATE")
        async with db.execute("SELECT 1 FROM artist_follows WHERE user_id=? AND term_id=?", (int(user_id), int(term_id))) as cur:
            exists = await cur.fetchone() is not None
        if exists:
            await db.execute("DELETE FROM artist_follows WHERE user_id=? AND term_id=?", (int(user_id), int(term_id)))
            followed = False
        else:
            await db.execute(
                "INSERT OR IGNORE INTO artist_follows (user_id, term_id, artist_name, created_at) VALUES (?, ?, ?, ?)",
                (int(user_id), int(term_id), str(artist_name or "")[:250], int(time.time())),
            )
            followed = True
        async with db.execute("SELECT COUNT(*) FROM artist_follows WHERE term_id=?", (int(term_id),)) as cur:
            row = await cur.fetchone()
            followers = int(row[0] or 0)
        await db.commit()
        return followed, followers

async def remove_artist_follow(user_id: int, term_id: int) -> bool:
    if int(user_id) <= 0 or int(term_id) <= 0:
        return False
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute("DELETE FROM artist_follows WHERE user_id=? AND term_id=?", (int(user_id), int(term_id)))
        await db.commit()
        return cursor.rowcount > 0

async def is_artist_followed(user_id: int, term_id: int) -> bool:
    async with aiosqlite.connect(DB_PATH) as db, db.execute(
        "SELECT 1 FROM artist_follows WHERE user_id=? AND term_id=?",
        (int(user_id), int(term_id)),
    ) as cur:
        return (await cur.fetchone()) is not None

async def count_artist_followers(term_id: int) -> int:
    async with aiosqlite.connect(DB_PATH) as db, db.execute(
        "SELECT COUNT(*) FROM artist_follows WHERE term_id=?",
        (int(term_id),),
    ) as cur:
        row = await cur.fetchone()
        return int(row[0] or 0)

async def list_artist_follows_page(user_id: int, page: int = 1, per_page: int = 8) -> dict:
    page = max(1, min(1000000, int(page)))
    per_page = max(1, min(20, int(per_page)))
    offset = (page - 1) * per_page
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT COUNT(*) AS total FROM artist_follows WHERE user_id=?", (int(user_id),)) as cur:
            total = int((await cur.fetchone())["total"] or 0)
        async with db.execute(
            "SELECT term_id, artist_name FROM artist_follows WHERE user_id=? ORDER BY created_at DESC, term_id DESC LIMIT ? OFFSET ?",
            (int(user_id), per_page, offset),
        ) as cur:
            rows = await cur.fetchall()
    return {"results": [dict(row) for row in rows], "total": total, "page": page, "per_page": per_page}

async def list_users_page(page: int = 1, per_page: int = 10) -> dict:
    """Return Telegram user IDs and moderation status for the admin-only user browser."""
    page = max(1, min(1000000, int(page)))
    per_page = max(1, min(20, int(per_page)))
    offset = (page - 1) * per_page
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT COUNT(*) AS total FROM users") as cur:
            total = int((await cur.fetchone())["total"] or 0)
        async with db.execute(
            "SELECT user_id, username, first_name, last_name, blocked, is_admin, last_seen "
            "FROM users ORDER BY last_seen DESC, user_id DESC LIMIT ? OFFSET ?",
            (per_page, offset),
        ) as cur:
            rows = await cur.fetchall()
    return {"results": [dict(row) for row in rows], "total": total, "page": page, "per_page": per_page}

# --- File cache (Telegram file_id for same URL) ---
async def get_file_id(url: str) -> str | None:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT file_id FROM file_cache WHERE url=?", (url,)) as cur:
            row = await cur.fetchone()
            return row["file_id"] if row else None

async def set_file_id(url: str, file_id: str, quality: str = "", unique_id: str = ""):
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("INSERT OR REPLACE INTO file_cache (url, file_id, file_unique_id, quality, created_at) VALUES (?, ?, ?, ?, ?)",
                         (url, file_id, unique_id, quality, int(time.time())))
        await db.commit()

# --- Stats ---
async def increment_stat(field: str):
    # field: searches, song_views, artist_views
    allowed = {"searches", "song_views", "artist_views"}
    if field not in allowed:
        return
    today = time.strftime("%Y-%m-%d")
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute(f"INSERT INTO stats_daily (date, {field}) VALUES (?, 1) ON CONFLICT(date) DO UPDATE SET {field}={field}+1", (today,))
        await db.commit()

async def get_stats() -> dict:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT SUM(searches) as s, SUM(song_views) as sv, SUM(artist_views) as av FROM stats_daily") as cur:
            row = await cur.fetchone()
            return {
                "searches_total": row["s"] or 0,
                "song_views_total": row["sv"] or 0,
                "artist_views_total": row["av"] or 0,
            }
