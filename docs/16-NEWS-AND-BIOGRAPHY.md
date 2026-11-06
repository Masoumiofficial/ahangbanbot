# اخبار و بیوگرافی هنرمندان در ربات

## دسترسی به اخبار

- دکمهٔ «📰 اخبار» در منوی اصلی و دستور `/news` در هر دو حالت webhook PHP و Python polling صفحهٔ رسمی `https://ahangban.ir/news/` را باز می‌کنند.
- فعلاً خبرها داخل تلگرام بازنشر یا scrape نمی‌شوند. در API افزونهٔ موجود، مسیر تأییدشده‌ای برای اخبار پیدا نشده و نوع نوشته/دسته/فیلدهای WordPress نباید حدس زده شود.
- برای نمایش فهرست خبرها به‌شکل کارت تلگرام، ابتدا باید ساختار واقعی محتوای اخبار بررسی و سپس یک endpoint امن و اختصاصی در افزونهٔ WordPress تعریف شود.

## دسترسی به بیوگرافی

- دکمهٔ «📖 بیوگرافی هنرمندان» در منو و دستور `/biography` در هر دو حالت، صفحهٔ رسمی `https://ahangban.ir/biography/` را باز می‌کنند.
- از کارت هر هنرمند، «📖 بیوگرافی کامل» متن کامل توضیح همان هنرمند را می‌گیرد؛ Python از endpoint موجود جزئیات هنرمند و webhook PHP از همان `AhangBan_Search::get_artist_detail()` استفاده می‌کند. منبع متن در هر دو حالت `term.description` برگشتی از WordPress است. پیش‌نمایش کارت همچنان کوتاه می‌ماند.
- دستور `/biography <نام هنرمند>` یا `/bio <نام هنرمند>` همان متن را برای هنرمند مشخص نمایش می‌دهد. `/bio` نام مستعار `/biography` است.
- دکمهٔ سایت از `term.link` واقعی برمی‌گردد؛ دیتابیس موازی یا فیلد جدیدی ساخته نشده است.

## فایل‌های مرتبط

- `bot/config.py`: نشانی صفحات عمومی و اعتبارسنجی HTTPS.
- `bot/utils/keyboards.py`: پیوندهای منوی اصلی و دکمهٔ بیوگرافی کارت هنرمند.
- `bot/handlers/site_pages.py`: فرمان `/news`.
- `bot/handlers/artist.py`: فرمان‌ها و callback بیوگرافی کامل.
- `bot/utils/formatters.py`: تبدیل HTML توضیح وردپرس به متن امن Telegram HTML.
- `wp-plugin/ahangban-connector/includes/class-telegram.php`: منو، فرمان‌ها و callbackهای حالت webhook PHP.
- `wp-plugin/ahangban-connector/ahangban-connector.php`: نسخهٔ افزونهٔ 1.6.8.

## بررسی و بستهٔ نصب

- آزمون‌های Python: **۱۱۳ موفق**؛ `compileall`، `git diff --check` و Ruff با قواعد E4/E7/E9/F/I برای فایل‌های تغییرکرده موفق شدند.
- ۸ فایل PHP افزونه با `php-parser` و دستور زبان PHP 7.4 parse شدند؛ این بررسی جای اجرای PHP/WordPress واقعی را نمی‌گیرد.
- ZIP افزونه: `releases/ahangban-connector-1.6.8.zip`، حجم ۷۸٬۲۳۲ بایت، SHA-256: `5de02d34b1749aed64717cb31cd530139722bb478141d98b2c3cb7317286e7fa`؛ `unzip -t` موفق بود.
- اتصال واقعی به سایت و تلگرام، دریافت زندهٔ اخبار، و نمایش بیوگرافی از دیتابیس سایت در این محیط آزمایش نشده‌اند.
