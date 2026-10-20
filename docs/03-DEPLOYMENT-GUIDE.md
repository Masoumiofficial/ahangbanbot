# راهنمای استقرار — آهنگ‌بان

## ۱) افزونهٔ WordPress

### پیش‌نیاز

- WordPress 5.9+، PHP 7.4+ و داده‌های واقعی قالب/سایت
- برای webhook: امکان تعریف محیطی‌های امن در PHP-FPM/هاست
- دسترسی مدیر برای نصب و تنظیم گزینه‌های غیرحساس

### نصب و اعتبارنامه‌ها

1. ZIP نسخهٔ جاری را از پیوند README نصب کنید یا پوشهٔ `wp-plugin/ahangban-connector` را در `wp-content/plugins` قرار دهید.
2. **پیش از ارتقا از v1.4.0:** متغیرهای `AHANGBAN_API_TOKEN`, `AHANGBAN_BOT_TOKEN`, `AHANGBAN_BOT_SECRET` را در محیط PHP-FPM/هاست تنظیم کنید. اولین اجرای v1.5.0، گزینه‌های legacy این سه Secret را از `wp_options` حذف می‌کند و دیگر fallback به آن‌ها ندارد.
3. `AHANGBAN_API_TOKEN` باید دقیقاً ۶۴ رقم hex تصادفی باشد و با مقدار هم‌نام در `.env` خصوصی ربات Python یکسان باشد. فایل `.env` ربات توسط PHP خودکار خوانده نمی‌شود.
4. فعال‌سازی → پیشخوان **آهنگ‌بان API** → بررسی وضعیت envها. صفحه فقط «آماده/نیازمند تنظیم» نشان می‌دهد و مقدار Secret را چاپ نمی‌کند.
5. تنظیمات غیرحساس (شناسهٔ ادمین، یوزرنیم، حالت، کانال) را ذخیره کنید.

برای انتشار دستی آثار از پنل Telegram، افزونهٔ WordPress را به v1.5.1 یا جدیدتر ارتقا دهید. ادمین از منوی `/admin` جست‌وجو/انتخاب و پیش‌نمایش را تأیید می‌کند؛ مقصد کانال از تنظیم موجود WordPress می‌آید و انتشار خودکار لازم نیست.

اگر هاست envهای PHP را پشتیبانی نمی‌کند، از مدیر هاست بخواهید آن‌ها را به PHP-FPM تزریق کند. از `SetEnv`, `.htaccess` یا `.env` تنها وقتی استفاده کنید که handler هاست صریحاً پشتیبانی امن آن را تأیید کند.

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

### systemd

از فایل `systemd/ahangbanbot.service` استفاده کنید؛ فایل env با دسترسی محدود کاربر سرویس نگه‌داری شود. پس از نصب، `systemctl enable --now ahangbanbot` و `journalctl -u ahangbanbot -f` را اجرا کنید.

## ۳) WordPress webhook (جایگزین polling)

1. در محیط PHP، `AHANGBAN_API_TOKEN`, `AHANGBAN_BOT_TOKEN`, `AHANGBAN_BOT_SECRET` را تعریف کنید.
2. SSL معتبر را فعال کنید.
3. از پیشخوان **آهنگ‌بان API** روی «ثبت وب‌هوک در تلگرام» بزنید.
4. در Telegram `/start` و `/ping` را امتحان کنید.

Webhook Secret از هدر `X-Telegram-Bot-Api-Secret-Token` بررسی می‌شود. برای تنظیم این webhook نیازی به تعریف endpoint در قالب یا تغییر `functions.php` نیست؛ افزونهٔ اختصاصی مسیر `/wp-json/ahangban/v1/telegram/webhook` را ثبت می‌کند.

## ۴) امنیت و محدودیت‌ها

- هیچ Secret فعالی در `wp_options` ذخیره یا از آن خوانده نمی‌شود؛ تنظیمات معمول پیشخوان برای مقادیر غیرحساس است.
- rate limit افزونه اتمی و در دیتابیس اصلی WordPress است. هدرهای proxy فقط با `AHANGBAN_TRUSTED_PROXY_IPS` و تطبیق IP پراکسی معتبر می‌شوند.
- CORS wildcard وجود ندارد؛ ربات از اتصال سروربه‌سرور استفاده می‌کند.
- درخواست‌های Telegram روی HTTPS و بدون redirect هستند. فایل رسانه با سقف ۴۹ MiB و رد مقصد محلی/خصوصی بررسی می‌شود.
- فایل‌های صوتی/ویدیویی و هنرمندان از داده‌های واقعی WordPress خوانده می‌شوند؛ دیتابیس موازی برای داده‌های سایت ساخته نمی‌شود. SQLite در حالت Python فقط برای state/caching محلی ربات است.
- اجرای WP-Cron و ارتباط زنده با Telegram باید روی هاست واقعی بررسی شوند؛ در محیط توسعه اتصال زنده تأیید نشده است.

## ۵) نگه‌داری و عیب‌یابی

- `401/403`: مقدار API token را در PHP و ربات مقایسه کنید؛ در v1.5.0 گزینه‌های دیتابیس fallback نیستند.
- `503` در REST: کنترل کنید `AHANGBAN_API_TOKEN` در همان worker PHP-FPM قابل‌دسترسی و ۶۴رقمی hex باشد.
- Webhook `403`: `AHANGBAN_BOT_SECRET` را در PHP بررسی و webhook را دوباره ثبت کنید.
- `429`: rate limit فعال شده؛ در proxyهای مورداعتماد، فقط CIDRهای واقعی را وارد کنید.
- ارسال رسانه ناموفق: نشانی فایل واقعی، HTTPS، اندازه و cURL خروجی هاست را بررسی کنید؛ در صورت شکست، ربات پیوند واقعی اثر را نشان می‌دهد.
- صف پیام/کانال متوقف: WP-Cron یا Cron Job هاست را بررسی کنید.
- بکاپ WordPress شامل گزینه‌های غیرحساس، جدول‌های state و metadata انتشار است؛ محیطی‌های Secret را جداگانه و امن نگه دارید.
