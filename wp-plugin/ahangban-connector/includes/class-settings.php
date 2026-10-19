<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AhangBan Settings — ذخیرهٔ امن تنظیمات ربات بدون نیاز به SSH.
 * مقادیر حساس در wp_options با autoload=no نگه‌داری می‌شوند.
 */
class AhangBan_Settings {
    const OPTION_BOT_TOKEN    = 'ahangban_bot_token';
    const OPTION_BOT_SECRET   = 'ahangban_bot_secret';
    const OPTION_ADMIN_IDS    = 'ahangban_admin_ids';
    const OPTION_BOT_USERNAME = 'ahangban_bot_username';
    const OPTION_WEBHOOK_URL  = 'ahangban_webhook_url';
    const OPTION_MODE         = 'ahangban_bot_mode';

    public static function init() {}

    public static function get_bot_token() {
        if ( defined( 'AHANGBAN_BOT_TOKEN' ) && AHANGBAN_BOT_TOKEN ) {
            return trim( (string) AHANGBAN_BOT_TOKEN );
        }
        return trim( (string) get_option( self::OPTION_BOT_TOKEN, '' ) );
    }

    public static function get_bot_secret() {
        if ( defined( 'AHANGBAN_BOT_SECRET' ) && AHANGBAN_BOT_SECRET ) {
            return (string) AHANGBAN_BOT_SECRET;
        }
        $value = (string) get_option( self::OPTION_BOT_SECRET, '' );
        if ( '' === $value ) {
            try {
                $value = bin2hex( random_bytes( 32 ) );
                $created = add_option( self::OPTION_BOT_SECRET, $value, '', false );
                // Another request may have created it first; repair an existing empty option.
                $saved = (string) get_option( self::OPTION_BOT_SECRET, '' );
                if ( '' === $saved && ! $created ) {
                    update_option( self::OPTION_BOT_SECRET, $value, false );
                    $saved = (string) get_option( self::OPTION_BOT_SECRET, '' );
                }
                if ( '' !== $saved ) { $value = $saved; }
            } catch ( Throwable $e ) {
                return '';
            }
        }
        return $value;
    }

    public static function get_admin_ids() {
        $raw = ( defined( 'AHANGBAN_ADMIN_IDS' ) && AHANGBAN_ADMIN_IDS )
            ? AHANGBAN_ADMIN_IDS
            : get_option( self::OPTION_ADMIN_IDS, '' );
        $ids = array();
        foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $part ) {
            $part = trim( $part );
            if ( '' !== $part && ctype_digit( $part ) && (int) $part > 0 ) {
                $ids[] = (int) $part;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    public static function get_bot_username() {
        if ( defined( 'AHANGBAN_BOT_USERNAME' ) && AHANGBAN_BOT_USERNAME ) {
            return ltrim( (string) AHANGBAN_BOT_USERNAME, '@' );
        }
        return ltrim( (string) get_option( self::OPTION_BOT_USERNAME, '' ), '@' );
    }

    public static function get_mode() {
        $value = get_option( self::OPTION_MODE, 'webhook' );
        return in_array( $value, array( 'webhook', 'polling' ), true ) ? $value : 'webhook';
    }

    public static function get_webhook_url() {
        $value = (string) get_option( self::OPTION_WEBHOOK_URL, '' );
        return '' !== $value ? $value : rest_url( 'ahangban/v1/telegram/webhook' );
    }

    public static function is_bot_configured() {
        $token = self::get_bot_token();
        return '' !== $token && true === self::validate_bot_token( $token );
    }

    public static function is_admin( $user_id ) {
        return in_array( (int) $user_id, self::get_admin_ids(), true );
    }

    public static function validate_bot_token( $token ) {
        $token = trim( (string) $token );
        if ( '' === $token ) { return true; }
        if ( ! preg_match( '/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token ) ) {
            return new WP_Error( 'invalid_token', 'فرمت توکن ربات نامعتبر است. توکن را از @BotFather کپی کنید.' );
        }
        return true;
    }

    public static function validate_admin_ids( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) { return true; }
        foreach ( preg_split( '/[\s,;]+/', $raw ) as $part ) {
            if ( '' === $part ) { continue; }
            if ( ! ctype_digit( $part ) || (int) $part <= 0 ) {
                return new WP_Error( 'invalid_admin', 'شناسهٔ ادمین باید یک عدد صحیح مثبت باشد: ' . esc_html( $part ) );
            }
        }
        return true;
    }

    /** Validate all input first; a bad field never partially saves other fields. */
    public static function save( $data ) {
        $errors = array();
        $values = array();

        if ( isset( $data['bot_token'] ) ) {
            $token = trim( (string) $data['bot_token'] );
            $valid = self::validate_bot_token( $token );
            if ( is_wp_error( $valid ) ) { $errors[] = $valid->get_error_message(); }
            elseif ( '' !== $token ) { $values[ self::OPTION_BOT_TOKEN ] = $token; }
        }

        if ( isset( $data['admin_ids'] ) ) {
            $admin_ids = trim( (string) $data['admin_ids'] );
            $valid = self::validate_admin_ids( $admin_ids );
            if ( is_wp_error( $valid ) ) { $errors[] = $valid->get_error_message(); }
            else { $values[ self::OPTION_ADMIN_IDS ] = $admin_ids; }
        }

        if ( isset( $data['bot_username'] ) ) {
            $username = ltrim( sanitize_text_field( (string) $data['bot_username'] ), '@' );
            if ( '' !== $username && ! preg_match( '/^[A-Za-z0-9_]{5,32}$/', $username ) ) {
                $errors[] = 'یوزرنیم ربات باید ۵ تا ۳۲ نویسهٔ انگلیسی، عدد یا زیرخط باشد.';
            } else {
                $values[ self::OPTION_BOT_USERNAME ] = $username;
            }
        }

        if ( isset( $data['mode'] ) ) {
            $mode = sanitize_key( $data['mode'] );
            if ( ! in_array( $mode, array( 'webhook', 'polling' ), true ) ) {
                $errors[] = 'حالت اجرا نامعتبر است.';
            } else {
                $values[ self::OPTION_MODE ] = $mode;
            }
        }

        if ( $errors ) { return $errors; }
        foreach ( $values as $key => $value ) {
            update_option( $key, $value, false );
        }
        return array();
    }

    public static function mask_token( $token ) {
        $token = (string) $token;
        if ( '' === $token ) { return '— تنظیم نشده —'; }
        $length = strlen( $token );
        if ( $length <= 12 ) { return str_repeat( '•', $length ); }
        return substr( $token, 0, 6 ) . str_repeat( '•', max( 8, $length - 10 ) ) . substr( $token, -4 );
    }
}
