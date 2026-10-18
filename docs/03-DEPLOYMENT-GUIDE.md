# راهنمای استقرار — آهنگ‌بان

## ۱. استقرار افزونهٔ وردپرس (۵ دقیقه)

### پیش‌نیاز روی `ahangban.ir`

- وردپرس ۵.۹+، PHP ۷.۴+، افزونهٔ ACF فعال
- دسترسی مدیر (پیشخوان)

### مراحل

1. فایل `wp-plugin/ahangban-connector` را زیپ کنید یا مستقیم آپلود کنید:

```bash
cd wp-plugin && zip -r ahangban-connector.zip ahangban-connector
```

2. در پیشخوان: **افزونه‌ها → افزودن → بارگذاری → انتخاب زیپ → نصب → فعال‌سازی**

3. وارد منوی جدید **آهنگ‌بان API** شوید → توکن را کپی کنید.

4. تست سلامت:

```
https://ahangban.ir/wp-json/ahangban/v1/health
→ {"status":"ok","wp":"...","php":"...","taxonomy_singer":true}
```

5. تست احراز:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" https://ahangban.ir/wp-json/ahangban/v1/search?q=معین | jq
```

---

## ۲. استقرار ربات (Docker — پیشنهادی)

### سرور پیشنهادی

- VPS ۱ vCPU / ۱GB RAM (مثلاً Hetzner CX11 یا هر VPS ارزان خارج)
- Ubuntu 22.04
- دامنه لازم نیست (polling)

### مراحل

```bash
git clone https://github.com/Masoumiofficial/ahangbanbot.git /opt/ahangbanbot
cd /opt/ahangbanbot
cp .env.example .env
nano .env   # BOT_TOKEN و AHANGBAN_API_TOKEN را پر کنید
docker compose up -d --build
docker compose logs -f
```

### به‌روزرسانی

```bash
cd /opt/ahangbanbot
git pull
docker compose up -d --build
```

---

## ۳. استقرار با systemd

```bash
sudo useradd -r -m -d /opt/ahangbanbot ahangban
sudo cp -r . /opt/ahangbanbot
sudo chown -R ahangban:ahangban /opt/ahangbanbot
cd /opt/ahangbanbot
python3 -m venv venv
venv/bin/pip install -r bot/requirements.txt
cp .env.example .env && nano .env
sudo cp systemd/ahangbanbot.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ahangbanbot
systemctl status ahangbanbot
journalctl -u ahangbanbot -f
```

---

## ۴. تنظیم متغیرهای محیطی

`.env` روی سرور ربات (هرگز کامیت نکنید):

```env
BOT_TOKEN=12345:ABC...
AHANGBAN_API_BASE=https://ahangban.ir/wp-json/ahangban/v1
AHANGBAN_API_TOKEN=64-hex...
ADMIN_IDS=YOUR_TELEGRAM_ID
```

برای یافتن Telegram ID خود: به [@userinfobot](https://t.me/userinfobot) پیام دهید.

---

## ۵. وب‌هوک (اختیاری)

اگر polling روی شبکهٔ ایران مسدود شد، وب‌هوک را فعال کنید:

1. روی سرور با IP عمومی + دامنه + SSL:
2. در وردپرس یک endpoint `POST /wp-json/ahangban/v1/webhook` اضافه کنید (نیاز به کدنویسی — در حال حاضر پولینگ پایدارتر است).
3. در تلگرام:

```bash
curl -F "url=https://your-domain/hook" https://api.telegram.org/bot$BOT_TOKEN/setWebhook
```

---

## ۶. بکاپ و بازیابی

### وردپرس

- افزونه فقط یک سطر در `wp_options` اضافه می‌کند (`ahangban_connector_token`) — با بکاپ عادی وردپرس پوشش داده می‌شود.

### ربات (SQLite)

```bash
# روزانه via cron
0 3 * * * sqlite3 /opt/ahangbanbot/bot/ahangban.db ".backup /backups/ahangban-$(date +\%F).db"
# نگه‌داری ۷ روز
find /backups -name "ahangban-*.db" -mtime +7 -delete
```

بازیابی:

```bash
systemctl stop ahangbanbot
cp /backups/ahangban-2026-10-18.db bot/ahangban.db
systemctl start ahangbanbot
```

---

## ۷. مانیتورینگ

- سلامت از تلگرام: `/ping` (همه) و `/health` + `/stats` (ادمین)
- سلامت از سرور:

```bash
curl -H "Authorization: Bearer $TOKEN" https://ahangban.ir/wp-json/ahangban/v1/health | jq
```

- لاگ: `journalctl -u ahangbanbot --since today -f` یا `docker compose logs --tail 100 -f`
- آلرت: می‌توانید Uptime Kuma یا Healthchecks.io را به `/health` وصل کنید.

---

## ۸. عیب‌یابی

| علامت | علت محتمل | راه‌حل |
|---|---|---|
| ربات پاسخ نمی‌دهد | BOT_TOKEN اشتباه / polling مسدود | `journalctl` را ببینید؛ `/ping` از BotFather توکن را چک کنید |
| `401/403` در لاگ | AHANGBAN_API_TOKEN اشتباه | توکن را از پیشخوان دوباره کپی کنید |
| `singer غیرفعال` در `/health` | تاکسونومی ثبت نشده | مستند `SINGER-ARCHIVE-DIAGNOSIS.md` در قالب را بخوانید |
| ارسال فایل ناموفق | حجم >۵۰MB یا لینک expire | ربات خودکار لینک می‌دهد — طبیعی است |
| Flood/429 | درخواست زیاد | صبر کنید؛ rate limit خودکار است |
