"""
SQLite محلی ربات: کاربران، تاریخچه، rate-limit و کش file_id تلگرام
"""
import aiosqlite
import time
import logging
from pathlib import Path

log = logging.getLogger("ahangban.db")

DB_PATH = Path(__file__).parent / "ahangban.db"
# Allow override via env
import os
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

# --- Favorites ---
async def add_favorite(user_id: int, song_id: int, title: str = "", artist: str = "") -> bool:
    try:
        async with aiosqlite.connect(DB_PATH) as db:
            await db.execute("INSERT OR IGNORE INTO favorites (user_id, song_id, song_title, artist, created_at) VALUES (?, ?, ?, ?, ?)",
                             (user_id, song_id, title, artist, int(time.time())))
            await db.commit()
            return True
    except Exception:
        return False

async def remove_favorite(user_id: int, song_id: int):
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("DELETE FROM favorites WHERE user_id=? AND song_id=?", (user_id, song_id))
        await db.commit()

async def list_favorites(user_id: int, limit: int = 20) -> list[dict]:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute("SELECT song_id, song_title, artist FROM favorites WHERE user_id=? ORDER BY created_at DESC LIMIT ?", (user_id, limit)) as cur:
            rows = await cur.fetchall()
            return [dict(r) for r in rows]

async def is_favorite(user_id: int, song_id: int) -> bool:
    async with aiosqlite.connect(DB_PATH) as db:
        async with db.execute("SELECT 1 FROM favorites WHERE user_id=? AND song_id=?", (user_id, song_id)) as cur:
            return (await cur.fetchone()) is not None

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
