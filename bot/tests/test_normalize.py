import pytest
from bot.utils.normalize import normalize, is_valid_query

def test_normalize_y_k():
    assert normalize("علي") == "علی"
    assert normalize("كاظم") == "کاظم"
    assert normalize("محمّد") == "محمد"  # tashkeel removed

def test_normalize_zwnj():
    assert normalize("موزیک\u200cویدیو") == "موزیک ویدیو"
    # ZWNJ → space, and آ→ا in normalized search form (intentional for search)
    assert normalize("آهنگ\u200cها") == "اهنگ ها"

def test_normalize_spaces():
    assert normalize("  محسن    یگانه  ") == "محسن یگانه"

def test_valid_query_ok():
    ok, _ = is_valid_query("محسن یگانه")
    assert ok

def test_valid_query_too_short():
    ok, msg = is_valid_query("ا")
    assert not ok

def test_valid_query_too_long():
    ok, _ = is_valid_query("a" * 101)
    assert not ok

def test_valid_query_url_blocked():
    ok, _ = is_valid_query("https://evil.com")
    assert not ok
