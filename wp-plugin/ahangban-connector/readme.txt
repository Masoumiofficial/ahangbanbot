=== AhangBan Connector ===
Contributors: ahangban
Tags: telegram, api, music, ahangban
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

لایهٔ امن اتصال ربات تلگرام آهنگ‌بان به داده‌های واقعی سایت — بدون وابستگی به قالب.

== نصب ==
1. پوشه ahangban-connector را در wp-content/plugins آپلود کنید.
2. در پیشخوان → افزونه‌ها فعال کنید.
3. به منوی «آهنگ‌بان API» بروید و توکن را کپی کنید.
4. توکن را در .env ربات (AHANGBAN_API_TOKEN) قرار دهید.

== امنیت ==
* فقط publish برگردانده می‌شود.
* Rate limit داخلی.
* توکن فقط در wp_options، هرگز در گیت.

== تغییرات ==
= 1.0.0 =
* نسخهٔ اولیه: health, search, song, artists, artist, latest, popular, cache/clear.
