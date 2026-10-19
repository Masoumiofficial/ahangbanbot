=== AhangBan Connector ===
Contributors: ahangban
Tags: telegram, api, music, ahangban
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

لایهٔ امن اتصال ربات تلگرام آهنگ‌بان به داده‌های واقعی سایت — بدون وابستگی به قالب.

== نصب بدون SSH (دایرکت‌ادمین) ==
1. پوشه ahangban-connector را در File Manager → wp-content/plugins آپلود و Extract کنید.
2. در پیشخوان → افزونه‌ها → فعال‌سازی.
3. به منوی «آهنگ‌بان API» بروید.
4. در بخش «تنظیم ربات تلگرام — بدون نیاز به SSH»: توکن BotFather و شناسه ادمین را Paste → ذخیره.
5. روی «🚀 ست کردن وب‌هوک» کلیک کنید — تمام! ربات روی همین هاست جواب می‌دهد.

== نصب با ربات بیرونی (polling) ==
همان مراحل بالا، سپس توکن API (بخش پایین صفحه) را در .env ربات بیرونی قرار دهید.

== امنیت ==
* فقط publish برگردانده می‌شود.
* Rate limit داخلی.
* توکن فقط در wp_options (autoload=no)، هرگز در گیت.
* وب‌هوک با Secret Header احراز می‌شود.

== تغییرات ==
= 1.2.0 =
* تنظیم توکن‌ها بدون SSH از پیشخوان (BOT_TOKEN، ADMIN_IDS، BOT_USERNAME، حالت webhook/polling)
* وب‌هوک داخلی PHP — اجرا روی هاست اشتراکی دایرکت‌ادمین بدون نیاز به VPS
* دکمه ست/حذف وب‌هوک و تست پیام مستقیم از پیشخوان
* سازگاری singer→artist + health جدید

= 1.1.0 =
* مهاجرت artist: singer→artist، سازگاری خودکار

= 1.0.0 =
* نسخهٔ اولیه: health, search, song, artists, artist, latest, popular, cache/clear.
