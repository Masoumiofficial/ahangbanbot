import ipaddress
import os
import re
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

from dotenv import load_dotenv

# Load .env from project root and bot dir. The WordPress PHP worker does not read these files.
for p in [Path(__file__).parent.parent / ".env", Path(__file__).parent / ".env", Path.cwd() / ".env"]:
    if p.exists():
        load_dotenv(p, override=False)


def _env(name: str, default: str = "") -> str:
    value = os.getenv(name, default)
    return value.strip() if isinstance(value, str) else default


def _derive_tara_api_base(base: str) -> str:
    """Derive the theme account namespace from the existing WordPress API origin."""
    try:
        parsed = urlsplit((base or "").strip())
        parsed.port  # force validation of malformed/out-of-range ports
    except ValueError:
        return ""
    if not parsed.scheme or not parsed.netloc:
        return ""

    path = parsed.path.rstrip("/")
    if path.endswith("/wp-json/ahangban/v1"):
        path = path[: -len("ahangban/v1")] + "tara/v1"
    elif path.endswith("/wp-json/tara/v1"):
        pass
    elif path.endswith("/wp-json"):
        path += "/tara/v1"
    else:
        path = "/wp-json/tara/v1"
    return urlunsplit((parsed.scheme, parsed.netloc, path, "", "")).rstrip("/")


def _telegram_bot_token() -> str:
    """Prefer the documented name while preserving compatibility with existing deployments."""
    return _env("TELEGRAM_BOT_TOKEN") or _env("BOT_TOKEN")


TELEGRAM_BOT_TOKEN = _telegram_bot_token()
BOT_TOKEN = TELEGRAM_BOT_TOKEN
AHANGBAN_API_BASE = _env("AHANGBAN_API_BASE", "https://ahangban.ir/wp-json/ahangban/v1").rstrip("/")
TARA_API_BASE = _env("TARA_API_BASE") or _derive_tara_api_base(AHANGBAN_API_BASE)
TARA_API_BASE = TARA_API_BASE.rstrip("/")
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


def _is_public_https_base(value: str) -> bool:
    try:
        parsed = urlsplit((value or "").strip())
        hostname = (parsed.hostname or "").lower().rstrip(".")
        parsed.port  # force validation of malformed/out-of-range port syntax
        valid = (
            parsed.scheme == "https"
            and bool(hostname)
            and not parsed.username
            and not parsed.password
            and not parsed.query
            and not parsed.fragment
            and hostname != "localhost"
            and not hostname.endswith((".localhost", ".local", ".internal", ".test", ".invalid"))
        )
        try:
            address = ipaddress.ip_address(hostname)
        except ValueError:
            address = None
        return valid and (address is None or address.is_global)
    except ValueError:
        return False


def validate() -> list[str]:
    errors = []
    if not re.fullmatch(r"\d{6,}:[A-Za-z0-9_-]{20,}", BOT_TOKEN):
        errors.append("TELEGRAM_BOT_TOKEN (یا BOT_TOKEN قدیمی) نامعتبر است")

    if not _is_public_https_base(AHANGBAN_API_BASE):
        errors.append("AHANGBAN_API_BASE باید یک URL عمومی HTTPS بدون credential باشد")
    if not _is_public_https_base(TARA_API_BASE):
        errors.append("TARA_API_BASE باید یک URL عمومی HTTPS بدون credential باشد")

    if not re.fullmatch(r"[a-fA-F0-9]{64}", AHANGBAN_API_TOKEN):
        errors.append("AHANGBAN_API_TOKEN باید دقیقاً ۶۴ رقم hex و با توکن تنظیم‌شده در WordPress یکسان باشد")
    return errors
