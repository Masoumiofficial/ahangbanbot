import ipaddress
import os
import re
from pathlib import Path
from urllib.parse import urlsplit
from dotenv import load_dotenv

# Load .env from project root and bot dir. The WordPress PHP worker does not read these files.
for p in [Path(__file__).parent.parent / ".env", Path(__file__).parent / ".env", Path.cwd() / ".env"]:
    if p.exists():
        load_dotenv(p, override=False)


def _env(name: str, default: str = "") -> str:
    value = os.getenv(name, default)
    return value.strip() if isinstance(value, str) else default


BOT_TOKEN = _env("BOT_TOKEN")
AHANGBAN_API_BASE = _env("AHANGBAN_API_BASE", "https://ahangban.ir/wp-json/ahangban/v1").rstrip("/")
AHANGBAN_API_TOKEN = _env("AHANGBAN_API_TOKEN")
ADMIN_IDS = [int(value.strip()) for value in _env("ADMIN_IDS", "").split(",") if value.strip().isdigit()]
LOG_LEVEL = _env("LOG_LEVEL", "INFO")
CACHE_TTL = int(_env("CACHE_TTL", "300") or 300)
RATE_LIMIT_PER_MINUTE = int(_env("RATE_LIMIT_PER_MINUTE", "20") or 20)

# Brand
BRAND = {
    "name": "آهنگ‌بان",
    "site": "https://ahangban.ir",
    "artists_path": "https://ahangban.ir/artists/",
    "color": "#185ADB",
}


def is_admin(user_id: int) -> bool:
    return user_id in ADMIN_IDS


def validate() -> list[str]:
    errors = []
    if not re.fullmatch(r"\d{6,}:[A-Za-z0-9_-]{20,}", BOT_TOKEN):
        errors.append("BOT_TOKEN نامعتبر است (.env را بررسی کنید)")

    try:
        parsed_base = urlsplit(AHANGBAN_API_BASE)
        hostname = (parsed_base.hostname or "").lower().rstrip(".")
        # Accessing .port also validates malformed/out-of-range port syntax.
        parsed_base.port
        valid_base = (
            parsed_base.scheme == "https"
            and bool(hostname)
            and not parsed_base.username
            and not parsed_base.password
            and not parsed_base.query
            and not parsed_base.fragment
            and hostname != "localhost"
            and not hostname.endswith((".localhost", ".local", ".internal", ".test", ".invalid"))
        )
        try:
            address = ipaddress.ip_address(hostname)
        except ValueError:
            address = None
        if address is not None and not address.is_global:
            valid_base = False
    except ValueError:
        valid_base = False

    if not valid_base:
        errors.append("AHANGBAN_API_BASE باید یک URL عمومی HTTPS بدون credential باشد")

    if not re.fullmatch(r"[a-fA-F0-9]{64}", AHANGBAN_API_TOKEN):
        errors.append("AHANGBAN_API_TOKEN باید دقیقاً ۶۴ رقم hex باشد و با مقدار PHP یکسان تنظیم شود")
    return errors
