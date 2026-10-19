# مهاجرت singer → artist — راهنمای مدیر سایت آهنگ‌بان

> این سند توضیح می‌دهد چگونه تاکسونومی قدیمی `singer` به `artist` و مسیرهای `/singer/` و `/singers/` به `/artist/` و `/artists/` تغییر پیدا کردند — و چگونه داده‌های قبلی را بدون از دست رفتن منتقل کنید.

## ۱. چه چیزی عوض شد؟

| قبل (قدیمی) | بعد (جدید) |
|---|---|
| تاکسونومی `singer` | تاکسونومی `artist` |
| آرشیو خواننده `/singer/{slug}/` | آرشیو جدید `/artist/{slug}/` |
| فهرست همه خوانندگان `/singers/` | `/artists/` |
| فایل قالب `taxonomy-singer.php` | `taxonomy-artist.php` (یا فیلتر rewrite) |
| پارامتر سلامت `taxonomy_singer` | `taxonomy_artist` (+ سازگاری با قدیمی) |
| کلید `singers_path` در `BRAND` | `artists_path` |

افزونه به‌صورت **سازگار** نوشته شده: اگر هنوز `artist` ثبت نشده و `singer` قدیمی وجود دارد، خودکار روی `singer` می‌افتد تا سایت نخوابد. اما برای مهاجرت کامل، قدم‌های زیر را انجام دهید.

## ۲. پیش‌نیاز

- بکاپ کامل DB (یا حداقل `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`, `wp_termmeta`, `wp_options`)
- دسترسی SSH یا phpMyAdmin + WP-CLI (اختیاری ولی پیشنهادی)

## ۳. مهاجرت با WP-CLI (پیشنهادی — ۲ دقیقه)

```bash
# 1) بکاپ
wp db export ~/backup-ahangban-$(date +%F).sql

# 2) تغییر نام تاکسونومی در DB
wp db query "UPDATE wp_term_taxonomy SET taxonomy='artist' WHERE taxonomy='singer';"

# 3) اگر ترم‌متا با کلیدهای singer_en دارید (اختیاری: rename them)
wp db query "UPDATE wp_termmeta SET meta_key='artist_en' WHERE meta_key='singer_en';"

# 4) پاک کردن کش و بازسازی قوانین
wp cache flush
wp rewrite flush --hard
wp transient delete --all

# 5) بررسی
wp term list artist --fields=term_id,name,slug,count | head -20
wp rewrite list | grep artist
```

> اگر هنوز فایل `taxonomy-singer.php` دارید، آن را به `taxonomy-artist.php` کپی کنید یا در قالب یک فیلتر اضافه کنید:
```php
add_filter('template_include', function($t){
  if(is_tax('artist') && !file_exists(get_template_directory().'/taxonomy-artist.php')){
    // fallback به singer قدیمی
    $old = get_template_directory().'/taxonomy-singer.php';
    if(file_exists($old)) return $old;
  }
  return $t;
});
```

## ۴. مهاجرت دستی (بدون WP-CLI)

### phpMyAdmin
1. جدول `wp_term_taxonomy` → تب SQL → اجرا:
```sql
UPDATE wp_term_taxonomy SET taxonomy='artist' WHERE taxonomy='singer';
```
2. تنظیمات → پیوندهای یکتا → ذخیره (بدون تغییر) — برای فلاش rewrite

### ویرایش قالب
- فایل `taxonomy-singer.php` را کپی و به `taxonomy-artist.php` تغییر نام دهید.
- برگهٔ هنرمندان: ویرایش برگه با اسلاگ `singers` → به `artists` تغییر دهید (یا یک ریدایرکت ۳۰۱ اضافه کنید).

## ۵. ریدایرکت‌های ۳۰۱ (سئو — مهم)

بعد از مهاجرت، آدرس‌های قدیمی باید ۳۰۱ شوند تا گوگل و لینک‌های تلگرام نشکنند.

`.htaccess` (یا افزونه Redirection):

```apache
RedirectMatch 301 ^/singer/(.*)$ /artist/$1
RedirectMatch 301 ^/singers/?$ /artists/
```

یا با فیلتر وردپرس:

```php
add_action('template_redirect', function(){
  if(strpos($_SERVER['REQUEST_URI'],'/singer/')===0){
    wp_redirect(str_replace('/singer/','/artist/',$_SERVER['REQUEST_URI']),301); exit;
  }
  if($_SERVER['REQUEST_URI']==='/singers/' || $_SERVER['REQUEST_URI']==='/singers'){
    wp_redirect('/artists/',301); exit;
  }
});
```

## ۶. راستی‌آزمایی بعد از مهاجرت

```bash
curl -s https://ahangban.ir/wp-json/ahangban/v1/health | jq
# باید ببینید: "taxonomy": "artist", "taxonomy_exists": true, "legacy_singer": false

# فهرست خوانندگان
curl -H "Authorization: Bearer $TOKEN" "https://ahangban.ir/wp-json/ahangban/v1/artists?page=1&per_page=2" | jq

# یک خواننده
curl -H "Authorization: Bearer $TOKEN" "https://ahangban.ir/wp-json/ahangban/v1/artist/mohsen-yeganeh" | jq
```

در پیشخوان وردپرس → **آهنگ‌بان API** باید ببینید: `تاکسونومی فعال — ✅ فعال — artist`

## ۷. سازگاری ربات

ربات تلگرام نیازی به تغییر ندارد — خودکار از `artist` استفاده می‌کند. اگر سایت هنوز مهاجرت نکرده (هنوز `singer` است)، ربات به‌صورت خودکار fallback می‌کند.

تکسونومی در `.env` قابل تنظیم نیست — کد `get_taxonomy()` هوشمند است. فقط بعد از مهاجرت، کش ربات را خالی کنید:

```
/cache_clear  (دستور ادمین در تلگرام)
یا
curl -X POST -H "Authorization: Bearer $TOKEN" https://ahangban.ir/wp-json/ahangban/v1/cache/clear
```

## ۸. بازگشت (Rollback)

اگر مشکلی پیش آمد:

```bash
wp db query "UPDATE wp_term_taxonomy SET taxonomy='singer' WHERE taxonomy='artist';"
wp rewrite flush --hard
```

و افزونه به‌صورت خودکار دوباره روی `singer` می‌افتد.

---

**نکته:** فیلدهای ACF مثل `aimg2` و `singer_en` (قدیمی) همچنان خوانده می‌شوند — بعد از مهاجرت می‌توانید `singer_en` را به `artist_en` تغییر دهید ولی الزامی نیست (کد هر دو را می‌خواند).
