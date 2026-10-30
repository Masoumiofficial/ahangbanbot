<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AhangBan Settings — bot/channel preferences and legacy-compatible credential storage.
 * PHP environment values take precedence; administrator-entered credentials are a fallback.
 */
class AhangBan_Settings {
    // Keep the historical option names for administrator-managed credentials.
    const OPTION_BOT_TOKEN      = 'ahangban_bot_token';
    const OPTION_BOT_SECRET     = 'ahangban_bot_secret';
    const OPTION_ADMIN_IDS      = 'ahangban_admin_ids';
    const OPTION_BOT_USERNAME   = 'ahangban_bot_username';
    const OPTION_WEBHOOK_URL    = 'ahangban_webhook_url';
    const OPTION_MODE           = 'ahangban_bot_mode';
    const OPTION_CHANNEL_TARGET = 'ahangban_channel_target';
    const OPTION_CHANNEL_AUTO_POST = 'ahangban_channel_auto_post';
    const OPTION_WELCOME_MESSAGE = 'ahangban_bot_welcome_message';
    const OPTION_HELP_MESSAGE = 'ahangban_bot_help_message';
    const OPTION_RESULTS_PER_PAGE = 'ahangban_bot_results_per_page';
    const OPTION_ARTISTS_PER_PAGE = 'ahangban_bot_artists_per_page';
    const OPTION_MENU_LATEST = 'ahangban_bot_menu_latest';
    const OPTION_MENU_POPULAR = 'ahangban_bot_menu_popular';
    const OPTION_MENU_ARTIST_SEARCH = 'ahangban_bot_menu_artist_search';
    const OPTION_MENU_ARTIST_LIST = 'ahangban_bot_menu_artist_list';
    const OPTION_MENU_HELP = 'ahangban_bot_menu_help';
    const OPTION_CHANNEL_PREVIEW_ENABLED = 'ahangban_channel_preview_enabled';
    const OPTION_CHANNEL_PREVIEW_SECONDS = 'ahangban_channel_preview_seconds';
    const OPTION_CHANNEL_CAPTION_FOOTER = 'ahangban_channel_caption_footer';
    const OPTION_CHANNEL_VOICE_CAPTION = 'ahangban_channel_voice_caption';
    const DEFAULT_CHANNEL_VOICE_CAPTION = "🩵 @AhangBan_ir 🩵\n\n🎧 آهنگ بان | موسیقی همیشه همراهته 🎵";
    const OPTION_SECRET_MIGRATION = 'ahangban_env_secrets_migrated';

    public static function init() {
        self::migrate_legacy_secrets();
    }

    /** Preserve historical credentials during upgrades and ensure they are not autoloaded. */
    private static function migrate_legacy_secrets() {
        if ( '1' === (string) get_option( self::OPTION_SECRET_MIGRATION, '0' ) ) { return; }
        foreach ( array( self::OPTION_BOT_TOKEN, self::OPTION_BOT_SECRET, AhangBan_Auth::OPTION_TOKEN ) as $key ) {
            $value = get_option( $key, '' );
            if ( is_string( $value ) && '' !== $value ) { update_option( $key, $value, false ); }
        }
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
        if ( true === self::validate_bot_token( $token ) && '' !== $token ) { return $token; }

        $token = trim( (string) get_option( self::OPTION_BOT_TOKEN, '' ) );
        return true === self::validate_bot_token( $token ) ? $token : '';
    }

    public static function get_bot_secret() {
        $secret = self::environment_value( 'AHANGBAN_BOT_SECRET' );
        if ( preg_match( '/^[A-Za-z0-9_-]{1,256}$/', $secret ) ) { return $secret; }

        $secret = trim( (string) get_option( self::OPTION_BOT_SECRET, '' ) );
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

    public static function get_bot_welcome_message() {
        return trim( (string) get_option( self::OPTION_WELCOME_MESSAGE, '' ) );
    }

    public static function get_bot_help_message() {
        return trim( (string) get_option( self::OPTION_HELP_MESSAGE, '' ) );
    }

    public static function get_results_per_page() {
        return max( 3, min( 10, (int) get_option( self::OPTION_RESULTS_PER_PAGE, 5 ) ) );
    }

    public static function get_artists_per_page() {
        return max( 5, min( 15, (int) get_option( self::OPTION_ARTISTS_PER_PAGE, 10 ) ) );
    }

    public static function is_menu_item_enabled( $item ) {
        $options = array(
            'latest' => self::OPTION_MENU_LATEST,
            'popular' => self::OPTION_MENU_POPULAR,
            'artist_search' => self::OPTION_MENU_ARTIST_SEARCH,
            'artist_list' => self::OPTION_MENU_ARTIST_LIST,
            'help' => self::OPTION_MENU_HELP,
        );
        if ( ! isset( $options[ $item ] ) ) { return false; }
        return '0' !== (string) get_option( $options[ $item ], '1' );
    }

    public static function is_channel_preview_enabled() {
        // Keep the release 1.5.3 behavior for existing installations until explicitly disabled.
        return '0' !== (string) get_option( self::OPTION_CHANNEL_PREVIEW_ENABLED, '1' );
    }

    public static function get_channel_preview_seconds() {
        $seconds = (int) get_option( self::OPTION_CHANNEL_PREVIEW_SECONDS, 60 );
        return in_array( $seconds, array( 15, 30, 45, 60 ), true ) ? $seconds : 60;
    }

    public static function get_channel_caption_footer() {
        return trim( (string) get_option( self::OPTION_CHANNEL_CAPTION_FOOTER, '' ) );
    }

    public static function get_channel_voice_caption() {
        return trim( (string) get_option( self::OPTION_CHANNEL_VOICE_CAPTION, self::DEFAULT_CHANNEL_VOICE_CAPTION ) );
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
        return in_array( $value, array( 'webhook', 'polling', 'wordpress_polling' ), true ) ? $value : 'webhook';
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
            return new WP_Error( 'invalid_token', 'فرمت توکن ربات نامعتبر است؛ توکن BotFather را بدون فاصله وارد کنید.' );
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

    private static function text_length( $text ) {
        $text = (string) $text;
        if ( function_exists( 'mb_strlen' ) ) { return mb_strlen( $text, 'UTF-8' ); }
        $characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $characters ) ? count( $characters ) : strlen( $text );
    }

    /** Validate all submitted settings first; a bad field never partially saves other fields. */
    public static function save( $data ) {
        $errors = array();
        $values = array();
        $credential_values = array();
        $credential_deletes = array();

        if ( isset( $data['api_token'] ) ) {
            $api_token = trim( (string) $data['api_token'] );
            if ( '' !== $api_token && ! preg_match( '/^[a-f0-9]{64}$/i', $api_token ) ) {
                $errors[] = 'توکن API باید دقیقاً ۶۴ نویسهٔ hex باشد.';
            } elseif ( '' !== $api_token ) {
                $credential_values[ AhangBan_Auth::OPTION_TOKEN ] = $api_token;
            } elseif ( ! empty( $data['clear_api_token'] ) ) {
                $credential_deletes[] = AhangBan_Auth::OPTION_TOKEN;
            }
        }

        if ( isset( $data['bot_token'] ) ) {
            $bot_token = trim( (string) $data['bot_token'] );
            $valid = self::validate_bot_token( $bot_token );
            if ( is_wp_error( $valid ) ) {
                $errors[] = $valid->get_error_message();
            } elseif ( '' !== $bot_token ) {
                $credential_values[ self::OPTION_BOT_TOKEN ] = $bot_token;
            } elseif ( ! empty( $data['clear_bot_token'] ) ) {
                $credential_deletes[] = self::OPTION_BOT_TOKEN;
            }
        }

        if ( isset( $data['bot_secret'] ) ) {
            $bot_secret = trim( (string) $data['bot_secret'] );
            if ( '' !== $bot_secret && ! preg_match( '/^[A-Za-z0-9_-]{1,256}$/', $bot_secret ) ) {
                $errors[] = 'Secret وب‌هوک فقط می‌تواند شامل حروف انگلیسی، عدد، خط تیره و زیرخط باشد.';
            } elseif ( '' !== $bot_secret ) {
                $credential_values[ self::OPTION_BOT_SECRET ] = $bot_secret;
            } elseif ( ! empty( $data['clear_bot_secret'] ) ) {
                $credential_deletes[] = self::OPTION_BOT_SECRET;
            }
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
            if ( ! in_array( $mode, array( 'webhook', 'polling', 'wordpress_polling' ), true ) ) {
                $errors[] = 'حالت اجرا نامعتبر است.';
            } else {
                $values[ self::OPTION_MODE ] = $mode;
            }
        }

        if ( array_key_exists( 'welcome_message', $data ) ) {
            $welcome_message = sanitize_textarea_field( (string) $data['welcome_message'] );
            if ( self::text_length( $welcome_message ) > 3500 ) {
                $errors[] = 'متن خوش‌آمدگویی حداکثر می‌تواند ۳۵۰۰ نویسه باشد.';
            } else {
                $values[ self::OPTION_WELCOME_MESSAGE ] = $welcome_message;
            }
        }

        if ( array_key_exists( 'help_message', $data ) ) {
            $help_message = sanitize_textarea_field( (string) $data['help_message'] );
            if ( self::text_length( $help_message ) > 3500 ) {
                $errors[] = 'متن راهنمای ربات حداکثر می‌تواند ۳۵۰۰ نویسه باشد.';
            } else {
                $values[ self::OPTION_HELP_MESSAGE ] = $help_message;
            }
        }

        if ( isset( $data['results_per_page'] ) ) {
            $results_per_page = trim( (string) $data['results_per_page'] );
            if ( ! ctype_digit( $results_per_page ) || (int) $results_per_page < 3 || (int) $results_per_page > 10 ) {
                $errors[] = 'تعداد نتیجه در هر صفحه باید عددی بین ۳ تا ۱۰ باشد.';
            } else {
                $values[ self::OPTION_RESULTS_PER_PAGE ] = (string) (int) $results_per_page;
            }
        }

        if ( isset( $data['artists_per_page'] ) ) {
            $artists_per_page = trim( (string) $data['artists_per_page'] );
            if ( ! ctype_digit( $artists_per_page ) || (int) $artists_per_page < 5 || (int) $artists_per_page > 15 ) {
                $errors[] = 'تعداد هنرمند در هر صفحه باید عددی بین ۵ تا ۱۵ باشد.';
            } else {
                $values[ self::OPTION_ARTISTS_PER_PAGE ] = (string) (int) $artists_per_page;
            }
        }

        $menu_options = array(
            'menu_latest' => self::OPTION_MENU_LATEST,
            'menu_popular' => self::OPTION_MENU_POPULAR,
            'menu_artist_search' => self::OPTION_MENU_ARTIST_SEARCH,
            'menu_artist_list' => self::OPTION_MENU_ARTIST_LIST,
            'menu_help' => self::OPTION_MENU_HELP,
        );
        foreach ( $menu_options as $field => $option ) {
            if ( array_key_exists( $field, $data ) ) {
                $enabled = in_array( (string) $data[ $field ], array( '1', 'true', 'yes', 'on' ), true );
                $values[ $option ] = $enabled ? '1' : '0';
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

        if ( array_key_exists( 'channel_preview_enabled', $data ) ) {
            $enabled = in_array( (string) $data['channel_preview_enabled'], array( '1', 'true', 'yes', 'on' ), true );
            $values[ self::OPTION_CHANNEL_PREVIEW_ENABLED ] = $enabled ? '1' : '0';
        }

        if ( isset( $data['channel_preview_seconds'] ) ) {
            $seconds = trim( (string) $data['channel_preview_seconds'] );
            if ( ! in_array( $seconds, array( '15', '30', '45', '60' ), true ) ) {
                $errors[] = 'مدت پیش‌نمایش ویس باید یکی از گزینه‌های ۱۵، ۳۰، ۴۵ یا ۶۰ ثانیه باشد.';
            } else {
                $values[ self::OPTION_CHANNEL_PREVIEW_SECONDS ] = (string) (int) $seconds;
            }
        }

        if ( array_key_exists( 'channel_caption_footer', $data ) ) {
            $footer = sanitize_textarea_field( (string) $data['channel_caption_footer'] );
            if ( self::text_length( $footer ) > 180 ) {
                $errors[] = 'متن پایانی کپشن کانال حداکثر می‌تواند ۱۸۰ نویسه باشد.';
            } else {
                $values[ self::OPTION_CHANNEL_CAPTION_FOOTER ] = $footer;
            }
        }

        if ( array_key_exists( 'channel_voice_caption', $data ) ) {
            $caption = sanitize_textarea_field( (string) $data['channel_voice_caption'] );
            if ( self::text_length( $caption ) > 900 ) {
                $errors[] = 'کپشن ویس حداکثر می‌تواند ۹۰۰ نویسه باشد.';
            } else {
                $values[ self::OPTION_CHANNEL_VOICE_CAPTION ] = $caption;
            }
        }

        if ( $errors ) { return $errors; }
        foreach ( $values as $key => $value ) { update_option( $key, $value, false ); }
        foreach ( $credential_values as $key => $value ) { update_option( $key, $value, false ); }
        foreach ( array_unique( $credential_deletes ) as $key ) { delete_option( $key ); }
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
