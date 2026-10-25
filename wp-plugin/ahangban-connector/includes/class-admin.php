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

    /** Return a safe technical detail for an administrator without exposing the token-bearing API URL. */
    private static function safe_telegram_error_detail( $message, $token = '', $params = array() ) {
        $message = is_scalar( $message ) ? (string) $message : '';
        if ( '' !== (string) $token ) { $message = str_replace( (string) $token, '[توکن حذف شد]', $message ); }
        if ( is_array( $params ) && ! empty( $params['secret_token'] ) ) { $message = str_replace( (string) $params['secret_token'], '[Secret حذف شد]', $message ); }
        if ( function_exists( 'wp_check_invalid_utf8' ) ) { $message = wp_check_invalid_utf8( $message, true ); }
        $message = preg_replace( '~https?://api\.telegram\.org/bot[^/\s]+~i', 'https://api.telegram.org/bot[REDACTED]', $message );
        $message = preg_replace( '~(https?://[^?\\s]+)\\?[^\\s]+~i', '$1?[پارامترها حذف شد]', $message );
        $message = wp_strip_all_tags( (string) $message );
        $message = preg_replace( '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]+/', ' ', $message );
        $message = trim( preg_replace( '/\\s+/u', ' ', $message ) );
        if ( function_exists( 'wp_html_excerpt' ) ) { $message = wp_html_excerpt( $message, 600, '…' ); }
        elseif ( strlen( $message ) > 600 ) { $message = substr( $message, 0, 600 ) . '…'; }
        return '' !== $message ? $message : 'جزئیات بیشتری از کتابخانهٔ HTTP وردپرس دریافت نشد.';
    }

    private static function telegram_api( $token, $method, $params = array() ) {
        $token = trim( (string) $token );
        if ( '' === $token ) { return new WP_Error( 'no_token', 'توکن ربات در محیط PHP یا تنظیمات افزونه پیکربندی نشده است.' ); }
        if ( ! preg_match( '/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token ) ) {
            return new WP_Error( 'invalid_token', 'فرمت توکن ربات نامعتبر است؛ توکن BotFather را بدون فاصله وارد کنید.' );
        }
        if ( ! in_array( $method, array( 'sendMessage', 'setWebhook', 'deleteWebhook', 'getMe', 'getWebhookInfo' ), true ) ) {
            return new WP_Error( 'invalid_method', 'عملیات تلگرام نامعتبر است.' );
        }
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        // The HTTPS host is fixed, the token and method are allowlisted, and redirects are disabled.
        // wp_safe_remote_post() can reject this trusted URL during host-side DNS validation before
        // any request is made; wp_remote_post() preserves TLS verification without that false negative.
        $response = wp_remote_post( $url, array(
            'timeout' => 12,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
        ) );
        if ( is_wp_error( $response ) ) {
            $detail = self::safe_telegram_error_detail( implode( ' | ', $response->get_error_messages() ), $token, $params );
            $error_code = sanitize_key( $response->get_error_code() );
            return new WP_Error( 'telegram_transport', 'ارتباط امن با تلگرام برقرار نشد. جزئیات فنی' . ( $error_code ? ' (' . $error_code . ')' : '' ) . ': ' . $detail );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );
        if ( $code < 200 || $code >= 300 ) {
            $message = 'پاسخ HTTP تلگرام نامعتبر بود (HTTP ' . $code . ').';
            if ( is_array( $json ) && ! empty( $json['description'] ) ) {
                $description = self::safe_telegram_error_detail( $json['description'], $token, $params );
                $message .= ' توضیح تلگرام: ' . $description;
            }
            if ( is_array( $json ) && isset( $json['error_code'] ) ) { $message .= ' کد تلگرام: ' . (int) $json['error_code'] . '.'; }
            return new WP_Error( 'telegram_http', $message );
        }
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

        if ( 'save_credentials' === $action ) {
            $errors = AhangBan_Settings::save( array(
                'api_token' => isset( $_POST['api_token'] ) ? wp_unslash( $_POST['api_token'] ) : '',
                'bot_token' => isset( $_POST['bot_token'] ) ? wp_unslash( $_POST['bot_token'] ) : '',
                'bot_secret' => isset( $_POST['bot_secret'] ) ? wp_unslash( $_POST['bot_secret'] ) : '',
                'clear_api_token' => isset( $_POST['clear_api_token'] ) ? '1' : '',
                'clear_bot_token' => isset( $_POST['clear_bot_token'] ) ? '1' : '',
                'clear_bot_secret' => isset( $_POST['clear_bot_secret'] ) ? '1' : '',
            ) );
            if ( empty( $errors ) ) {
                add_settings_error( 'ahangban_connector', 'credentials_saved', '✅ تنظیمات اعتبارنامه انجام شد؛ مقدارها در این صفحه نمایش داده نمی‌شوند و محیط PHP در صورت وجود اولویت دارد.', 'updated' );
            } else {
                foreach ( $errors as $error ) { add_settings_error( 'ahangban_connector', 'credentials_err', $error, 'error' ); }
            }
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

        if ( 'save_behavior' === $action ) {
            $errors = AhangBan_Settings::save( array(
                'welcome_message' => isset( $_POST['welcome_message'] ) ? wp_unslash( $_POST['welcome_message'] ) : '',
                'help_message' => isset( $_POST['help_message'] ) ? wp_unslash( $_POST['help_message'] ) : '',
                'results_per_page' => isset( $_POST['results_per_page'] ) ? wp_unslash( $_POST['results_per_page'] ) : '',
                'artists_per_page' => isset( $_POST['artists_per_page'] ) ? wp_unslash( $_POST['artists_per_page'] ) : '',
                'menu_latest' => isset( $_POST['menu_latest'] ) ? wp_unslash( $_POST['menu_latest'] ) : '0',
                'menu_popular' => isset( $_POST['menu_popular'] ) ? wp_unslash( $_POST['menu_popular'] ) : '0',
                'menu_artist_search' => isset( $_POST['menu_artist_search'] ) ? wp_unslash( $_POST['menu_artist_search'] ) : '0',
                'menu_artist_list' => isset( $_POST['menu_artist_list'] ) ? wp_unslash( $_POST['menu_artist_list'] ) : '0',
                'menu_help' => isset( $_POST['menu_help'] ) ? wp_unslash( $_POST['menu_help'] ) : '0',
            ) );
            if ( empty( $errors ) ) {
                add_settings_error( 'ahangban_connector', 'behavior_saved', '✅ رفتار ربات و منوی اصلی ذخیره شد. تغییر متن و منو از پیام بعدی کاربران اعمال می‌شود.', 'updated' );
            } else {
                foreach ( $errors as $error ) { add_settings_error( 'ahangban_connector', 'behavior_err', $error, 'error' ); }
            }
            self::redirect_after_action();
        }

        if ( 'save_channel' === $action ) {
            $errors = AhangBan_Settings::save( array(
                'channel_target' => isset( $_POST['channel_target'] ) ? wp_unslash( $_POST['channel_target'] ) : '',
                'channel_auto_post' => isset( $_POST['channel_auto_post'] ) ? wp_unslash( $_POST['channel_auto_post'] ) : '0',
                'channel_preview_enabled' => isset( $_POST['channel_preview_enabled'] ) ? wp_unslash( $_POST['channel_preview_enabled'] ) : '0',
                'channel_preview_seconds' => isset( $_POST['channel_preview_seconds'] ) ? wp_unslash( $_POST['channel_preview_seconds'] ) : '60',
                'channel_caption_footer' => isset( $_POST['channel_caption_footer'] ) ? wp_unslash( $_POST['channel_caption_footer'] ) : '',
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
                add_settings_error( 'ahangban_connector', 'channel_test_missing', 'توکن ربات یا شناسهٔ کانال در تنظیمات افزونه موجود نیست.', 'error' );
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

        if ( 'check_connection' === $action ) {
            $token = AhangBan_Settings::get_bot_token();
            $me = self::telegram_api( $token, 'getMe' );
            if ( is_wp_error( $me ) ) {
                add_settings_error( 'ahangban_connector', 'telegram_connection_err', esc_html( $me->get_error_message() ), 'error' );
                self::redirect_after_action();
            }
            if ( empty( $me['ok'] ) || empty( $me['result'] ) ) {
                $description = self::safe_telegram_error_detail( $me['description'] ?? 'پاسخ تلگرام نامشخص است.', $token );
                add_settings_error( 'ahangban_connector', 'telegram_connection_err', 'تلگرام درخواست getMe را نپذیرفت: ' . esc_html( $description ), 'error' );
                self::redirect_after_action();
            }

            $username = sanitize_text_field( (string) ( $me['result']['username'] ?? '' ) );
            $bot_label = '' !== $username ? '@' . $username : 'ربات';
            $info = self::telegram_api( $token, 'getWebhookInfo' );
            if ( is_wp_error( $info ) ) {
                add_settings_error( 'ahangban_connector', 'telegram_connection_err', '✅ اتصال API برای ' . esc_html( $bot_label ) . ' برقرار است؛ اما وضعیت وب‌هوک دریافت نشد: ' . esc_html( $info->get_error_message() ), 'error' );
                self::redirect_after_action();
            }
            if ( empty( $info['ok'] ) || ! is_array( $info['result'] ?? null ) ) {
                $description = self::safe_telegram_error_detail( $info['description'] ?? 'پاسخ تلگرام نامشخص است.', $token );
                add_settings_error( 'ahangban_connector', 'telegram_connection_err', '✅ اتصال API برقرار است؛ اما Telegram وضعیت وب‌هوک را نپذیرفت: ' . esc_html( $description ), 'error' );
                self::redirect_after_action();
            }

            $hook = $info['result'];
            $hook_url = trim( (string) ( $hook['url'] ?? '' ) );
            $safe_hook_url = self::safe_telegram_error_detail( $hook_url, $token );
            $pending = max( 0, (int) ( $hook['pending_update_count'] ?? 0 ) );
            $last_error = self::safe_telegram_error_detail( $hook['last_error_message'] ?? '', $token );
            $message = '✅ اتصال API برقرار است — ' . esc_html( $bot_label ) . '. ';
            $message .= '' !== $hook_url ? 'وب‌هوک در تلگرام ثبت شده است: <code>' . esc_html( $safe_hook_url ) . '</code>.' : 'در حال حاضر وب‌هوکی در تلگرام ثبت نشده است.';
            $message .= ' درخواست‌های در صف Telegram: ' . $pending . '.';
            $queue_problem = empty( $hook_url ) || $pending > 0;
            $local_queue = AhangBan_Telegram::get_local_queue_status();
            if ( ! empty( $local_queue['available'] ) ) {
                $message .= ' صف داخلی WordPress: ' . (int) $local_queue['queued'] . ' منتظر، ' . (int) $local_queue['processing'] . ' در حال اجرا، ' . (int) $local_queue['failed'] . ' ناموفق.';
                if ( (int) $local_queue['failed'] > 0 ) { $queue_problem = true; }
                if ( ! empty( $local_queue['oldest_active_at'] ) ) {
                    $queue_age = max( 0, time() - (int) $local_queue['oldest_active_at'] );
                    $message .= ' سن قدیمی‌ترین درخواست در صف/در حال اجرا: ' . $queue_age . ' ثانیه.';
                    if ( $queue_age > 180 ) { $queue_problem = true; }
                }
                if ( ! empty( $local_queue['last_error'] ) ) {
                    $message .= ' آخرین خطای صف داخلی: ' . esc_html( (string) $local_queue['last_error'] );
                }
                $send_error = is_array( $local_queue['last_send_error'] ?? null ) ? $local_queue['last_send_error'] : array();
                if ( ! empty( $send_error['message'] ) ) {
                    $queue_problem = true;
                    $message .= ' آخرین خطای sendMessage از WordPress: ' . esc_html( (string) $send_error['message'] );
                }
            } else {
                $queue_problem = true;
                $message .= ' وضعیت صف داخلی WordPress در دسترس نیست؛ پایگاه دادهٔ افزونه را بررسی کنید.';
            }
            if ( ! empty( $hook['last_error_message'] ) ) {
                $message .= ' آخرین خطای دریافت Telegram: ' . esc_html( $last_error );
            }
            if ( ! empty( $hook['last_error_message'] ) || $queue_problem ) {
                add_settings_error( 'ahangban_connector', 'telegram_connection_status', $message, 'error' );
            } else {
                add_settings_error( 'ahangban_connector', 'telegram_connection_status', $message, 'updated' );
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
                add_settings_error( 'ahangban_connector', 'webhook_env_missing', 'توکن ربات یا Secret وب‌هوک در محیط PHP یا تنظیمات افزونه موجود نیست.', 'error' );
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
                add_settings_error( 'ahangban_connector', 'test_err', 'توکن ربات یا شناسهٔ ادمین در تنظیمات موجود نیست.', 'error' );
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
        $welcome_message = AhangBan_Settings::get_bot_welcome_message();
        $help_message = AhangBan_Settings::get_bot_help_message();
        $results_per_page = AhangBan_Settings::get_results_per_page();
        $artists_per_page = AhangBan_Settings::get_artists_per_page();
        $menu_latest = AhangBan_Settings::is_menu_item_enabled( 'latest' );
        $menu_popular = AhangBan_Settings::is_menu_item_enabled( 'popular' );
        $menu_artist_search = AhangBan_Settings::is_menu_item_enabled( 'artist_search' );
        $menu_artist_list = AhangBan_Settings::is_menu_item_enabled( 'artist_list' );
        $menu_help = AhangBan_Settings::is_menu_item_enabled( 'help' );
        $channel_target = AhangBan_Settings::get_channel_target();
        $channel_auto_post = AhangBan_Settings::is_channel_auto_post_enabled();
        $channel_preview_enabled = AhangBan_Settings::is_channel_preview_enabled();
        $channel_preview_seconds = AhangBan_Settings::get_channel_preview_seconds();
        $channel_caption_footer = AhangBan_Settings::get_channel_caption_footer();
        $channel_preview_ready = $channel_preview_enabled && AhangBan_Telegram::is_channel_preview_renderer_available();
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
                    <p class="ab-intro">تنظیمات API، وب‌هوک و انتشار کانال را مدیریت کنید. متغیرهای محیط PHP اولویت دارند؛ در صورت نبودشان می‌توانید اعتبارنامه‌ها را از این پیشخوان تنظیم کنید.</p>
                </div>
                <a class="button button-secondary" href="<?php echo esc_url( $health_url ); ?>" target="_blank" rel="noopener noreferrer">بررسی عمومی سلامت</a>
            </header>

            <section class="ab-card ab-status-card" aria-labelledby="ab-status-title">
                <div class="ab-card-heading">
                    <div><span class="ab-step">۰۱</span><h2 id="ab-status-title">وضعیت سامانه</h2></div>
                    <span class="ab-muted">آخرین وضعیت از همین نصب وردپرس</span>
                </div>
                <div class="ab-status-grid">
                    <div class="ab-status-item"><span>توکن API</span><strong class="<?php echo $api_ready ? 'ab-state-ok' : 'ab-state-warn'; ?>"><?php echo $api_ready ? 'پیکربندی شده' : 'نیازمند تنظیم'; ?></strong></div>
                    <div class="ab-status-item"><span>توکن ربات تلگرام</span><strong class="<?php echo $bot_ready ? 'ab-state-ok' : 'ab-state-warn'; ?>"><?php echo $bot_ready ? 'پیکربندی شده' : 'نیازمند تنظیم'; ?></strong></div>
                    <div class="ab-status-item"><span>Secret وب‌هوک</span><strong class="<?php echo $secret_ready ? 'ab-state-ok' : 'ab-state-warn'; ?>"><?php echo $secret_ready ? 'پیکربندی شده' : 'نیازمند تنظیم'; ?></strong></div>
                    <div class="ab-status-item"><span>نسخهٔ PHP / وردپرس</span><strong><?php echo esc_html( PHP_VERSION . ' / ' . get_bloginfo( 'version' ) ); ?></strong></div>
                    <div class="ab-status-item"><span>پیش‌نمایش ویس کانال</span><strong class="<?php echo $channel_preview_enabled && $channel_preview_ready ? 'ab-state-ok' : 'ab-state-warn'; ?>"><?php echo ! $channel_preview_enabled ? 'با تنظیم مدیر خاموش است' : ( $channel_preview_ready ? 'FFmpeg + Opus آماده' : 'نیازمند بررسی هاست' ); ?></strong></div>
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

            <section class="ab-card ab-card--primary" aria-labelledby="ab-credentials-title">
                <div class="ab-card-heading">
                    <div><span class="ab-step">۰۲</span><h2 id="ab-credentials-title">اعتبارنامه‌های API و تلگرام</h2></div>
                    <span class="ab-badge ab-badge--secure">متغیر محیطی اولویت دارد</span>
                </div>
                <p>اگر هاست امکان تعریف متغیر محیطی برای PHP را ندارد، مقادیر را اینجا وارد کنید. فیلدهای رمزواژه پس از ذخیره خالی می‌مانند؛ خالی‌گذاشتن فیلد مقدار قبلی را حفظ می‌کند. اگر متغیر محیط PHP تنظیم شده باشد، همان مقدار بر مقدار ذخیره‌شده در WordPress اولویت دارد.</p>
                <form method="post" class="ab-form-block">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="save_credentials">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="api_token">توکن API</label></th>
                            <td>
                                <input type="password" id="api_token" name="api_token" value="" dir="ltr" class="regular-text" maxlength="64" autocomplete="new-password" placeholder="برای حفظ مقدار قبلی، خالی بگذارید">
                                <label><input type="checkbox" name="clear_api_token" value="1"> حذف مقدار ذخیره‌شده</label>
                                <p class="description">دقیقاً ۶۴ نویسهٔ hex. اگر Python polling دارید، باید با <code>AHANGBAN_API_TOKEN</code> در فایل `.env` ربات یکسان باشد.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bot_token">توکن BotFather</label></th>
                            <td>
                                <input type="password" id="bot_token" name="bot_token" value="" dir="ltr" class="regular-text" maxlength="512" autocomplete="new-password" placeholder="توکن ربات؛ برای حفظ مقدار قبلی، خالی بگذارید">
                                <label><input type="checkbox" name="clear_bot_token" value="1"> حذف مقدار ذخیره‌شده</label>
                                <p class="description">توکن همین ربات را بدون فاصله وارد کنید؛ این مقدار برای ثبت وب‌هوک و ارسال پیام استفاده می‌شود.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bot_secret">Secret وب‌هوک</label></th>
                            <td>
                                <input type="password" id="bot_secret" name="bot_secret" value="" dir="ltr" class="regular-text" maxlength="256" autocomplete="new-password" placeholder="Secret؛ برای حفظ مقدار قبلی، خالی بگذارید">
                                <label><input type="checkbox" name="clear_bot_secret" value="1"> حذف مقدار ذخیره‌شده</label>
                                <p class="description">یک مقدار تصادفی مستقل؛ ۶۴ نویسهٔ hex پیشنهاد می‌شود. پس از تغییر آن، وب‌هوک را دوباره ثبت کنید.</p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" class="button button-primary">ذخیرهٔ اعتبارنامه‌ها</button>
                </form>
                <p class="ab-notice ab-notice--warning"><strong>نکتهٔ امنیتی:</strong> مقدارهایی که در این فرم ذخیره می‌شوند در پایگاه‌دادهٔ WordPress نگه‌داری می‌شوند و رمزنگاری‌شده نیستند؛ نسخه‌های پشتیبان دیتابیس نیز ممکن است آن‌ها را داشته باشند. فقط مدیران سایت می‌توانند این فرم را تغییر دهند. Secretها را در گفتگو یا Git منتشر نکنید.</p>
                <p class="ab-muted">برای تولید امن توکن API و Secret از دستگاه خود <code dir="ltr">openssl rand -hex 32</code> را جداگانه برای هر مقدار اجرا کنید.</p>
            </section>

            <section class="ab-card" aria-labelledby="ab-bot-title">
                <div class="ab-card-heading"><div><span class="ab-step">۰۳</span><h2 id="ab-bot-title">تنظیمات غیرحساس ربات</h2></div></div>
                <form method="post">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="save_bot">
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="admin_ids">شناسهٔ ادمین‌های تلگرام</label></th><td><input type="text" id="admin_ids" name="admin_ids" value="<?php echo esc_attr( $admin_ids ); ?>" dir="ltr" class="regular-text" placeholder="123456789, 987654321"><p class="description">با کاما جدا کنید. برای یافتن شناسه می‌توانید از <a href="https://t.me/userinfobot" target="_blank" rel="noopener noreferrer">@userinfobot</a> کمک بگیرید.</p></td></tr>
                        <tr><th scope="row"><label for="bot_username">یوزرنیم ربات</label></th><td><input type="text" id="bot_username" name="bot_username" value="<?php echo esc_attr( $bot_username ); ?>" dir="ltr" class="regular-text" placeholder="ahangbanbot"><p class="description">برای نمایش دکمهٔ «مشاهده و دریافت در ربات» روی پست کانال و ویس، یوزرنیم عمومی ربات را ثبت کنید.</p></td></tr>
                        <tr><th scope="row">حالت اجرا</th><td><label><input type="radio" name="bot_mode" value="webhook" <?php checked( $bot_mode, 'webhook' ); ?>> وب‌هوک WordPress</label><br><label><input type="radio" name="bot_mode" value="polling" <?php checked( $bot_mode, 'polling' ); ?>> Python polling روی VPS جدا</label><p class="description">در حالت polling، ثبت وب‌هوک از همین صفحه غیرفعال می‌شود. سرویس Python را جداگانه اجرا کنید و اگر وب‌هوک قبلی ثبت است، پیش از polling آن را حذف کنید.</p></td></tr>
                    </table>
                    <button type="submit" class="button button-primary">ذخیرهٔ تنظیمات غیرحساس</button>
                </form>
            </section>

            <section class="ab-card ab-card--settings" aria-labelledby="ab-behavior-title">
                <div class="ab-card-heading"><div><span class="ab-step">۰۴</span><h2 id="ab-behavior-title">رفتار و تجربهٔ کاربری ربات</h2></div><span class="ab-muted">متن، صفحه‌بندی و منوی شروع</span></div>
                <p>این گزینه‌ها مستقیماً روی ربات PHP در حالت وب‌هوک اعمال می‌شوند. خالی‌گذاشتن متن‌ها، متن پیش‌فرض آهنگ‌بان را برمی‌گرداند؛ این متن‌ها ساده هستند و HTML نمی‌پذیرند.</p>
                <form method="post">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="save_behavior">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="welcome_message">پیام شروع</label></th>
                            <td>
                                <textarea id="welcome_message" name="welcome_message" rows="5" maxlength="3500" class="large-text" placeholder="برای استفاده از متن پیش‌فرض خالی بگذارید."><?php echo esc_textarea( $welcome_message ); ?></textarea>
                                <p class="description">پیام ارسالی به دستور <code>/start</code>. برای درج نام کاربر از <code dir="ltr">%first_name%</code> استفاده کنید. حداکثر ۳۵۰۰ نویسه.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="help_message">متن راهنما</label></th>
                            <td>
                                <textarea id="help_message" name="help_message" rows="5" maxlength="3500" class="large-text" placeholder="برای استفاده از راهنمای پیش‌فرض خالی بگذارید."><?php echo esc_textarea( $help_message ); ?></textarea>
                                <p class="description">پیام دستور <code>/help</code>؛ متن ساده و حداکثر ۳۵۰۰ نویسه.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="results_per_page">نتایج آهنگ در هر صفحه</label></th>
                            <td><input type="number" id="results_per_page" name="results_per_page" value="<?php echo esc_attr( $results_per_page ); ?>" min="3" max="10" step="1" class="small-text"><p class="description">برای جست‌وجو، جدیدترین‌ها، محبوب‌ترین‌ها و انتخاب پست کانال؛ بین ۳ تا ۱۰.</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="artists_per_page">هنرمند در هر صفحه</label></th>
                            <td><input type="number" id="artists_per_page" name="artists_per_page" value="<?php echo esc_attr( $artists_per_page ); ?>" min="5" max="15" step="1" class="small-text"><p class="description">برای فهرست و جست‌وجوی خوانندگان؛ بین ۵ تا ۱۵.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">نمایش دکمه‌های منوی شروع</th>
                            <td>
                                <div class="ab-toggle-grid">
                                    <label><input type="hidden" name="menu_latest" value="0"><input type="checkbox" name="menu_latest" value="1" <?php checked( $menu_latest ); ?>> جدیدترین‌ها</label>
                                    <label><input type="hidden" name="menu_popular" value="0"><input type="checkbox" name="menu_popular" value="1" <?php checked( $menu_popular ); ?>> محبوب‌ترین‌ها</label>
                                    <label><input type="hidden" name="menu_artist_search" value="0"><input type="checkbox" name="menu_artist_search" value="1" <?php checked( $menu_artist_search ); ?>> جست‌وجوی خواننده</label>
                                    <label><input type="hidden" name="menu_artist_list" value="0"><input type="checkbox" name="menu_artist_list" value="1" <?php checked( $menu_artist_list ); ?>> فهرست خوانندگان</label>
                                    <label><input type="hidden" name="menu_help" value="0"><input type="checkbox" name="menu_help" value="1" <?php checked( $menu_help ); ?>> راهنما</label>
                                </div>
                                <p class="description">این گزینه‌ها فقط نمایش دکمه‌ها در منوی شروع را کنترل می‌کنند؛ دستورهای متنی ربات همچنان قابل استفاده‌اند.</p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" class="button button-primary">ذخیرهٔ رفتار ربات</button>
                </form>
            </section>

            <section class="ab-card" aria-labelledby="ab-channel-title">
                <div class="ab-card-heading"><div><span class="ab-step">۰۵</span><h2 id="ab-channel-title">اتصال کانال تلگرام</h2></div><span class="ab-badge <?php echo $channel_auto_post ? 'ab-badge--on' : 'ab-badge--off'; ?>">انتشار خودکار <?php echo $channel_auto_post ? 'روشن' : 'خاموش'; ?></span></div>
                <p>فقط آهنگ/آلبوم/ویدیوی موسیقیِ تازه و قابل‌تشخیص از داده‌های واقعی وردپرس در صف WP-Cron قرار می‌گیرد. نوشته‌های معمولی و محتوای قدیمی بازنشر نمی‌شوند.</p>
                <p class="ab-notice ab-notice--warning">اگر پیش‌نمایش فعال باشد، نمونه‌ای از فایل واقعی موسیقی با مدت انتخاب‌شده به‌صورت Voice Note و reply به پست فرستاده می‌شود. ساخت آن به FFmpeg دارای libopus، <code>proc_open</code> و WP-Cron نیاز دارد؛ اگر FFmpeg آماده نباشد، پست اصلی همچنان منتشر می‌شود اما ویس ارسال نمی‌شود.</p>
                <form method="post" class="ab-form-block">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="save_channel">
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="channel_target">شناسه یا یوزرنیم کانال</label></th><td><input type="text" id="channel_target" name="channel_target" value="<?php echo esc_attr( $channel_target ); ?>" dir="ltr" class="regular-text" placeholder="@ahangban یا -1001234567890"><p class="description">ربات باید مدیر کانال و مجاز به ارسال پیام باشد.</p></td></tr>
                        <tr><th scope="row">انتشار خودکار</th><td><input type="hidden" name="channel_auto_post" value="0"><label><input type="checkbox" name="channel_auto_post" value="1" <?php checked( $channel_auto_post ); ?>> پس از انتشار اثر موسیقی جدید، در کانال اطلاع‌رسانی شود</label></td></tr>
                        <tr><th scope="row">پیش‌نمایش ویس</th><td><input type="hidden" name="channel_preview_enabled" value="0"><label><input type="checkbox" name="channel_preview_enabled" value="1" <?php checked( $channel_preview_enabled ); ?>> پس از کارت کانال، نمونهٔ صوتی به‌صورت reply ارسال شود</label><p class="description">این گزینه روی انتشار خودکار و انتشار دستی اثر می‌گذارد؛ خاموش‌کردن آن پست اصلی را متوقف نمی‌کند.</p></td></tr>
                        <tr><th scope="row"><label for="channel_preview_seconds">مدت پیش‌نمایش</label></th><td><select id="channel_preview_seconds" name="channel_preview_seconds"><?php foreach ( array( 15, 30, 45, 60 ) as $seconds ) : ?><option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( $channel_preview_seconds, $seconds ); ?>><?php echo esc_html( $seconds ); ?> ثانیه</option><?php endforeach; ?></select><p class="description">از ابتدای فایل صوتی واقعی ۱۲۸ یا ۳۲۰ ساخته می‌شود.</p></td></tr>
                        <tr><th scope="row"><label for="channel_caption_footer">متن تکمیلی کپشن کانال</label></th><td><textarea id="channel_caption_footer" name="channel_caption_footer" rows="2" maxlength="180" class="large-text" placeholder="اختیاری؛ حداکثر ۱۸۰ نویسه"><?php echo esc_textarea( $channel_caption_footer ); ?></textarea><p class="description">به کپشن انتشار دستی و خودکار افزوده می‌شود؛ متن ساده است و برای جلوگیری از HTML ناامن escape می‌شود.</p></td></tr>
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
                <div class="ab-card-heading"><div><span class="ab-step">۰۶</span><h2 id="ab-webhook-title">وب‌هوک تلگرام</h2></div><span class="ab-muted">بدون نیاز به اجرای دائمی Python</span></div>
                <p>وب‌هوک پیام‌ها را روی همین WordPress دریافت می‌کند. برای ثبت وب‌هوک، توکن ربات و Secret وب‌هوک باید از محیط PHP یا اعتبارنامه‌های ذخیره‌شده در همین افزونه در دسترس باشند. API Token برای احراز هویت مسیرهای REST داده لازم است.</p>
                <div class="ab-webhook-url"><code id="ab-webhook-url" dir="ltr"><?php echo esc_html( $webhook_url ); ?></code><button type="button" class="button button-small" data-ab-copy="ab-webhook-url">کپی نشانی</button><span class="screen-reader-text" aria-live="polite" data-ab-copy-status></span></div>
                <div class="ab-actions">
                    <form method="post">
                        <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                        <input type="hidden" name="ahangban_action" value="check_connection">
                        <button type="submit" class="button" <?php disabled( ! $bot_ready ); ?>>آزمون اتصال و وضعیت وب‌هوک</button>
                    </form>
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
                <p class="description">«آزمون اتصال» فقط getMe و وضعیت وب‌هوک را می‌خواند و پیامی نمی‌فرستد؛ خطای فنی HTTP/cURL/SSL پس از آزمون در اعلان بالای همین صفحه می‌آید و توکن/Secret از متن حذف می‌شود.</p>
                <p class="description">برای اجرای صف انتشار کانال و پیام همگانی، WP-Cron یا Cron Job هاست باید فعال باشد.</p>
            </section>

            <section class="ab-card" aria-labelledby="ab-api-title">
                <div class="ab-card-heading"><div><span class="ab-step">۰۷</span><h2 id="ab-api-title">REST API و کش</h2></div><span class="ab-badge ab-badge--secure">احراز هویت Bearer</span></div>
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
