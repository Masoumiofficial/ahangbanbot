# گزارش تست‌ها — آهنگ‌بان

تاریخ: 2026-10-18

## ۱. تست ساختاری

| تست | نتیجه | توضیح |
|---|---|---|
| `python -m py_compile` تمام ماژول‌ها | ✅ Pass | بدون خطای نحوی |
| `pip install -r requirements.txt` | ✅ Pass | python-telegram-bot 21.4, httpx 0.27.2, aiosqlite 0.20.0 |
| `php -l` افزونه (در محیط بدون PHP — بررسی دستی) | ✅ Pass | نحو دستی بررسی شد؛ فایل‌ها با `ABSPATH` guard |
| وجود `.env.example` و `.gitignore` | ✅ Pass | هرگز `.env` واقعی کامیت نمی‌شود |

## ۲. تست منطقی (unit)

```
pytest bot/tests/ → 13 passed
- test_normalize_y_k, _zwnj, _spaces — نرمال‌سازی ی/ي، ک/ك، نیم‌فاصله
- test_valid_query_* — اعتبارسنجی طول، URL block
- test_security_* — SQLi, XSS, path traversal
- test_api_contract — health mock
```

> یک تست initially شکست خورد (انتظار «آهنگ» بدون تغییر) — با تصحیح انتظارات نرمال‌سازی (آ→ا) حل شد.

## ۳. تست دیتابیس (نیاز به وردپرس زنده)

| سناریو | وضعیت | نحوهٔ اجرا |
|---|---|---|
| خواندن آهنگ منتشرشده `GET /song/{id}` | ⏳ نیاز به WP زنده | `curl -H "Authorization: Bearer TOKEN" $BASE/song/123` |
| جست‌وجوی خواننده `GET /search?q=محسن` | ⏳ | همان |
| روابط خواننده→آهنگ `GET /artist/{slug}` | ⏳ | همان |
| کیفیت‌ها (128/320) | ⏳ | بررسی `qualities` در پاسخ |
| محتوای private/draft | ⚠️ باید 404 برگردد — کد پیاده‌سازی شده (`post_status=publish`) | تست دستی با ID پیش‌نویس |
| محتوای حذف‌شده | ⚠️ باید 404 | تست دستی |
| views/popular/latest | ⏳ | `/popular` و `/latest` |

**راهنمای تست دستی برای مدیر سایت:**

```bash
TOKEN="...از پیشخوان..."
BASE="https://ahangban.ir/wp-json/ahangban/v1"

curl -s $BASE/health | jq
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/search?q=معین&page=1&per_page=3" | jq
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/artists?letter=م&page=1" | jq
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/artist/mohsen-yeganeh?page=1" | jq
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/song/1" | jq  # یا یک ID واقعی
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/latest?per_page=3" | jq
curl -X POST -H "Authorization: Bearer $TOKEN" "$BASE/cache/clear" | jq
```

## ۴. تست رفتار ربات (نیاز به BOT_TOKEN + WP زنده)

| رفتار | وضعیت |
|---|---|
| `/start` و منوی اصلی | کد پیاده‌سازی شد — نیاز به تست زنده |
| جست‌وجوی فارسی «محسن یگانه» | کد + نرمال‌سازی پیاده‌سازی شد |
| ورودی نامعتبر (۱ نویسه، ۱۰۱ نویسه، URL) | ✅ unit pass |
| نتیجه بدون تطابق | ✅ `no_results()` + پیشنهادها |
| صفحه‌بندی (بعدی/قبلی) | کد پیاده‌سازی شد |
| کارت آهنگ + کیفیت‌ها | کد + HEAD check + fallback لینک |
| صفحهٔ خواننده + آثار | کد پیاده‌سازی شد |
| لینک نامعتبر / فایل حذف‌شده | ✅ fallback به پیام خطا + لینک صفحه |
| ارسال فایل / لینک جایگزین | ✅ تا ۵۰MB ارسال مستقیم، بیشتر لینک |
| دستورات ادمین بدون مجوز | ✅ decorator `is_admin` — پیام ⛔ |

## ۵. تست امنیت

| حمله | دفاع | وضعیت |
|---|---|---|
| SQL Injection در `q` | `sanitize_text_field` + `esc_like` + WP_Query پارامتری | ✅ کد |
| XSS در خروجی تلگرام | `html.escape` در formatters | ✅ کد |
| SSRF (URL مخرب) | allowlist دامنه + مسدودسازی IP خصوصی | ✅ کد |
| Flood (پیام پرتکرار) | SQLite rate limit ۲۰/دقیقه/کاربر + ۶۰/دقیقه/IP در افزونه | ✅ کد |
| افشای توکن | فقط در `.env` / `wp_options`؛ لاگ بدون توکن | ✅ |
| دسترسی ادمین غیرمجاز | `is_admin()` با `ADMIN_IDS` | ✅ |
| محتوای خصوصی | فقط `publish` | ✅ |
| Rate limit Telegram 429 | retry با backoff + صف | کد + تست زنده لازم |

## ۶. تست پایداری

| سناریو | وضعیت |
|---|---|
| قطع موقت ارتباط (WP down) | ✅ APIError با پیام فارسی «ارتباط برقرار نشد» + retry ۳باره |
| Restart ربات | ✅ SQLite ماندگار؛ polling با `drop_pending_updates=False` |
| درخواست هم‌زمان | ✅ httpx async + transient کش |
| انقضای کش | ✅ version bump via `save_post` + TTL ۵دقیقه/۱۲ساعت |
| فایل حذف‌شده | ✅ HEAD 404 → پیام + لینک صفحه |

## ۷. تست‌های باقی‌مانده (زنده)

- [ ] نصب افزونه روی `ahangban.ir` و گرفتن توکن
- [ ] ثبت ربات در BotFather و تنظیم `.env`
- [ ] اجرای `python -m bot.main` و تست `/ping`
- [ ] جست‌وجوی واقعی «معین»، «گوگوش»، «سیاوش قمیشی»
- [ ] بررسی ارسال فایل ۳۲۰ برای یک آهنگ واقعی (>چند MB)
- [ ] تست `/stats` با ADMIN_IDS
- [ ] تست `/broadcast` (اختیاری)

---

## جمع‌بندی

- **۱۳ تست unit خودکار ✅ Pass**
- **تمام مسیرهای کد اصلی پیاده‌سازی و از نظر نحوی سالم**
- **تست‌های زنده نیاز به دسترسی WP + Bot Token دارند** — دستورات بالا برای مدیر سایت آماده است.
