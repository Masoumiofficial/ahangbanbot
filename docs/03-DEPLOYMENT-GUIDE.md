# راهنمای استقرار — آهنگ‌بان

## ۱) افزونهٔ WordPress

### پیش‌نیاز

- WordPress 5.9+، PHP 7.4+ و داده‌های واقعی قالب/سایت
- برای webhook: تنظیم اعتبارنامه‌ها از محیط PHP یا فرم مدیریت افزونه
- دسترسی مدیر برای نصب و تنظیم افزونه

### نصب و اعتبارنامه‌ها

1. ZIP نسخهٔ جاری را از پیوند README نصب کنید یا پوشهٔ `wp-plugin/ahangban-connector` را در `wp-content/plugins` قرار دهید.
2. فعال‌سازی → پیشخوان **آهنگ‌بان API**. اگر متغیرهای محیطی PHP در دسترس نیستند، سه اعتبارنامه را در فرم اختصاصی افزونه وارد کنید؛ مقدار فیلدها دوباره نمایش داده نمی‌شود.
3. `AHANGBAN_API_TOKEN` باید دقیقاً ۶۴ رقم hex تصادفی باشد و اگر Python polling از API استفاده می‌کند، با مقدار هم‌نام در `.env` خصوصی ربات یکسان باشد. فایل `.env` ربات توسط PHP خودکار خوانده نمی‌شود.
4. وضعیت سه اعتبارنامه را بررسی کنید و تنظیمات شناسهٔ ادمین، یوزرنیم و حالت را ذخیره کنید.

برای انتشار دستی آثار از پنل Telegram، افزونهٔ WordPress را حداقل به v1.5.1 ارتقا دهید؛ برای fallback Voice Note در Python polling، v1.6.2 یا جدیدتر لازم است. افزونهٔ v1.6.3 در حالت webhook update را پیش از پردازش در صف پایدار WordPress ذخیره و سریع ACK می‌کند. از v1.6.4 می‌توان به‌جای webhook، polling زمان‌بندی‌شدهٔ WordPress را فعال کرد؛ در v1.6.5 رویداد polling هر ۳۰ ثانیه زمان‌بندی می‌شود و برای این سرعت، Cron Job هاست نیز باید wp-cron.php را هر ۳۰ ثانیه فراخوانی کند. اگر هاست فقط Cron دقیقه‌ای دارد، polling همچنان کار می‌کند اما تأخیر ممکن است حدود یک دقیقه باشد. وب‌هوک قبلی را حذف کنید؛ updateهای pending حفظ می‌شوند. ادمین از منوی `/admin` جست‌وجو/انتخاب و پیش‌نمایش را تأیید می‌کند؛ مقصد کانال از تنظیم موجود WordPress می‌آید و انتشار خودکار لازم نیست. اگر WP وضعیت ویس را `queued` بدهد، Python چیزی نمی‌فرستد؛ fallback فقط برای `unavailable`/`failed` است.

در صورت استفاده از فرم افزونه، Secretها در `wp_options` ذخیره می‌شوند و رمزنگاری‌شده نیستند؛ بکاپ دیتابیس را محرمانه نگه دارید. اگر محیط PHP در دسترس است، تنظیم env همچنان منبع ترجیحی است.

Health عمومی کمینه است:

```json
{"status":"ok"}
```

مسیرهای داده و کش با `Authorization: Bearer $AHANGBAN_API_TOKEN` محافظت می‌شوند؛ webhook از Secret header تلگرام استفاده می‌کند و مسیر وضعیت webhook فقط برای مدیر WordPress است.

## ۲) Python polling روی VPS (اختیاری)

```bash
git clone https://github.com/Masoumiofficial/ahangbanbot.git /opt/ahangbanbot
cd /opt/ahangbanbot
python3 -m venv venv
. venv/bin/activate
pip install -r bot/requirements.txt
cp .env.example .env
nano .env   # BOT_TOKEN و AHANGBAN_API_TOKEN را محرمانه تنظیم کنید
python -m bot.main
```

`AHANGBAN_API_BASE` باید HTTPS یک میزبان مورداعتماد باشد. API client تغییر مسیر را دنبال نمی‌کند و HTTP، localhost، دامنه‌های local شناخته‌شده و IPهای خصوصی/رزروشدهٔ صریح را رد می‌کند؛ DNS نام میزبان API در این بررسی pin نمی‌شود، پس hostname را فقط از منبع مورداعتماد تنظیم کنید. در حالت polling، `.env` را فقط روی VPS نگه دارید و هرگز commit نکنید. برای هر Bot Token فقط یکی از polling یا webhook را فعال نگه دارید؛ اگر از webhook به polling می‌روید، از پیشخوان وب‌هوک قبلی را حذف کنید تا Telegram خطای تداخل ندهد.

### Docker

```bash
cp .env.example .env  # فایل واقعی خصوصی بماند
docker compose up -d --build
docker compose logs -f
```

تصویر فعلی Docker، `ffmpeg` را برای تبدیل کوتاه OGG/Opus با libopus نصب می‌کند. پس از به‌روزرسانی کد، image باید دوباره build و سرویس redeploy شود.

### systemd

از فایل `systemd/ahangbanbot.service` استفاده کنید؛ فایل env با دسترسی محدود کاربر سرویس نگه‌داری شود. محیط Python/systemd نیز برای voice fallback به FFmpeg با libopus نیاز دارد؛ اگر دسترسی shell ندارید، از مدیر VPS/هاست بخواهید آن را برای سرویس فراهم کند. پس از نصب، `systemctl enable --now ahangbanbot` و `journalctl -u ahangbanbot -f` را اجرا کنید.

## ۳) WordPress webhook (جایگزین polling)

1. `AHANGBAN_API_TOKEN`, `AHANGBAN_BOT_TOKEN` و `AHANGBAN_BOT_SECRET` را در محیط PHP یا فرم اعتبارنامه‌های افزونه تنظیم کنید.
2. SSL معتبر را فعال کنید.
3. از پیشخوان **آهنگ‌بان API** روی «ثبت وب‌هوک در تلگرام» بزنید؛ بازکردن مسیر webhook در مرورگر فقط GET را آزمایش می‌کند و ثبتش نمی‌کند.
4. در Telegram `/start` و `/ping` را امتحان کنید.

Webhook Secret از هدر `X-Telegram-Bot-Api-Secret-Token` بررسی می‌شود. برای تنظیم این webhook نیازی به تعریف endpoint در قالب یا تغییر `functions.php` نیست؛ افزونهٔ اختصاصی مسیر `/wp-json/ahangban/v1/telegram/webhook` را ثبت می‌کند.

## ۴) امنیت و محدودیت‌ها

- محیط PHP اولویت دارد؛ در صورت نبود آن، فرم مدیر افزونه Secretها را در `wp_options` با autoload خاموش ذخیره می‌کند. مقدارها در دیتابیس رمزنگاری‌شده نیستند و دسترسی بکاپ باید محدود باشد.
- rate limit افزونه اتمی و در دیتابیس اصلی WordPress است. هدرهای proxy فقط با `AHANGBAN_TRUSTED_PROXY_IPS` و تطبیق IP پراکسی معتبر می‌شوند.
- CORS wildcard وجود ندارد؛ ربات از اتصال سروربه‌سرور استفاده می‌کند.
- درخواست‌های Telegram روی HTTPS و بدون redirect هستند. فایل رسانه با سقف ۴۹ MiB و رد مقصد محلی/خصوصی بررسی می‌شود.
- فایل‌های صوتی/ویدیویی، آهنگ‌ها و هنرمندان از داده‌های واقعی WordPress خوانده می‌شوند؛ دیتابیس موازی برای داده‌های سایت ساخته نمی‌شود. SQLite فقط برای state محلی ربات، کش، پسندها و شناسهٔ `term_id` هنرمندان دنبال‌شده است.
- اجرای WP-Cron و ارتباط زنده با Telegram باید روی هاست واقعی بررسی شوند؛ در محیط توسعه اتصال زنده تأیید نشده است.

## ۵) نگه‌داری و عیب‌یابی

- `401/403`: مقدار API token محیط PHP یا فرم افزونه را با ربات Python مقایسه کنید.
- `503` در REST: کنترل کنید `AHANGBAN_API_TOKEN` در PHP یا تنظیمات افزونه موجود و ۶۴رقمی hex باشد.
- Webhook `403`: `AHANGBAN_BOT_SECRET` محیطی/ذخیره‌شده را بررسی و وب‌هوک را دوباره ثبت کنید.
- `429`: rate limit فعال شده؛ در proxyهای مورداعتماد، فقط CIDRهای واقعی را وارد کنید.
- ارسال رسانه ناموفق: نشانی فایل واقعی، HTTPS، اندازه و cURL خروجی هاست را بررسی کنید؛ در صورت شکست، ربات پیوند واقعی اثر را نشان می‌دهد.
- صف پیام/کانال متوقف: WP-Cron یا Cron Job هاست را بررسی کنید.
- بکاپ WordPress در صورت استفاده از فرم credential شامل Secretهای ذخیره‌شده نیز می‌شود؛ فایل‌های پشتیبان را رمزگذاری و دسترسی را محدود کنید.
