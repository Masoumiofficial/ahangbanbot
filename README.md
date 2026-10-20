# 🎶 ربات تلگرام آهنگ‌بان — نسخهٔ 1.5.0

> **برند:** آهنگ‌بان (AhangBan) — https://ahangban.ir  
> **زبان:** فارسی · **جهت:** RTL · **منبع حقیقت:** دیتابیس وردپرس آهنگ‌بان  
> **قالب مرجع:** `javan-seda v3.0.1` · **برند کیت:** `Masoumiofficial/ahangban` (#185ADB)

> ### 📥 دانلود نسخهٔ فعلی — افزونه v1.5.0
> - **[دانلود مستقیم ZIP آمادهٔ نصب افزونه v1.5.0](https://github.com/Masoumiofficial/ahangbanbot/raw/e564a73fc7f9e507befa930058368b37b5613832/releases/ahangban-connector-1.5.0.zip)** (`releases/ahangban-connector-1.5.0.zip`)
> - پیش از ارتقا، اعتبارنامه‌ها را در محیط PHP هاست تنظیم کنید؛ v1.5.0 آن‌ها را از دیتابیس قدیمی پاک می‌کند و دیگر در پیشخوان ذخیره/نمایش نمی‌دهد.
> - نصب و تنظیم webhook/کانال: [`docs/05-DIRECTADMIN-GUIDE.md`](docs/05-DIRECTADMIN-GUIDE.md). برای PHP-FPM باید هاست امکان تعریف Environment Variables را بدهد.

---

## فهرست

1. [معرفی](#معرفی)
2. [معماری](#معماری)
3. [پیش‌نیازها](#پیشنیازها)
4. [نصب افزونهٔ وردپرس](#نصب-افزونهٔ-وردپرس)
5. [راه‌اندازی ربات](#راهاندازی-ربات)
6. [ساخت ربات در BotFather](#ساخت-ربات-در-botfather)
7. [متغیرهای محیطی](#متغیرهای-محیطی)
8. [استقرار (Docker / systemd)](#استقرار)
9. [مستندات API](#مستندات-api)
10. [امنیت](#امنیت)
11. [تست‌ها](#تستها)
12. [مانیتورینگ و لاگ](#مانیتورینگ-و-لاگ)
13. [پشتیبان‌گیری و بازیابی](#پشتیبانگیری)
14. [ریسک‌ها و محدودیت‌ها](#ریسکها)
15. [گزارش فاز صفر](#گزارش-فاز-صفر)

---

## معرفی

ربات تلگرام آهنگ‌بان یک سامانهٔ حرفه‌ای جست‌وجو، کشف و دریافت موسیقی است که **مستقیم به آرشیو واقعی سایت ahangban.ir** متصل است — نه دادهٔ ساختگی.

**امکانات هسته:**

- `/start` با خوش‌آمد فارسی و یک منوی Inline یک‌مرحله‌ای و تمیز؛ جست‌وجوی آهنگ/خواننده، جدیدترین‌ها و فهرست هنرمندان
- جست‌وجوی آهنگ/خواننده با نرمال‌سازی فارسی (ی/ي، ک/ك، نیم‌فاصله، تشدید) + رتبه‌بندی مرتبط‌ترین نتیجه
- کارت اختصاصی هر اثر: عنوان، خواننده(ها)، نوع، کاور با اندازهٔ نمایش بزرگ وردپرس (به‌جای thumbnail کوچک ۱۹۰×۱۹۰)، کیفیت‌های موجود، لینک صفحهٔ رسمی، متن ترانه و آثار مرتبط
- صفحهٔ خواننده: تصویر، بیوگرافی، شمار آثار به تفکیک نوع، لینک شبکه‌های اجتماعی، آثار صفحه‌بندی‌شده با مرتب‌سازی (جدید/قدیم/الفبایی)
- فهرست الفبایی همهٔ خوانندگان (مانند `/artists/` در سایت) + فیلتر حرف + جست‌وجوی زنده
- جدیدترین‌ها و محبوب‌ترین‌های ماه (بر اساس `meta_key=views`)
- ارسال فایل واقعی در تلگرام: دریافت مستقیم توسط تلگرام، سپس fallback امنِ دانلود/آپلود تا ۴۹ MiB؛ کش `file_id` صوت و پیوند معتبر در صورت شکست
- پنل ادمین داخل ربات: `/admin`، آمار، سلامت، پاک‌سازی کش، مدیریت کاربران و پیام همگانیِ تأییدشده
- اتصال اختیاری کانال: انتشار خودکار آثار موسیقی تازهٔ WordPress به کانال، با دکمهٔ deep-link به همان اثر در ربات؛ خاموش به‌صورت پیش‌فرض
- حالت webhook داخلی PHP برای هاست اشتراکی؛ Python polling گزینهٔ VPS/Docker
- ذخیرهٔ تاریخچهٔ جست‌وجو و کش رسانه در حالت Python

> **اصل طلایی:** اگر فیلدی در دیتابیس نیست، جعل نمی‌شود — حذف یا «ناموجود» نمایش داده می‌شود.

---

## معماری

**دو حالت اجرا با یک منبع دادهٔ وردپرس:**

**یکی از دو حالت را انتخاب کنید؛ برای یک Bot Token هم‌زمان اجرا نمی‌شوند.**

```text
[تلگرام] ⇄ Bot API ⇄ [WP Webhook PHP] ⇄ [افزونه ahangban-connector] ⇄ WP DB
                 یا
[تلگرام] ⇄ Bot API ⇄ [Python polling] ⇄ HTTPS+Token ⇄ [افزونه] ⇄ همان WP DB
```

برای دایرکت‌ادمین و بدون SSH، حالت **وب‌هوک PHP** را در پیشخوان فعال کنید؛ سرویس ماندگار یا دیتابیس جدا لازم نیست. حالت Python/polling برای VPS اختیاری است و دادهٔ آهنگ/خواننده همچنان فقط از WordPress خوانده می‌شود.

جزئیات: [`docs/01-ARCHITECTURE.md`](docs/01-ARCHITECTURE.md) · نصب بدون SSH: [`docs/05-DIRECTADMIN-GUIDE.md`](docs/05-DIRECTADMIN-GUIDE.md) · [ممیزی امنیتی v1.5.0](docs/08-SECURITY-AUDIT-v1.5.0.md) · [ممیزی UX/کانال v1.4.0](docs/07-CHANNEL-UX-AUDIT-v1.4.0.md) · [مهندسی معکوس](docs/00-REVERSE-ENGINEERING-REPORT.md)

---

## پیش‌نیازها

| لایه | نیاز |
|---|---|
| وردپرس | 5.9+ · PHP 7.4–8.3 · ionCube Loader (موجود روی هاست) |
| افزونه‌های وردپرس | **ACF (ضروری)** · OptionTree (همراه قالب) · WP-PostViews (برای محبوب‌ها) |
| ربات | Python 3.11+ · pip |
| استقرار | Docker 24+ **یا** systemd روی Ubuntu 22.04+ |
| تلگرام | توکن از @BotFather |

---

## نصب افزونهٔ وردپرس

### روش ۱: آپلود دستی

```bash
# از روی سرور وردپرس
cd /home/etehadya/domains/ahangban.ir/public_html/wp-content/plugins
unzip ahangban-connector.zip   # یا کپی پوشه wp-plugin/ahangban-connector
```

از پیشخوان: **افزونه‌ها → نصب‌شده → AhangBan Connector → فعال‌سازی**

### روش ۲: WP-CLI

```bash
wp plugin install ahangban-connector.zip --activate
wp plugin list
```

### پیش از نصب/ارتقا و پس از فعال‌سازی

اعتبارنامه‌ها باید در **محیط پردازش PHP هاست** تنظیم شوند، نه فرم پیشخوان. برای v1.5.0 مقادیر را پیش از ارتقا آماده کنید؛ اجرای نخست نسخهٔ جدید گزینه‌های قدیمی توکن API، Bot Token و Webhook Secret را از `wp_options` حذف می‌کند.

1. در محیط PHP-FPM/هاست تنظیم کنید: `AHANGBAN_API_TOKEN` (دقیقاً ۶۴ رقم hex)، `AHANGBAN_BOT_TOKEN` و `AHANGBAN_BOT_SECRET`. اگر هاست متغیر محیطی PHP را پشتیبانی نمی‌کند، از مدیر هاست بخواهید آن‌ها را به PHP-FPM تزریق کند؛ `.env` ربات به‌طور خودکار توسط PHP خوانده نمی‌شود.
2. همان `AHANGBAN_API_TOKEN` را در `.env` خصوصی ربات Python قرار دهید. برای polling، `BOT_TOKEN` نیز همان توکن BotFather است.
3. افزونه را فعال کنید و در منوی **آهنگ‌بان API** وضعیت متغیرها را ببینید؛ صفحه فقط آماده‌بودن Secretها را نشان می‌دهد، نه مقدار آن‌ها.
4. شناسهٔ ادمین، یوزرنیم ربات و کانال (اختیاری) را تنظیم کنید. برای این روش، حالت **وب‌هوک** را انتخاب و «ثبت وب‌هوک در تلگرام» را بزنید؛ اگر Python polling را انتخاب کرده‌اید، وب‌هوک را حذف کنید و سرویس Python را جداگانه اجرا کنید.
5. در تلگرام `/start` و `/ping` را امتحان کنید. Health عمومی فقط `status: ok` می‌دهد.

انتشار خودکار فقط برای آثار موسیقی تازه‌ای است که با متافیلدهای واقعی موسیقی قابل‌شناسایی باشند؛ WP-Cron باید فعال باشد و محتوای قدیمی خودکار بازنشر نمی‌شود. اگر `taxonomy_artist = ❌` بود، تاکسونومی واقعی قالب/سایت را بررسی کنید؛ افزونه به‌صورت خودکار چیزی ثبت نمی‌کند.

---

## راه‌اندازی ربات

```bash
git clone https://github.com/Masoumiofficial/ahangbanbot.git
cd ahangbanbot

python3 -m venv venv
source venv/bin/activate

pip install -r bot/requirements.txt

cp .env.example .env
nano .env   # BOT_TOKEN و AHANGBAN_API_TOKEN را پر کنید

python -m bot.main
```

لاگ موفق: `✅ Bot is polling. Press Ctrl+C to stop.`

### تست سریع

در تلگرام:

```
/start
محسن یگانه
/ping
/latest
/popular
```

---

## ساخت ربات در BotFather

1. در تلگرام به [@BotFather](https://t.me/BotFather) بروید → `/newbot`
2. نام: `آهنگ‌بان` · یوزرنیم: `ahangbanbot` (یا `ahangban_music_bot`)
3. توکن دریافتی را در `.env` بگذارید (`BOT_TOKEN`).
4. **دستورات پیشنهادی** (`/setcommands`):

```
start - شروع و منوی اصلی
search - جست‌وجوی آهنگ/خواننده
latest - جدیدترین آهنگ‌ها
popular - محبوب‌ترین‌ها
artist - صفحهٔ خواننده
help - راهنما
ping - سلامت اتصال
```

5. **توضیحات** (`/setdescription`):

```
🎶 ربات رسمی آهنگ‌بان — جست‌وجو و دانلود جدیدترین آهنگ‌ها مستقیم از آرشیو ahangban.ir
```

6. **درباره** (`/setabouttext`): «ربات موسیقی آهنگ‌بان — با مجوز محتوای واقعی»

---

## متغیرهای محیطی

فایل `.env` ربات Python (هرگز آن را کامیت نکنید — `.gitignore` شده است):

```env
BOT_TOKEN=REPLACE_WITH_BOTFATHER_TOKEN
AHANGBAN_API_BASE=https://ahangban.ir/wp-json/ahangban/v1
AHANGBAN_API_TOKEN=REPLACE_WITH_64_HEX_CHARACTERS
# شناسه واقعی ادمین‌ها را وارد کنید؛ در صورت نامشخص‌بودن خالی بگذارید.
ADMIN_IDS=
LOG_LEVEL=INFO
CACHE_TTL=300
RATE_LIMIT_PER_MINUTE=20
```

| متغیر | توضیح |
|---|---|
| `BOT_TOKEN` | توکن BotFather برای Python polling؛ در حالت WordPress webhook، همین مقدار با نام `AHANGBAN_BOT_TOKEN` به PHP داده می‌شود |
| `AHANGBAN_API_BASE` | فقط HTTPS؛ URL پایهٔ افزونه (بدون `/` انتهایی) |
| `AHANGBAN_API_TOKEN` | توکن تصادفیِ دقیقاً ۶۴رقمی hex؛ باید در PHP و این فایل خصوصی یکسان باشد |
| `ADMIN_IDS` | شناسه‌های عددی ادمین با کاما (برای `/stats` و `/broadcast`) |
| `AHANGBAN_DB_PATH` | مسیر SQLite ربات در حالت Python (پیش‌فرض `bot/ahangban.db`) |

یوزرنیم ربات برای deep-link کانال را در فیلد غیرحساس «یوزرنیم ربات» در پیشخوان WordPress وارد کنید؛ Python متغیر `BOT_USERNAME` را مصرف نمی‌کند.

برای WordPress webhook، متغیرهای `AHANGBAN_API_TOKEN`, `AHANGBAN_BOT_TOKEN`, `AHANGBAN_BOT_SECRET` را جداگانه در محیط **PHP-FPM/هاست** تنظیم کنید. PHP فایل `.env` ربات را خودکار نمی‌خواند. در صورت استفاده از reverse proxy، فقط IP/CIDR پراکسی‌های واقعی را در `AHANGBAN_TRUSTED_PROXY_IPS` بگذارید؛ در غیر این صورت هدرهای IP ارسال‌شده توسط کلاینت اعتماد نمی‌شوند.

برای یافتن `ADMIN_IDS`: در ربات `/start` بزنید، سپس به `@userinfobot` پیام دهید تا ID خود را ببینید.

---

## استقرار

### گزینه A — Docker (پیشنهادی)

```bash
cp .env.example .env  # و پر کنید
docker compose up -d --build
docker compose logs -f
```

دادهٔ SQLite در volume `ahangban-data:/data` ماندگار است.

### گزینه B — systemd (روی VPS)

```bash
sudo useradd -r -m -d /opt/ahangbanbot ahangban
sudo cp -r . /opt/ahangbanbot
sudo chown -R ahangban:ahangban /opt/ahangbanbot
cd /opt/ahangbanbot && python3 -m venv venv && venv/bin/pip install -r bot/requirements.txt
sudo cp systemd/ahangbanbot.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ahangbanbot
journalctl -u ahangbanbot -f
```

### گزینه C — وب‌هوک داخلی وردپرس

در این حالت پردازش پیام‌ها روی WordPress انجام می‌شود و Python polling لازم نیست؛ اما هاست باید متغیرهای محیطی PHP را پشتیبانی کند. پیش از ارتقا، `AHANGBAN_API_TOKEN`, `AHANGBAN_BOT_TOKEN` و `AHANGBAN_BOT_SECRET` را در PHP-FPM تنظیم کنید؛ سپس از پیشخوان وب‌هوک را در Telegram ثبت کنید. پیشخوان فقط وضعیت متغیرها را نشان می‌دهد و Secretها را ذخیره/چاپ نمی‌کند. مراحل کامل در [`docs/05-DIRECTADMIN-GUIDE.md`](docs/05-DIRECTADMIN-GUIDE.md) آمده است.

---

## مستندات API

مسیرهای داده و کش به هدر `Authorization: Bearer <TOKEN>` نیاز دارند؛ `/health` عمومی و حداقلی است، `/telegram/webhook` با هدر Secret تلگرام محافظت می‌شود و وضعیت وب‌هوک فقط برای مدیر WordPress در دسترس است.

| متد | مسیر | توضیح | پارامترها |
|---|---|---|---|
| `GET` | `/health` | سلامت (بدون احراز) | — |
| `GET` | `/search?q=&type=&page=&per_page=` | جست‌وجو + رتبه‌بندی | `q` الزامی (≤۱۰۰ نویسه)، `type` ∈ `audio,video,album` |
| `GET` | `/song/{id}` | کارت اثر + آثار مرتبط + ترک‌های آلبوم | `id` عددی |
| `GET` | `/artists?q=&letter=&sort=&page=&per_page=` | فهرست خوانندگان | `sort` ∈ `name,songs` |
| `GET` | `/artist/{slug_or_id}?page=&per_page=&sort=` | پروفایل خواننده + آثار | `sort` ∈ `new,old,title` |
| `GET` | `/latest?page=&per_page=` | جدیدترین‌ها | — |
| `GET` | `/popular?page=&per_page=` | محبوب‌ترین ماه | — |
| `POST` | `/cache/clear` | پاک‌سازی کش | — |

**مثال:**

```bash
curl -H "Authorization: Bearer $AHANGBAN_API_TOKEN" \
  "https://ahangban.ir/wp-json/ahangban/v1/search?q=محسن%20یگانه&page=1&per_page=5" | jq

curl -H "Authorization: Bearer $AHANGBAN_API_TOKEN" \
  "https://ahangban.ir/wp-json/ahangban/v1/song/12345" | jq
```

**نرمال‌سازی فارسی** در هر دو سمت (افزونه + ربات) انجام می‌شود: ی/ي، ک/ك، ه/ة، نیم‌فاصله (ZWNJ)، تشدید.

---

## امنیت

- ✅ اعتبارنامه‌های API، Bot و Webhook فقط از محیط PHP/`.env` خصوصی خوانده می‌شوند؛ هیچ fallback یا ذخیرهٔ جدیدی در `wp_options` نیست. گزینه‌های قدیمی در اولین اجرای v1.5.0 پاک می‌شوند.
- ✅ API فقط آثار موسیقیِ منتشرشده و قابل‌تشخیص را برمی‌گرداند؛ نوشتهٔ معمولی یا محتوای خصوصی شامل نمی‌شود.
- ✅ هدر webhook اجباری، update تکراری deduplicate و rate limit با شمارندهٔ اتمی در دیتابیس موجود اعمال می‌شوند.
- ✅ هدرهای IP فقط در صورت تطبیق `REMOTE_ADDR` با فهرست trusted proxy خوانده می‌شوند؛ CORS wildcard حذف شده است.
- ✅ درخواست‌های Telegram روی HTTPS و بدون دنبال‌کردن redirect هستند؛ timeout/5xx مبهم باعث retry رسانه و ارسال تکراری نمی‌شود.
- ✅ دریافت رسانه فقط با HTTPS؛ IP خصوصی/رزروشده، مقصد محلی و redirect ناامن رد می‌شوند. حجم fallback محدود است و شکست به پیوند واقعی اثر می‌رسد.
- ✅ Health عمومی فقط وضعیت کلی می‌دهد؛ نسخه‌های PHP/WordPress، نام تاکسونومی و شمارنده‌های محتوا افشا نمی‌شوند.

---

## تست‌ها

```bash
python3 -m venv venv
source venv/bin/activate
pip install -r bot/requirements-dev.txt
pytest bot/tests/ -v
```

تست‌ها شامل قرارداد API، نرمال‌سازی فارسی، امنیت URL و SSRF، middleware مسدودسازی/rate limit اتمیک، ارسال تصویر با fallback، deep-link، پنل ادمین و اعتبارسنجی پیکربندی هستند. در 2026-10-20، **۳۹ تست Python موفق** شد؛ `compileall` و بررسی نحوی JavaScript هم موفق بودند. هر ۸ فایل PHP با parser سازگار با دستور زبان PHP 7.4 بررسی ایستا شدند؛ این جایگزین اجرای PHP/WordPress نیست. گزارش و محدودیت‌ها: [`docs/08-SECURITY-AUDIT-v1.5.0.md`](docs/08-SECURITY-AUDIT-v1.5.0.md). گزارش تست قدیمی v1.4.0 در [`docs/02-TEST-REPORT.md`](docs/02-TEST-REPORT.md) باقی است.

> اتصال واقعی به WordPress، Telegram، کانال، WP-Cron و ارسال رسانه روی هاست از این محیط تأیید نشده است؛ پذیرش زنده باید روی سایت با اعتبارنامه‌های واقعی انجام شود.

---

## مانیتورینگ و لاگ

- لاگ ربات: `journalctl -u ahangbanbot -f` یا `docker compose logs -f`
- سلامت از تلگرام: `/ping` و `/health` (ادمین)
- سلامت از سرور:

```bash
curl https://ahangban.ir/wp-json/ahangban/v1/health | jq
```

- آمار ادمین در تلگرام: `/stats` → کاربران کل/فعال، تعداد جست‌وجو، بازدید.

---

## پشتیبان‌گیری

- **وردپرس:** تنظیمات غیرحساس در `wp_options`، وضعیت حداقلی تعامل در جدول‌های `{$prefix}ahangban_bot_*` و وضعیت انتشار کانال در `wp_postmeta` ذخیره می‌شود. Secretهای فعال در دیتابیس نیستند؛ متغیرهای محیطی را جداگانه در مخزن امن هاست پشتیبان بگیرید. دیتابیس موازی برای داده‌های سایت ساخته نمی‌شود.
- **ربات:** فایل `bot/ahangban.db` (یا `/data/ahangban.db` در Docker) را روزانه بکاپ بگیرید:

```bash
sqlite3 bot/ahangban.db ".backup backup/ahangban-$(date +%F).db"
```

- **بازیابی:** کافیست فایل DB را برگردانید و سرویس را ری‌استارت کنید.

---

## ریسک‌ها و محدودیت‌ها

| ریسک | وضعیت | اقدام |
|---|---|---|
| REST هسته مسدود | ✅ حل شد — endpoint اختصاصی `ahangban/v1` | تست `/health` |
| `functions.php` رمزگذاری ionCube | ✅ حل شد — افزونه مستقل، بدون دست‌زدن به قالب | — |
| لینک رسانه در دسترس نیست | ⚠️ URLهای واقعی از دیتابیس WordPress می‌آیند | fetch امن Telegram؛ در رد قطعی، stream محدود و در نهایت لینک واقعی صفحه/منبع |
| ارسال فایل بزرگ‌تر از سقف امن 49 MiB | ⚠️ محدودیت Bot API/هاست | ربات لینک واقعی را نشان می‌دهد؛ ارسال فایل تکراری انجام نمی‌شود |
| هاست اشتراکی بدون اجرای دائمی Python | ✅ وب‌هوک داخلی WordPress اختیاری است | در صورت نبود PHP-FPM env یا WP-Cron، Python روی VPS/Docker اجرا شود |
| تغییر قالب | ✅ ربات به قالب وابسته نیست | فقط `post` + `artist` + متا |

---

## گزارش فاز صفر

برای گزارش کامل مهندسی معکوس به [`docs/00-REVERSE-ENGINEERING-REPORT.md`](docs/00-REVERSE-ENGINEERING-REPORT.md) و برای تصمیم معماری به [`docs/01-ARCHITECTURE.md`](docs/01-ARCHITECTURE.md) مراجعه کنید.

---

## مجوز

GPL-2.0-or-later — مطابق وردپرس. فونت Vazirmatn با مجوز OFL.

---

<p align="center"><b>آهنگ‌بان</b> — ساخته‌شده با ❤️ برای موسیقی فارسی</p>
