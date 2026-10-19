# معماری پیشنهادی — آهنگ‌بان تلگرام

## ارزیابی گزینه‌ها

| معیار | گزینه A: مستقل + API | گزینه B: فقط افزونه WP | انتخاب نهایی |
|---|---|---|---|
| نیاز به اجرای دائمی | بیرون از هاست اشتراکی (VPS/کانتینر) | روی هاست اشتراکی — ناپایدار | **هیبرید A+B** |
| دسترسی به DB اصلی | فقط از API امن وردپرس | مستقیم | امن: فقط API |
| وابستگی به قالب | صفر — اگر قالب عوض شد API پابرجاست | زیاد | **API لایهٔ مجرد** |
| امکان polling/webhook | هر دو — پولینگ پیش‌فرض چون هاست webhook را ممکن است مسدود کند | محدود | پولینگ پیش‌فرض، وب‌هوک اختیاری |
| امنیت | توکن جداگانه + HMAC، حداقل دسترسی DB | کل وردپرس در معرض | هیبرید امن‌تر |
| قابلیت نگهداری | ربات پایتون مستقل، تست‌پذیر | PHP درگیر با قالب رمزگذاری‌شده | پایتون + افزونهٔ کوچک |

### نتیجه: **معماری هیبرید — افزونهٔ رابط امن + سرویس ربات مستقل**

```
[کاربر تلگرام] ⇄ Telegram Bot API ⇄ [سرویس ربات Python] ⇄ HTTPS+Token ⇄ [افزونه ahangban-connector @ WP ] ⇄ WP DB (post/singer/postmeta/termmeta)
                                         ↕
                                   SQLite محلی (کش، وضعیت گفتگو، علاقه‌مندی‌ها، rate-limit)
```

### چرا هیبرید؟
- هاست `etehadya` اشتراکی است و اجرای دائمی پایتون/نود روی آن قابل اتکا نیست (لاگ `charts_add_shortcode` نشان می‌دهد حتی افزونه‌های سفارشی ناقص‌اند).
- `functions.php` رمزگذاری ionCube دارد؛ دست‌زدن به قالب ریسک ۵۰۰ شدن سایت است.
- WP REST هسته مسدود/خالی برگشته → باید endpoint اختصاصی ساخت.
- ربات باید مستقل از ظاهر قالب بماند (اصل «تغییر قالب نباید ربات را بشکند»).

---

## فناوری‌ها

| لایه | انتخاب | دلیل |
|---|---|---|
| افزونه WP | PHP 7.4+ / 8.x (سازگار با ionCube Loader) | بدون وابستگی خارجی، فقط WP Core |
| ربات | **Python 3.11 + python-telegram-bot 21.x (async)** | پایدار، تایپ‌شده، جامعهٔ بزرگ، مناسب برای پولینگ طولانی |
| DB محلی ربات | **SQLite + aiosqlite** | بدون نیاز به Postgres روی هاست اشتراکی؛ برای ۱۰k کاربر کافی، قابل مهاجرت به Postgres |
| کش WP | Transients API (۱۲h برای فهرست خوانندگان، ۵m برای جست‌وجو) | هماهنگ با کش فعلی قالب |
| کش ربات | TTL در SQLite + حافظه | جلوگیری از حملهٔ Flood |
| احراز هویت API | `Authorization: Bearer <64-hex token>` + امضای HMAC اختیاری | ساده، بدون OAuth |
| استقرار ربات | systemd / Docker Compose (polling) | بدون نیاز به دامنهٔ عمومی برای webhook |

---

## جریان داده (Sequence)

1. کاربر `/start` یا متن جست‌وجو می‌فرستد → PTB handler.
2. `api_client` با `httpx` درخواست `GET /ahangban/v1/search?q=...` + `Authorization` می‌زند.
3. افزونه: اعتبارسنجی توکن → rate-limit (Transient) → نرمال‌سازی فارسی (ی/ي، ک/ك، ZWNJ) → `WP_Query` با `post_status=publish` → خروجی JSON (id, title, song, artist, type, cover, qualities, permalink, artist_terms).
4. ربات نتایج را صفحه‌بندی (۵تایی) + InlineKeyboard می‌سازد.
5. کارت آهنگ: `song/{id}` → جزئیات + لینک‌های دانلود معتبر + دکمهٔ خواننده + اشتراک‌گذاری.
6. ارسال فایل: ابتدا HEAD روی URL دانلود؛ اگر <50MB و 200 OK → `send_audio` با metadata؛ وگرنه لینک دانلود.
7. کش: كلید `song:{id}:v{modified_gmt}` — با `save_post` / `edited_term` باطل می‌شود.

---

## قرارداد داده (API Contract)

### جست‌وجو
`GET /wp-json/ahangban/v1/search?q=&type=&page=1&per_page=5`

```json
{
  "results": [{ "id": 123, "title": "...", "song": "...", "artist": "...", "type": "audio", "cover": "https://.../thumb2.jpg", "permalink": "https://ahangban.ir/.../", "qualities": { "128": "https://...", "320": "https://..." }, "artists": [{"term_id": 7, "name": "...", "slug": "..."}] }],
  "total": 42, "page": 1, "per_page": 5
}
```

### آهنگ
`GET /wp-json/ahangban/v1/song/{id}` → جزئیات + lyrics (اختیاری) + related (۳تا)

### خواننده
`GET /wp-json/ahangban/v1/artist/{slug_or_id}?page=1&per_page=5&sort=new` → پروفایل + آثار صفحه‌بندی

### فهرست خوانندگان
`GET /wp-json/ahangban/v1/artists?q=&letter=&sort=name&page=1&per_page=20`

### جدیدترین / محبوب‌ترین
`GET /wp-json/ahangban/v1/latest?per_page=5` / `popular?per_page=5`

### سلامت
`GET /wp-json/ahangban/v1/health` → `{ "status": "ok", "wp": "6.x", "php": "8.x" }`

همهٔ endpointها به جز health نیاز به `Authorization: Bearer TOKEN` دارند.

---

## همگام‌سازی

- **کش کوتاه‌مدت**: نتایج جست‌وجو ۵دقیقه؛ فهرست خوانندگان ۱۲ساعت (مثل قالب).
- **باطل‌سازی**: هوک‌های `save_post`, `delete_post`, `created_term`, `edited_term`, `delete_term` → `delete_transient('ahangban_search_*')`.
- **محتوای خصوصی**: فقط `post_status=publish` برگردانده می‌شود — پیش‌نویس/خصوصی هرگز.

---

## امنیت

- توکن ۶۴هگز در `wp_options` (`ahangban_connector_token`) — فقط مدیر می‌بیند.
- Rate limit: ۶۰ req/min/IP + ۲۰۰ req/min/token (Transient).
- ورودی: `sanitize_text_field` + `absint` + حداکثر طول ۱۰۰ کاراکتر برای q.
- SSRF: URLهای ارسالی از کاربر هرگز fetch نمی‌شوند؛ فقط URLهای داخل DB (که مدیر وارد کرده) HEAD می‌شوند با allowlist دامنه (`ahangban.ir`, `dl.*`).
- لاگ بدون توکن.
- ربات: توکن تلگرام فقط در `.env`، نه در گیت.

---

## استقرار (هاست فعلی نامناسب برای اجرای دائمی)

> هاست `etehadya` برای **اجرای پولینگ ۲۴/۷ مناسب نیست**. افزونه روی همین هاست نصب می‌شود؛ ربات روی VPS ارزان (مثلاً ۱vCPU/1GB) یا هر سرویس کانتینر خارج از ایران با systemd/Docker اجرا می‌شود.

جایگزین اگر VPS ممکن نبود: **webhook روی همین هاست** با مسیر `/wp-json/ahangban/v1/webhook` + Secret + HTTPS — ولی پولینگ پایدارتر است.

---

## ساختار پروژه

```
ahangbanbot/
├── wp-plugin/ahangban-connector/   # افزونه (نصب روی ahangban.ir)
├── bot/                             # سرویس پایتون
│   ├── handlers/  start/search/song/artist/browse/admin
│   ├── utils/     normalize/keyboards/formatters
│   ├── db.py  api_client.py  config.py  main.py
│   └── tests/
├── docs/
├── docker-compose.yml
├── systemd/ahangbanbot.service
└── .env.example
```

---

## ریسک‌ها

| ریسک | اثر | میتگیشن |
|---|---|---|
| REST مسدود است | API کار نمی‌کند | endpoint اختصاصی با prefix `ahangban` |
| ionCube مانع ویرایش functions.php | سایت ۵۰۰ | هرگز functions.php را دست نزن — فقط افزونه |
| لینک دانلود expire | ارسال ناموفق | HEAD + fallback به لینک صفحه |
| Flood تلگرام (429) | بلاک | صف + exponential backoff |
| تغییر قالب | شکست ربات | وابستگی فقط به DB/ACF، نه به CSS/PHP قالب |

