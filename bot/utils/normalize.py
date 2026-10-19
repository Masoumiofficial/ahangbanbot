"""
نرمال‌سازی فارسی — یکسان با افزونهٔ وردپرس
"""
import re

_ZWNJ = "\u200c"
_ZWJ = "\u200d"

def normalize(text: str) -> str:
    if not text:
        return ""
    text = text.replace(_ZWNJ, " ").replace(_ZWJ, " ")
    # ي/ك عربی → فارسی
    text = text.replace("ي", "ی").replace("ك", "ک").replace("ة", "ه").replace("ۀ", "ه")
    text = text.replace("ؤ", "و").replace("أ", "ا").replace("إ", "ا").replace("آ", "ا").replace("ٱ", "ا").replace("ئ", "ی").replace("ى", "ی")
    # Tashkeel + kashida
    text = re.sub(r"[\u064B-\u0652\u0640\u0670]", "", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()

def normalize_lower(text: str) -> str:
    return normalize(text).lower()

def is_valid_query(text: str) -> tuple[bool, str]:
    """
    Validate user input before sending to API.
    Returns (ok, error_message)
    """
    t = text.strip()
    if not t:
        return False, "متن خالی است."
    if len(t) > 100:
        return False, "متن بیش از ۱۰۰ نویسه است."
    if len(t) < 2:
        return False, "حداقل ۲ نویسه وارد کنید."
    # Block obvious injection / URL spam
    if re.search(r"https?://", t):
        return False, "لطفاً فقط نام آهنگ یا خواننده را وارد کنید (بدون لینک)."
    # Allow Persian, Arabic, Latin, numbers, spaces, - _
    # But block excessive special chars
    if len(re.findall(r"[^\w\s\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\-_.،,]", t)) > 3:
        return False, "نویسه‌های غیرمجاز زیاد است."
    return True, ""
