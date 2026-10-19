import os
from pathlib import Path
from dotenv import load_dotenv

# Load .env from project root and bot dir
for p in [Path(__file__).parent.parent / ".env", Path(__file__).parent / ".env", Path.cwd() / ".env"]:
    if p.exists():
        load_dotenv(p, override=False)

def _env(name: str, default: str = "") -> str:
    v = os.getenv(name, default)
    return v.strip() if isinstance(v, str) else default

BOT_TOKEN = _env("BOT_TOKEN")
AHANGBAN_API_BASE = _env("AHANGBAN_API_BASE", "https://ahangban.ir/wp-json/ahangban/v1").rstrip("/")
AHANGBAN_API_TOKEN = _env("AHANGBAN_API_TOKEN")
ADMIN_IDS = [int(x.strip()) for x in _env("ADMIN_IDS", "").split(",") if x.strip().isdigit()]
BOT_USERNAME = _env("BOT_USERNAME", "")
LOG_LEVEL = _env("LOG_LEVEL", "INFO")
CACHE_TTL = int(_env("CACHE_TTL", "300") or 300)
RATE_LIMIT_PER_MINUTE = int(_env("RATE_LIMIT_PER_MINUTE", "20") or 20)

# Brand
BRAND = {
    "name": "آهنگ‌بان",
    "site": "https://ahangban.ir",
    "singers_path": "https://ahangban.ir/singers/",
    "color": "#185ADB",
}

def is_admin(user_id: int) -> bool:
    return user_id in ADMIN_IDS

def validate() -> list[str]:
    errs = []
    if not BOT_TOKEN or ":" not in BOT_TOKEN:
        errs.append("BOT_TOKEN نامعتبر است (.env را بررسی کنید)")
    if not AHANGBAN_API_BASE or not AHANGBAN_API_BASE.startswith("http"):
        errs.append("AHANGBAN_API_BASE باید https://... باشد")
    if not AHANGBAN_API_TOKEN or len(AHANGBAN_API_TOKEN) < 32:
        errs.append("AHANGBAN_API_TOKEN کوتاه/خالی است — از پیشخوان وردپرس کپی کنید")
    return errs
