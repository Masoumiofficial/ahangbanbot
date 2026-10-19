<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AhangBan Settings — ذخیره تنظیمات ربات بدون نیاز به SSH
 * همه کلیدها با autoload=no ذخیره می‌شوند تا در حافظه لود نشوند.
 */
class AhangBan_Settings {
    const OPTION_BOT_TOKEN   = 'ahangban_bot_token';
    const OPTION_BOT_SECRET  = 'ahangban_bot_secret';
    const OPTION_ADMIN_IDS   = 'ahangban_admin_ids';
    const OPTION_BOT_USERNAME= 'ahangban_bot_username';
    const OPTION_WEBHOOK_URL = 'ahangban_webhook_url';
    const OPTION_MODE        = 'ahangban_bot_mode'; // webhook | polling

    public static function init() {}

    // --- Getters ---
    public static function get_bot_token() {
        // اولویت: ثابت wp-config.php → سپس گزینه دیتابیس
        if ( defined( 'AHANGBAN_BOT_TOKEN' ) && AHANGBAN_BOT_TOKEN ) {
            return (string) AHANGBAN_BOT_TOKEN;
        }
        return (string) get_option( self::OPTION_BOT_TOKEN, '' );
    }

    public static function get_bot_secret() {
        if ( defined( 'AHANGBAN_BOT_SECRET' ) && AHANGBAN_BOT_SECRET ) {
            return (string) AHANGBAN_BOT_SECRET;
        }
        $v = get_option( self::OPTION_BOT_SECRET, '' );
        if ( '' === $v ) {
            // تولید خودکار secret اولیه
            $v = bin2hex( random_bytes( 16 ) );
            update_option( self::OPTION_BOT_SECRET, $v, false );
        }
        return (string) $v;
    }

    public static function get_admin_ids() {
        if ( defined( 'AHANGBAN_ADMIN_IDS' ) && AHANGBAN_ADMIN_IDS ) {
            $raw = AHANGBAN_ADMIN_IDS;
        } else {
            $raw = get_option( self::OPTION_ADMIN_IDS, '' );
        }
        $ids = array();
        foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $p ) {
            $p = trim( $p );
            if ( '' !== $p && is_numeric( $p ) ) { $ids[] = (int) $p; }
        }
        return array_unique( $ids );
    }

    public static function get_bot_username() {
        if ( defined( 'AHANGBAN_BOT_USERNAME' ) && AHANGBAN_BOT_USERNAME ) {
            return (string) AHANGBAN_BOT_USERNAME;
        }
        return (string) get_option( self::OPTION_BOT_USERNAME, '' );
    }

    public static function get_mode() {
        $v = get_option( self::OPTION_MODE, 'webhook' );
        return in_array( $v, array( 'webhook', 'polling' ), true ) ? $v : 'webhook';
    }

    public static function get_webhook_url() {
        $v = get_option( self::OPTION_WEBHOOK_URL, '' );
        if ( '' !== $v ) { return $v; }
        // پیش‌فرض: مسیر همین افزونه
        return rest_url( 'ahangban/v1/telegram/webhook' );
    }

    public static function is_bot_configured() {
        $t = self::get_bot_token();
        return '' !== $t && false !== strpos( $t, ':' ) && strlen( $t ) > 20;
    }

    public static function is_admin( $user_id ) {
        $ids = self::get_admin_ids();
        return in_array( (int) $user_id, $ids, true );
    }

    // --- Validation ---
    public static function validate_bot_token( $token ) {
        $token = trim( (string) $token );
        if ( '' === $token ) { return true; } // خالی مجاز است (برای پاک کردن)
        // فرمت تلگرام: 123456789:AAH... (حداقل 20 کاراکتر، شامل کولون)
        if ( ! preg_match( '/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token ) ) {
            return new WP_Error( 'invalid_token', 'فرمت توکن نامعتبر است. مثال صحیح: 123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw' );
        }
        return true;
    }

    public static function validate_admin_ids( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) { return true; }
        foreach ( preg_split( '/[\s,;]+/', $raw ) as $p ) {
            if ( '' === $p ) { continue; }
            if ( ! is_numeric( $p ) || (int) $p <= 0 ) {
                return new WP_Error( 'invalid_admin', 'شناسه ادمین باید عددی باشد. شناسه نامعتبر: '. esc_html( $p ) );
            }
        }
        return true;
    }

    // --- Save ---
    public static function save( $data ) {
        $errors = array();
        // bot token
        if ( isset( $data['bot_token'] ) ) {
            $v = trim( (string) $data['bot_token'] );
            $chk = self::validate_bot_token( $v );
            if ( is_wp_error( $chk ) ) { $errors[] = $chk->get_error_message(); }
            else { update_option( self::OPTION_BOT_TOKEN, $v, false ); }
        }
        // bot username
        if ( isset( $data['bot_username'] ) ) {
            $v = sanitize_text_field( $data['bot_username'] );
            $v = ltrim( $v, '@' );
            update_option( self::OPTION_BOT_USERNAME, $v, false );
        }
        // admin ids
        if ( isset( $data['admin_ids'] ) ) {
            $v = trim( (string) $data['admin_ids'] );
            $chk = self::validate_admin_ids( $v );
            if ( is_wp_error( $chk ) ) { $errors[] = $chk->get_error_message(); }
            else { update_option( self::OPTION_ADMIN_IDS, $v, false ); }
        }
        // mode
        if ( isset( $data['mode'] ) ) {
            $v = sanitize_key( $data['mode'] );
            if ( in_array( $v, array( 'webhook', 'polling' ), true ) ) {
                update_option( self::OPTION_MODE, $v, false );
            }
        }
        // webhook secret (regenerate if requested)
        if ( ! empty( $data['regenerate_secret'] ) ) {
            update_option( self::OPTION_BOT_SECRET, bin2hex( random_bytes( 16 ) ), false );
        }
        return $errors;
    }

    public static function mask_token( $token ) {
        if ( '' === $token ) { return '— تنظیم نشده —'; }
        $len = strlen( $token );
        if ( $len <= 12 ) { return str_repeat( '•', $len ); }
        return substr( $token, 0, 6 ) . str_repeat( '•', max( 8, $len - 12 ) ) . substr( $token, -4 );
    }
}
