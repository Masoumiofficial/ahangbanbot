<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_Admin {
    const CAP = 'manage_options';
    const SLUG = 'ahangban-connector';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
    }

    public static function menu() {
        add_menu_page(
            'اتصال آهنگ‌بان',
            'آهنگ‌بان API',
            self::CAP,
            self::SLUG,
            array( __CLASS__, 'render' ),
            'dashicons-rest-api',
            81
        );
    }

    private static function telegram_api( $token, $method, $params = array() ) {
        if ( '' === $token ) { return new WP_Error( 'no_token', 'توکن ربات تنظیم نشده' ); }
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $resp = wp_remote_post( $url, array(
            'timeout' => 12,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
        ) );
        if ( is_wp_error( $resp ) ) { return $resp; }
        $body = wp_remote_retrieve_body( $resp );
        $json = json_decode( $body, true );
        return $json;
    }

    public static function handle_actions() {
        if ( ! current_user_can( self::CAP ) ) { return; }
        if ( empty( $_POST['ahangban_action'] ) ) { return; }
        check_admin_referer( 'ahangban_connector_action' );

        $action = sanitize_key( $_POST['ahangban_action'] );

        if ( 'regenerate' === $action ) {
            $new = bin2hex( random_bytes( 32 ) );
            update_option( AhangBan_Auth::OPTION_TOKEN, $new, false );
            AhangBan_Cache::bump_version();
            add_settings_error( 'ahangban_connector', 'regenerated', 'توکن API جدید ساخته شد. ربات بیرونی را با توکن جدید به‌روزرسانی کنید.', 'updated' );

        } elseif ( 'clear_cache' === $action ) {
            AhangBan_Cache::clear_all();
            add_settings_error( 'ahangban_connector', 'cleared', 'کش پاک شد.', 'updated' );

        } elseif ( 'save_bot' === $action ) {
            $errors = AhangBan_Settings::save( array(
                'bot_token'    => isset( $_POST['bot_token'] ) ? wp_unslash( $_POST['bot_token'] ) : null,
                'bot_username' => isset( $_POST['bot_username'] ) ? wp_unslash( $_POST['bot_username'] ) : null,
                'admin_ids'    => isset( $_POST['admin_ids'] ) ? wp_unslash( $_POST['admin_ids'] ) : null,
                'mode'         => isset( $_POST['bot_mode'] ) ? wp_unslash( $_POST['bot_mode'] ) : null,
            ) );
            if ( empty( $errors ) ) {
                add_settings_error( 'ahangban_connector', 'bot_saved', '✅ تنظیمات ربات ذخیره شد — بدون نیاز به SSH.', 'updated' );
            } else {
                foreach ( $errors as $e ) { add_settings_error( 'ahangban_connector', 'bot_err', $e, 'error' ); }
            }

        } elseif ( 'regenerate_secret' === $action ) {
            update_option( AhangBan_Settings::OPTION_BOT_SECRET, bin2hex( random_bytes( 16 ) ), false );
            add_settings_error( 'ahangban_connector', 'secret_regen', '🔐 Secret جدید وب‌هوک ساخته شد. دوباره وب‌هوک را ست کنید.', 'updated' );

        } elseif ( 'set_webhook' === $action ) {
            $token = AhangBan_Settings::get_bot_token();
            if ( '' === $token ) {
                add_settings_error( 'ahangban_connector', 'no_token', 'ابتدا توکن ربات را ذخیره کنید.', 'error' );
            } else {
                $url = rest_url( 'ahangban/v1/telegram/webhook' );
                // Telegram فقط https را می‌پذیرد
                $url = set_url_scheme( $url, 'https' );
                $secret = AhangBan_Settings::get_bot_secret();
                if ( '' === $secret ) {
                    add_settings_error( 'ahangban_connector', 'secret_missing', 'ساخت Secret وب‌هوک ناموفق بود؛ تنظیمات امنیتی هاست را بررسی کنید.', 'error' );
                    return;
                }
                $res = self::telegram_api( $token, 'setWebhook', array(
                    'url' => $url,
                    'secret_token' => $secret,
                    'allowed_updates' => array( 'message', 'callback_query' ),
                    'drop_pending_updates' => false,
                ) );
                if ( is_wp_error( $res ) ) {
                    add_settings_error( 'ahangban_connector', 'wh_err', 'خطای اتصال: '. $res->get_error_message(), 'error' );
                } elseif ( ! empty( $res['ok'] ) ) {
                    update_option( AhangBan_Settings::OPTION_WEBHOOK_URL, $url, false );
                    add_settings_error( 'ahangban_connector', 'wh_ok', '✅ وب‌هوک با موفقیت ست شد: '. esc_html( $url ), 'updated' );
                } else {
                    add_settings_error( 'ahangban_connector', 'wh_err', 'خطای تلگرام: '. esc_html( $res['description'] ?? wp_json_encode($res) ), 'error' );
                }
            }

        } elseif ( 'delete_webhook' === $action ) {
            $token = AhangBan_Settings::get_bot_token();
            $res = self::telegram_api( $token, 'deleteWebhook', array( 'drop_pending_updates' => false ) );
            if ( is_wp_error( $res ) ) {
                add_settings_error( 'ahangban_connector', 'wh_err', 'خطا: '. $res->get_error_message(), 'error' );
            } elseif ( ! empty( $res['ok'] ) ) {
                delete_option( AhangBan_Settings::OPTION_WEBHOOK_URL );
                add_settings_error( 'ahangban_connector', 'wh_del', '🗑 وب‌هوک حذف شد.', 'updated' );
            } else {
                add_settings_error( 'ahangban_connector', 'wh_err', 'خطا: '. esc_html($res['description'] ?? ''), 'error' );
            }

        } elseif ( 'test_message' === $action ) {
            $token = AhangBan_Settings::get_bot_token();
            $admins = AhangBan_Settings::get_admin_ids();
            $target = $admins ? $admins[0] : 0;
            if ( '' === $token || ! $target ) {
                add_settings_error( 'ahangban_connector', 'test_err', 'توکن یا شناسه ادمین تنظیم نیست.', 'error' );
            } else {
                $res = self::telegram_api( $token, 'sendMessage', array( 'chat_id' => $target, 'text' => '✅ تست از پیشخوان آهنگ‌بان — ربات متصل است! 🎶' ) );
                if ( is_wp_error( $res ) ) { add_settings_error( 'ahangban_connector','test_err','خطا: '.$res->get_error_message(),'error'); }
                elseif ( !empty($res['ok']) ) { add_settings_error( 'ahangban_connector','test_ok','✅ پیام تست ارسال شد به '. $target,'updated'); }
                else { add_settings_error( 'ahangban_connector','test_err','خطای تلگرام: '. esc_html($res['description']??''),'error'); }
            }
        }
    }

    public static function render() {
        if ( ! current_user_can( self::CAP ) ) { wp_die( 'دسترسی غیرمجاز' ); }
        settings_errors( 'ahangban_connector' );

        $token = AhangBan_Auth::get_token();
        $health_url = rest_url( 'ahangban/v1/health' );
        $base = rest_url( 'ahangban/v1/' );
        $masked = $token ? substr( $token, 0, 8 ) . str_repeat( '•', 48 ) . substr( $token, -8 ) : '— توکن موجود نیست —';

        // Bot settings
        $bot_token_raw = AhangBan_Settings::get_bot_token();
        $bot_token_masked = AhangBan_Settings::mask_token( $bot_token_raw );
        $bot_username = AhangBan_Settings::get_bot_username();
        $admin_ids_raw = implode( ', ', AhangBan_Settings::get_admin_ids() );
        $maybe_const_admin_ids = defined( 'AHANGBAN_ADMIN_IDS' ) && AHANGBAN_ADMIN_IDS;
        $maybe_const_username = defined( 'AHANGBAN_BOT_USERNAME' ) && AHANGBAN_BOT_USERNAME;
        $bot_secret = AhangBan_Settings::get_bot_secret();
        $bot_mode = AhangBan_Settings::get_mode();
        $webhook_url = AhangBan_Settings::get_webhook_url();
        $is_configured = AhangBan_Settings::is_bot_configured();
        $maybe_const_token = defined('AHANGBAN_BOT_TOKEN') && AHANGBAN_BOT_TOKEN ? true : false;
        ?>
        <div class="wrap" dir="rtl" style="text-align:right">
            <h1>🔌 اتصال آهنگ‌بان — API ربات تلگرام <small style="color:#646970">v<?php echo esc_html(AHANGBAN_CONNECTOR_VERSION); ?></small></h1>
            <p>این افزونه داده‌های منتشرشدهٔ سایت را از طریق REST امن در اختیار ربات تلگرام قرار می‌دهد. <strong>از v1.2.0 تنظیم توکن‌ها بدون SSH ممکن است؛ v1.3.0 ارسال فایل و پنل مدیریت ربات را هم اضافه می‌کند.</strong></p>

            <!-- 1. وضعیت سامانه -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:20px;margin:16px 0;max-width:920px">
                <h2>وضعیت سامانه</h2>
                <table class="widefat" style="max-width:840px">
                    <tr><th>نسخهٔ افزونه</th><td><?php echo esc_html( AHANGBAN_CONNECTOR_VERSION ); ?></td></tr>
                    <tr><th>وردپرس</th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?> — PHP <?php echo esc_html( PHP_VERSION ); ?></td></tr>
                    <?php $ab_tax = AhangBan_Search::get_taxonomy(); $ab_tax_exists = taxonomy_exists( $ab_tax ); $ab_legacy = taxonomy_exists( 'singer' ) && $ab_tax !== 'singer'; ?>
                    <tr><th>تاکسونومی فعال</th><td><?php echo $ab_tax_exists ? '✅ فعال — <code>'.esc_html($ab_tax).'</code>' : '❌ ثبت نشده'; ?><?php if ( $ab_legacy ) echo ' <span style="color:#d63638">(قدیمی singer هنوز موجود است — مهاجرت توصیه می‌شود)</span>'; ?></td></tr>
                    <tr><th>تعداد آثار منتشرشده</th><td><?php echo esc_html( number_format_i18n( (int) wp_count_posts( 'post' )->publish ) ); ?></td></tr>
                    <tr><th>تعداد خوانندگان</th><td><?php echo esc_html( $ab_tax_exists ? number_format_i18n( (int) wp_count_terms( array( 'taxonomy' => $ab_tax, 'hide_empty' => false ) ) ) : '—' ); ?><?php if ( $ab_legacy ) echo ' <small>— singer: '.esc_html(number_format_i18n((int)wp_count_terms(array('taxonomy'=>'singer','hide_empty'=>false)))).'</small>'; ?></td></tr>
                    <tr><th>حالت ربات</th><td><?php echo 'webhook'===$bot_mode ? '🔗 وب‌هوک (پیشنهادی برای هاست اشتراکی)' : '🔄 پولینگ (نیاز به سرور جدا)'; ?> <?php echo $is_configured ? '— ✅ توکن تنظیم شده' : '— ⚠️ توکن تنظیم نشده'; ?></td></tr>
                    <tr><th>آدرس پایه API</th><td><code dir="ltr"><?php echo esc_html( $base ); ?></code></td></tr>
                </table>
                <h3>بررسی سلامت (بدون احراز هویت)</h3>
                <p><code dir="ltr"><?php echo esc_url( $health_url ); ?></code> — باید JSON با <code>status: ok</code> برگرداند.</p>
                <p><a class="button" href="<?php echo esc_url( $health_url ); ?>" target="_blank">باز کردن Health</a> &nbsp; <a class="button" href="<?php echo esc_url( rest_url('ahangban/v1/telegram/webhook') ); ?>" target="_blank">تست وب‌هوک (GET)</a></p>
            </div>

            <!-- 2. تنظیم ربات تلگرام — بدون SSH -->
            <div style="background:#fff;border:2px solid #2271b1;border-radius:12px;padding:20px;margin:16px 0;max-width:920px">
                <h2>🤖 تنظیم ربات تلگرام — بدون نیاز به SSH</h2>
                <p style="background:#f0f6fc;border:1px solid #c3c4c7;padding:10px;border-radius:8px">همه فیلدها را از همین صفحه پر کنید. توکن‌ها با <code>autoload=no</code> در <code>wp_options</code> ذخیره می‌شوند و لاگ نمی‌شوند. اگر قبلاً با <code>.env</code> یا <code>wp-config.php</code> ثابت تعریف کرده‌اید، آن مقدار اولویت دارد.</p>

                <form method="post">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="save_bot">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="bot_token">توکن ربات (Bot Token)</label></th>
                            <td>
                                <?php if ( $maybe_const_token ): ?>
                                    <p style="color:#d63638">⚠️ توکن از <code>wp-config.php</code> (ثابت <code>AHANGBAN_BOT_TOKEN</code>) خوانده می‌شود — برای ویرایش از اینجا، ثابت را حذف کنید.</p>
                                    <code dir="ltr"><?php echo esc_html( AhangBan_Settings::mask_token( AHANGBAN_BOT_TOKEN ) ); ?></code>
                                <?php else: ?>
                                    <input type="password" id="bot_token" name="bot_token" value="" autocomplete="new-password" dir="ltr" style="width:100%;max-width:520px;font-family:monospace" placeholder="برای حفظ امنیت، توکن ذخیره‌شده دوباره نمایش داده نمی‌شود">
                                    <p class="description">از <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer">@BotFather</a> → /newbot بگیرید. وضعیت فعلی: <code dir="ltr"><?php echo esc_html($bot_token_masked); ?></code>. برای تغییر، توکن جدید را وارد کنید؛ خالی‌گذاشتن فیلد، توکن فعلی را حفظ می‌کند.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bot_username">یوزرنیم ربات (اختیاری)</label></th>
                            <td>
                                <input type="text" id="bot_username" name="bot_username" value="<?php echo esc_attr($bot_username); ?>" dir="ltr" style="width:320px" placeholder="ahangbanbot" <?php disabled( $maybe_const_username ); ?>> <span class="description">بدون @</span>
                                <?php if ( $maybe_const_username ): ?><p class="description">از ثابت <code>AHANGBAN_BOT_USERNAME</code> در wp-config.php خوانده می‌شود.</p><?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admin_ids">شناسه ادمین‌ها</label></th>
                            <td>
                                <input type="text" id="admin_ids" name="admin_ids" value="<?php echo esc_attr($admin_ids_raw); ?>" dir="ltr" style="width:100%;max-width:520px" placeholder="123456789, 987654321" <?php disabled( $maybe_const_admin_ids ); ?>>
                                <p class="description">با کاما جدا کنید. برای یافتن ID خود به <a href="https://t.me/userinfobot" target="_blank" rel="noopener noreferrer">@userinfobot</a> پیام دهید.</p>
                                <?php if ( $maybe_const_admin_ids ): ?><p class="description">از ثابت <code>AHANGBAN_ADMIN_IDS</code> در wp-config.php خوانده می‌شود.</p><?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">حالت اجرا</th>
                            <td>
                                <label><input type="radio" name="bot_mode" value="webhook" <?php checked($bot_mode,'webhook'); ?>> 🔗 وب‌هوک — <strong>پیشنهادی برای دایرکت‌ادمین</strong> (بدون سرور جدا، پایدار)</label><br>
                                <label><input type="radio" name="bot_mode" value="polling" <?php checked($bot_mode,'polling'); ?>> 🔄 پولینگ — نیاز به VPS/Docker جدا (برای مقیاس بالا)</label>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary">💾 ذخیره تنظیمات ربات</button> <span class="description">ذخیره بدون SSH — فقط همین صفحه.</span></p>
                </form>
            </div>

            <!-- 3. وب‌هوک -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:20px;margin:16px 0;max-width:920px">
                <h2>🔗 وب‌هوک تلگرام (برای هاست اشتراکی)</h2>
                <p>در حالت وب‌هوک، هاست شما خودش ربات را جواب می‌دهد — نیازی به <code>python -m bot.main</code> روی سرور جدا نیست.</p>
                <table class="form-table">
                    <tr><th>آدرس وب‌هوک (ثابت)</th><td><code dir="ltr" style="user-select:all;background:#f6f7f7;padding:6px 8px;border-radius:6px"><?php echo esc_html( set_url_scheme( $webhook_url, 'https' ) ); ?></code> <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( set_url_scheme($webhook_url,'https') ); ?>'); alert('کپی شد');">📋 کپی</button></td></tr>
                    <tr><th>Secret (امضا)</th><td><code dir="ltr" style="user-select:all"><?php echo esc_html( substr($bot_secret,0,8).str_repeat('•',8).substr($bot_secret,-4) ); ?></code> <small>(برای امنیت هدر <code>X-Telegram-Bot-Api-Secret-Token</code>)</small></td></tr>
                </table>
                <form method="post" style="display:inline-block;margin:6px 4px 0 0">
                    <?php wp_nonce_field('ahangban_connector_action'); ?>
                    <input type="hidden" name="ahangban_action" value="set_webhook">
                    <button type="submit" class="button button-primary" <?php disabled(!$is_configured); ?>>🚀 ست کردن وب‌هوک در تلگرام</button>
                </form>
                <form method="post" style="display:inline-block;margin:6px 4px 0 0">
                    <?php wp_nonce_field('ahangban_connector_action'); ?>
                    <input type="hidden" name="ahangban_action" value="delete_webhook">
                    <button type="submit" class="button" onclick="return confirm('وب‌هوک حذف شود؟')">🗑 حذف وب‌هوک</button>
                </form>
                <form method="post" style="display:inline-block;margin:6px 4px 0 0">
                    <?php wp_nonce_field('ahangban_connector_action'); ?>
                    <input type="hidden" name="ahangban_action" value="regenerate_secret">
                    <button type="submit" class="button">🔐 ساخت Secret جدید</button>
                </form>
                <form method="post" style="display:inline-block;margin:6px 4px 0 0">
                    <?php wp_nonce_field('ahangban_connector_action'); ?>
                    <input type="hidden" name="ahangban_action" value="test_message">
                    <button type="submit" class="button" <?php disabled(!$is_configured || empty($admin_ids_raw)); ?>>✉️ ارسال پیام تست به ادمین</button>
                </form>
                <p class="description">دکمه «ست کردن» به‌صورت خودکار <code>setWebhook</code> را به تلگرام می‌زند — نیازی به باز کردن لینک دستی نیست. اگر هاست شما <code>https</code> ندارد، ابتدا SSL (Let’s Encrypt) را در دایرکت‌ادمین فعال کنید.</p>
                <?php if(!$is_configured): ?><p style="color:#d63638">⚠️ اول توکن ربات را ذخیره کنید تا وب‌هوک فعال شود.</p><?php endif; ?>
            </div>

            <!-- 4. توکن API (سرویس بیرونی) -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:20px;margin:16px 0;max-width:920px">
                <h2>توکن API افزونه (برای ربات بیرونی — پولینگ)</h2>
                <p>اگر ربات Python جداگانه (VPS/Docker) دارید، از این توکن استفاده کنید. در حالت وب‌هوک نیازی نیست.</p>
                <p>
                    <strong>ماسک‌شده:</strong> <code dir="ltr" style="user-select:all"><?php echo esc_html( $masked ); ?></code><br>
                    <button type="button" class="button" onclick="document.getElementById('ahangban-token-raw').style.display='block'; this.style.display='none'">نمایش کامل توکن</button>
                </p>
                <div id="ahangban-token-raw" style="display:none;background:#f6f7f7;border:1px dashed #c3c4c7;padding:12px;border-radius:8px;margin:8px 0">
                    <code dir="ltr" style="word-break:break-all;user-select:all;font-size:13px"><?php echo esc_html( $token ); ?></code>
                    <p style="margin:8px 0 0;color:#d63638"><strong>هشدار:</strong> توکن را کپی کرده و این صفحه را ببندید.</p>
                </div>
                <form method="post" style="margin-top:12px">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="regenerate">
                    <button type="submit" class="button button-secondary" onclick="return confirm('توکن API فعلی باطل و جدید ساخته می‌شود. ادامه؟')">🔄 ساخت توکن API جدید</button>
                </form>
            </div>

            <!-- 5. مستندات API -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:20px;margin:16px 0;max-width:920px">
                <h2>مستندات سریع API</h2>
                <p>همهٔ درخواست‌ها به جز <code>/health</code> نیاز به هدر <code>Authorization: Bearer &lt;TOKEN&gt;</code> دارند.</p>
                <table class="widefat" style="max-width:900px">
                    <thead><tr><th>Endpoint</th><th>توضیح</th></tr></thead>
                    <tr><td><code>GET /search?q=محسن&amp;page=1&amp;per_page=5</code></td><td>جست‌وجوی آهنگ/خواننده</td></tr>
                    <tr><td><code>GET /song/{id}</code></td><td>کارت کامل اثر</td></tr>
                    <tr><td><code>GET /artists?q=&amp;letter=م&amp;page=1</code></td><td>فهرست خوانندگان</td></tr>
                    <tr><td><code>GET /artist/{slug}?page=1&amp;sort=new</code></td><td>پروفایل خواننده</td></tr>
                    <tr><td><code>GET /latest?per_page=5</code></td><td>جدیدترین‌ها</td></tr>
                    <tr><td><code>GET /popular?per_page=5</code></td><td>محبوب‌ترین</td></tr>
                    <tr><td><code>POST /cache/clear</code></td><td>پاک‌سازی کش</td></tr>
                    <tr><td><code>POST /telegram/webhook</code></td><td>وب‌هوک تلگرام (POST از تلگرام)</td></tr>
                    <tr><td><code>GET /telegram/webhook</code></td><td>تست سلامت وب‌هوک</td></tr>
                    <tr><td><code>GET /telegram/webhook-status</code></td><td>وضعیت وب‌هوک در تلگرام (ادمین)</td></tr>
                </table>
                <form method="post" style="margin-top:12px">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="clear_cache">
                    <button type="submit" class="button">🧹 پاک‌سازی کش</button>
                </form>
            </div>

            <div style="background:#fffbe5;border:1px solid #f0c040;border-radius:12px;padding:20px;margin:16px 0;max-width:920px">
                <h2>راهنمای سریع بدون SSH (دایرکت‌ادمین)</h2>
                <ol style="margin-right:18px;line-height:1.9">
                    <li>توکن ربات را از <a href="https://t.me/BotFather" target="_blank">@BotFather</a> بگیرید → در فیلد بالا Paste → <strong>ذخیره</strong>.</li>
                    <li>شناسه عددی خود را از <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> بگیرید → در فیلد ادمین Paste → ذخیره.</li>
                    <li>روی <strong>🚀 ست کردن وب‌هوک</strong> کلیک کنید — پیام سبز «ست شد» باید بیاید.</li>
                    <li>در تلگرام به ربات بروید → <code>/start</code> — باید جواب دهد!</li>
                    <li>اگر جواب نداد: <code>✉️ ارسال پیام تست</code> را بزنید یا <code>https://ahangban.ir/wp-json/ahangban/v1/telegram/webhook</code> را در مرورگر باز کنید — باید <code>{\"ok\":true}</code> ببینید.</li>
                </ol>
                <p>برای هاست اشتراکی، <strong>وب‌هوک</strong> را انتخاب کنید. پولینگ فقط اگر VPS جدا دارید.</p>
            </div>
        </div>
        <?php
    }
}
