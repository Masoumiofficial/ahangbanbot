# 🎶 ربات تلگرام آهنگ‌بان — نسخهٔ 1.3.0

> **برند:** آهنگ‌بان (AhangBan) — https://ahangban.ir  
> **زبان:** فارسی · **جهت:** RTL · **منبع حقیقت:** دیتابیس وردپرس آهنگ‌بان  
> **قالب مرجع:** `javan-seda v3.0.1` · **برند کیت:** `Masoumiofficial/ahangban` (#185ADB)

> ### 📥 دانلود نسخهٔ فعلی — افزونه v1.3.0
> - **ZIP شاخهٔ به‌روزشده:** https://github.com/Masoumiofficial/ahangbanbot/archive/refs/heads/arena/24085415-ahangbanbot.zip
> - برای WordPress، از ZIP شاخه فقط پوشهٔ `wp-plugin/ahangban-connector` را ZIP کنید و از پیشخوان بارگذاری کنید.
> - **دایرکت‌ادمین/بدون SSH:** توکن را در پیشخوان ذخیره کنید، وب‌هوک را ست کنید؛ فایل‌های صوتی در صورت امکان مستقیم upload می‌شوند و در غیر این صورت پیوند واقعی می‌آید.
> - جزئیات نصب و پنل ادمین: [`docs/05-DIRECTADMIN-GUIDE.md`](docs/05-DIRECTADMIN-GUIDE.md).

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

- `/start` با پیام خوش‌آمد فارسی و منوی شیشه‌ای (Inline + Reply Keyboard)
- جست‌وجوی آهنگ/خواننده با نرمال‌سازی فارسی (ی/ي، ک/ك، نیم‌فاصله، تشدید) + رتبه‌بندی مرتبط‌ترین نتیجه
- کارت اختصاصی هر اثر: عنوان، خواننده(ها)، نوع (آهنگ/آلبوم/موزیک‌ویدیو)، کاور، کیفیت‌های ۱۲۸/۳۲۰ و ویدیو ۴۸۰/۷۲۰/۱۰۸۰، لینک صفحهٔ رسمی، متن ترانه، آثار مرتبط
- صفحهٔ خواننده: تصویر، بیوگرافی، شمار آثار به تفکیک نوع، لینک شبکه‌های اجتماعی، آثار صفحه‌بندی‌شده با مرتب‌سازی (جدید/قدیم/الفبایی)
- فهرست الفبایی همهٔ خوانندگان (مانند `/artists/` در سایت) + فیلتر حرف + جست‌وجوی زنده
- جدیدترین‌ها و محبوب‌ترین‌های ماه (بر اساس `meta_key=views`)
- ارسال فایل واقعی در تلگرام: دریافت مستقیم توسط تلگرام، سپس fallback امنِ دانلود/آپلود تا ۴۹ MiB؛ کش `file_id` صوت و پیوند معتبر در صورت شکست
- پنل ادمین داخل ربات: `/admin` و دکمهٔ مدیریت، آمار، سلامت، پاک‌سازی کش، `/block`, `/unblock` و پیام همگانیِ تأییدشده
- حالت webhook داخلی PHP برای هاست اشتراکی؛ ربات Python با polling به‌عنوان گزینهٔ VPS/Docker
- علاقه‌مندی‌ها (در حالت Python)، تاریخچهٔ جست‌وجو، اشتراک‌گذاری لینک اثر

> **اصل طلایی:** اگر فیلدی در دیتابیس نیست، جعل نمی‌شود — حذف یا «ناموجود» نمایش داده می‌شود.

---

## معماری

**دو حالت اجرا با یک منبع دادهٔ وردپرس:**

```
هاست اشتراکی (پیشنهادی)                 VPS/Docker (اختیاری)
[تلگرام] ⇄ Bot API ⇄ [WP Webhook PHP] ─┐  [تلگرام] ⇄ Bot API ⇄ [Python polling]
                                       ├⇄ [افزونه ahangban-connector] ⇄ WP DB
                                       └   (توکن API برای Python)
```

برای دایرکت‌ادمین و بدون SSH، حالت **وب‌هوک PHP** را در پیشخوان فعال کنید؛ سرویس ماندگار یا دیتابیس جدا لازم نیست. حالت Python/polling برای VPS اختیاری است و دادهٔ آهنگ/خواننده همچنان فقط از WordPress خوانده می‌شود.

جزئیات: [`docs/01-ARCHITECTURE.md`](docs/01-ARCHITECTURE.md) · نصب بدون SSH: [`docs/05-DIRECTADMIN-GUIDE.md`](docs/05-DIRECTADMIN-GUIDE.md) · یافته‌های مهندسی معکوس: [`docs/00-REVERSE-ENGINEERING-REPORT.md`](docs/00-REVERSE-ENGINEERING-REPORT.md)

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

### پس از فعال‌سازی (وب‌هوک، بدون SSH)

1. به منوی **آهنگ‌بان API** بروید و توکن BotFather و شناسهٔ ادمین را وارد کنید.
2. حالت `وب‌هوک` را انتخاب و ذخیره کنید؛ سپس «ست کردن وب‌هوک» را بزنید.
3. در تلگرام `/start` بفرستید؛ برای مدیریت، `/admin` را بزنید.
4. پنل سلامت REST: `https://ahangban.ir/wp-json/ahangban/v1/health`.

توکن ربات بعد از ذخیره در صفحه HTML نمایش داده نمی‌شود؛ فیلد خالی مقدار فعلی را حفظ می‌کند. اگر `taxonomy_artist = ❌` بود، تاکسونومی واقعی قالب/سایت را بررسی کنید؛ افزونه به‌صورت خودکار چیزی ثبت نمی‌کند.

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

فایل `.env` (هرگز آن را کامیت نکنید — `.gitignore` شده است):

```env
BOT_TOKEN=123456:ABC...
AHANGBAN_API_BASE=https://ahangban.ir/wp-json/ahangban/v1
AHANGBAN_API_TOKEN=64-hex-token-from-WP-admin
ADMIN_IDS=123456789,987654321
BOT_USERNAME=ahangbanbot
LOG_LEVEL=INFO
CACHE_TTL=300
RATE_LIMIT_PER_MINUTE=20
```

| متغیر | توضیح |
|---|---|
| `BOT_TOKEN` | توکن BotFather (الزامی) |
| `AHANGBAN_API_BASE` | آدرس پایهٔ API افزونه (بدون `/` انتهایی) |
| `AHANGBAN_API_TOKEN` | توکن افزونه از پیشخوان (الزامی) |
| `ADMIN_IDS` | شناسه‌های عددی ادمین با کاما (برای `/stats` و `/broadcast`) |
| `BOT_USERNAME` | اختیاری، برای لینک‌سازی |
| `AHANGBAN_DB_PATH` | مسیر فایل SQLite (پیش‌فرض `bot/ahangban.db`) |

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

### گزینه C — وب‌هوک داخلی وردپرس (دایرکت‌ادمین)

در حالت اشتراکی نیازی به اجرای Python یا تعریف Webhook دستی نیست: افزونه v1.3.0 از پیشخوان توکن را می‌گیرد، Secret را تنظیم می‌کند و `POST /wp-json/ahangban/v1/telegram/webhook` را با Telegram ثبت می‌کند. مراحل کامل در [`docs/05-DIRECTADMIN-GUIDE.md`](docs/05-DIRECTADMIN-GUIDE.md) آمده است.

---

## مستندات API

همهٔ endpointها به جز `/health` نیاز به هدر `Authorization: Bearer <TOKEN>` دارند.

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

- ✅ توکن‌ها فقط در `.env` و `wp_options (autoload=no)` — هرگز در گیت.
- ✅ فقط `post_status=publish` برگردانده می‌شود.
- ✅ وب‌هوک Secret header را اجباری بررسی می‌کند؛ update تکراری و rate limit مدیریت می‌شود.
- ✅ اعتبارسنجی ورودی، دسترسی ادمین بر اساس شناسهٔ تلگرام، و توکن‌های WordPress با `autoload=no`.
- ✅ دریافت رسانه فقط با HTTPS؛ IP خصوصی/رزروشده، مقصد محلی و redirect ناامن رد می‌شوند. سقف آپلود محدود است؛ شکست به پیوند اصلیِ اثر می‌رسد.
- ✅ لاگ‌ها توکن Bot API را ثبت نمی‌کنند؛ پیام‌های خطا به کاربر جزئیات فنی/محرمانه نمی‌دهند.
- ✅ `file_id` برای صوت در transient وردپرس یا SQLite حالت Python cache می‌شود.

---

## تست‌ها

```bash
source venv/bin/activate
pytest bot/tests/ -v

# یا مستقیم
python -m pytest bot/tests/test_normalize.py -v
```

تست‌های موجود:

- `test_normalize.py` — نرمال‌سازی ی/ي، ک/ك، نیم‌فاصله، اعتبارسنجی طول
- `test_api_contract.py` — قرارداد health/search (mock)
- `test_security.py` — XSS/SQLi/URL/path traversal

> برای تست زنده با وردپرس واقعی: `.env` را با مقادیر واقعی پر کنید و `python -m bot.main` → `/ping` در تلگرام.

---

## مانیتورینگ و لاگ

- لاگ ربات: `journalctl -u ahangbanbot -f` یا `docker compose logs -f`
- سلامت از تلگرام: `/ping` و `/health` (ادمین)
- سلامت از سرور:

```bash
curl -H "Authorization: Bearer $TOKEN" https://ahangban.ir/wp-json/ahangban/v1/health | jq
```

- آمار ادمین در تلگرام: `/stats` → کاربران کل/فعال، تعداد جست‌وجو، بازدید.

---

## پشتیبان‌گیری

- **وردپرس:** افزونه به DB چیزی اضافه نمی‌کند جز یک سطر `ahangban_connector_token` در `wp_options` — با بکاپ معمول وردپرس پوشش داده می‌شود.
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
| لینک دانلود expire/404 | ⚠️ لینک‌ها از DB می‌آیند؛ افزونه HEAD نمی‌زند (عهدهٔ ربات) | ربات HEAD + fallback به صفحه |
| ارسال فایل >۵۰MB | ⚠️ محدودیت Bot API | ربات لینک می‌دهد |
| هاست اشتراکی بدون اجرای دائمی | ⚠️ باید ربات بیرون از هاست اجرا شود | Docker/VPS |
| تغییر قالب | ✅ ربات به قالب وابسته نیست | فقط `post` + `artist` + متا |

---

## گزارش فاز صفر

برای گزارش کامل مهندسی معکوس به [`docs/00-REVERSE-ENGINEERING-REPORT.md`](docs/00-REVERSE-ENGINEERING-REPORT.md) و برای تصمیم معماری به [`docs/01-ARCHITECTURE.md`](docs/01-ARCHITECTURE.md) مراجعه کنید.

---

## مجوز

GPL-2.0-or-later — مطابق وردپرس. فونت Vazirmatn با مجوز OFL.

---

<p align="center"><b>آهنگ‌بان</b> — ساخته‌شده با ❤️ برای موسیقی فارسی</p>
