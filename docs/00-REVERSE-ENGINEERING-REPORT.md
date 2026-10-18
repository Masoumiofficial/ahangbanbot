# گزارش فاز صفر — مهندسی معکوس قالب آهنگ‌بان (جوان‌صدا v3.0.1)

> تاریخ: 2026-10-18 · مخزن: `Masoumiofficial/javan-seda` · کیت برند: `Masoumiofficial/ahangban/brand-kit`

## ۱. ساختار فایل‌ها و معماری قالب

```
javan-seda (artifact javanseda-v3.0.1-theme.zip — SHA هش در docs/CHANGES موجود است)
├── header.php / footer.php / home.php / single.php / page.php / search.php / tag.php / category.php
├── artist-template.php        ← برگهٔ «همهٔ هنرمندان» (/singers/)
├── taxonomy-singer.php        ← آرشیو هر خواننده (/singer/{slug})
├── functions.php              ← رمزگذاری ionCube (قابل خواندن نیست — خطا می‌دهد)
├── myfunctions.php            ← منطق تکمیلی خواننده + گزارش تشخیصی + ثبت جایگزین singer
├── style.css (v3.0.1) + css/icofont.min.css + fonts/vazir-*
├── js/scripts.js + owl.carousel
├── inc/
│   ├── songs_post.php         ← کارت تکی آهنگ
│   ├── tarlanweb_player.php   ← پلیر ایندکس
│   ├── artist_slider.php      ← اسلایدر هنرمندان
│   ├── new_posts.php / mostviews_posts.php / vip_slider.php / breadcrumbs.php
│   ├── theme-options.php       ← تنظیمات OptionTree
│   └── option-tree/            ← کتابخانهٔ OptionTree
├── images/def-singer.webp, nomusics.jpg, logo.png, ...
├── searchwp-live-ajax-search/ ← جست‌وجوی زندهٔ قدیمی (data-swplive=true)
└── docs/CHANGES_v3.0.1.md
```

**نکتهٔ مهم هاست:** لاگ `debug (1).log` مسیر واقعی را لو داد:
`/home/etehadya/domains/ahangban.ir/public_html/wp-content/themes/javanseda/...`
→ هاست اشتراکی (احتمالاً DirectAdmin) + ionCube Loader فعال.

---

## ۲. پست‌تایپ‌ها

- **هیچ CPT اختصاصی نیست.** همهٔ آثار (`post`) با `post_type = 'post'` ذخیره می‌شوند.
- تمایز «آهنگ / آلبوم / ریمیکس / پادکست / موزیک‌ویدیو» فقط با **فیلد متای `musics_type`** انجام می‌شود (نگاشت در جدول §۴).
- `page` برای برگهٔ «همهٔ خواننده‌ها» با قالب `artist-template.php` استفاده می‌شود.

---

## ۳. تاکسونومی‌ها و روابط

| تاکسونومی | اسلاگ | سلسله‌مراتبی | کاربرد | rewrite |
|---|---|---|---|---|
| `singer` | `singer` | خیر (مانند برچسب) | خواننده — «منبع حقیقت» برای ربات | `/singer/{slug}` |
| `category` | `category` | بله | دسته‌بندی سایت (برای باکس‌های صفحهٔ اصلی) | `/category/{slug}` |
| `post_tag` | `tag` | خیر | نگهداشت قدیمی خواننده‌ها (حالت `reltag=on`) | `/tag/{slug}` |

- رابطهٔ اصلی: **`wp_term_relationships` → `term_taxonomy_id` متعلق به `singer`** ↔ هر post.
- یک اثر می‌تواند **چند خواننده** داشته باشد (چند ترم singer).
- ستون کناری و «سایر آهنگ‌های ...» دو منطق دارد:
  ۱) اگر `reltag=off` (پیش‌فرض جدید): `tax_query` روی `singer`.
  ۲) اگر `reltag=on`: `meta_query` با `LIKE` روی متای `artist` (قدیمی، کند).
- کشف: اگر `singer` ثبت نشده باشد، `myfunctions.php` با ثابت `JAVANSEDA_SINGER_FIX=true` می‌تواند در اولویت ۹۹ آن را بسازد — ولی پیش‌فرض **خاموش** است.

---

## ۴. فیلدهای متا (قطعی — از single.php و taxonomy-singer.php استخراج شد)

### ۴.۱ متای پست (`wp_postmeta` — توسط ACF ثبت شده‌اند)

| meta_key | نوع | مثال مقدار | توضیح |
|---|---|---|---|
| `song` | text | `بی‌تاب` | نام آهنگ (عنوان نمایشی؛ اگر خالی باشد `post_title` جایگزین می‌شود) |
| `artist` | text | `محسن یگانه` | نام خواننده (رشته؛ ممکن است چندنام با «،» جدا شده باشد) |
| `artist_link` | url | `/singer/mohsen-yeganeh` | لینک دستی صفحهٔ خواننده (اختیاری — بر تاکسونومی اولویت دارد) |
| `musics_type` | select | `آهنگ` / `mp3` / `album` / `موزیک ویدیو` ... | نوع محتوا — نگاشت کامل در جدول بعدی |
| `music128` | url | `https://dl.ahangban.ir/...128.mp3` | لینک دانلود کیفیت ۱۲۸ |
| `music320` | url | `https://dl.ahangban.ir/...320.mp3` | لینک دانلود کیفیت ۳۲۰ |
| `album128` | url |  | لینک آلبوم ۱۲۸ (وقتی اثر «آلبوم» است) |
| `album320` | url |  | لینک آلبوم ۳۲۰ |
| `video480` | url |  | موزیک‌ویدیو ۴۸۰ |
| `video720` | url |  | موزیک‌ویدیو ۷۲۰ |
| `video1080` | url |  | موزیک‌ویدیو ۱۰۸۰ |
| `music_text` | wysiwyg/textarea | متن ترانه (HTML یا متن ساده) | اگر خالی، باکس ترانه نمایش داده نمی‌شود |
| `views` | number | `15420` | بازدید (مورد استفادهٔ `mostviews_posts.php`: `meta_key=views` مرتب‌سازی `meta_value_num`، ماه گذشته) |
| `_thumbnail_id` | int |  | کاور — `wp_postmeta` استاندارد وردپرس |
| `_wp_page_template` | string | `artist-template.php` | برای یافتن برگهٔ فهرست خوانندگان |

### ۴.۲ فیلد تکرارشوندهٔ `album` (ACF Repeater)

| sub_field | نوع | توضیح |
|---|---|---|
| `song_names` | text | نام هر ترک آلبوم |
| `albumlink128` | url | لینک آن ترک ۱۲۸ |
| `albumlink320` | url | لینک آن ترک ۳۲۰ |

### ۴.۳ نگاشت `musics_type` → دستهٔ منطقی (از `taxonomy-singer.php`)

```php
audio:  mp3, audio, ahang, آهنگ, آهنگ جدید, song
video:  mp4, video, musicvideo, music-video, موزیک ویدیو, موزیک‌ویدیو, کلیپ
album:  album, آلبوم
other:  هر مقدار دیگر (نمایش در «سایر موارد»)
```

### ۴.۴ متای ترم `singer` (`wp_termmeta` + ACF on term)

| meta_key | نوع | توضیح |
|---|---|---|
| `aimg2` | attachment ID / array / url | تصویر خواننده (اصلی‌ترین فیلد) |
| `artist_en` / `en_name` / `singer_en` / ... | text | نام لاتین (اختیاری — در صورت نبود از اسلاگ ساخته می‌شود) |
| `atelegram`, `ainstagram`, `atwitter`, `ayoutube` | url | شبکه‌های اجتماعی ترم (در taxonomy-singer.php استفاده می‌شود) |
| `description` (term description) | html | بیوگرافی خواننده |

---

## ۵. URL و permalink

- تک‌آهنگ: `https://ahangban.ir/{postname}/` (ساختار پیش‌فرض وردپرس `/%postname%/` حدس زده می‌شود؛ مقدار دقیق permalink_structure در گزارش `?ab_singer_diag=1` قابل مشاهده است)
- آرشیو خواننده: `https://ahangban.ir/singer/{slug}/` + صفحه‌بندی `?paged=`
- فهرست همهٔ خوانندگان: `https://ahangban.ir/singers/` (برگه با قالب `artist-template.php`)
- جست‌وجوی سایت: `https://ahangban.ir/?s={query}` + لایو AJAX قدیمی (`searchwp-live-ajax-search`)

---

## ۶. فایل صوتی / کیفیت / کاور

- **لینک مستقیم** در متافیلد ذخیره می‌شود؛ هیچ سرویس محافظت‌شده/امضادار در قالب دیده نشد.
- کاور = تصویر شاخص وردپرس (`_thumbnail_id` → `wp_posts` + `wp_postmeta`). سایزها:
  `thumb1 640×360`, `thumb2 190×190`, `thumb3 60×60`, `thumb4 150×150`, `large`, `full`.
- پلیر HTML5 `<audio>` از `music128` (یا `albumlink128`) استفاده می‌کند.
- باکس دانلود هر کیفیت موجود را جداگانه نشان می‌دهد؛ اگر مقداری خالی باشد لینک آن حذف می‌شود.

---

## ۷. بازدید / امتیاز / پسندیدن

- `views` (مستقیم با `the_views()` و کوئری `meta_key=views` — افزونهٔ جانبی اما در قالب فراخوانی می‌شود).
- `kk-star-ratings` اختیاری (`is_plugin_active('kk-star-ratings/index.php')`).
- هیچ جدول امتیاز/لایک اختصاصی در قالب نیست.

---

## ۸. وابستگی‌ها / افزونه‌ها

| افزونه/کتابخانه | وضعیت | کاربرد |
|---|---|---|
| **ACF (Advanced Custom Fields)** | **ضروری** | تمام `get_field()`ها؛ بدون آن هیچ فیلد موسیقی نمایش داده نمی‌شود |
| OptionTree | همراه قالب (`inc/option-tree`) | پنل تنظیمات قالب |
| kk-star-ratings | اختیاری | ستاره‌ها در single.php |
| WP-PostViews / مشابه | اختیاری ولی فعال (چون `the_views` صدا زده می‌شود) | شمارش بازدید |
| ionCube Loader | روی هاست نصب است | رمزگشایی `functions.php` |
| SearchWP Live Ajax Search | همراه قالب | جست‌وجوی زنده (قدیمی) |

---

## ۹. محتوای منتشر/پیش‌نویس/حذف‌شده

- همهٔ کوئری‌های قالب `post_status=publish` را رعایت می‌کنند؛ پیش‌نویس/خصوصی نمایش داده نمی‌شود — ربات نیز باید همین فیلتر را اعمال کند.
- حذف‌شده (`trash`) در کوئری‌ها نمی‌آید.
- کش فهرست خوانندگان در `artist-template.php`: `transient` → باید با انتشار/حذف ترم باطل شود.

---

## ۱۰. مسیر فایل اصلی و کیفیت‌ها

- دامنهٔ دانلود از متافیلد خوانده می‌شود؛ در نمونه‌های سایت احتمالاً `dl.ahangban.ir` یا زیردامنهٔ مشابه است (در کد هاردکد نیست).
- اعتبار لینک باید در زمان درخواست بررسی شود (HEAD request) — قالب هیچ اعتبارسنجی نمی‌کند.

---

## ۱۱. تصویر کاور فعلی و برند

- برند: **آهنگ‌بان — #185ADB (آبی)، #1248B2 (هاور)، #EAF1FF (سطح آبی)، #111111 (مشکی)**.
- فونت سازمانی: `Vazirmatn` (در `brand-kit/assets/fonts/` با مجوز OFL).
- لوگو: سه حالت رنگی/مشکی/سفید — گرادینت مجاز نیست.

---

## ۱۲. اطلاعات نامشخص (نیاز به دسترسی زنده)

1. **schema کامل `wp_postmeta` و `wp_termmeta`** — مثلاً کلیدهای شبکه‌های اجتماعی ترم (`atelegram` ...) و اینکه آیا `aimg2` دقیقاً چه نامی در `wp_termmeta` دارد (ACF آن را با پیشوند ذخیره می‌کند یا خیر). → راه‌حل: خروجی `SELECT DISTINCT meta_key FROM wp_postmeta/termmeta` بدون مقدار.
2. **دامنهٔ واقعی فایل‌های دانلود** و آیا هات‌لینک/امضا/انقضا دارند یا خیر.
3. **وضعیت REST API** — درخواست `wp-json` خالی برگشت (احتمالاً افزونهٔ امنیتی آن را بسته). باید از WP CLI یا مدیر پرسید.
4. **نسخهٔ PHP و دسترسی SSH / cron / اجرای دائمی** روی هاست `etehadya` — در لاگ خطای `javan_seda_charts_add_shortcode` دیده شد (افزونهٔ ناقص).
5. **تنظیمات permalink_structure دقیق** و اینکه `/singers/` واقعاً کدام برگه است (ID برگهٔ قالب `artist-template.php`).

> پیشنهاد امن دریافت اطلاعات: مدیر وردپرس یک کاربر «read-only» با دسترسی REST سفارشی بسازد یا خروجی `wp db export --tables=wp_postmeta,wp_termmeta --no-data` + `wp option get permalink_structure` را بدهد — بدون رمز یا دادهٔ خصوصی.

---

## جمع‌بندی یافته‌های قطعی

- ربات باید **فقط به `post` + `singer` + متای ACF** متکی باشد — هر جدول موازی «منبع حقیقت دوگانه» است و ممنوع.
- هیچ بازنویسی قالب لازم نیست؛ یک **افزونهٔ جانبی** امن‌ترین لایهٔ اتصال است.
- ionCube یعنی `functions.php` قابل ویرایش نیست — پس افزونه باید هرگز به آن وابسته نباشد.
