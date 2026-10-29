# استقرار ربات polling روی AbrhaPaaS

این ربات Telegram را با polling دریافت می‌کند و برای خود Telegram به دامنه یا webhook نیاز ندارد. قالب Python این PaaS پورت ورودی می‌خواهد؛ بنابراین ربات یک HTTP health endpoint کمینه روی `0.0.0.0:$PORT` باز می‌کند. مسیرهای `/` و `/health` فقط `{"status":"ok"}` برمی‌گردانند و هیچ تنظیم یا Secretی را افشا نمی‌کنند.

## تنظیمات اپلیکیشن

| فیلد | مقدار پیشنهادی |
|---|---|
| Namespace | فضایی که به مخزن GitHub `Masoumiofficial/ahangbanbot` دسترسی دارد |
| نام پروژه | `ahangbanbot` |
| نام اپلیکیشن | `ahangbanbot-worker` |
| Sub-domain | `ahangbanbot-worker` (دامنه برای health check پنل است؛ Telegram از آن استفاده نمی‌کند) |
| نسخهٔ Python | `3.11-ubi9` |
| application port | `8000` |
| Build command | `pip install -r requirements.txt` (if the panel requires one; it includes `bot/requirements.txt`) |
| Start command | `python -m bot.main` |
| Build variables | خالی بگذارید |

کد از متغیر `PORT` استفاده می‌کند و اگر PaaS آن را تزریق نکند، مقدار پیش‌فرض `8000` است. در Environment Variables نیز `PORT=8000` بگذارید تا با application port یکی باشد.

برای دسترسی به health endpoint، PaaS باید مخزن و شاخه‌ای را دریافت کند که فایل `bot/health_server.py` را دارد. در زمان این راهنما، این تغییر روی شاخهٔ `arena/0f6da245-ahangbanbot` قرار می‌گیرد؛ بعد از ادغام PR می‌توان `main` را انتخاب کرد.

## Environment Variables

این متغیرها را در بخش **متغیر محیطی** بسازید؛ Secretها را در Build Variables نگذارید و در پیام/اسکرین‌شات منتشر نکنید:

| نام | مقدار |
|---|---|
| `BOT_TOKEN` | توکن فعلی BotFather |
| `AHANGBAN_API_BASE` | `https://ahangban.ir/wp-json/ahangban/v1` |
| `AHANGBAN_API_TOKEN` | دقیقاً همان توکن ۶۴رقمی hex که افزونهٔ WordPress استفاده می‌کند |
| `ADMIN_IDS` | شناسهٔ عددی Telegram ادمین‌ها با کاما؛ اختیاری |
| `PORT` | `8000` |
| `LOG_LEVEL` | `INFO` |
| `CACHE_TTL` | `300` |
| `RATE_LIMIT_PER_MINUTE` | `20` |
| `AHANGBAN_DB_PATH` | فقط اگر PaaS دیسک پایدار متصل می‌کند، مسیر قابل‌نوشتن همان disk؛ مسیر را حدس نزنید |

SQLite ربات برای کاربرها، پسندها، دنبال‌کردن هنرمند و cache محلی است. اگر PaaS فایل‌سیستم موقت دارد، در مرحلهٔ منابع یک Persistent Disk اضافه کنید و `AHANGBAN_DB_PATH` را به مسیر mount آن بگذارید؛ در غیر این صورت این داده‌ها ممکن است با redeploy از بین بروند. دادهٔ اصلی موسیقی همچنان از WordPress می‌آید.

## جابه‌جایی امن از WordPress polling

تا وقتی اپلیکیشن VPS آماده نیست، حالت WordPress polling را نگه دارید. درست پیش از Deploy نهایی:

1. در WordPress حالت را به **Python polling روی VPS** تغییر دهید و ذخیره کنید؛ این کار زمان‌بندی polling وردپرس را متوقف می‌کند.
2. مطمئن شوید webhook در Telegram ثبت نیست. حذف webhook با `drop_pending_updates=false` انجام می‌شود.
3. اپلیکیشن AbrhaPaaS را Deploy/Start کنید. Python polling با `drop_pending_updates=False` بالا می‌آید و updateهای pending را دور نمی‌ریزد.
4. لاگ باید پیام `Bot is polling` را نشان دهد؛ از دامنهٔ PaaS مسیر `/health` را باز کنید و در Telegram یک `/start` بفرستید.

برای هر Bot Token فقط یک دریافت‌کننده فعال باشد. خطای `Conflict` معمولاً یعنی webhook، polling وردپرس یا یک Python worker دیگر هنوز با همین توکن فعال است.
