# راهنمای دایرکت‌ادمین — بدون SSH (v1.2.0)

این راهنما مخصوص هاست اشتراکی دایرکت‌ادمین (مثل etehadya) است که SSH ندارید.

## گام ۱ — آپلود افزونه (File Manager)
1. دانلود ZIP:
   `https://github.com/Masoumiofficial/ahangbanbot/archive/refs/heads/arena/24085415-ahangbanbot.zip`
2. داخل ZIP برو `wp-plugin/ahangban-connector` → دوباره ZIP کن به `ahangban-connector.zip`
3. دایرکت‌ادمین → File Manager → `domains/ahangban.ir/public_html/wp-content/plugins` → Upload → Extract
4. وردپرس → افزونه‌ها → فعال‌سازی **AhangBan Connector**

## گام ۲ — گرفتن توکن‌ها
1. **Bot Token:** تلگرام → `@BotFather` → `/newbot` → مثلاً `ahangbanbot` → توکن `123456:AAH...` را کپی کن.
2. **Admin ID:** تلگرام → `@userinfobot` → `/start` → عدد `987654321` را کپی کن.

## گام ۳ — تنظیم بدون SSH (پیشخوان)
وردپرس → **آهنگ‌بان API** → بخش **تنظیم ربات — بدون نیاز به SSH**:
- `توکن ربات` را Paste کن (نمایش ماسک شده است)
- `یوزرنیم` (اختیاری): `ahangbanbot`
- `شناسه ادمین‌ها`: `987654321` (چندتا با کاما)
- حالت: **وب‌هوک** (پیشنهادی برای دایرکت‌ادمین)
- **ذخیره**

> نکته: اگر قبلاً در `wp-config.php` ثابت گذاشته بودی، افزونه همان را می‌خواند. برای ویرایش از پیشخوان، ثابت را حذف کن.

## گام ۴ — ست کردن وب‌هوک (یک کلیک)
همان صفحه → بخش **وب‌هوک**:
- آدرس وب‌هوک را می‌بینی: `https://ahangban.ir/wp-json/ahangban/v1/telegram/webhook`
- روی **🚀 ست کردن وب‌هوک** کلیک کن → باید «✅ وب‌هوک ست شد» ببینی.
- اگر خطای `https` دیدی: دایرکت‌ادمین → SSL → Let’s Encrypt را فعال کن.

## گام ۵ — تست
- دکمه **✉️ ارسال پیام تست** → باید در تلگرام پیام بیاید.
- مرورگر: باز کن `https://ahangban.ir/wp-json/ahangban/v1/telegram/webhook` → باید `{"ok":true}` ببینی.
- تلگرام: به ربات برو `/start` — باید جواب دهد.

## عیب‌یابی
- ربات جواب نمی‌دهد: توکن را دوباره Paste کن → ست وب‌هوک → تست پیام
- وب‌هوک ست نمی‌شود: هاست باید HTTPS باشد و `wp-json` باز باشد (افزونه امنیتی REST را نبسته باشد)
- می‌خوای به حالت پولینگ (ربات جدا) برگردی: حالت را بگذار `پولینگ` و از `.env` استفاده کن (نیاز به VPS)

## مهاجرت artist
اگر قبلاً singer داشتی: phpMyAdmin → SQL:
`UPDATE wp_term_taxonomy SET taxonomy='artist' WHERE taxonomy='singer';`
بعد پیوندهای یکتا → ذخیره.

