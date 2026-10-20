<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_Admin {
    const CAP = 'manage_options';
    const SLUG = 'ahangban-connector';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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

    public static function enqueue_assets( $hook_suffix ) {
        if ( 'toplevel_page_' . self::SLUG !== $hook_suffix ) { return; }
        wp_enqueue_style(
            'ahangban-connector-admin',
            AHANGBAN_CONNECTOR_URL . 'assets/admin.css',
            array(),
            AHANGBAN_CONNECTOR_VERSION
        );
        wp_enqueue_script(
            'ahangban-connector-admin',
            AHANGBAN_CONNECTOR_URL . 'assets/admin.js',
            array(),
            AHANGBAN_CONNECTOR_VERSION,
            true
        );
    }

    private static function telegram_api( $token, $method, $params = array() ) {
        if ( '' === $token ) { return new WP_Error( 'no_token', 'متغیر محیطی AHANGBAN_BOT_TOKEN تنظیم نشده است.' ); }
        if ( ! in_array( $method, array( 'sendMessage', 'setWebhook', 'deleteWebhook' ), true ) ) {
            return new WP_Error( 'invalid_method', 'عملیات تلگرام نامعتبر است.' );
        }
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $response = wp_safe_remote_post( $url, array(
            'timeout' => 12,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
        ) );
        // Never return a transport message containing the token-bearing URL to the admin screen.
        if ( is_wp_error( $response ) ) { return new WP_Error( 'telegram_transport', 'ارتباط امن با تلگرام برقرار نشد.' ); }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'telegram_http', 'پاسخ HTTP تلگرام نامعتبر بود (' . $code . ').' ); }
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $json ) ? $json : new WP_Error( 'telegram_response', 'پاسخ تلگرام قابل‌خواندن نیست.' );
    }

    private static function flash_key() {
        return 'ahangban_admin_flash_' . (int) get_current_user_id();
    }

    private static function redirect_after_action() {
        $notices = get_settings_errors( 'ahangban_connector' );
        set_transient( self::flash_key(), is_array( $notices ) ? $notices : array(), 2 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
        exit;
    }

    public static function handle_actions() {
        if ( ! current_user_can( self::CAP ) || empty( $_POST['ahangban_action'] ) ) { return; }
        check_admin_referer( 'ahangban_connector_action' );
        $action = sanitize_key( wp_unslash( $_POST['ahangban_action'] ) );

        if ( 'clear_cache' === $action ) {
            AhangBan_Cache::clear_all();
            add_settings_error( 'ahangban_connector', 'cleared', 'کش پاک شد؛ داده‌های وردپرس تغییر نکردند.', 'updated' );
            self::redirect_after_action();
        }

        if ( 'save_bot' === $action ) {
            $errors = AhangBan_Settings::save( array(
                'bot_username' => isset( $_POST['bot_username'] ) ? wp_unslash( $_POST['bot_username'] ) : null,
                'admin_ids' => isset( $_POST['admin_ids'] ) ? wp_unslash( $_POST['admin_ids'] ) : null,
                'mode' => isset( $_POST['bot_mode'] ) ? wp_unslash( $_POST['bot_mode'] ) : null,
            ) );
            if ( empty( $errors ) ) {
                add_settings_error( 'ahangban_connector', 'bot_saved', '✅ تنظیمات غیرحساس ربات ذخیره شد.', 'updated' );
            } else {
                foreach ( $errors as $error ) { add_settings_error( 'ahangban_connector', 'bot_err', $error, 'error' ); }
            }
            self::redirect_after_action();
        }

        if ( 'save_channel' === $action ) {
            $errors = AhangBan_Settings::save( array(
                'channel_target' => isset( $_POST['channel_target'] ) ? wp_unslash( $_POST['channel_target'] ) : '',
                'channel_auto_post' => isset( $_POST['channel_auto_post'] ) ? wp_unslash( $_POST['channel_auto_post'] ) : '0',
            ) );
            if ( empty( $errors ) ) {
                add_settings_error( 'ahangban_connector', 'channel_saved', '✅ تنظیمات کانال ذخیره شد. برای انتشار واقعی، ربات باید مدیر کانال باشد.', 'updated' );
            } else {
                foreach ( $errors as $error ) { add_settings_error( 'ahangban_connector', 'channel_err', $error, 'error' ); }
            }
            self::redirect_after_action();
        }

        if ( 'test_channel' === $action ) {
            $token = AhangBan_Settings::get_bot_token();
            $target = AhangBan_Settings::get_channel_target();
            if ( '' === $token || '' === $target ) {
                add_settings_error( 'ahangban_connector', 'channel_test_missing', 'متغیر AHANGBAN_BOT_TOKEN یا شناسهٔ کانال تنظیم نشده است.', 'error' );
                self::redirect_after_action();
            }
            $response = self::telegram_api( $token, 'sendMessage', array(
                'chat_id' => $target,
                'text' => '✅ اتصال ربات آهنگ‌بان به این کانال برقرار است. پیام آزمایشی از پیشخوان وردپرس ارسال شد.',
                'disable_web_page_preview' => true,
            ) );
            if ( is_wp_error( $response ) ) {
                add_settings_error( 'ahangban_connector', 'channel_test_err', esc_html( $response->get_error_message() ), 'error' );
            } elseif ( ! empty( $response['ok'] ) ) {
                add_settings_error( 'ahangban_connector', 'channel_test_ok', '✅ پیام آزمایشی در کانال ارسال شد؛ دسترسی انتشار تأیید شد.', 'updated' );
            } else {
                add_settings_error( 'ahangban_connector', 'channel_test_err', 'تلگرام پیام را نپذیرفت: ' . esc_html( $response['description'] ?? 'پاسخ نامشخص' ), 'error' );
            }
            self::redirect_after_action();
        }

        if ( 'set_webhook' === $action ) {
            if ( 'webhook' !== AhangBan_Settings::get_mode() ) {
                add_settings_error( 'ahangban_connector', 'wh_mode', 'حالت اجرا روی Python polling است؛ برای جلوگیری از تداخل، ابتدا حالت وب‌هوک را انتخاب کنید یا وب‌هوک قبلی را حذف کنید.', 'error' );
                self::redirect_after_action();
            }
            $token = AhangBan_Settings::get_bot_token();
            $secret = AhangBan_Settings::get_bot_secret();
            if ( '' === $token || '' === $secret ) {
                add_settings_error( 'ahangban_connector', 'webhook_env_missing', 'متغیرهای AHANGBAN_BOT_TOKEN و AHANGBAN_BOT_SECRET را در محیط PHP تنظیم کنید.', 'error' );
                self::redirect_after_action();
            }
            $url = set_url_scheme( rest_url( 'ahangban/v1/telegram/webhook' ), 'https' );
            $response = self::telegram_api( $token, 'setWebhook', array(
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => array( 'message', 'callback_query' ),
                'drop_pending_updates' => false,
            ) );
            if ( is_wp_error( $response ) ) {
                add_settings_error( 'ahangban_connector', 'wh_err', esc_html( $response->get_error_message() ), 'error' );
            } elseif ( ! empty( $response['ok'] ) ) {
                update_option( AhangBan_Settings::OPTION_WEBHOOK_URL, $url, false );
                add_settings_error( 'ahangban_connector', 'wh_ok', '✅ وب‌هوک با موفقیت در تلگرام ثبت شد.', 'updated' );
            } else {
                add_settings_error( 'ahangban_connector', 'wh_err', 'تلگرام وب‌هوک را نپذیرفت: ' . esc_html( $response['description'] ?? 'پاسخ نامشخص' ), 'error' );
            }
            self::redirect_after_action();
        }

        if ( 'delete_webhook' === $action ) {
            $response = self::telegram_api( AhangBan_Settings::get_bot_token(), 'deleteWebhook', array( 'drop_pending_updates' => false ) );
            if ( is_wp_error( $response ) ) {
                add_settings_error( 'ahangban_connector', 'wh_err', esc_html( $response->get_error_message() ), 'error' );
            } elseif ( ! empty( $response['ok'] ) ) {
                delete_option( AhangBan_Settings::OPTION_WEBHOOK_URL );
                add_settings_error( 'ahangban_connector', 'wh_del', '🗑 وب‌هوک حذف شد.', 'updated' );
            } else {
                add_settings_error( 'ahangban_connector', 'wh_err', 'تلگرام درخواست حذف را نپذیرفت: ' . esc_html( $response['description'] ?? '' ), 'error' );
            }
            self::redirect_after_action();
        }

        if ( 'test_message' === $action ) {
            $admins = AhangBan_Settings::get_admin_ids();
            if ( ! AhangBan_Settings::is_bot_configured() || empty( $admins ) ) {
                add_settings_error( 'ahangban_connector', 'test_err', 'توکن محیطی ربات یا شناسهٔ ادمین تنظیم نشده است.', 'error' );
                self::redirect_after_action();
            }
            $response = self::telegram_api( AhangBan_Settings::get_bot_token(), 'sendMessage', array(
                'chat_id' => (int) $admins[0],
                'text' => '✅ تست از پیشخوان آهنگ‌بان — ربات متصل است! 🎶',
            ) );
            if ( is_wp_error( $response ) ) {
                add_settings_error( 'ahangban_connector', 'test_err', esc_html( $response->get_error_message() ), 'error' );
            } elseif ( ! empty( $response['ok'] ) ) {
                add_settings_error( 'ahangban_connector', 'test_ok', '✅ پیام تست به ادمین تلگرام ارسال شد.', 'updated' );
            } else {
                add_settings_error( 'ahangban_connector', 'test_err', 'تلگرام پیام تست را نپذیرفت: ' . esc_html( $response['description'] ?? '' ), 'error' );
            }
            self::redirect_after_action();
        }
    }

    public static function render() {
        if ( ! current_user_can( self::CAP ) ) { wp_die( 'دسترسی غیرمجاز' ); }
        $notices = get_transient( self::flash_key() );
        delete_transient( self::flash_key() );
        if ( is_array( $notices ) ) {
            foreach ( $notices as $notice ) {
                if ( ! is_array( $notice ) || empty( $notice['message'] ) ) { continue; }
                add_settings_error(
                    'ahangban_connector',
                    sanitize_key( $notice['code'] ?? 'action' ),
                    (string) $notice['message'],
                    sanitize_key( $notice['type'] ?? 'updated' )
                );
            }
        }
        settings_errors( 'ahangban_connector' );

        $api_ready = '' !== AhangBan_Auth::get_token();
        $bot_ready = AhangBan_Settings::is_bot_configured();
        $secret_ready = '' !== AhangBan_Settings::get_bot_secret();
        $health_url = rest_url( 'ahangban/v1/health' );
        $webhook_url = set_url_scheme( AhangBan_Settings::get_webhook_url(), 'https' );
        $base_url = rest_url( 'ahangban/v1/' );
        $bot_username = AhangBan_Settings::get_bot_username();
        $admin_ids = implode( ', ', AhangBan_Settings::get_admin_ids() );
        $bot_mode = AhangBan_Settings::get_mode();
        $channel_target = AhangBan_Settings::get_channel_target();
        $channel_auto_post = AhangBan_Settings::is_channel_auto_post_enabled();
        $taxonomy = AhangBan_Search::get_taxonomy();
        $taxonomy_exists = taxonomy_exists( $taxonomy );
        $legacy_singer = taxonomy_exists( 'singer' ) && 'singer' !== $taxonomy;
        $post_counts = wp_count_posts( 'post' );
        $published_posts = ( $post_counts && isset( $post_counts->publish ) ) ? (int) $post_counts->publish : 0;
        $artist_count = $taxonomy_exists ? wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) : 0;
        if ( is_wp_error( $artist_count ) ) { $artist_count = 0; }
        ?>
        <div class="wrap ab-admin" dir="rtl">
            <header class="ab-page-header">
                <div>
                    <p class="ab-eyebrow">AHANGBAN CONNECTOR · مدیریت اتصال</p>
                    <h1>اتصال آهنگ‌بان <span class="ab-version">v<?php echo esc_html( AHANGBAN_CONNECTOR_VERSION ); ?></span></h1>
                    <p class="ab-intro">تنظیمات API، وب‌هوک و انتشار کانال را مدیریت کنید. اعتبارنامه‌ها فقط از محیط PHP خوانده می‌شوند و در این صفحه یا پایگاه‌داده نمایش/ذخیره نمی‌شوند.</p>
                </div>
                <a class="button button-secondary" href="<?php echo esc_url( $health_url ); ?>" target="_blank" rel="noopener noreferrer">بررسی عمومی سلامت</a>
            </header>

            <section class="ab-card ab-status-card" aria-labelledby="ab-status-title">
                <div class="ab-card-heading">
                    <div><span class="ab-step">۰۱</span><h2 id="ab-status-title">وضعیت سامانه</h2></div>
                    <span class="ab-muted">آخرین وضعیت از همین نصب وردپرس</span>
                </div>
                <div class="ab-status-grid">
                    <div class="ab-status-item"><span>API token در PHP</span><strong class="<?php echo $api_ready ? 'ab-state-ok' : 'ab-state-warn'; ?>"><?php echo $api_ready ? 'پیکربندی شده' : 'نیازمند تنظیم'; ?></strong></div>
                    <div class="ab-status-item"><span>Bot token در PHP</span><strong class="<?php echo $bot_ready ? 'ab-state-ok' : 'ab-state-warn'; ?>"><?php echo $bot_ready ? 'پیکربندی شده' : 'نیازمند تنظیم'; ?></strong></div>
                    <div class="ab-status-item"><span>Secret وب‌هوک در PHP</span><strong class="<?php echo $secret_ready ? 'ab-state-ok' : 'ab-state-warn'; ?>"><?php echo $secret_ready ? 'پیکربندی شده' : 'نیازمند تنظیم'; ?></strong></div>
                    <div class="ab-status-item"><span>نسخهٔ PHP / وردپرس</span><strong><?php echo esc_html( PHP_VERSION . ' / ' . get_bloginfo( 'version' ) ); ?></strong></div>
                    <div class="ab-status-item"><span>تاکسونومی هنرمند</span><strong><?php echo $taxonomy_exists ? 'فعال · ' . esc_html( $taxonomy ) : 'ثبت نشده'; ?></strong></div>
                    <div class="ab-status-item"><span>هنرمندان / نوشته‌های منتشرشده</span><strong><?php echo esc_html( number_format_i18n( (int) $artist_count ) . ' / ' . number_format_i18n( $published_posts ) ); ?></strong></div>
                </div>
                <?php if ( $legacy_singer ) : ?><p class="ab-notice ab-notice--warning">تاکسونومی قدیمی <code>singer</code> نیز ثبت است؛ افزونه فقط برای سازگاری از آن استفاده می‌کند.</p><?php endif; ?>
                <div class="ab-endpoints">
                    <div><span>Health عمومی و حداقلی</span><code dir="ltr"><?php echo esc_html( $health_url ); ?></code></div>
                    <div><span>API پایه</span><code dir="ltr"><?php echo esc_html( $base_url ); ?></code></div>
                    <div><span>GET وب‌هوک</span><a href="<?php echo esc_url( $webhook_url ); ?>" target="_blank" rel="noopener noreferrer">آزمون مسیر دریافت</a></div>
                </div>
            </section>

            <section class="ab-card ab-card--primary" aria-labelledby="ab-env-title">
                <div class="ab-card-heading">
                    <div><span class="ab-step">۰۲</span><h2 id="ab-env-title">اعتبارنامه‌های محیط PHP</h2></div>
                    <span class="ab-badge ab-badge--secure">بدون ذخیره در دیتابیس</span>
                </div>
                <p>این نام‌ها باید در محیط پردازش PHP/‏PHP-FPM هاست تعریف شوند؛ فایل <code>.env</code> ربات Python به‌طور خودکار توسط PHP خوانده نمی‌شود. مقدارها را در بخش Environment Variables هاست تنظیم کنید یا از مدیر هاست بخواهید در محیط PHP-FPM تزریق کند.</p>
                <div class="ab-env-list" dir="ltr">
                    <code>AHANGBAN_API_TOKEN</code><span>توکن مشترک ۶۴رقمی hex؛ با <code>AHANGBAN_API_TOKEN</code> در `.env` ربات یکی باشد.</span>
                    <code>AHANGBAN_BOT_TOKEN</code><span>توکن BotFather برای وب‌هوک، کانال و ارسال فایل.</span>
                    <code>AHANGBAN_BOT_SECRET</code><span>Secret وب‌هوک، فقط حروف انگلیسی/عدد، خط تیره یا زیرخط؛ پیشنهاد: ۶۴رقمی hex.</span>
                    <code>AHANGBAN_TRUSTED_PROXY_IPS</code><span>اختیاری؛ IP/CIDR پراکسی‌های مورداعتماد، با کاما جدا شود. بدون این مقدار، هدرهای X-Forwarded نادیده گرفته می‌شوند.</span>
                </div>
                <p class="ab-notice ab-notice--warning"><strong>مهم برای ارتقا از v1.4.0:</strong> نسخهٔ 1.5.0 هنگام نخستین اجرا، توکن‌های قدیمی API، ربات و وب‌هوک را از <code>wp_options</code> حذف می‌کند. ابتدا مقادیر را در محیط PHP و `.env` خصوصی ربات تنظیم کنید؛ بعد افزونه را ارتقا دهید. این صفحه هیچ مقداری از Secretها را چاپ نمی‌کند.</p>
                <p class="ab-muted">برای ساخت توکن قوی، روی دستگاه امن خود از <code dir="ltr">openssl rand -hex 32</code> استفاده کنید. همان مقدار API را در محیط PHP و <code dir="ltr">.env</code> سرویس Python بگذارید؛ Secretها را در Git، گفتگو یا تنظیمات معمول وردپرس قرار ندهید.</p>
            </section>

            <section class="ab-card" aria-labelledby="ab-bot-title">
                <div class="ab-card-heading"><div><span class="ab-step">۰۳</span><h2 id="ab-bot-title">تنظیمات غیرحساس ربات</h2></div></div>
                <form method="post">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="save_bot">
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="admin_ids">شناسهٔ ادمین‌های تلگرام</label></th><td><input type="text" id="admin_ids" name="admin_ids" value="<?php echo esc_attr( $admin_ids ); ?>" dir="ltr" class="regular-text" placeholder="123456789, 987654321"><p class="description">با کاما جدا کنید. برای یافتن شناسه می‌توانید از <a href="https://t.me/userinfobot" target="_blank" rel="noopener noreferrer">@userinfobot</a> کمک بگیرید.</p></td></tr>
                        <tr><th scope="row"><label for="bot_username">یوزرنیم ربات</label></th><td><input type="text" id="bot_username" name="bot_username" value="<?php echo esc_attr( $bot_username ); ?>" dir="ltr" class="regular-text" placeholder="ahangbanbot"><p class="description">اختیاری؛ برای ساخت لینک deep-link از کانال.</p></td></tr>
                        <tr><th scope="row">حالت اجرا</th><td><label><input type="radio" name="bot_mode" value="webhook" <?php checked( $bot_mode, 'webhook' ); ?>> وب‌هوک WordPress</label><br><label><input type="radio" name="bot_mode" value="polling" <?php checked( $bot_mode, 'polling' ); ?>> Python polling روی VPS جدا</label><p class="description">در حالت polling، ثبت وب‌هوک از همین صفحه غیرفعال می‌شود. سرویس Python را جداگانه اجرا کنید و اگر وب‌هوک قبلی ثبت است، پیش از polling آن را حذف کنید.</p></td></tr>
                    </table>
                    <button type="submit" class="button button-primary">ذخیرهٔ تنظیمات غیرحساس</button>
                </form>
            </section>

            <section class="ab-card" aria-labelledby="ab-channel-title">
                <div class="ab-card-heading"><div><span class="ab-step">۰۴</span><h2 id="ab-channel-title">اتصال کانال تلگرام</h2></div><span class="ab-badge <?php echo $channel_auto_post ? 'ab-badge--on' : 'ab-badge--off'; ?>">انتشار خودکار <?php echo $channel_auto_post ? 'روشن' : 'خاموش'; ?></span></div>
                <p>فقط آهنگ/آلبوم/ویدیوی موسیقیِ تازه و قابل‌تشخیص از داده‌های واقعی وردپرس در صف WP-Cron قرار می‌گیرد. نوشته‌های معمولی و محتوای قدیمی بازنشر نمی‌شوند.</p>
                <form method="post" class="ab-form-block">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="save_channel">
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="channel_target">شناسه یا یوزرنیم کانال</label></th><td><input type="text" id="channel_target" name="channel_target" value="<?php echo esc_attr( $channel_target ); ?>" dir="ltr" class="regular-text" placeholder="@ahangban یا -1001234567890"><p class="description">ربات باید مدیر کانال و مجاز به ارسال پیام باشد.</p></td></tr>
                        <tr><th scope="row">انتشار خودکار</th><td><input type="hidden" name="channel_auto_post" value="0"><label><input type="checkbox" name="channel_auto_post" value="1" <?php checked( $channel_auto_post ); ?>> پس از انتشار اثر موسیقی جدید، در کانال اطلاع‌رسانی شود</label></td></tr>
                    </table>
                    <button type="submit" class="button button-primary">ذخیرهٔ تنظیمات کانال</button>
                </form>
                <form method="post" class="ab-inline-form">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="test_channel">
                    <button type="submit" class="button" <?php disabled( ! $bot_ready || ! $channel_target ); ?>>ارسال پیام آزمایشی به کانال</button>
                    <span class="ab-muted">این آزمون واقعاً یک پیام در کانال منتشر می‌کند.</span>
                </form>
            </section>

            <section class="ab-card" aria-labelledby="ab-webhook-title">
                <div class="ab-card-heading"><div><span class="ab-step">۰۵</span><h2 id="ab-webhook-title">وب‌هوک تلگرام</h2></div><span class="ab-muted">بدون نیاز به اجرای دائمی Python</span></div>
                <p>وب‌هوک پیام‌ها را روی همین WordPress دریافت می‌کند. برای تنظیم، هر سه متغیر <code>AHANGBAN_API_TOKEN</code>، <code>AHANGBAN_BOT_TOKEN</code> و <code>AHANGBAN_BOT_SECRET</code> باید در محیط PHP فعال باشند.</p>
                <div class="ab-webhook-url"><code id="ab-webhook-url" dir="ltr"><?php echo esc_html( $webhook_url ); ?></code><button type="button" class="button button-small" data-ab-copy="ab-webhook-url">کپی نشانی</button><span class="screen-reader-text" aria-live="polite" data-ab-copy-status></span></div>
                <div class="ab-actions">
                    <form method="post">
                        <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                        <input type="hidden" name="ahangban_action" value="set_webhook">
                        <button type="submit" class="button button-primary" <?php disabled( ! $bot_ready || ! $secret_ready || 'webhook' !== $bot_mode ); ?>>ثبت وب‌هوک در تلگرام</button>
                    </form>
                    <form method="post">
                        <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                        <input type="hidden" name="ahangban_action" value="delete_webhook">
                        <button type="submit" class="button" <?php disabled( ! $bot_ready ); ?> data-ab-confirm="وب‌هوک تلگرام حذف شود؟">حذف وب‌هوک</button>
                    </form>
                    <form method="post">
                        <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                        <input type="hidden" name="ahangban_action" value="test_message">
                        <button type="submit" class="button" <?php disabled( ! $bot_ready || empty( AhangBan_Settings::get_admin_ids() ) ); ?>>ارسال پیام تست به ادمین</button>
                    </form>
                </div>
                <p class="description">برای اجرای صف انتشار کانال و پیام همگانی، WP-Cron یا Cron Job هاست باید فعال باشد.</p>
            </section>

            <section class="ab-card" aria-labelledby="ab-api-title">
                <div class="ab-card-heading"><div><span class="ab-step">۰۶</span><h2 id="ab-api-title">REST API و کش</h2></div><span class="ab-badge ab-badge--secure">احراز هویت Bearer</span></div>
                <p>مسیرهای داده و کش به <code>Authorization: Bearer &lt;TOKEN&gt;</code> نیاز دارند. <code>/health</code> عمومی و حداقلی است؛ <code>/telegram/webhook</code> هدر Secret تلگرام را می‌سنجد و وضعیت وب‌هوک فقط برای مدیر WordPress مجاز است.</p>
                <table class="widefat striped ab-endpoint-table"><thead><tr><th>مسیر</th><th>کاربرد</th></tr></thead><tbody>
                    <tr><td><code>GET /search?q=…&amp;page=1&amp;per_page=5</code></td><td>جست‌وجوی موسیقی</td></tr>
                    <tr><td><code>GET /song/{id}</code></td><td>کارت اثر منتشرشده</td></tr>
                    <tr><td><code>GET /artists?q=…&amp;letter=…</code></td><td>فهرست هنرمندان</td></tr>
                    <tr><td><code>GET /artist/{slug_or_id}</code></td><td>پروفایل و آثار صفحه‌بندی‌شده</td></tr>
                    <tr><td><code>GET /latest</code> · <code>GET /popular</code></td><td>جدیدترین و محبوب‌ترین آثار</td></tr>
                    <tr><td><code>POST /cache/clear</code></td><td>پاک‌سازی کش با توکن API</td></tr>
                </tbody></table>
                <form method="post" class="ab-inline-form">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="clear_cache">
                    <button type="submit" class="button">پاک‌سازی کش افزونه</button>
                    <span class="ab-muted">هیچ محتوای وردپرس حذف یا تغییر نمی‌کند.</span>
                </form>
            </section>
            <p class="ab-footer-note">منبع داده: همین پایگاه‌دادهٔ وردپرس. افزونه دیتابیس موازی برای نوشته‌ها یا هنرمندان ایجاد نمی‌کند.</p>
        </div>
        <?php
    }
}
