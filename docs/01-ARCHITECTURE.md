# معماری پیشنهادی — آهنگ‌بان تلگرام

## ارزیابی گزینه‌ها

| معیار | گزینه A: مستقل + API | گزینه B: فقط افزونه WP | انتخاب نهایی |
|---|---|---|---|
| نیاز به اجرای دائمی | بیرون از هاست اشتراکی (VPS/کانتینر) | روی هاست اشتراکی — ناپایدار | **هیبرید A+B** |
| دسترسی به DB اصلی | فقط از API امن وردپرس | مستقیم | امن: فقط API |
| وابستگی به قالب | صفر — اگر قالب عوض شد API پابرجاست | زیاد | **API لایهٔ مجرد** |
| امکان polling/webhook | هر دو — اما برای یک توکن نباید هم‌زمان اجرا شوند | محدود | Python polling روی VPS یا وب‌هوک WordPress؛ انتخاب وابسته به امکانات هاست |
| امنیت | Bearer token برای API و Secret header برای وب‌هوک؛ حداقل دسترسی DB | کل وردپرس در معرض | API اختصاصی و اعتبارسنجی صریح |
| قابلیت نگهداری | ربات پایتون مستقل، تست‌پذیر | PHP درگیر با قالب رمزگذاری‌شده | پایتون + افزونهٔ کوچک |

### نتیجه: **معماری هیبرید — افزونهٔ رابط امن + سرویس ربات مستقل**

```
[کاربر تلگرام] ⇄ Telegram Bot API ⇄ [سرویس ربات Python] ⇄ HTTPS+Token ⇄ [افزونه ahangban-connector @ WP ] ⇄ WP DB (post/artist/postmeta/termmeta)
                                         ↕
                                   SQLite محلی (کش، وضعیت کاربر، تاریخچهٔ جست‌وجو، rate-limit و file_id)
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
| احراز هویت API | `Authorization: Bearer <64-hex token>`؛ وب‌هوک با Secret header مجزا | بدون OAuth؛ هر مسیر حداقل مجوز لازم را دارد |
| استقرار ربات | systemd / Docker Compose (polling) | بدون نیاز به دامنهٔ عمومی برای webhook |

---

## جریان داده (Sequence)

1. کاربر `/start` یا متن جست‌وجو می‌فرستد → PTB handler.
2. `api_client` با `httpx` درخواست `GET /ahangban/v1/search?q=...` + `Authorization` می‌زند.
3. افزونه: اعتبارسنجی Bearer token → rate-limit اتمی در جدول options وردپرس → نرمال‌سازی فارسی (ی/ي، ک/ك، ZWNJ) → `WP_Query` با `post_status=publish` و شرط متادیتای واقعی موسیقی → خروجی JSON.
4. ربات نتایج را صفحه‌بندی (۵تایی) + InlineKeyboard می‌سازد.
5. کارت آهنگ: `song/{id}` → جزئیات + لینک‌های دانلود معتبر + دکمهٔ خواننده + اشتراک‌گذاری.
6. ارسال فایل: ابتدا Telegram از URL عمومی HTTPS دریافت می‌کند؛ فقط پس از رد قطعی، WordPress فایل را با سقف 49 MiB به‌صورت stream می‌گیرد و با cURL بارگذاری می‌کند. خطای مبهم/timeout باعث ارسال تکراری نمی‌شود؛ در صورت شکست، پیوند واقعی اثر ارائه می‌شود.
7. کش WordPress: کلیدها با نسخهٔ namespace ساخته می‌شوند (۵ دقیقه برای جست‌وجو/اثر، ۱۰ دقیقه برای جزئیات هنرمند، ۱۲ ساعت برای فهرست هنرمندان). تغییر نوشته/ترم نسخه را اتمی افزایش می‌دهد؛ پاک‌سازی دستی نیز همین namespace را عوض می‌کند.

---

## قرارداد داده (API Contract)

### جست‌وجو
`GET /wp-json/ahangban/v1/search?q=&type=&page=1&per_page=5`

```json
{
  "results": [{ "id": 123, "title": "...", "song": "...", "artist": "...", "type": "audio", "cover": "https://.../large.jpg", "cover_fallback": "https://.../medium_large.jpg", "permalink": "https://ahangban.ir/.../", "qualities": { "128": "https://...", "320": "https://..." }, "artists": [{"term_id": 7, "name": "...", "slug": "..."}] }],
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
`GET /wp-json/ahangban/v1/health` → `{ "status": "ok" }` (عمومی و بدون افشای نسخه یا شمارنده)

مسیرهای داده و کش به `Authorization: Bearer TOKEN` نیاز دارند؛ `health` عمومی و کم‌جزئیات است، وب‌هوک Telegram هدر Secret جداگانه دارد و وضعیت وب‌هوک به مدیر WordPress محدود است.

---

## همگام‌سازی

- **کش کوتاه‌مدت**: نتایج جست‌وجو ۵دقیقه؛ فهرست خوانندگان ۱۲ساعت (مثل قالب).
- **باطل‌سازی**: هوک‌های تغییر نوشته/ترم و عملیات پاک‌سازی نسخهٔ کش را در options اتمی افزایش می‌دهند؛ کلیدهای قدیمی دیگر استفاده نمی‌شوند و پس از TTL منقضی می‌شوند.
- **محتوای خصوصی**: فقط `post_status=publish` برگردانده می‌شود — پیش‌نویس/خصوصی هرگز.

---

## امنیت

- `AHANGBAN_API_TOKEN`, `AHANGBAN_BOT_TOKEN` و `AHANGBAN_BOT_SECRET` فقط از محیط PHP خوانده می‌شوند؛ نسخهٔ 1.5.0 در اولین اجرا گزینه‌های legacy آن‌ها را از `wp_options` حذف می‌کند.
- Rate limit با شمارندهٔ اتمیِ fixed-window در همان جدول options وردپرس: ۶۰ درخواست/دقیقه/IP و ۲۰۰ درخواست/دقیقه/token؛ مقدار پیش‌فرض IP از `REMOTE_ADDR` است. فقط در صورت تعریف صریح `AHANGBAN_TRUSTED_PROXY_IPS` به هدرهای proxy اعتماد می‌شود.
- CORS wildcard حذف شده است؛ Python سروربه‌سرور وصل می‌شود.
- ورودی جست‌وجو تا ۱۰۰ نویسه و صفحه تا ۱۰۰۰ محدود است؛ پردازش UTF-8 بدون الزام `mbstring` انجام می‌شود.
- پاسخ health عمومی فقط `{ "status": "ok" }` است؛ جزئیات نسخه، taxonomy و تعداد نوشته‌ها عمومی نیست.
- API فقط postهای publish را برمی‌گرداند که با نوع موسیقی تأییدشده یا متافیلدهای واقعی رسانه شناخته شوند.
- URLهای رسانه از دیتابیس WordPress بررسی می‌شوند؛ IP خصوصی/رزروشده، مقصد محلی و redirect ناامن رد می‌شوند و fallback حجمی محدود است.
- تمام تماس‌های Telegram روی HTTPS، با SSL verify و redirect خاموش انجام می‌شوند؛ خطای انتقال مبهم فایل را کورکورانه resend نمی‌کند.
- Python API client HTTPS اجباری دارد و redirect را دنبال نمی‌کند.
- `.env` ربات در Git نیست؛ فایل نمونه فقط placeholder دارد.

---

## استقرار (هاست فعلی نامناسب برای اجرای دائمی)

> هاست `etehadya` برای **اجرای پولینگ ۲۴/۷ مناسب نیست**. افزونه روی همین هاست نصب می‌شود؛ ربات روی VPS ارزان (مثلاً ۱vCPU/1GB) یا هر سرویس کانتینر خارج از ایران با systemd/Docker اجرا می‌شود.

جایگزین اگر VPS ممکن نبود: **وب‌هوک روی همین هاست** با مسیر `/wp-json/ahangban/v1/telegram/webhook` + `X-Telegram-Bot-Api-Secret-Token` + HTTPS. این حالت به پشتیبانی PHP-FPM از environment variables و اجرای WP-Cron نیاز دارد.

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
| لینک رسانه در دسترس نیست/بزرگ است | ارسال ناموفق | ابتدا fetch امن Telegram؛ سپس fallback محدودِ stream و در نهایت پیوند واقعی صفحه/منبع |
| Flood تلگرام (429) | بلاک | صف + exponential backoff |
| تغییر قالب | شکست ربات | وابستگی فقط به DB/ACF، نه به CSS/PHP قالب |

