<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AhangBan Settings — non-secret bot and channel preferences.
 * Telegram/API credentials are read from the PHP process environment only.
 */
class AhangBan_Settings {
    // Legacy option names are retained only for the one-time secret cleanup.
    const OPTION_BOT_TOKEN      = 'ahangban_bot_token';
    const OPTION_BOT_SECRET     = 'ahangban_bot_secret';
    const OPTION_ADMIN_IDS      = 'ahangban_admin_ids';
    const OPTION_BOT_USERNAME   = 'ahangban_bot_username';
    const OPTION_WEBHOOK_URL    = 'ahangban_webhook_url';
    const OPTION_MODE           = 'ahangban_bot_mode';
    const OPTION_CHANNEL_TARGET = 'ahangban_channel_target';
    const OPTION_CHANNEL_AUTO_POST = 'ahangban_channel_auto_post';
    const OPTION_SECRET_MIGRATION = 'ahangban_env_secrets_migrated';

    public static function init() {
        self::migrate_legacy_secrets();
    }

    /** Remove pre-1.5 credentials from wp_options exactly once; they are no longer read. */
    private static function migrate_legacy_secrets() {
        if ( '1' === (string) get_option( self::OPTION_SECRET_MIGRATION, '0' ) ) { return; }
        delete_option( self::OPTION_BOT_TOKEN );
        delete_option( self::OPTION_BOT_SECRET );
        delete_option( AhangBan_Auth::OPTION_TOKEN );
        update_option( self::OPTION_SECRET_MIGRATION, '1', false );
    }

    private static function environment_value( $name ) {
        $value = getenv( $name );
        if ( false !== $value && '' !== trim( (string) $value ) ) { return trim( (string) $value ); }
        if ( isset( $_SERVER[ $name ] ) && '' !== trim( (string) $_SERVER[ $name ] ) ) { return trim( (string) $_SERVER[ $name ] ); }
        if ( isset( $_ENV[ $name ] ) && '' !== trim( (string) $_ENV[ $name ] ) ) { return trim( (string) $_ENV[ $name ] ); }
        return '';
    }

    public static function get_bot_token() {
        $token = self::environment_value( 'AHANGBAN_BOT_TOKEN' );
        return true === self::validate_bot_token( $token ) ? $token : '';
    }

    public static function get_bot_secret() {
        $secret = self::environment_value( 'AHANGBAN_BOT_SECRET' );
        return preg_match( '/^[A-Za-z0-9_-]{1,256}$/', $secret ) ? $secret : '';
    }

    public static function get_admin_ids() {
        $raw = ( defined( 'AHANGBAN_ADMIN_IDS' ) && AHANGBAN_ADMIN_IDS )
            ? AHANGBAN_ADMIN_IDS
            : self::environment_value( 'AHANGBAN_ADMIN_IDS' );
        if ( '' === $raw ) { $raw = get_option( self::OPTION_ADMIN_IDS, '' ); }
        $ids = array();
        foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $part ) {
            $part = trim( $part );
            if ( '' !== $part && ctype_digit( $part ) && (int) $part > 0 ) { $ids[] = (int) $part; }
        }
        return array_values( array_unique( $ids ) );
    }

    public static function get_bot_username() {
        if ( defined( 'AHANGBAN_BOT_USERNAME' ) && AHANGBAN_BOT_USERNAME ) {
            return ltrim( (string) AHANGBAN_BOT_USERNAME, '@' );
        }
        $username = self::environment_value( 'AHANGBAN_BOT_USERNAME' );
        if ( '' === $username ) { $username = (string) get_option( self::OPTION_BOT_USERNAME, '' ); }
        return ltrim( $username, '@' );
    }

    public static function get_channel_target() {
        return trim( (string) get_option( self::OPTION_CHANNEL_TARGET, '' ) );
    }

    public static function is_channel_auto_post_enabled() {
        return '1' === (string) get_option( self::OPTION_CHANNEL_AUTO_POST, '0' );
    }

    public static function normalize_channel_target( $target ) {
        $target = trim( (string) $target );
        if ( '' === $target ) { return ''; }
        if ( preg_match( '/^-100[0-9]{6,20}$/', $target ) ) { return $target; }
        $username = '@' === substr( $target, 0, 1 ) ? substr( $target, 1 ) : $target;
        return preg_match( '/^[A-Za-z0-9_]{5,32}$/', $username ) ? '@' . $username : '';
    }

    public static function validate_channel_target( $target ) {
        $target = trim( (string) $target );
        if ( '' === $target || '' !== self::normalize_channel_target( $target ) ) { return true; }
        return new WP_Error( 'invalid_channel', 'کانال باید با @username یا شناسهٔ عددی منفی مثل -100… وارد شود.' );
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
        return '' !== self::get_bot_token();
    }

    public static function is_admin( $user_id ) {
        return in_array( (int) $user_id, self::get_admin_ids(), true );
    }

    public static function validate_bot_token( $token ) {
        $token = trim( (string) $token );
        if ( '' === $token ) { return true; }
        if ( ! preg_match( '/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token ) ) {
            return new WP_Error( 'invalid_token', 'فرمت توکن ربات نامعتبر است. مقدار محیطی AHANGBAN_BOT_TOKEN را بررسی کنید.' );
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

    /** Validate all non-secret settings first; a bad field never partially saves other fields. */
    public static function save( $data ) {
        $errors = array();
        $values = array();

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

        if ( isset( $data['channel_target'] ) ) {
            $channel_target = trim( (string) $data['channel_target'] );
            $valid = self::validate_channel_target( $channel_target );
            if ( is_wp_error( $valid ) ) {
                $errors[] = $valid->get_error_message();
            } else {
                $values[ self::OPTION_CHANNEL_TARGET ] = self::normalize_channel_target( $channel_target );
            }
        }

        if ( array_key_exists( 'channel_auto_post', $data ) ) {
            $enabled = in_array( (string) $data['channel_auto_post'], array( '1', 'true', 'yes', 'on' ), true );
            $channel_target = array_key_exists( 'channel_target', $values )
                ? $values[ self::OPTION_CHANNEL_TARGET ]
                : self::get_channel_target();
            if ( $enabled && '' === $channel_target ) {
                $errors[] = 'برای فعال‌کردن ارسال خودکار، ابتدا شناسه یا یوزرنیم کانال را وارد کنید.';
            } else {
                $values[ self::OPTION_CHANNEL_AUTO_POST ] = $enabled ? '1' : '0';
            }
        }

        if ( $errors ) { return $errors; }
        foreach ( $values as $key => $value ) { update_option( $key, $value, false ); }
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
