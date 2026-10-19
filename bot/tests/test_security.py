"""
تست‌های امنیتی — ورودی مخرب نباید به API برسد یا SSRF ایجاد کند
"""
from bot.utils.normalize import is_valid_query
from bot.utils.formatters import lyrics_text

def test_sql_injection_string():
    # Should be treated as plain text search, not executed
    q = "' OR 1=1 --"
    ok, _ = is_valid_query(q)
    # Either allow (harmless LIKE) or block — but must not crash
    assert isinstance(ok, bool)

def test_xss_attempt():
    q = "<script>alert(1)</script>"
    ok, msg = is_valid_query(q)
    # Should be allowed to search but sanitized in output (esc)
    # At least not crash
    assert isinstance(ok, bool)

def test_path_traversal_url():
    q = "https://example.com/../../etc/passwd"
    ok, _ = is_valid_query(q)
    assert not ok  # URLs blocked

def test_very_long_input():
    q = "ا" * 500
    ok, msg = is_valid_query(q)
    assert not ok
    assert "۱۰۰" in msg or "طولانی" in msg


def test_lyrics_html_is_escaped_before_telegram_parse_mode():
    text = lyrics_text({"song": "عنوان", "lyrics": "<script>alert(1)</script> & test"})
    assert "<script>" not in text
    assert "alert(1)" in text
    assert "&amp; test" in text
