<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AhangBan Telegram — وب‌هوک PHP داخل وردپرس (بدون نیاز به سرور جدا)
 * برای هاست اشتراکی دایرکت‌ادمین: کافیست وب‌هوک ست شود، پیام‌ها مستقیم روی همین هاست هندل می‌شوند.
 */
class AhangBan_Telegram {
    const NS = 'ahangban/v1';
    const SCHEMA_VERSION = '3';
    const MAX_MEDIA_BYTES = 51380224; // Conservative 49 MiB under Telegram Bot API upload limits.
    const BROADCAST_BATCH = 5;

    public static function init() {
        self::maybe_install_tables();
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'ahangban_bot_process_broadcast', array( __CLASS__, 'process_broadcast_batch' ), 10, 1 );
        // wp_after_insert_post runs after post metadata (including ACF musics_type/media fields) is saved.
        add_action( 'wp_after_insert_post', array( __CLASS__, 'queue_channel_announcement_after_insert' ), 10, 4 );
        add_action( 'ahangban_bot_send_channel_announcement', array( __CLASS__, 'send_channel_announcement' ), 10, 1 );
    }

    /** Only classify verified music records; never broadcast an arbitrary WordPress post. */
    private static function is_music_post( $post ) {
        return AhangBan_Search::is_music_record( $post );
    }

    /** Inspect post metadata only after WordPress/ACF have completed saving it. */
    public static function queue_channel_announcement_after_insert( $post_id, $post, $update, $post_before ) {
        if ( ! is_object( $post ) ) { return; }
        $old_status = is_object( $post_before ) ? (string) $post_before->post_status : '';
        self::queue_channel_announcement( (string) $post->post_status, $old_status, $post );
    }

    /** Queue newly published music records instead of slowing the WordPress editor with Telegram HTTP calls. */
    public static function queue_channel_announcement( $new_status, $old_status, $post ) {
        if ( 'publish' !== $new_status || 'publish' === $old_status || ! self::is_music_post( $post ) ) { return; }
        if ( ! AhangBan_Settings::is_channel_auto_post_enabled() || '' === AhangBan_Settings::get_channel_target() || '' === AhangBan_Settings::get_bot_token() ) { return; }

        $post_id = (int) $post->ID;
        $status_key = '_ahangban_tg_channel_announcement_status';
        $status = (string) get_post_meta( $post_id, $status_key, true );
        if ( in_array( $status, array( 'pending', 'sent', 'unknown' ), true ) ) { return; }

        update_post_meta( $post_id, $status_key, 'pending' );
        $scheduled = wp_schedule_single_event( time() + 10, 'ahangban_bot_send_channel_announcement', array( $post_id ) );
        if ( is_wp_error( $scheduled ) || ( false === $scheduled && ! wp_next_scheduled( 'ahangban_bot_send_channel_announcement', array( $post_id ) ) ) ) {
            update_post_meta( $post_id, $status_key, 'failed' );
            error_log( '[AhangBan Telegram] Could not queue channel announcement for post ' . $post_id . '.' );
        }
    }

    /** Publish a compact announcement with links back to the canonical post and bot deep link. */
    public static function send_channel_announcement( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || 'pending' !== (string) get_post_meta( $post_id, '_ahangban_tg_channel_announcement_status', true ) ) { return; }
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status || ! self::is_music_post( $post ) ) {
            update_post_meta( $post_id, '_ahangban_tg_channel_announcement_status', 'cancelled' );
            return;
        }
        $target = AhangBan_Settings::get_channel_target();
        if ( ! AhangBan_Settings::is_channel_auto_post_enabled() || '' === $target || '' === AhangBan_Settings::get_bot_token() ) {
            update_post_meta( $post_id, '_ahangban_tg_channel_announcement_status', 'cancelled' );
            return;
        }

        $song = AhangBan_Search::get_single( $post_id );
        if ( ! is_array( $song ) ) {
            update_post_meta( $post_id, '_ahangban_tg_channel_announcement_status', 'failed' );
            return;
        }
        $caption = self::channel_caption( $song );
        $keyboard = self::channel_keyboard( $song );
        $photo_status = self::send_card_photo( $target, $song, $caption, $keyboard );

        if ( 'sent' === $photo_status['status'] ) {
            self::mark_channel_announcement_sent( $post_id, $photo_status['response'] );
            return;
        }
        if ( 'unknown' === $photo_status['status'] ) {
            // A timeout is ambiguous: Telegram may already have delivered the image. Do not retry and duplicate it.
            update_post_meta( $post_id, '_ahangban_tg_channel_announcement_status', 'unknown' );
            return;
        }

        $response = self::send( $target, $caption, $keyboard );
        if ( self::telegram_ok( $response ) ) {
            self::mark_channel_announcement_sent( $post_id, $response );
        } else {
            update_post_meta( $post_id, '_ahangban_tg_channel_announcement_status', is_wp_error( $response ) ? 'unknown' : 'failed' );
        }
    }

    /** Publish a post selected by the authenticated Python admin bot; automatic-post settings are intentionally ignored. */
    public static function publish_channel_post( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return new WP_Error( 'invalid_post_id', 'شناسهٔ پست نامعتبر است.', array( 'status' => 400 ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status || ! self::is_music_post( $post ) ) {
            return new WP_Error( 'post_not_found', 'این اثر منتشرشده نیست یا در فهرست موسیقی آهنگ‌بان قرار ندارد.', array( 'status' => 404 ) );
        }

        $target = AhangBan_Settings::normalize_channel_target( AhangBan_Settings::get_channel_target() );
        if ( '' === $target ) {
            return new WP_Error( 'channel_unconfigured', 'ابتدا کانال را در پیشخوان WordPress → آهنگ‌بان API تنظیم کنید.', array( 'status' => 409 ) );
        }
        if ( '' === AhangBan_Settings::get_bot_token() ) {
            return new WP_Error( 'bot_unconfigured', 'توکن ربات در محیط PHP هاست تنظیم نشده است.', array( 'status' => 409 ) );
        }

        $song = AhangBan_Search::get_single( $post_id );
        if ( ! is_array( $song ) ) {
            return new WP_Error( 'post_not_found', 'اطلاعات این اثر برای انتشار در دسترس نیست.', array( 'status' => 404 ) );
        }

        $caption = self::channel_caption( $song, 'انتشار از آرشیو آهنگ‌بان' );
        $keyboard = self::channel_keyboard( $song );
        $photo_status = self::send_card_photo( $target, $song, $caption, $keyboard );
        if ( 'unknown' === $photo_status['status'] ) {
            return new WP_Error( 'telegram_delivery_unknown', 'پاسخ ارسال تصویر نامشخص است؛ برای جلوگیری از انتشار تکراری خودکار دوباره تلاش نشد.', array( 'status' => 502 ) );
        }

        $delivery = 'message';
        if ( 'sent' === $photo_status['status'] ) {
            $response = $photo_status['response'];
            $delivery = 'photo';
        } else {
            $response = self::send( $target, $caption, $keyboard );
        }

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'telegram_delivery_unknown', 'نتیجهٔ ارسال به تلگرام نامشخص است؛ کانال را بررسی کنید و از تلاش دوباره تا روشن‌شدن وضعیت خودداری کنید.', array( 'status' => 502 ) );
        }
        if ( ! self::telegram_ok( $response ) ) {
            return new WP_Error( 'telegram_send_failed', 'تلگرام پیام را نپذیرفت؛ دسترسی ربات به کانال و تنظیمات را بررسی کنید.', array( 'status' => 400 ) );
        }

        return array(
            'status' => 'sent',
            'post_id' => $post_id,
            'delivery' => $delivery,
            'message_id' => (int) ( $response['result']['message_id'] ?? 0 ),
        );
    }

    private static function mark_channel_announcement_sent( $post_id, $response ) {
        $message_id = is_array( $response ) ? (int) ( $response['result']['message_id'] ?? 0 ) : 0;
        update_post_meta( (int) $post_id, '_ahangban_tg_channel_announcement_status', 'sent' );
        if ( $message_id > 0 ) { update_post_meta( (int) $post_id, '_ahangban_tg_channel_message_id', $message_id ); }
    }

    private static function channel_caption( $song, $heading = 'اثر تازه در آهنگ‌بان' ) {
        $heading = self::esc( self::text_limit( $heading, 80 ) );
        $title = self::esc( self::text_limit( $song['song'] ?? $song['title'] ?? 'آهنگ جدید', 100 ) );
        $artist = self::esc( self::text_limit( $song['artist'] ?? '', 90 ) );
        $permalink = esc_url( $song['permalink'] ?? '', array( 'http', 'https' ) );
        $caption = "🎶 <b>{$heading}</b>\n\n🎵 <b>{$title}</b>";
        if ( '' !== $artist ) { $caption .= "\n👤 {$artist}"; }
        if ( $permalink ) { $caption .= "\n🔗 <a href=\"{$permalink}\">مشاهدهٔ صفحهٔ رسمی</a>"; }
        return self::text_limit( $caption, 850 );
    }

    private static function channel_keyboard( $song ) {
        $rows = array();
        $username = AhangBan_Settings::get_bot_username();
        $song_id = (int) ( $song['id'] ?? 0 );
        if ( '' !== $username && $song_id > 0 ) {
            $rows[] = array( array( 'text' => '🎧 مشاهده و دریافت در ربات', 'url' => 'https://t.me/' . rawurlencode( $username ) . '?start=song_' . $song_id ) );
        }
        $permalink = esc_url( $song['permalink'] ?? '', array( 'http', 'https' ) );
        if ( $permalink ) { $rows[] = array( array( 'text' => '🌐 صفحهٔ آهنگ در سایت', 'url' => $permalink ) ); }
        return $rows ? array( 'inline_keyboard' => $rows ) : null;
    }

    /** Try the large cover first, then a medium fallback; never duplicate a send after a transport timeout. */
    private static function send_card_photo( $chat_id, $item, $caption, $keyboard = null ) {
        $primary = $item['cover'] ?? $item['image'] ?? '';
        $fallback = $item['cover_fallback'] ?? $item['image_fallback'] ?? '';
        $urls = array_unique( array_filter( array( $primary, $fallback ) ) );
        $attempted = false;
        foreach ( $urls as $url ) {
            $photo = self::safe_media_url( $url );
            if ( ! $photo ) { continue; }
            $attempted = true;
            $response = self::send_photo( $chat_id, $photo, $caption, $keyboard );
            if ( self::telegram_ok( $response ) ) { return array( 'status' => 'sent', 'response' => $response ); }
            if ( is_wp_error( $response ) ) { return array( 'status' => 'unknown', 'response' => $response ); }
        }
        return array( 'status' => $attempted ? 'rejected' : 'unavailable', 'response' => null );
    }

    private static function users_table() {
        global $wpdb;
        return $wpdb->prefix . 'ahangban_bot_users';
    }

    private static function broadcasts_table() {
        global $wpdb;
        return $wpdb->prefix . 'ahangban_bot_broadcasts';
    }

    private static function updates_table() {
        global $wpdb;
        return $wpdb->prefix . 'ahangban_bot_updates';
    }

    /** Bot interaction state is stored in the existing WordPress database (not a parallel WP-content database). */
    private static function maybe_install_tables() {
        if ( self::SCHEMA_VERSION === (string) get_option( 'ahangban_telegram_schema', '' ) ) { return; }
        global $wpdb;
        if ( ! isset( $wpdb ) ) { return; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $users = self::users_table();
        $broadcasts = self::broadcasts_table();
        $updates = self::updates_table();
        $sql_users = "CREATE TABLE {$users} (
            user_id bigint(20) unsigned NOT NULL,
            chat_id bigint(20) NOT NULL,
            blocked tinyint(1) NOT NULL DEFAULT 0,
            is_admin tinyint(1) NOT NULL DEFAULT 0,
            first_seen bigint(20) unsigned NOT NULL DEFAULT 0,
            last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
            searches bigint(20) unsigned NOT NULL DEFAULT 0,
            downloads bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (user_id),
            KEY blocked_last_seen (blocked,last_seen)
        ) {$charset};";
        $sql_broadcasts = "CREATE TABLE {$broadcasts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            admin_id bigint(20) unsigned NOT NULL,
            message longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            cursor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sent int(10) unsigned NOT NULL DEFAULT 0,
            failed int(10) unsigned NOT NULL DEFAULT 0,
            locked_until bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY status_locked (status,locked_until)
        ) {$charset};";
        $sql_updates = "CREATE TABLE {$updates} (
            update_id bigint(20) unsigned NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'processing',
            processed_at bigint(20) unsigned NOT NULL DEFAULT 0,
            locked_until bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (update_id),
            KEY processed_at (processed_at)
        ) {$charset};";
        dbDelta( $sql_users );
        dbDelta( $sql_broadcasts );
        dbDelta( $sql_updates );
        $users_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $users ) ) );
        $broadcasts_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $broadcasts ) ) );
        $updates_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $updates ) ) );
        if ( $users === $users_exists && $broadcasts === $broadcasts_exists && $updates === $updates_exists ) {
            update_option( 'ahangban_telegram_schema', self::SCHEMA_VERSION, false );
        }
    }

    public static function register_routes() {
        register_rest_route( self::NS, '/telegram/webhook', array(
            'methods'  => array( 'GET', 'POST' ),
            'callback' => array( __CLASS__, 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::NS, '/telegram/webhook-status', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'webhook_status' ),
            'permission_callback' => array( __CLASS__, 'check_admin' ),
        ) );
    }

    public static function check_admin( $req ) {
        return current_user_can( 'manage_options' );
    }

    /** Claim update_id atomically with a primary-key insert, so concurrent retries cannot double-send. */
    private static function claim_update( $update_id ) {
        global $wpdb;
        $table = self::updates_table();
        $now = time();
        if ( ! get_transient( 'ahangban_tg_update_cleanup' ) ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE processed_at < %d AND (status = 'done' OR locked_until < %d)", $now - ( 2 * DAY_IN_SECONDS ), $now ) );
            set_transient( 'ahangban_tg_update_cleanup', 1, DAY_IN_SECONDS );
        }

        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (update_id,status,processed_at,locked_until) VALUES (%d,'processing',%d,%d)",
            (int) $update_id, $now, $now + 120
        ) );
        if ( 1 === (int) $inserted ) { return true; }
        if ( false === $inserted ) { return new WP_Error( 'dedupe_unavailable', 'ذخیرهٔ شناسهٔ درخواست انجام نشد.', array( 'status' => 503 ) ); }

        $reclaimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'processing', processed_at = %d, locked_until = %d WHERE update_id = %d AND status = 'processing' AND locked_until < %d",
            $now, $now + 120, (int) $update_id, $now
        ) );
        if ( 1 === (int) $reclaimed ) { return true; }
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT update_id FROM {$table} WHERE update_id = %d", (int) $update_id ) );
        if ( null !== $existing ) { return false; }
        return new WP_Error( 'dedupe_unavailable', 'بررسی تکراری‌بودن درخواست انجام نشد.', array( 'status' => 503 ) );
    }

    private static function complete_update( $update_id ) {
        global $wpdb;
        $wpdb->update( self::updates_table(), array( 'status' => 'done', 'processed_at' => time(), 'locked_until' => 0 ), array( 'update_id' => (int) $update_id ), array( '%s', '%d', '%d' ), array( '%d' ) );
    }

    private static function release_update( $update_id ) {
        global $wpdb;
        $wpdb->delete( self::updates_table(), array( 'update_id' => (int) $update_id, 'status' => 'processing' ), array( '%d', '%s' ) );
    }

    public static function handle_webhook( WP_REST_Request $req ) {
        if ( 'GET' === $req->get_method() ) {
            return rest_ensure_response( array(
                'status' => 'ok',
                'message' => 'مسیر وب‌هوک آمادهٔ دریافت درخواست است.',
            ) );
        }

        // Telegram's secret header is mandatory once a secret has been configured.
        $secret = (string) $req->get_header( 'x-telegram-bot-api-secret-token' );
        $expected = AhangBan_Settings::get_bot_secret();
        if ( '' === $expected ) {
            return new WP_Error( 'secret_unavailable', 'Secret وب‌هوک ساخته نشد؛ تنظیمات افزونه را بررسی کنید.', array( 'status' => 503 ) );
        }
        if ( '' === $secret || ! hash_equals( $expected, $secret ) ) {
            return new WP_Error( 'forbidden', 'هدر امنیتی وب‌هوک نامعتبر است.', array( 'status' => 403 ) );
        }

        $body = (string) $req->get_body();
        if ( '' === $body ) { $body = (string) file_get_contents( 'php://input' ); }
        if ( strlen( $body ) > 1048576 ) {
            return new WP_Error( 'payload_too_large', 'درخواست وب‌هوک بیش از حد بزرگ است.', array( 'status' => 413 ) );
        }
        $update = json_decode( $body, true );
        $raw_update_id = is_array( $update ) && array_key_exists( 'update_id', $update ) ? $update['update_id'] : null;
        $validated_update_id = filter_var( $raw_update_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
        if ( ! is_array( $update ) || empty( $update ) || false === $validated_update_id ) {
            return new WP_Error( 'invalid_update', 'ساختار به‌روزرسانی تلگرام نامعتبر است.', array( 'status' => 400 ) );
        }

        // Atomic database claim prevents duplicate replies during concurrent Telegram retries.
        $update_id = (int) $validated_update_id;
        $claimed = self::claim_update( $update_id );
        if ( is_wp_error( $claimed ) ) { return $claimed; }
        if ( ! $claimed ) { return rest_ensure_response( array( 'ok' => true, 'duplicate' => true ) ); }

        try {
            self::process_update( $update );
            self::complete_update( $update_id );
        } catch ( Throwable $e ) {
            self::release_update( $update_id );
            $log_message = sanitize_text_field( $e->getMessage() );
            $bot_token = AhangBan_Settings::get_bot_token();
            if ( '' !== $bot_token ) { $log_message = str_replace( $bot_token, '[redacted]', $log_message ); }
            error_log( '[AhangBan Telegram] update processing failed: ' . $log_message );
            return new WP_Error( 'update_failed', 'پردازش درخواست موقتاً ناموفق بود.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'ok' => true ) );
    }

    public static function webhook_status( $req ) {
        $token = AhangBan_Settings::get_bot_token();
        if ( '' === $token ) {
            return new WP_Error( 'no_token', 'توکن ربات تنظیم نشده.', array( 'status' => 400 ) );
        }
        $resp = wp_safe_remote_get( 'https://api.telegram.org/bot' . $token . '/getWebhookInfo', array(
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => true,
        ) );
        if ( is_wp_error( $resp ) ) { return new WP_Error( 'telegram_status_unavailable', 'ارتباط امن با تلگرام برقرار نشد.', array( 'status' => 502 ) ); }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'telegram_status_unavailable', 'تلگرام وضعیت وب‌هوک را نپذیرفت.', array( 'status' => 502 ) ); }
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $data ) ) { return new WP_Error( 'telegram_status_invalid', 'پاسخ وضعیت وب‌هوک نامعتبر است.', array( 'status' => 502 ) ); }
        return rest_ensure_response( $data );
    }

    // ---------- WordPress-backed bot state ----------
    private static function touch_user( $update ) {
        global $wpdb;
        $message = isset( $update['message'] ) ? $update['message'] : ( $update['callback_query']['message'] ?? array() );
        $user = isset( $update['message']['from'] ) ? $update['message']['from'] : ( $update['callback_query']['from'] ?? array() );
        $chat = isset( $update['message']['chat'] ) ? $update['message']['chat'] : ( $message['chat'] ?? array() );
        if ( empty( $user['id'] ) || empty( $chat['id'] ) || 'private' !== ( $chat['type'] ?? '' ) ) { return; }

        $now = time();
        $is_admin = AhangBan_Settings::is_admin( (int) $user['id'] ) ? 1 : 0;
        $sql = $wpdb->prepare(
            'INSERT INTO ' . self::users_table() . ' (user_id,chat_id,is_admin,first_seen,last_seen) VALUES (%d,%d,%d,%d,%d) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),is_admin=VALUES(is_admin),last_seen=VALUES(last_seen)',
            (int) $user['id'], (int) $chat['id'], $is_admin, $now, $now
        );
        $wpdb->query( $sql );
    }

    private static function is_user_blocked( $user_id ) {
        global $wpdb;
        $blocked = $wpdb->get_var( $wpdb->prepare( 'SELECT blocked FROM ' . self::users_table() . ' WHERE user_id = %d', (int) $user_id ) );
        return 1 === (int) $blocked;
    }

    private static function set_user_blocked( $user_id, $blocked ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) { return false; }
        $now = time();
        $value = $blocked ? 1 : 0;
        $sql = $wpdb->prepare(
            'INSERT INTO ' . self::users_table() . ' (user_id,chat_id,blocked,first_seen,last_seen) VALUES (%d,%d,%d,%d,%d) ON DUPLICATE KEY UPDATE blocked=VALUES(blocked)',
            $user_id, $user_id, $value, $now, $now
        );
        return false !== $wpdb->query( $sql );
    }

    private static function increment_user_counter( $user_id, $counter ) {
        global $wpdb;
        if ( ! in_array( $counter, array( 'searches', 'downloads' ), true ) || (int) $user_id <= 0 ) { return; }
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::users_table() . ' SET ' . $counter . ' = ' . $counter . ' + 1 WHERE user_id = %d', (int) $user_id ) );
    }

    private static function user_statistics() {
        global $wpdb;
        $cutoff = time() - ( 7 * DAY_IN_SECONDS );
        $sql = $wpdb->prepare(
            'SELECT COUNT(*) AS total, SUM(blocked = 0) AS available, SUM(blocked = 1) AS blocked, SUM(last_seen >= %d) AS active_7d, SUM(searches) AS searches, SUM(downloads) AS downloads FROM ' . self::users_table(),
            $cutoff
        );
        $row = $wpdb->get_row( $sql, ARRAY_A );
        return is_array( $row ) ? $row : false;
    }

    private static function admin_state_key( $user_id ) {
        return 'ahangban_tg_admin_' . (int) $user_id;
    }

    /** Atomic one-at-a-time guard against double-clicked broadcast confirmations. */
    private static function acquire_broadcast_confirm_lock( $user_id ) {
        global $wpdb;
        $key = 'ahangban_broadcast_confirm_' . (int) $user_id;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
            $key,
            time() - 60
        ) );
        wp_cache_delete( $key, 'options' );
        return add_option( $key, time(), '', false );
    }

    private static function release_broadcast_confirm_lock( $user_id ) {
        $key = 'ahangban_broadcast_confirm_' . (int) $user_id;
        delete_option( $key );
        wp_cache_delete( $key, 'options' );
    }

    private static function text_limit( $text, $limit ) {
        $text = (string) $text;
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $text, 0, (int) $limit, 'UTF-8' ); }
        $chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $chars ) ? implode( '', array_slice( $chars, 0, (int) $limit ) ) : substr( $text, 0, (int) $limit );
    }

    private static function text_length( $text ) {
        if ( function_exists( 'mb_strlen' ) ) { return mb_strlen( (string) $text, 'UTF-8' ); }
        $chars = preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $chars ) ? count( $chars ) : strlen( (string) $text );
    }

    private static function store_search_query( $query ) {
        $token = wp_generate_password( 12, false, false );
        set_transient( 'ahangban_search_query_' . $token, (string) $query, HOUR_IN_SECONDS );
        return $token;
    }

    private static function starts_with( $text, $prefix ) {
        return 0 === strpos( (string) $text, (string) $prefix );
    }

    // ---------- Telegram API helpers ----------
    private static function api_call( $method, $params = array(), $timeout = 12 ) {
        $token = AhangBan_Settings::get_bot_token();
        if ( '' === $token ) { return new WP_Error( 'no_token', 'توکن تنظیم نشده' ); }
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $request_args = array(
            'timeout' => max( 5, (int) $timeout ),
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
        );
        // The Telegram token is embedded in the API path; never forward it through redirects.
        $resp = wp_safe_remote_post( $url, $request_args );
        if ( is_wp_error( $resp ) ) {
            $transport_error = str_replace( $token, '[redacted]', sanitize_text_field( $resp->get_error_message() ) );
            error_log( '[AhangBan Telegram] API transport failed for ' . sanitize_key( $method ) . ': ' . $transport_error );
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $json = json_decode( wp_remote_retrieve_body( $resp ), true );
        // Retry a definitive Telegram 429 once; never blindly retry an ambiguous transport timeout.
        if ( 429 === $code && is_array( $json ) && isset( $json['parameters']['retry_after'] ) ) {
            $retry_after = (int) $json['parameters']['retry_after'];
            if ( $retry_after > 0 && $retry_after <= 3 ) {
                sleep( $retry_after );
                $resp = wp_safe_remote_post( $url, $request_args );
                if ( is_wp_error( $resp ) ) { return $resp; }
                $code = (int) wp_remote_retrieve_response_code( $resp );
                $json = json_decode( wp_remote_retrieve_body( $resp ), true );
            }
        }
        if ( ! is_array( $json ) ) {
            error_log( '[AhangBan Telegram] Invalid API response for ' . sanitize_key( $method ) . ' (HTTP ' . $code . ').' );
            return new WP_Error( 'telegram_bad_response', 'پاسخ نامعتبر از تلگرام دریافت شد.' );
        }
        if ( $code >= 400 || ( isset( $json['ok'] ) && ! $json['ok'] ) ) {
            $description = isset( $json['description'] ) ? sanitize_text_field( $json['description'] ) : 'unknown error';
            $description = str_replace( $token, '[redacted]', $description );
            error_log( '[AhangBan Telegram] API ' . sanitize_key( $method ) . ' failed (HTTP ' . $code . '): ' . $description );
        }
        // A proxy/5xx response can be ambiguous: Telegram may have accepted the update before
        // the connection failed. Callers must not automatically send the same item again.
        if ( $code >= 500 || ( $code >= 300 && $code < 400 ) ) {
            return new WP_Error( 'telegram_ambiguous_response', 'پاسخ تلگرام نامطمئن است؛ برای جلوگیری از ارسال تکراری دوباره تلاش نشد.' );
        }
        return $json;
    }

    private static function send( $chat_id, $text, $kb = null, $parse = 'HTML' ) {
        $text = (string) $text;
        if ( self::text_length( $text ) > 3800 ) {
            if ( 'HTML' === $parse ) {
                $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
                $parse = null;
            }
            $text = self::text_limit( $text, 3800 );
        }
        $params = array( 'chat_id' => $chat_id, 'text' => $text, 'disable_web_page_preview' => false );
        if ( $parse ) { $params['parse_mode'] = $parse; }
        if ( $kb ) { $params['reply_markup'] = $kb; }
        return self::api_call( 'sendMessage', $params );
    }

    private static function telegram_ok( $response ) {
        return is_array( $response ) && ! empty( $response['ok'] );
    }

    private static function safe_media_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) { return false; }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) { return false; }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) { return false; }
        $host = strtolower( rtrim( trim( $parts['host'], '[]' ), '.' ) );
        if ( 'localhost' === $host || self::ends_with( $host, '.localhost' ) || self::ends_with( $host, '.local' ) || self::ends_with( $host, '.internal' ) ) { return false; }

        $addresses = array();
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $addresses[] = $host;
        } else {
            $ipv4 = @gethostbynamel( $host );
            if ( is_array( $ipv4 ) ) { $addresses = array_merge( $addresses, $ipv4 ); }
            if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
                $ipv6 = @dns_get_record( $host, DNS_AAAA );
                if ( is_array( $ipv6 ) ) {
                    foreach ( $ipv6 as $record ) { if ( ! empty( $record['ipv6'] ) ) { $addresses[] = $record['ipv6']; } }
                }
            }
        }
        if ( empty( $addresses ) ) { return false; }
        foreach ( array_unique( $addresses ) as $address ) {
            if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return false; }
        }
        if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) { return false; }
        return esc_url_raw( $url, array( 'https' ) );
    }

    private static function ends_with( $text, $suffix ) {
        $text = (string) $text;
        $suffix = (string) $suffix;
        return '' === $suffix || substr( $text, -strlen( $suffix ) ) === $suffix;
    }

    private static function media_cache_key( $url, $method ) {
        return 'ahangban_tg_file_' . substr( hash( 'sha256', $method . '|' . $url ), 0, 40 );
    }

    private static function media_method( $quality, $url ) {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( in_array( (string) $quality, array( '480', '720', '1080' ), true ) || 'mp4' === $extension || 'webm' === $extension ) { return 'sendVideo'; }
        if ( in_array( $extension, array( 'zip', 'rar', '7z' ), true ) ) { return 'sendDocument'; }
        return 'sendAudio';
    }

    private static function media_caption( $song, $quality ) {
        $title = self::esc( self::text_limit( $song['song'] ?? $song['title'] ?? 'آهنگ آهنگ‌بان', 110 ) );
        $artist = self::esc( self::text_limit( $song['artist'] ?? '', 100 ) );
        $permalink = esc_url( $song['permalink'] ?? '' );
        $caption = "🎵 <b>{$title}</b>";
        if ( '' !== $artist ) { $caption .= "\n👤 {$artist}"; }
        $caption .= "\n💿 کیفیت: " . self::esc( $quality );
        if ( $permalink ) { $caption .= "\n🔗 <a href=\"{$permalink}\">صفحهٔ آهنگ‌بان</a>"; }
        return self::text_limit( $caption, 900 );
    }

    private static function download_media_file( $url, $quality ) {
        $safe_url = self::safe_media_url( $url );
        if ( ! $safe_url ) { return new WP_Error( 'unsafe_media_url', 'نشانی فایل امن یا HTTPS نیست.' ); }

        $temp = wp_tempnam( $safe_url );
        if ( ! $temp ) { return new WP_Error( 'temp_file_failed', 'فضای موقت هاست در دسترس نیست.' ); }
        $response = wp_safe_remote_get( $safe_url, array(
            'timeout' => 18,
            'redirection' => 3,
            'stream' => true,
            'filename' => $temp,
            'limit_response_size' => self::MAX_MEDIA_BYTES + 1,
            'headers' => array( 'Accept' => 'audio/*,video/*,application/octet-stream,application/zip;q=0.9,*/*;q=0.5' ),
        ) );
        if ( is_wp_error( $response ) ) {
            @unlink( $temp );
            return new WP_Error( 'media_fetch_failed', 'هاست نتوانست فایل را از منبع دریافت کند.' );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $length = (int) wp_remote_retrieve_header( $response, 'content-length' );
        $size = file_exists( $temp ) ? (int) filesize( $temp ) : 0;
        if ( 200 !== $status || $size <= 0 || $size > self::MAX_MEDIA_BYTES || $length > self::MAX_MEDIA_BYTES ) {
            @unlink( $temp );
            return new WP_Error( 'media_size_or_status', ( $size > self::MAX_MEDIA_BYTES || $length > self::MAX_MEDIA_BYTES ) ? 'حجم فایل از سقف بارگذاری تلگرام بیشتر است.' : 'فایل دانلودی معتبر یا در دسترس نیست.' );
        }

        $content_type = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
        if ( self::starts_with( $content_type, 'text/' ) || false !== strpos( $content_type, 'json' ) || false !== strpos( $content_type, 'xml' ) ) {
            @unlink( $temp );
            return new WP_Error( 'not_a_media_file', 'نشانی به‌جای فایل، صفحهٔ وب برگرداند.' );
        }
        $path = (string) wp_parse_url( $safe_url, PHP_URL_PATH );
        $name = sanitize_file_name( wp_basename( $path ) );
        $extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( '' === $name || strlen( $name ) > 120 ) { $name = 'ahangban-' . sanitize_key( $quality ); }
        if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) {
            $name .= in_array( (string) $quality, array( '480', '720', '1080' ), true ) ? '.mp4' : '.mp3';
        }

        $mime = $content_type;
        if ( '' === $mime && function_exists( 'mime_content_type' ) ) { $mime = (string) mime_content_type( $temp ); }
        if ( '' === $mime || 'application/octet-stream' === $mime || 'binary/octet-stream' === $mime ) {
            $mime = in_array( (string) $quality, array( '480', '720', '1080' ), true ) ? 'video/mp4' : 'audio/mpeg';
        }
        $method = self::media_method( $quality, $safe_url );
        if ( self::starts_with( $mime, 'video/' ) ) { $method = 'sendVideo'; }
        if ( 'application/zip' === $mime || 'application/x-rar-compressed' === $mime || in_array( $extension, array( 'zip', 'rar', '7z' ), true ) ) { $method = 'sendDocument'; }
        if ( 'sendDocument' === $method && ! in_array( $extension, array( 'zip', 'rar', '7z' ), true ) && 'application/zip' === $mime ) { $name = pathinfo( $name, PATHINFO_FILENAME ) . '.zip'; }
        if ( 'sendVideo' === $method && ! in_array( $extension, array( 'mp4', 'mov', 'm4v', 'webm' ), true ) ) { $name = pathinfo( $name, PATHINFO_FILENAME ) . '.mp4'; }
        return array( 'path' => $temp, 'name' => $name, 'mime' => $mime, 'method' => $method, 'size' => $size );
    }

    /** Upload a bounded temp file to Telegram; cURL streams it from disk, not into PHP memory. */
    private static function telegram_upload_file( $method, $chat_id, $file, $song, $quality ) {
        if ( ! function_exists( 'curl_init' ) || ! class_exists( 'CURLFile' ) ) {
            return new WP_Error( 'curl_unavailable', 'افزونهٔ cURL هاست برای ارسال فایل فعال نیست.' );
        }
        $token = AhangBan_Settings::get_bot_token();
        $field = 'sendVideo' === $method ? 'video' : ( 'sendDocument' === $method ? 'document' : 'audio' );
        $fields = array(
            'chat_id' => (string) $chat_id,
            $field => new CURLFile( $file['path'], $file['mime'], $file['name'] ),
            'caption' => self::media_caption( $song, $quality ),
            'parse_mode' => 'HTML',
        );
        if ( 'sendAudio' === $method ) {
            $fields['title'] = self::text_limit( $song['song'] ?? $song['title'] ?? 'آهنگ آهنگ‌بان', 64 );
            $fields['performer'] = self::text_limit( $song['artist'] ?? 'آهنگ‌بان', 64 );
        } elseif ( 'sendVideo' === $method ) {
            $fields['supports_streaming'] = 'true';
        }
        $handle = curl_init( 'https://api.telegram.org/bot' . $token . '/' . $method );
        if ( false === $handle ) { return new WP_Error( 'telegram_upload_init', 'آغاز ارتباط امن برای بارگذاری فایل ممکن نشد.' ); }
        curl_setopt_array( $handle, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ) );
        if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) { curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS ); }
        $body = curl_exec( $handle );
        $error = curl_error( $handle );
        $code = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
        curl_close( $handle );
        if ( false === $body ) {
            $safe_error = str_replace( $token, '[redacted]', sanitize_text_field( $error ) );
            error_log( '[AhangBan Telegram] File upload transport failed: ' . $safe_error );
            return new WP_Error( 'telegram_upload_transport', 'ارتباط امن برای بارگذاری فایل برقرار نشد.' );
        }
        $json = json_decode( $body, true );
        if ( ! is_array( $json ) ) { return new WP_Error( 'telegram_upload_response', 'پاسخ بارگذاری فایل نامعتبر است.' ); }
        if ( $code >= 400 || empty( $json['ok'] ) ) {
            $description = str_replace( $token, '[redacted]', sanitize_text_field( $json['description'] ?? 'unknown error' ) );
            error_log( '[AhangBan Telegram] File upload rejected (HTTP ' . $code . '): ' . $description );
        }
        if ( 429 === $code || $code >= 500 || ( $code >= 300 && $code < 400 ) ) {
            return new WP_Error( 'telegram_upload_ambiguous', 'پاسخ بارگذاری تلگرام نامطمئن یا محدودشده است؛ برای جلوگیری از ارسال تکراری دوباره بارگذاری نشد.' );
        }
        return $json;
    }

    private static function send_media( $chat, $song, $quality, $user_id = 0 ) {
        $qualities = $song['qualities'] ?? array();
        $url = isset( $qualities[ $quality ] ) ? (string) $qualities[ $quality ] : '';
        if ( '' === $url ) {
            self::send( $chat, '❌ این کیفیت برای اثر موجود نیست.' );
            return;
        }
        $safe_url = self::safe_media_url( $url );
        if ( ! $safe_url ) {
            self::send( $chat, '⚠️ نشانی فایل امن یا قابل دسترس نیست؛ از صفحهٔ رسمی اثر استفاده کنید.', self::media_fallback_kb( '', $song ) );
            return;
        }
        $method = self::media_method( $quality, $safe_url );
        $caption = self::media_caption( $song, $quality );
        $action = 'sendVideo' === $method ? 'upload_video' : ( 'sendDocument' === $method ? 'upload_document' : 'upload_audio' );
        self::api_call( 'sendChatAction', array( 'chat_id' => $chat, 'action' => $action ), 5 );
        $file_field = 'sendVideo' === $method ? 'video' : ( 'sendDocument' === $method ? 'document' : 'audio' );
        $params = array( 'chat_id' => $chat, $file_field => $safe_url, 'caption' => $caption, 'parse_mode' => 'HTML' );
        if ( 'sendAudio' === $method ) {
            $params['title'] = self::text_limit( $song['song'] ?? $song['title'] ?? 'آهنگ آهنگ‌بان', 64 );
            $params['performer'] = self::text_limit( $song['artist'] ?? 'آهنگ‌بان', 64 );
        } elseif ( 'sendVideo' === $method ) {
            $params['supports_streaming'] = true;
        }

        $cache_key = self::media_cache_key( $safe_url, $method );
        $cached_id = (string) get_transient( $cache_key );
        if ( '' !== $cached_id ) {
            $cached_params = $params;
            $cached_params[ $file_field ] = $cached_id;
            $response = self::api_call( $method, $cached_params, 18 );
            if ( self::telegram_ok( $response ) ) {
                self::increment_user_counter( $user_id, 'downloads' );
                return;
            }
            if ( is_wp_error( $response ) ) {
                self::send( $chat, '⚠️ پاسخ تلگرام نامشخص است؛ برای جلوگیری از ارسال تکراری، فایل دوباره فرستاده نشد. اگر فایل نرسید، کمی بعد دوباره امتحان کنید.', self::media_fallback_kb( $safe_url, $song ) );
                return;
            }
            if ( is_array( $response ) && empty( $response['ok'] ) ) { delete_transient( $cache_key ); }
        }

        // First let Telegram fetch the URL itself. Retry with a host upload only after a
        // definitive Telegram rejection; transport/5xx errors are ambiguous and must not resend.
        $response = self::api_call( $method, $params, 18 );
        if ( self::telegram_ok( $response ) ) {
            $result = $response['result'] ?? array();
            $file = $result['audio']['file_id'] ?? ( $result['video']['file_id'] ?? ( $result['document']['file_id'] ?? '' ) );
            if ( $file ) { set_transient( $cache_key, $file, 30 * DAY_IN_SECONDS ); }
            self::increment_user_counter( $user_id, 'downloads' );
            return;
        }
        if ( is_wp_error( $response ) ) {
            self::send( $chat, '⚠️ پاسخ تلگرام نامشخص است؛ برای جلوگیری از ارسال تکراری، فایل دوباره فرستاده نشد. اگر فایل نرسید، کمی بعد دوباره امتحان کنید.', self::media_fallback_kb( $safe_url, $song ) );
            return;
        }

        $download = self::download_media_file( $safe_url, $quality );
        if ( is_wp_error( $download ) ) {
            self::send( $chat, '⚠️ ارسال فایل مستقیم ممکن نشد. از دکمهٔ امن زیر یا صفحهٔ اثر دانلود کنید.', self::media_fallback_kb( $safe_url, $song ) );
            return;
        }
        try {
            $upload_method = $download['method'];
            $response = self::telegram_upload_file( $upload_method, $chat, $download, $song, $quality );
            if ( ! self::telegram_ok( $response ) && 'sendDocument' !== $upload_method && ! is_wp_error( $response ) ) {
                // Some hosts label MP3/M4A files as generic binary. A document upload is a safe fallback.
                $response = self::telegram_upload_file( 'sendDocument', $chat, $download, $song, $quality );
                $upload_method = 'sendDocument';
            }
            if ( self::telegram_ok( $response ) ) {
                $result = $response['result'] ?? array();
                $file = $result['audio']['file_id'] ?? ( $result['video']['file_id'] ?? ( $result['document']['file_id'] ?? '' ) );
                if ( $file ) { set_transient( self::media_cache_key( $safe_url, $upload_method ), $file, 30 * DAY_IN_SECONDS ); }
                self::increment_user_counter( $user_id, 'downloads' );
                return;
            }
        } finally {
            if ( ! empty( $download['path'] ) && file_exists( $download['path'] ) ) { @unlink( $download['path'] ); }
        }
        self::send( $chat, '⚠️ تلگرام فایل را نپذیرفت. از لینک امن زیر یا صفحهٔ اثر استفاده کنید.', self::media_fallback_kb( $safe_url, $song ) );
    }

    private static function media_fallback_kb( $url, $song ) {
        $rows = array();
        if ( $url ) { $rows[] = array( array( 'text' => '🌐 دریافت فایل از منبع', 'url' => $url ) ); }
        if ( ! empty( $song['permalink'] ) ) { $rows[] = array( array( 'text' => '🔗 صفحهٔ اثر در آهنگ‌بان', 'url' => esc_url( $song['permalink'] ) ) ); }
        $rows[] = array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) );
        return array( 'inline_keyboard' => $rows );
    }

    private static function send_photo( $chat_id, $photo, $caption, $kb = null ) {
        $p = array( 'chat_id' => $chat_id, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML' );
        if ( $kb ) { $p['reply_markup'] = $kb; }
        return self::api_call( 'sendPhoto', $p );
    }

    private static function answer_cb( $id, $text = '' ) {
        return self::api_call( 'answerCallbackQuery', array( 'callback_query_id' => $id, 'text' => $text ) );
    }

    private static function edit_text( $chat_id, $mid, $text, $kb = null ) {
        $p = array( 'chat_id' => $chat_id, 'message_id' => $mid, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => false );
        if ( $kb ) { $p['reply_markup'] = $kb; }
        return self::api_call( 'editMessageText', $p );
    }

    private static function edit_caption( $chat_id, $mid, $caption, $kb = null ) {
        $p = array( 'chat_id' => $chat_id, 'message_id' => $mid, 'caption' => $caption, 'parse_mode' => 'HTML' );
        if ( $kb ) { $p['reply_markup'] = $kb; }
        return self::api_call( 'editMessageCaption', $p );
    }

    // ---------- Core logic ----------
    public static function process_update( $u ) {
        $from_id = 0;
        if ( isset( $u['message']['from']['id'] ) ) { $from_id = (int) $u['message']['from']['id']; }
        elseif ( isset( $u['callback_query']['from']['id'] ) ) { $from_id = (int) $u['callback_query']['from']['id']; }

        if ( $from_id ) {
            if ( ! AhangBan_Auth::allow_telegram_update( $from_id, 20 ) ) {
                if ( isset( $u['callback_query']['id'] ) ) { self::answer_cb( $u['callback_query']['id'], '⏳ کمی صبر کنید؛ تعداد درخواست‌ها زیاد است.' ); }
                return;
            }

            if ( self::is_user_blocked( $from_id ) ) {
                if ( isset( $u['callback_query']['id'] ) ) {
                    self::answer_cb( $u['callback_query']['id'], '⛔ دسترسی شما محدود شده است.' );
                } else {
                    $notice_key = 'ahangban_tg_block_notice_' . $from_id;
                    if ( ! get_transient( $notice_key ) ) {
                        self::send( (int) ( $u['message']['chat']['id'] ?? 0 ), '⛔ دسترسی شما به ربات محدود شده است. برای پیگیری با پشتیبانی تماس بگیرید.', null, null );
                        set_transient( $notice_key, 1, HOUR_IN_SECONDS );
                    }
                }
                return;
            }
            // Avoid DB writes for rate-limited or blocked users.
            self::touch_user( $u );
        }

        if ( isset( $u['callback_query'] ) ) {
            self::handle_callback( $u['callback_query'] );
            return;
        }
        if ( ! isset( $u['message']['text'] ) ) { return; }

        $msg = $u['message'];
        $text = trim( (string) $msg['text'] );
        $chat = (int) ( $msg['chat']['id'] ?? 0 );
        if ( ! $chat || '' === $text ) { return; }

        if ( '/' === substr( $text, 0, 1 ) ) {
            if ( $from_id > 0 ) { delete_transient( 'ahangban_tg_flow_' . $from_id ); }
            $tokens = preg_split( '/\\s+/', $text, 2 );
            $command = strtolower( explode( '@', $tokens[0] )[0] );
            $arg = isset( $tokens[1] ) ? trim( $tokens[1] ) : '';
            switch ( $command ) {
                case '/start': self::handle_start( $chat, $msg, $arg ); return;
                case '/help': self::handle_help( $chat, $from_id ); return;
                case '/ping': self::handle_ping( $chat ); return;
                case '/latest': self::handle_latest( $chat, 1 ); return;
                case '/popular': self::handle_popular( $chat, 1 ); return;
                case '/search':
                    if ( '' === $arg ) { self::send( $chat, '🔍 استفاده: /search نام آهنگ یا خواننده' ); return; }
                    self::handle_search( $chat, $arg, 1, $from_id ); return;
                case '/artist':
                    if ( '' === $arg ) { self::send( $chat, '🎤 استفاده: /artist نام خواننده' ); return; }
                    self::handle_artist_by_name( $chat, $arg, 1 ); return;
                case '/admin':
                case '/stats': self::handle_admin_menu( $chat, $from_id ); return;
                case '/health': self::handle_admin_health( $chat, $from_id ); return;
                case '/cache_clear': self::handle_admin_cache_clear( $chat, $from_id, false ); return;
                case '/block': self::handle_admin_block( $chat, $from_id, $arg, true ); return;
                case '/unblock': self::handle_admin_block( $chat, $from_id, $arg, false ); return;
                case '/broadcast': self::handle_broadcast_command( $chat, $from_id, $arg ); return;
                case '/cancel':
                    delete_transient( self::admin_state_key( $from_id ) );
                    self::send( $chat, 'انجام شد؛ عملیات مدیریتی لغو شد.', self::is_admin( $from_id ) ? self::admin_kb() : null );
                    return;
            }
        }

        if ( self::handle_admin_text_state( $chat, $text, $from_id ) ) { return; }

        $shortcuts = array(
            '🔍 جست‌وجوی آهنگ' => function() use ( $chat ) { self::send( $chat, '🎵 نام آهنگ را بنویسید؛ مثلاً: بی‌تاب محسن یگانه' ); },
            '👤 جست‌وجوی خواننده' => function() use ( $chat, $from_id ) {
                set_transient( 'ahangban_tg_flow_' . (int) $from_id, array( 'action' => 'await_artist' ), 10 * MINUTE_IN_SECONDS );
                self::send( $chat, '🎤 نام خواننده را در پیام بعدی بنویسید؛ مثلاً: معین یا گوگوش', self::home_kb() );
            },
            '🆕 جدیدترین‌ها' => function() use ( $chat ) { self::handle_latest( $chat, 1 ); },
            '🔥 محبوب‌ترین‌ها' => function() use ( $chat ) { self::handle_popular( $chat, 1 ); },
            '🎤 فهرست خوانندگان' => function() use ( $chat ) { self::handle_artists_list( $chat, 1 ); },
            'ℹ️ راهنما' => function() use ( $chat, $from_id ) { self::handle_help( $chat, $from_id ); },
            '🛠 مدیریت' => function() use ( $chat, $from_id ) { self::handle_admin_menu( $chat, $from_id ); },
        );
        if ( isset( $shortcuts[ $text ] ) ) {
            if ( '👤 جست‌وجوی خواننده' !== $text && $from_id > 0 ) { delete_transient( 'ahangban_tg_flow_' . $from_id ); }
            $shortcuts[ $text ]();
            return;
        }

        if ( $from_id > 0 && '/' !== substr( $text, 0, 1 ) ) {
            $flow_key = 'ahangban_tg_flow_' . $from_id;
            $flow = get_transient( $flow_key );
            if ( is_array( $flow ) && 'await_artist' === ( $flow['action'] ?? '' ) ) {
                delete_transient( $flow_key );
                self::handle_artist_search( $chat, $text );
                return;
            }
        }

        self::handle_search( $chat, $text, 1, $from_id );
    }

    // ---- Helpers: branding ----
    private static function welcome_text( $name = '' ) {
        $n = $name ? ' ' . self::esc( $name ) : '';
        return "سلام{$n} عزیز! 👋\n\nبه <b>ربات رسمی آهنگ‌بان</b> خوش آمدید 🎶\nاینجا جدیدترین آهنگ‌ها را جست‌وجو، گوش دهید و دانلود کنید — مستقیم از آرشیو واقعی سایت.\n\n✨ امکانات:\n• 🔍 جست‌وجو بر اساس نام آهنگ یا خواننده\n• 🎤 مرور همهٔ خوانندگان الفبایی\n• 🆕 جدیدترین و 🔥 محبوب‌ترین\n• 💿 کیفیت ۱۲۸/۳۲۰ + ویدیو\n• 📝 متن ترانه و آثار مرتبط\n\nنام آهنگ یا خواننده را بنویسید یا یکی از دکمه‌ها را بزنید.";
    }

    private static function help_text() {
        return "ℹ️ <b>راهنمای ربات آهنگ‌بان</b>\n\n🔍 <b>جست‌وجو:</b> نام آهنگ/خواننده را تایپ کن.\n🎤 <b>فهرست خوانندگان:</b> دکمهٔ «فهرست خوانندگان».\n🆕 <b>جدیدترین / محبوب:</b> آخرین یا پرشنونده‌ترین.\n🎵 <b>کارت آهنگ:</b> کاور + کیفیت‌ها + متن + صفحه سایت.\n\n📌 دستورات:\n/start — شروع\n/search <عبارت>\n/latest — جدیدترین\n/popular — محبوب‌ترین\n/artist <نام>\n/help\n/ping\n\n🔗 https://ahangban.ir";
    }

    private static function main_kb( $is_admin = false ) {
        $rows = array(
            array( array( 'text' => '🔍 جست‌وجو', 'callback_data' => 'prompt:search' ) ),
            array( array( 'text' => '🆕 جدیدترین‌ها', 'callback_data' => 'browse:latest:1' ), array( 'text' => '🔥 محبوب‌ترین‌ها', 'callback_data' => 'browse:popular:1' ) ),
            array( array( 'text' => '👤 جست‌وجوی خواننده', 'callback_data' => 'prompt:artist' ), array( 'text' => '🎤 همهٔ خوانندگان', 'callback_data' => 'artists:page:1' ) ),
            array( array( 'text' => 'ℹ️ راهنما', 'callback_data' => 'help' ) ),
        );
        if ( $is_admin ) { $rows[] = array( array( 'text' => '🛠 مدیریت ربات', 'callback_data' => 'admin:menu' ) ); }
        return array( 'inline_keyboard' => $rows );
    }

    private static function reply_kb( $is_admin = false ) {
        $keyboard = array(
            array( array( 'text' => '🔍 جست‌وجوی آهنگ' ), array( 'text' => '👤 جست‌وجوی خواننده' ) ),
            array( array( 'text' => '🆕 جدیدترین‌ها' ), array( 'text' => '🔥 محبوب‌ترین‌ها' ) ),
            array( array( 'text' => '🎤 فهرست خوانندگان' ), array( 'text' => 'ℹ️ راهنما' ) ),
        );
        if ( $is_admin ) { $keyboard[] = array( array( 'text' => '🛠 مدیریت' ) ); }
        return array( 'keyboard' => $keyboard, 'resize_keyboard' => true, 'input_field_placeholder' => 'نام آهنگ یا خواننده را بنویسید…' );
    }

    private static function home_kb() {
        return array( 'inline_keyboard' => array( array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) ) ) );
    }

    private static function esc( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }

    private static function song_caption( $song ) {
        $title = self::esc( self::text_limit( $song['title'] ?? $song['song'] ?? '—', 100 ) );
        $song_name = self::esc( self::text_limit( $song['song'] ?? '', 90 ) );
        $artist = self::esc( self::text_limit( $song['artist'] ?? '', 90 ) );
        $type = self::esc( self::text_limit( $song['type'] ?? '', 40 ) );
        $date = self::esc( self::text_limit( $song['date_jalali'] ?? '', 30 ) );
        $views = isset( $song['views'] ) ? (int) $song['views'] : 0;
        $link = esc_url( $song['permalink'] ?? '', array( 'http', 'https' ) );
        $lines = array( '🎵 <b>' . $title . '</b>' );
        if ( $song_name && $song_name !== $title ) { $lines[] = '🎶 اثر: <b>' . $song_name . '</b>'; }
        if ( $artist ) { $lines[] = '👤 خواننده: <b>' . $artist . '</b>'; }
        if ( $type ) { $lines[] = '🏷 نوع: ' . $type; }
        if ( $date ) { $lines[] = '📅 انتشار: ' . $date; }
        if ( $views ) { $lines[] = '👁 بازدید: ' . number_format_i18n( $views ); }
        if ( $link ) { $lines[] = '🔗 <a href="' . $link . '">صفحه در آهنگ‌بان</a>'; }
        return implode( "\n", $lines );
    }

    private static function artist_caption( $term, $buckets = null ) {
        $name = self::esc( self::text_limit( $term['name'] ?? '—', 80 ) );
        $count = isset( $term['count'] ) ? (int) $term['count'] : 0;
        $description = $term['description'] ?? '';
        if ( $description ) {
            $description = self::text_limit( wp_strip_all_tags( $description ), 220 );
            $description = self::esc( $description );
        }
        $lines = array( '🎤 <b>' . $name . '</b>' );
        if ( $count ) { $lines[] = '🎵 تعداد آثار: <b>' . number_format_i18n( $count ) . '</b>'; }
        if ( $buckets ) {
            $parts = array();
            foreach ( array( 'audio' => 'آهنگ', 'album' => 'آلبوم', 'video' => 'موزیک‌ویدیو', 'other' => 'سایر' ) as $key => $label ) {
                if ( ! empty( $buckets[ $key ] ) ) { $parts[] = (int) $buckets[ $key ] . ' ' . $label; }
            }
            if ( $parts ) { $lines[] = '📊 ' . implode( ' · ', $parts ); }
        }
        if ( $description ) { $lines[] = "\n" . $description; }
        $link = esc_url( $term['link'] ?? '', array( 'http', 'https' ) );
        if ( $link ) { $lines[] = "\n" . '🔗 <a href="' . $link . '">صفحه در سایت</a>'; }
        return implode( "\n", $lines );
    }

    private static function song_kb( $song, $show_dl = true ) {
        $id = (int) ($song['id'] ?? 0);
        $rows = array();
        $quals = $song['qualities'] ?? array();
        if ( $show_dl && $quals ) {
            $qrow = array();
            foreach ( array('128','320') as $lb) { if(isset($quals[$lb])){ $qrow[] = array('text'=>"⬇️ {$lb}",'callback_data'=>"dl:{$id}:{$lb}"); } }
            if($qrow){ $rows[]=$qrow; }
            foreach ( array('album128','album320') as $lb) { if(isset($quals[$lb])){ $rows[] = array( array('text'=>"💿 دانلود {$lb}",'callback_data'=>"dl:{$id}:{$lb}") ); } }
            foreach ( array('480','720','1080') as $lb) { if(isset($quals[$lb])){ $rows[] = array( array('text'=>"🎬 {$lb}p",'callback_data'=>"dl:{$id}:{$lb}") ); } }
            if( !empty($song['album_tracks']) ){ $rows[] = array( array('text'=>'💿 ترک‌های آلبوم','callback_data'=>"album:{$id}") ); }
        }
        $artists = $song['artists'] ?? array();
        foreach ( array_slice( $artists, 0, 2 ) as $artist ) {
            $artist_id = (int) ( $artist['term_id'] ?? 0 );
            if ( $artist_id > 0 ) {
                $rows[] = array( array( 'text' => '👤 ' . self::text_limit( $artist['name'] ?? 'هنرمند', 48 ), 'callback_data' => 'artist:' . $artist_id ) );
            }
        }
        if( !empty($song['lyrics']) ){ $rows[] = array( array('text'=>'📝 متن ترانه','callback_data'=>"lyrics:{$id}") ); }
        if( !empty($song['related']) ){ $rows[] = array( array('text'=>'🎧 آثار مرتبط','callback_data'=>"related:{$id}") ); }
        if( !empty($song['permalink']) ){ $rows[] = array( array('text'=>'🔗 صفحه در سایت','url'=>$song['permalink']) ); }
        $rows[] = array( array( 'text' => '🔍 جست‌وجوی جدید', 'callback_data' => 'prompt:search' ), array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) );
        return array('inline_keyboard'=>$rows);
    }

    private static function is_valid_query( $text ) {
        $text = trim( (string) $text );
        $length = self::text_length( $text );
        if ( '' === $text || $length < 2 || $length > 100 ) { return false; }
        if ( preg_match( '~https?://~i', $text ) ) { return false; }
        return true;
    }

    // ---- Handlers ----
    private static function handle_start( $chat, $msg, $payload = '' ) {
        $payload = trim( (string) $payload );
        if ( preg_match( '/^song_([1-9][0-9]*)$/', $payload, $match ) ) {
            // Telegram deep link from a channel opens the exact real WordPress item immediately.
            self::handle_song_card( $chat, (int) $match[1] );
            return;
        }
        $name = $msg['from']['first_name'] ?? '';
        $user_id = (int) ( $msg['from']['id'] ?? 0 );
        $is_admin = AhangBan_Settings::is_admin( $user_id );
        // A single inline menu is clearer than sending a second, duplicate reply-keyboard message.
        self::send( $chat, self::welcome_text( $name ), self::main_kb( $is_admin ) );
    }

    private static function handle_song_card( $chat, $song_id ) {
        $song = AhangBan_Search::get_single( (int) $song_id );
        if ( ! $song ) {
            self::send( $chat, '❌ این اثر دیگر منتشرشده نیست یا در سایت پیدا نشد.', self::main_kb(), null );
            return;
        }
        $caption = self::song_caption( $song );
        $keyboard = self::song_kb( $song );
        $photo_result = self::send_card_photo( $chat, $song, $caption, $keyboard );
        if ( 'sent' === $photo_result['status'] || 'unknown' === $photo_result['status'] ) { return; }
        self::send( $chat, $caption, $keyboard );
    }

    private static function handle_help( $chat, $user_id = 0 ) {
        self::send( $chat, self::help_text(), self::main_kb( AhangBan_Settings::is_admin( $user_id ) ) );
    }

    private static function handle_ping( $chat ) {
        self::send( $chat, '✅ ربات آهنگ‌بان فعال است و آمادهٔ دریافت جست‌وجوست.' );
    }

    private static function handle_search( $chat, $q, $page, $user_id = 0 ) {
        $q = trim( (string) $q );
        if ( ! self::is_valid_query( $q ) ) {
            self::send( $chat, '⚠️ عبارت نامعتبر است؛ ۲ تا ۱۰۰ نویسه وارد کنید و لینک نفرستید.', null, null );
            return;
        }
        $page = max( 1, min( 1000, (int) $page ) );
        $data = AhangBan_Search::query_posts( $q, '', $page, 5 );
        if ( is_wp_error( $data ) || ! is_array( $data ) ) {
            self::send( $chat, '❌ جست‌وجو موقتاً انجام نشد؛ کمی بعد دوباره امتحان کنید.', self::home_kb(), null );
            return;
        }
        if ( 1 === $page ) { self::increment_user_counter( $user_id, 'searches' ); }
        $results = $data['results'] ?? array();
        $total = (int) ( $data['total'] ?? 0 );
        if ( empty( $results ) ) {
            $message = "😕 نتیجه‌ای برای «" . self::esc( self::text_limit( $q, 100 ) ) . "» یافت نشد.\n\nپیشنهاد:\n• املای نام را بررسی کنید (ی/ي، ک/ك و نیم‌فاصله نرمال می‌شوند)\n• بخشی از نام آهنگ یا خواننده را جست‌وجو کنید\n• از فهرست خوانندگان کمک بگیرید";
            self::send( $chat, $message, self::home_kb() );
            return;
        }

        $per_page = max( 1, (int) ( $data['per_page'] ?? 5 ) );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( $page > $pages ) { self::send( $chat, 'این صفحه وجود ندارد؛ دوباره جست‌وجو کنید.', self::home_kb(), null ); return; }
        $header = '🔍 نتایج «<b>' . self::esc( self::text_limit( $q, 100 ) ) . '</b>» — ' . number_format_i18n( $total ) . " مورد (صفحه {$page}/{$pages})";
        $lines = array( $header, '' );
        $start_index = ( $page - 1 ) * $per_page;
        foreach ( $results as $index => $result ) {
            $number = $start_index + $index + 1;
            $title = self::esc( self::text_limit( $result['song'] ?? $result['title'] ?? '—', 70 ) );
            $artist = self::esc( self::text_limit( $result['artist'] ?? '', 50 ) );
            $type = self::esc( self::text_limit( $result['type'] ?? '', 24 ) );
            $lines[] = $number . '. <b>' . $title . '</b>' . ( $artist ? ' — ' . $artist : '' ) . ( $type ? ' <i>(' . $type . ')</i>' : '' );
        }

        $rows = array();
        foreach ( $results as $result ) {
            $song_id = (int) ( $result['id'] ?? 0 );
            if ( $song_id <= 0 ) { continue; }
            $title = self::text_limit( $result['song'] ?? $result['title'] ?? '', 28 );
            $artist = self::text_limit( $result['artist'] ?? '', 20 );
            $label = $artist ? $title . ' — ' . $artist : $title;
            $rows[] = array( array( 'text' => '🎵 ' . self::text_limit( $label, 58 ), 'callback_data' => 'song:' . $song_id ) );
        }
        $navigation = array();
        $query_token = $pages > 1 ? self::store_search_query( $q ) : '';
        if ( $page > 1 ) { $navigation[] = array( 'text' => '◀️ قبلی', 'callback_data' => 'search:' . ( $page - 1 ) . ':' . $query_token ); }
        $navigation[] = array( 'text' => $page . '/' . $pages, 'callback_data' => 'noop' );
        if ( $page < $pages ) { $navigation[] = array( 'text' => 'بعدی ▶️', 'callback_data' => 'search:' . ( $page + 1 ) . ':' . $query_token ); }
        $rows[] = $navigation;
        $rows[] = array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) );
        self::send( $chat, implode( "\n", $lines ), array( 'inline_keyboard' => $rows ) );
    }

    private static function handle_latest( $chat, $page ) {
        $page = max( 1, min( 1000, (int) $page ) );
        $data = AhangBan_Search::get_latest( $page, 5 );
        self::send_browse( $chat, $data, $page, 'latest', '🆕 جدیدترین آثار' );
    }

    private static function handle_popular( $chat, $page ) {
        $page = max( 1, min( 1000, (int) $page ) );
        $data = AhangBan_Search::get_popular( $page, 5 );
        self::send_browse( $chat, $data, $page, 'popular', '🔥 محبوب‌ترین‌های ماه' );
    }

    private static function send_browse( $chat, $data, $page, $kind, $title ) {
        if ( is_wp_error( $data ) || ! is_array( $data ) ) { self::send( $chat, '❌ فهرست آثار فعلاً در دسترس نیست.', self::home_kb(), null ); return; }
        $results = $data['results'] ?? array();
        $total = (int) ( $data['total'] ?? count( $results ) );
        $per_page = max( 1, (int) ( $data['per_page'] ?? 5 ) );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( empty( $results ) ) { self::send( $chat, 'فعلاً اثری برای نمایش پیدا نشد.', self::home_kb() ); return; }
        if ( $page > $pages ) { self::send( $chat, 'این صفحه وجود ندارد.', self::home_kb(), null ); return; }

        $lines = array( '<b>' . self::esc( $title ) . '</b> — صفحه ' . $page . ' از ' . $pages, '' );
        $start_index = ( $page - 1 ) * $per_page;
        foreach ( $results as $index => $result ) {
            $number = $start_index + $index + 1;
            $song_title = self::esc( self::text_limit( $result['song'] ?? $result['title'] ?? '—', 65 ) );
            $artist = self::esc( self::text_limit( $result['artist'] ?? '', 45 ) );
            $type = self::esc( self::text_limit( $result['type'] ?? '', 20 ) );
            $lines[] = $number . '. <b>' . $song_title . '</b>' . ( $artist ? ' — ' . $artist : '' ) . ( $type ? ' <i>(' . $type . ')</i>' : '' );
        }
        $rows = array();
        foreach ( $results as $result ) {
            $song_id = (int) ( $result['id'] ?? 0 );
            if ( $song_id <= 0 ) { continue; }
            $name = self::text_limit( $result['song'] ?? $result['title'] ?? '', 28 );
            $artist = self::text_limit( $result['artist'] ?? '', 20 );
            $label = $artist ? $name . ' — ' . $artist : $name;
            $rows[] = array( array( 'text' => '🎵 ' . self::text_limit( $label, 55 ), 'callback_data' => 'song:' . $song_id ) );
        }
        $navigation = array();
        if ( $page > 1 ) { $navigation[] = array( 'text' => '◀️ قبلی', 'callback_data' => 'browse:' . $kind . ':' . ( $page - 1 ) ); }
        $navigation[] = array( 'text' => $page . '/' . $pages, 'callback_data' => 'noop' );
        if ( $page < $pages ) { $navigation[] = array( 'text' => 'بعدی ▶️', 'callback_data' => 'browse:' . $kind . ':' . ( $page + 1 ) ); }
        $rows[] = $navigation;
        $rows[] = array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) );
        self::send( $chat, implode( "\n", $lines ), array( 'inline_keyboard' => $rows ) );
    }

    private static function handle_artists_list( $chat, $page, $letter = '', $q = '' ) {
        $page = max( 1, min( 1000, (int) $page ) );
        $data = AhangBan_Search::get_artists( array( 'q' => $q, 'letter' => $letter, 'sort' => 'name', 'page' => $page, 'per_page' => 10 ) );
        if ( is_wp_error( $data ) || ! is_array( $data ) ) {
            self::send( $chat, '❌ فهرست خوانندگان فعلاً در دسترس نیست.', self::home_kb(), null );
            return;
        }
        $results = $data['results'] ?? array();
        $total = (int) ( $data['total'] ?? 0 );
        $per_page = max( 1, (int) ( $data['per_page'] ?? 10 ) );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( empty( $results ) ) { self::send( $chat, '😕 خواننده‌ای برای این فیلتر پیدا نشد.', self::home_kb() ); return; }
        $header = '🎤 <b>فهرست خوانندگان</b> — ' . number_format_i18n( $total ) . ' هنرمند';
        if ( $letter ) { $header .= ' (حرف «' . self::esc( $letter ) . '»)'; }
        $lines = array( $header, 'صفحه ' . $page . ' از ' . $pages, '' );
        $rows = array();
        foreach ( $results as $item ) {
            $artist_id = (int) ( $item['term_id'] ?? 0 );
            $name = self::text_limit( $item['name'] ?? 'هنرمند', 45 );
            $count = (int) ( $item['count'] ?? 0 );
            $lines[] = '• <b>' . self::esc( $name ) . '</b> — ' . number_format_i18n( $count ) . ' اثر';
            if ( $artist_id > 0 ) {
                $rows[] = array( array( 'text' => '🎤 ' . $name . ' (' . $count . ')', 'callback_data' => 'artist:' . $artist_id ) );
            }
        }

        if ( '' === $letter && '' === $q ) {
            $letters = preg_split( '//u', 'ابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی', -1, PREG_SPLIT_NO_EMPTY );
            $letters = is_array( $letters ) ? $letters : array();
            for ( $offset = 0; $offset < count( $letters ); $offset += 6 ) {
                $row = array();
                foreach ( array_slice( $letters, $offset, 6 ) as $character ) {
                    $row[] = array( 'text' => $character, 'callback_data' => 'artists:letter:' . $character );
                }
                if ( $row ) { $rows[] = $row; }
            }
        }

        $navigation = array();
        if ( $page > 1 ) { $navigation[] = array( 'text' => '◀️ قبلی', 'callback_data' => 'artists:page:' . ( $page - 1 ) . ( $letter ? ':' . $letter : '' ) ); }
        $navigation[] = array( 'text' => $page . '/' . $pages, 'callback_data' => 'noop' );
        if ( $page < $pages ) { $navigation[] = array( 'text' => 'بعدی ▶️', 'callback_data' => 'artists:page:' . ( $page + 1 ) . ( $letter ? ':' . $letter : '' ) ); }
        $rows[] = $navigation;
        $rows[] = array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) );
        self::send( $chat, implode( "\n", $lines ), array( 'inline_keyboard' => $rows ) );
    }

    private static function handle_artist_search( $chat, $name ) {
        $name = trim( (string) $name );
        if ( ! self::is_valid_query( $name ) ) {
            self::send( $chat, '⚠️ نام خواننده باید بین ۲ تا ۱۰۰ نویسه باشد.', self::home_kb(), null );
            return;
        }
        $list = AhangBan_Search::get_artists( array( 'q' => $name, 'page' => 1, 'per_page' => 8, 'sort' => 'name' ) );
        if ( is_wp_error( $list ) || empty( $list['results'] ) ) {
            self::send( $chat, '😕 خواننده‌ای با این نام پیدا نشد؛ نام دیگری را امتحان کنید.', self::home_kb() );
            return;
        }
        $rows = array();
        $lines = array( '🎤 <b>خوانندگان پیدا‌شده برای «' . self::esc( self::text_limit( $name, 100 ) ) . '»</b>', '' );
        foreach ( array_slice( $list['results'], 0, 8 ) as $artist ) {
            $term_id = (int) ( $artist['term_id'] ?? 0 );
            $artist_name = self::text_limit( $artist['name'] ?? 'هنرمند', 55 );
            $count = (int) ( $artist['count'] ?? 0 );
            $lines[] = '• <b>' . self::esc( $artist_name ) . '</b> — ' . number_format_i18n( $count ) . ' اثر';
            if ( $term_id > 0 ) { $rows[] = array( array( 'text' => '🎤 ' . $artist_name, 'callback_data' => 'artist:' . $term_id ) ); }
        }
        $rows[] = array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) );
        self::send( $chat, implode( "\n", $lines ), array( 'inline_keyboard' => $rows ) );
    }

    private static function handle_artist_by_name( $chat, $name, $page ) {
        $list = AhangBan_Search::get_artists( array( 'q' => $name, 'page' => 1, 'per_page' => 5, 'sort' => 'name' ) );
        if ( is_wp_error( $list ) || empty( $list['results'] ) ) {
            self::send( $chat, '😕 خواننده‌ای با این نام پیدا نشد.', self::home_kb() );
            return;
        }
        $first = $list['results'][0];
        $identifier = (int) ( $first['term_id'] ?? 0 );
        if ( $identifier <= 0 ) { self::send( $chat, '❌ اطلاعات خواننده کامل نیست.', self::home_kb(), null ); return; }
        self::handle_artist( $chat, $identifier, $page, 'new' );
    }

    private static function handle_artist( $chat, $identifier, $page, $sort ) {
        $page = max( 1, min( 1000, (int) $page ) );
        $sort = in_array( $sort, array( 'new', 'old' ), true ) ? $sort : 'new';
        $data = AhangBan_Search::get_artist_detail( $identifier, $page, 6, $sort );
        if ( is_wp_error( $data ) || ! is_array( $data ) ) {
            self::send( $chat, '❌ صفحهٔ خواننده فعلاً در دسترس نیست.', self::home_kb(), null );
            return;
        }
        $term = $data['term'] ?? array();
        $term_id = (int) ( $term['term_id'] ?? ( is_numeric( $identifier ) ? $identifier : 0 ) );
        $buckets = $data['buckets'] ?? array();
        $results = $data['results'] ?? array();
        $total = (int) ( $data['total'] ?? 0 );
        $per_page = max( 1, (int) ( $data['per_page'] ?? 6 ) );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( $page > $pages ) { self::send( $chat, 'این صفحه از آثار وجود ندارد.', self::home_kb(), null ); return; }

        $caption = self::artist_caption( $term, $buckets );
        if ( $results ) {
            $caption .= "\n\n🎵 <b>آثار (صفحه {$page} از {$pages}):</b>";
            $start_index = ( $page - 1 ) * $per_page;
            foreach ( $results as $index => $song ) {
                $number = $start_index + $index + 1;
                $title = self::esc( self::text_limit( $song['song'] ?? $song['title'] ?? '—', 60 ) );
                $artist = self::esc( self::text_limit( $song['artist'] ?? '', 40 ) );
                $type = self::esc( self::text_limit( $song['type'] ?? '', 20 ) );
                $caption .= "\n{$number}. <b>{$title}</b>" . ( $artist ? ' — ' . $artist : '' ) . ( $type ? ' <i>(' . $type . ')</i>' : '' );
            }
        } else {
            $caption .= "\n\n😕 هنوز اثری ثبت نشده است.";
        }
        if ( self::text_length( $caption ) > 3800 ) { $caption = self::text_limit( $caption, 3800 ); }

        $rows = array();
        foreach ( $results as $song ) {
            $song_id = (int) ( $song['id'] ?? 0 );
            if ( $song_id <= 0 ) { continue; }
            $title = self::text_limit( $song['song'] ?? $song['title'] ?? '', 50 );
            $rows[] = array( array( 'text' => '🎵 ' . $title, 'callback_data' => 'song:' . $song_id ) );
        }
        if ( $term_id > 0 ) {
            $rows[] = array(
                array( 'text' => '🕐 جدیدترین' . ( 'new' === $sort ? ' ✅' : '' ), 'callback_data' => 'artist:' . $term_id . ':1:new' ),
                array( 'text' => '📅 قدیمی‌ترین' . ( 'old' === $sort ? ' ✅' : '' ), 'callback_data' => 'artist:' . $term_id . ':1:old' ),
            );
        }
        $navigation = array();
        if ( $page > 1 ) { $navigation[] = array( 'text' => '◀️ قبلی', 'callback_data' => 'artist:' . $term_id . ':' . ( $page - 1 ) . ':' . $sort ); }
        $navigation[] = array( 'text' => $page . '/' . $pages, 'callback_data' => 'noop' );
        if ( $page < $pages ) { $navigation[] = array( 'text' => 'بعدی ▶️', 'callback_data' => 'artist:' . $term_id . ':' . ( $page + 1 ) . ':' . $sort ); }
        if ( $navigation ) { $rows[] = $navigation; }
        if ( ! empty( $term['link'] ) ) {
            $link = esc_url( $term['link'], array( 'http', 'https' ) );
            if ( $link ) { $rows[] = array( array( 'text' => '🔗 صفحه در سایت', 'url' => $link ) ); }
        }
        $rows[] = array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) );
        $keyboard = array( 'inline_keyboard' => $rows );
        if ( 1 === $page ) {
            $photo_result = self::send_card_photo( $chat, $term, $caption, $keyboard );
            if ( 'sent' === $photo_result['status'] || 'unknown' === $photo_result['status'] ) { return; }
        }
        self::send( $chat, $caption, $keyboard );
    }

    private static function is_admin( $user_id ) {
        return AhangBan_Settings::is_admin( (int) $user_id );
    }

    private static function admin_kb() {
        return array( 'inline_keyboard' => array(
            array( array( 'text' => '📊 آمار ربات', 'callback_data' => 'admin:stats' ), array( 'text' => '🩺 سلامت سایت', 'callback_data' => 'admin:health' ) ),
            array( array( 'text' => '🧹 پاک‌سازی کش', 'callback_data' => 'admin:cache_confirm' ) ),
            array( array( 'text' => '⛔ مدیریت کاربران', 'callback_data' => 'admin:users' ), array( 'text' => '📢 پیام همگانی', 'callback_data' => 'admin:broadcast' ) ),
            array( array( 'text' => '📣 انتشار پست در کانال', 'callback_data' => 'admin:channel_publish' ) ),
            array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) ),
        ) );
    }

    private static function admin_menu_text() {
        $stats = self::user_statistics();
        $available = is_array( $stats );
        $total = $available ? number_format_i18n( (int) ( $stats['total'] ?? 0 ) ) : '—';
        $active = $available ? number_format_i18n( (int) ( $stats['active_7d'] ?? 0 ) ) : '—';
        $blocked = $available ? number_format_i18n( (int) ( $stats['blocked'] ?? 0 ) ) : '—';
        $searches = $available ? number_format_i18n( (int) ( $stats['searches'] ?? 0 ) ) : '—';
        $downloads = $available ? number_format_i18n( (int) ( $stats['downloads'] ?? 0 ) ) : '—';
        $posts = wp_count_posts( 'post' );
        $published = ( $posts && isset( $posts->publish ) ) ? number_format_i18n( (int) $posts->publish ) : '—';
        $channel = AhangBan_Settings::get_channel_target();
        $channel_status = $channel
            ? self::esc( $channel ) . ( AhangBan_Settings::is_channel_auto_post_enabled() ? ' · خودکار روشن' : ' · خودکار خاموش' )
            : 'تنظیم نشده';
        $state_note = $available ? 'آمار از زمان فعال‌شدن افزونه در همین دیتابیس وردپرس ثبت می‌شود.' : '⚠️ جدول آمار در دسترس نیست؛ مجوز ساخت جدول دیتابیس را بررسی کنید.';
        return "🛠 <b>پنل مدیریت آهنگ‌بان</b>\n\n👥 کاربران ثبت‌شده: <b>{$total}</b>\n🟢 فعال در ۷ روز اخیر: <b>{$active}</b>\n⛔ مسدود: <b>{$blocked}</b>\n🔍 جست‌وجوهای ثبت‌شده: <b>{$searches}</b>\n⬇️ ارسال‌های موفق فایل: <b>{$downloads}</b>\n🎵 آثار منتشرشده: <b>{$published}</b>\n📣 کانال: <b>{$channel_status}</b>\n\n{$state_note}";
    }

    private static function handle_admin_menu( $chat, $from_id ) {
        if ( ! self::is_admin( $from_id ) ) { self::send( $chat, '⛔ این بخش فقط برای ادمین مجاز است.', null, null ); return; }
        self::send( $chat, self::admin_menu_text(), self::admin_kb() );
    }

    private static function handle_admin_health( $chat, $from_id, $message_id = 0 ) {
        if ( ! self::is_admin( $from_id ) ) { self::send( $chat, '⛔ دسترسی غیرمجاز.', null, null ); return; }
        $taxonomy = AhangBan_Search::get_taxonomy();
        $posts = wp_count_posts( 'post' );
        $published = ( $posts && isset( $posts->publish ) ) ? (int) $posts->publish : 0;
        $text = "🩺 <b>سلامت آهنگ‌بان</b>\n\n✅ وب‌هوک PHP فعال\n🔧 وردپرس: " . self::esc( get_bloginfo( 'version' ) ) . "\n🐘 PHP: " . self::esc( PHP_VERSION ) . "\n🎤 تاکسونومی: <code>" . self::esc( $taxonomy ) . "</code> — " . ( taxonomy_exists( $taxonomy ) ? 'فعال' : 'ثبت‌نشده' ) . "\n🎵 آثار منتشرشده: " . number_format_i18n( $published ) . "\n🗂 نسخهٔ کش: " . (int) AhangBan_Cache::get_version();
        if ( $message_id ) { self::edit_text( $chat, $message_id, $text, self::admin_kb() ); }
        else { self::send( $chat, $text, self::admin_kb() ); }
    }

    private static function handle_admin_cache_clear( $chat, $from_id, $confirm = false, $message_id = 0 ) {
        if ( ! self::is_admin( $from_id ) ) { self::send( $chat, '⛔ دسترسی غیرمجاز.', null, null ); return; }
        if ( ! $confirm ) {
            $keyboard = array( 'inline_keyboard' => array(
                array( array( 'text' => '✅ بله، پاک شود', 'callback_data' => 'admin:cache_clear' ), array( 'text' => 'انصراف', 'callback_data' => 'admin:menu' ) ),
            ) );
            $text = '🧹 کش نتایج و اطلاعات آهنگ‌بان پاک شود؟';
            if ( $message_id ) { self::edit_text( $chat, $message_id, $text, $keyboard ); }
            else { self::send( $chat, $text, $keyboard ); }
            return;
        }
        AhangBan_Cache::clear_all();
        $text = '✅ کش افزونه پاک شد؛ داده‌های وردپرس تغییر نکردند.';
        if ( $message_id ) { self::edit_text( $chat, $message_id, $text, self::admin_kb() ); }
        else { self::send( $chat, $text, self::admin_kb() ); }
    }

    private static function handle_admin_block( $chat, $from_id, $arg, $block ) {
        if ( ! self::is_admin( $from_id ) ) { self::send( $chat, '⛔ دسترسی غیرمجاز.', null, null ); return; }
        $target = trim( (string) $arg );
        if ( ! ctype_digit( $target ) || (int) $target <= 0 ) {
            self::send( $chat, $block ? 'استفاده: /block <شناسهٔ عددی کاربر>' : 'استفاده: /unblock <شناسهٔ عددی کاربر>', null, null );
            return;
        }
        $target = (int) $target;
        if ( $block && self::is_admin( $target ) ) { self::send( $chat, '⚠️ ادمین را نمی‌توان با این دستور مسدود کرد.', null, null ); return; }
        if ( ! self::set_user_blocked( $target, $block ) ) { self::send( $chat, '❌ ذخیرهٔ تغییر در پایگاه‌داده انجام نشد.', null, null ); return; }
        self::send( $chat, $block ? "⛔ کاربر <code>{$target}</code> مسدود شد." : "✅ کاربر <code>{$target}</code> از مسدودی خارج شد.", self::admin_kb() );
    }

    private static function broadcast_preview_kb() {
        return array( 'inline_keyboard' => array(
            array( array( 'text' => '📢 تأیید ارسال', 'callback_data' => 'admin:broadcast_confirm' ), array( 'text' => 'لغو', 'callback_data' => 'admin:broadcast_cancel' ) ),
        ) );
    }

    private static function start_broadcast( $chat, $from_id ) {
        set_transient( self::admin_state_key( $from_id ), array( 'action' => 'await_broadcast' ), 10 * MINUTE_IN_SECONDS );
        self::send( $chat, '📢 متن پیام همگانی را در پیام بعدی بفرستید. پیام به کاربران خصوصی‌ای می‌رسد که قبلاً ربات را شروع کرده‌اند.\nبرای لغو بنویسید /cancel.', self::admin_kb() );
    }

    private static function handle_broadcast_command( $chat, $from_id, $text ) {
        if ( ! self::is_admin( $from_id ) ) { self::send( $chat, '⛔ دسترسی غیرمجاز.', null, null ); return; }
        if ( '' === trim( (string) $text ) ) { self::start_broadcast( $chat, $from_id ); return; }
        self::set_broadcast_preview( $chat, $from_id, $text );
    }

    private static function handle_admin_text_state( $chat, $text, $from_id ) {
        if ( ! self::is_admin( $from_id ) ) { return false; }
        $state = get_transient( self::admin_state_key( $from_id ) );
        if ( ! is_array( $state ) ) { return false; }
        if ( 'await_channel_publish_search' === ( $state['action'] ?? '' ) ) {
            delete_transient( self::admin_state_key( $from_id ) );
            self::handle_channel_publish_search( $chat, $from_id, $text );
            return true;
        }
        if ( 'await_broadcast' !== ( $state['action'] ?? '' ) ) { return false; }
        self::set_broadcast_preview( $chat, $from_id, $text );
        return true;
    }

    private static function set_broadcast_preview( $chat, $from_id, $text ) {
        $text = trim( sanitize_textarea_field( (string) $text ) );
        if ( '' === $text ) { self::send( $chat, 'متن پیام خالی است؛ دوباره بنویسید یا /cancel را بزنید.', null, null ); return; }
        $text = self::text_limit( $text, 3500 );
        set_transient( self::admin_state_key( $from_id ), array( 'action' => 'pending_broadcast', 'text' => $text ), 10 * MINUTE_IN_SECONDS );
        $preview = self::text_limit( $text, 2200 );
        self::send( $chat, "📢 <b>پیش‌نمایش پیام همگانی</b>\n\n" . self::esc( $preview ) . "\n\nپیام به کاربران مسدودشده ارسال نمی‌شود. ارسال فقط بعد از تأیید شما شروع خواهد شد.", self::broadcast_preview_kb() );
    }

    private static function queue_broadcast( $chat, $message_id, $callback_id, $from_id ) {
        global $wpdb;
        if ( ! self::is_admin( $from_id ) ) { self::answer_cb( $callback_id, '⛔ دسترسی غیرمجاز.' ); return; }
        if ( ! self::acquire_broadcast_confirm_lock( $from_id ) ) {
            self::answer_cb( $callback_id, 'این تأیید قبلاً دریافت شده است؛ از ایجاد ارسال تکراری جلوگیری شد.' );
            return;
        }

        try {
            $state = get_transient( self::admin_state_key( $from_id ) );
            if ( ! is_array( $state ) || 'pending_broadcast' !== ( $state['action'] ?? '' ) || empty( $state['text'] ) ) {
                self::answer_cb( $callback_id, 'پیش‌نمایش منقضی شده؛ دوباره /broadcast را بزنید.' );
                return;
            }
            $inserted = $wpdb->insert( self::broadcasts_table(), array(
                'admin_id' => (int) $from_id,
                'message' => (string) $state['text'],
                'status' => 'queued',
                'created_at' => time(),
            ), array( '%d', '%s', '%s', '%d' ) );
            if ( false === $inserted ) {
                self::answer_cb( $callback_id, 'ذخیرهٔ کار ارسال ناموفق بود.' );
                return;
            }

            $job_id = (int) $wpdb->insert_id;
            delete_transient( self::admin_state_key( $from_id ) );
            $scheduled = wp_schedule_single_event( time() + 10, 'ahangban_bot_process_broadcast', array( $job_id ) );
            if ( is_wp_error( $scheduled ) || ( false === $scheduled && ! wp_next_scheduled( 'ahangban_bot_process_broadcast', array( $job_id ) ) ) ) {
                $wpdb->update( self::broadcasts_table(), array( 'status' => 'failed' ), array( 'id' => $job_id ), array( '%s' ), array( '%d' ) );
                self::answer_cb( $callback_id, 'صف ذخیره شد، اما زمان‌بندی WP-Cron ناموفق بود؛ چیزی ارسال نشده است.' );
                self::edit_text( $chat, (int) $message_id, '⚠️ زمان‌بندی پیام همگانی شکست خورد. WP-Cron را بررسی و پیام را دوباره ثبت کنید.', self::admin_kb() );
                return;
            }

            self::answer_cb( $callback_id, 'ارسال در صف قرار گرفت.' );
            self::edit_text( $chat, (int) $message_id, '⏳ پیام در صف قرار گرفت؛ ارسال دسته‌ای با WP-Cron ادامه پیدا می‌کند. ممکن است با توجه به ترافیک سایت چند دقیقه زمان ببرد.', self::admin_kb() );
        } finally {
            self::release_broadcast_confirm_lock( $from_id );
        }
    }

    public static function process_broadcast_batch( $job_id ) {
        global $wpdb;
        $job_id = (int) $job_id;
        if ( $job_id <= 0 ) { return; }
        $jobs = self::broadcasts_table();
        $now = time();
        $job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs} WHERE id = %d AND status = 'queued'", $job_id ) );
        if ( ! $job ) { return; }
        $locked = $wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET locked_until = %d WHERE id = %d AND status = 'queued' AND locked_until < %d", $now + 90, $job_id, $now ) );
        if ( 1 !== (int) $locked ) { return; }

        $users = self::users_table();
        $recipients = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id,chat_id FROM {$users} WHERE blocked = 0 AND chat_id <> 0 AND user_id > %d ORDER BY user_id ASC LIMIT %d",
            (int) $job->cursor_user_id, self::BROADCAST_BATCH
        ), ARRAY_A );
        if ( ! is_array( $recipients ) || '' !== (string) $wpdb->last_error ) {
            // A failed recipient query is not an empty audience; unlock and retry instead of
            // silently marking the broadcast complete.
            $wpdb->update( $jobs, array( 'locked_until' => 0 ), array( 'id' => $job_id ), array( '%d' ), array( '%d' ) );
            $args = array( $job_id );
            if ( ! wp_next_scheduled( 'ahangban_bot_process_broadcast', $args ) ) {
                wp_schedule_single_event( time() + 60, 'ahangban_bot_process_broadcast', $args );
            }
            error_log( '[AhangBan Telegram] Broadcast recipient query failed; job ' . $job_id . ' will retry.' );
            return;
        }
        if ( empty( $recipients ) ) {
            $finished = $wpdb->update( $jobs, array( 'status' => 'done', 'locked_until' => 0 ), array( 'id' => $job_id ), array( '%s', '%d' ), array( '%d' ) );
            if ( false === $finished ) {
                error_log( '[AhangBan Telegram] Could not finalize broadcast job ' . $job_id . '.' );
                return;
            }
            self::send( (int) $job->admin_id, '✅ ارسال همگانی پایان یافت. موفق: ' . (int) $job->sent . ' | ناموفق: ' . (int) $job->failed, self::admin_kb(), null );
            return;
        }

        $cursor = (int) $job->cursor_user_id;
        $sent = 0;
        $failed = 0;
        foreach ( $recipients as $recipient ) {
            $cursor = max( $cursor, (int) $recipient['user_id'] );
            $response = self::api_call( 'sendMessage', array(
                'chat_id' => (int) $recipient['chat_id'],
                'text' => (string) $job->message,
                'disable_web_page_preview' => true,
            ), 5 );
            if ( self::telegram_ok( $response ) ) { $sent++; } else { $failed++; }
        }

        $done = count( $recipients ) < self::BROADCAST_BATCH;
        $saved = $wpdb->update( $jobs, array(
            'cursor_user_id' => $cursor,
            'sent' => (int) $job->sent + $sent,
            'failed' => (int) $job->failed + $failed,
            'status' => $done ? 'done' : 'queued',
            'locked_until' => 0,
        ), array( 'id' => $job_id ), array( '%d', '%d', '%d', '%s', '%d' ), array( '%d' ) );
        if ( false === $saved ) {
            // The batch was already attempted; do not retry blindly and duplicate deliveries.
            error_log( '[AhangBan Telegram] Could not save progress for broadcast job ' . $job_id . '; automatic retry stopped.' );
            self::send( (int) $job->admin_id, '⚠️ ذخیرهٔ پیشرفت پیام همگانی ناموفق بود؛ برای جلوگیری از ارسال تکراری، ادامهٔ خودکار متوقف شد. جدول‌های افزونه را بررسی کنید.', self::admin_kb(), null );
            return;
        }

        if ( $done ) {
            self::send( (int) $job->admin_id, '✅ ارسال همگانی پایان یافت. موفق: ' . ( (int) $job->sent + $sent ) . ' | ناموفق: ' . ( (int) $job->failed + $failed ), self::admin_kb(), null );
        } else {
            $args = array( $job_id );
            if ( ! wp_next_scheduled( 'ahangban_bot_process_broadcast', $args ) ) {
                $scheduled = wp_schedule_single_event( time() + 15, 'ahangban_bot_process_broadcast', $args );
                if ( is_wp_error( $scheduled ) || ( false === $scheduled && ! wp_next_scheduled( 'ahangban_bot_process_broadcast', $args ) ) ) {
                    error_log( '[AhangBan Telegram] Could not schedule next batch for broadcast job ' . $job_id . '.' );
                    self::send( (int) $job->admin_id, '⚠️ ادامهٔ پیام همگانی در صف ماند؛ زمان‌بندی WP-Cron شکست خورد. Cron هاست را بررسی کنید.', self::admin_kb(), null );
                }
            }
        }
    }

    private static function start_channel_publish_search( $chat, $user_id ) {
        if ( '' === AhangBan_Settings::get_channel_target() ) {
            self::send( $chat, '❌ ابتدا کانال را در پیشخوان WordPress → آهنگ‌بان API تنظیم کنید.', self::admin_kb() );
            return;
        }
        if ( '' === AhangBan_Settings::get_bot_token() ) {
            self::send( $chat, '❌ توکن ربات در محیط PHP هاست تنظیم نشده است.', self::admin_kb() );
            return;
        }
        set_transient( self::admin_state_key( $user_id ), array( 'action' => 'await_channel_publish_search' ), 10 * MINUTE_IN_SECONDS );
        self::send( $chat, '🔍 نام آهنگ/خواننده یا شناسهٔ عددی پست را بفرستید. فقط اثرهای منتشرشده در فهرست موسیقی قابل ارسال‌اند. برای لغو /cancel را بزنید.', self::admin_kb() );
    }

    private static function handle_channel_publish_search( $chat, $user_id, $query, $page = 1, $token = '' ) {
        if ( ! self::is_admin( $user_id ) ) { return; }
        $query = trim( sanitize_text_field( (string) $query ) );
        if ( ctype_digit( $query ) && (int) $query > 0 ) {
            $song = AhangBan_Search::get_single( (int) $query );
            if ( ! $song ) {
                self::send( $chat, '❌ پست منتشرشده‌ای با این شناسه در فهرست موسیقی پیدا نشد.', self::admin_kb() );
                return;
            }
            self::show_channel_publish_preview( $chat, $user_id, $song );
            return;
        }
        if ( ! self::is_valid_query( $query ) ) {
            self::send( $chat, '⚠️ عبارت جست‌وجو باید ۲ تا ۱۰۰ نویسه باشد؛ یا شناسهٔ عددی پست را بفرستید.', self::admin_kb(), null );
            return;
        }

        $page = max( 1, min( 1000, (int) $page ) );
        if ( '' === $token ) {
            $token = wp_generate_password( 8, false, false );
            set_transient( 'ahangban_channel_publish_query_' . $token, $query, 10 * MINUTE_IN_SECONDS );
        }
        $data = AhangBan_Search::query_posts( $query, '', $page, 10 );
        if ( is_wp_error( $data ) || ! is_array( $data ) ) {
            self::send( $chat, '❌ جست‌وجوی آثار فعلاً انجام نشد؛ کمی بعد دوباره امتحان کنید.', self::admin_kb(), null );
            return;
        }
        $results = $data['results'] ?? array();
        $total = (int) ( $data['total'] ?? 0 );
        $per_page = max( 1, (int) ( $data['per_page'] ?? 10 ) );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( empty( $results ) ) {
            self::send( $chat, '😕 اثری با این عبارت پیدا نشد. عنوان دقیق‌تر یا شناسهٔ پست را امتحان کنید.', self::admin_kb() );
            return;
        }

        $lines = array( '📣 <b>انتخاب پست برای انتشار در کانال</b> — صفحه ' . $page . ' از ' . $pages, '' );
        $rows = array();
        foreach ( $results as $index => $item ) {
            $post_id = (int) ( $item['id'] ?? 0 );
            if ( $post_id <= 0 ) { continue; }
            $title = self::text_limit( $item['song'] ?? $item['title'] ?? 'اثر بدون عنوان', 58 );
            $artist = self::text_limit( $item['artist'] ?? '', 34 );
            $label = $artist ? $title . ' — ' . $artist : $title;
            $number = ( ( $page - 1 ) * $per_page ) + $index + 1;
            $lines[] = $number . '. <b>' . self::esc( $title ) . '</b>' . ( $artist ? ' — ' . self::esc( $artist ) : '' );
            $rows[] = array( array( 'text' => '🎵 ' . self::text_limit( $label, 58 ), 'callback_data' => 'admin:channel_publish_select:' . $post_id ) );
        }
        $navigation = array();
        if ( $page > 1 ) { $navigation[] = array( 'text' => '◀️ قبلی', 'callback_data' => 'admin:channel_publish_page:' . ( $page - 1 ) . ':' . $token ); }
        $navigation[] = array( 'text' => $page . '/' . $pages, 'callback_data' => 'noop' );
        if ( $page < $pages ) { $navigation[] = array( 'text' => 'بعدی ▶️', 'callback_data' => 'admin:channel_publish_page:' . ( $page + 1 ) . ':' . $token ); }
        if ( $navigation ) { $rows[] = $navigation; }
        $rows[] = array( array( 'text' => 'لغو', 'callback_data' => 'admin:channel_publish_cancel_search' ) );
        self::send( $chat, implode( "\n", $lines ), array( 'inline_keyboard' => $rows ) );
    }

    private static function show_channel_publish_preview( $chat, $user_id, $song ) {
        $post_id = (int) ( $song['id'] ?? 0 );
        if ( $post_id <= 0 ) { self::send( $chat, '❌ شناسهٔ پست نامعتبر است.', self::admin_kb() ); return; }
        set_transient( self::admin_state_key( $user_id ), array(
            'action' => 'pending_channel_publish',
            'post_id' => $post_id,
            'created_at' => time(),
        ), 10 * MINUTE_IN_SECONDS );
        $keyboard = array( 'inline_keyboard' => array(
            array(
                array( 'text' => '✅ تأیید انتشار', 'callback_data' => 'admin:channel_publish_confirm:' . $post_id ),
                array( 'text' => 'لغو', 'callback_data' => 'admin:channel_publish_cancel:' . $post_id ),
            ),
        ) );
        $preview = "📣 <b>پیش‌نمایش انتشار در کانال</b>\n\n" . self::channel_caption( $song, 'انتشار از آرشیو آهنگ‌بان' ) . "\n\nاین ارسال مستقل از انتشار خودکار است؛ فقط با تأیید شما منتشر می‌شود.";
        self::send( $chat, $preview, $keyboard );
    }

    private static function confirm_channel_publish( $chat, $message_id, $callback_id, $user_id, $post_id ) {
        if ( ! self::is_admin( $user_id ) ) { self::answer_cb( $callback_id, '⛔ دسترسی غیرمجاز.' ); return; }
        $state = get_transient( self::admin_state_key( $user_id ) );
        if ( ! is_array( $state ) || 'pending_channel_publish' !== ( $state['action'] ?? '' ) || (int) ( $state['post_id'] ?? 0 ) !== (int) $post_id || time() - (int) ( $state['created_at'] ?? 0 ) > 600 ) {
            delete_transient( self::admin_state_key( $user_id ) );
            self::answer_cb( $callback_id, 'پیش‌نمایش منقضی شده؛ پست را دوباره انتخاب کنید.' );
            return;
        }
        // Consume confirmation before the Telegram request to prevent double-click duplicates.
        delete_transient( self::admin_state_key( $user_id ) );
        self::answer_cb( $callback_id, 'در حال انتشار…' );
        $result = self::publish_channel_post( (int) $post_id );
        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            $error_text = self::esc( $result->get_error_message() );
            $text = in_array( $error_code, array( 'telegram_delivery_unknown', 'telegram_bad_response' ), true )
                ? "⚠️ نتیجهٔ ارسال قطعی نیست؛ کانال را بررسی کنید و برای جلوگیری از پیام تکراری دوباره تأیید نکنید.\n" . $error_text
                : '❌ انتشار انجام نشد: ' . $error_text;
            self::edit_text( $chat, $message_id, $text, self::admin_kb() );
            return;
        }
        $message = '✅ پست <code>' . (int) $post_id . '</code> در کانال منتشر شد.';
        if ( ! empty( $result['message_id'] ) ) { $message .= "\nشناسهٔ پیام کانال: <code>" . (int) $result['message_id'] . '</code>'; }
        self::edit_text( $chat, $message_id, $message, self::admin_kb() );
    }

    private static function cancel_channel_publish( $chat, $message_id, $callback_id, $user_id, $post_id ) {
        if ( ! self::is_admin( $user_id ) ) { self::answer_cb( $callback_id, '⛔ دسترسی غیرمجاز.' ); return; }
        $state = get_transient( self::admin_state_key( $user_id ) );
        if ( is_array( $state ) && 'pending_channel_publish' === ( $state['action'] ?? '' ) && (int) ( $state['post_id'] ?? 0 ) === (int) $post_id ) {
            delete_transient( self::admin_state_key( $user_id ) );
        }
        self::answer_cb( $callback_id, 'انتشار لغو شد.' );
        self::edit_text( $chat, $message_id, '✅ انتشار لغو شد.', self::admin_kb() );
    }

    private static function handle_admin_callback( $data, $chat, $message_id, $callback_id, $user_id ) {
        if ( ! self::is_admin( $user_id ) ) {
            self::answer_cb( $callback_id, '⛔ این بخش فقط برای ادمین مجاز است.' );
            return;
        }
        if ( preg_match( '/^admin:channel_publish_select:([1-9][0-9]*)$/', $data, $match ) ) {
            self::answer_cb( $callback_id );
            $song = AhangBan_Search::get_single( (int) $match[1] );
            if ( ! $song ) { self::send( $chat, '❌ این اثر دیگر منتشرشده نیست یا از فهرست موسیقی حذف شده است.', self::admin_kb() ); return; }
            self::show_channel_publish_preview( $chat, $user_id, $song );
            return;
        }
        if ( preg_match( '/^admin:channel_publish_confirm:([1-9][0-9]*)$/', $data, $match ) ) {
            self::confirm_channel_publish( $chat, $message_id, $callback_id, $user_id, (int) $match[1] );
            return;
        }
        if ( preg_match( '/^admin:channel_publish_cancel:([1-9][0-9]*)$/', $data, $match ) ) {
            self::cancel_channel_publish( $chat, $message_id, $callback_id, $user_id, (int) $match[1] );
            return;
        }
        if ( preg_match( '/^admin:channel_publish_page:([1-9][0-9]*):([A-Za-z0-9]{8})$/', $data, $match ) ) {
            $query = get_transient( 'ahangban_channel_publish_query_' . $match[2] );
            if ( ! is_string( $query ) || '' === $query ) { self::answer_cb( $callback_id, 'جست‌وجو منقضی شده؛ دوباره شروع کنید.' ); return; }
            self::answer_cb( $callback_id );
            self::handle_channel_publish_search( $chat, $user_id, $query, (int) $match[1], $match[2] );
            return;
        }
        switch ( $data ) {
            case 'admin:menu':
                self::answer_cb( $callback_id );
                self::edit_text( $chat, $message_id, self::admin_menu_text(), self::admin_kb() );
                return;
            case 'admin:stats':
                self::answer_cb( $callback_id );
                self::edit_text( $chat, $message_id, self::admin_menu_text(), self::admin_kb() );
                return;
            case 'admin:health':
                self::answer_cb( $callback_id );
                self::handle_admin_health( $chat, $user_id, $message_id );
                return;
            case 'admin:cache_confirm':
                self::answer_cb( $callback_id );
                self::handle_admin_cache_clear( $chat, $user_id, false, $message_id );
                return;
            case 'admin:cache_clear':
                self::answer_cb( $callback_id );
                self::handle_admin_cache_clear( $chat, $user_id, true, $message_id );
                return;
            case 'admin:users':
                self::answer_cb( $callback_id );
                $text = "👥 <b>مدیریت کاربران</b>\n\nمسدودکردن: <code>/block 123456789</code>\nرفع مسدودی: <code>/unblock 123456789</code>\n\nشناسهٔ عددی تلگرام را وارد کنید. ادمین‌ها قابل مسدودشدن نیستند. فقط کاربران خصوصیِ شروع‌کنندهٔ ربات در آمار/پیام همگانی ثبت می‌شوند.";
                self::edit_text( $chat, $message_id, $text, self::admin_kb() );
                return;
            case 'admin:broadcast':
                self::answer_cb( $callback_id );
                self::start_broadcast( $chat, $user_id );
                return;
            case 'admin:channel_publish':
                self::answer_cb( $callback_id );
                self::start_channel_publish_search( $chat, $user_id );
                return;
            case 'admin:channel_publish_cancel_search':
                delete_transient( self::admin_state_key( $user_id ) );
                self::answer_cb( $callback_id, 'جست‌وجوی انتشار لغو شد.' );
                self::edit_text( $chat, $message_id, self::admin_menu_text(), self::admin_kb() );
                return;
            case 'admin:broadcast_confirm':
                self::queue_broadcast( $chat, $message_id, $callback_id, $user_id );
                return;
            case 'admin:broadcast_cancel':
                delete_transient( self::admin_state_key( $user_id ) );
                self::answer_cb( $callback_id, 'پیام همگانی لغو شد.' );
                self::edit_text( $chat, $message_id, self::admin_menu_text(), self::admin_kb() );
                return;
        }
        self::answer_cb( $callback_id, 'عملیات ناشناخته است.' );
    }

    private static function handle_callback( $cb ) {
        $data = (string) ( $cb['data'] ?? '' );
        $callback_id = (string) ( $cb['id'] ?? '' );
        $chat = (int) ( $cb['message']['chat']['id'] ?? 0 );
        $message_id = (int) ( $cb['message']['message_id'] ?? 0 );
        $user_id = (int) ( $cb['from']['id'] ?? 0 );
        if ( '' === $data || ! $chat ) { if ( $callback_id ) { self::answer_cb( $callback_id ); } return; }
        if ( 'prompt:artist' !== $data && $user_id > 0 ) { delete_transient( 'ahangban_tg_flow_' . $user_id ); }

        if ( self::starts_with( $data, 'admin:' ) ) {
            self::handle_admin_callback( $data, $chat, $message_id, $callback_id, $user_id );
            return;
        }
        if ( 'noop' === $data ) { self::answer_cb( $callback_id, '—' ); return; }
        if ( 'home' === $data ) {
            self::answer_cb( $callback_id );
            $text = "🏠 <b>منوی اصلی آهنگ‌بان</b>\nنام آهنگ یا خواننده را بنویسید یا یکی از گزینه‌ها را انتخاب کنید.";
            $keyboard = self::main_kb( self::is_admin( $user_id ) );
            $has_media = ! empty( $cb['message']['photo'] ) || ! empty( $cb['message']['video'] ) || ! empty( $cb['message']['audio'] ) || ! empty( $cb['message']['document'] );
            $result = $has_media
                ? self::edit_caption( $chat, $message_id, $text, $keyboard )
                : self::edit_text( $chat, $message_id, $text, $keyboard );
            if ( ! self::telegram_ok( $result ) && ! is_wp_error( $result ) ) { self::send( $chat, $text, $keyboard ); }
            return;
        }
        if ( 'help' === $data ) {
            self::answer_cb( $callback_id );
            $text = self::help_text();
            $keyboard = self::main_kb( self::is_admin( $user_id ) );
            $has_media = ! empty( $cb['message']['photo'] ) || ! empty( $cb['message']['video'] ) || ! empty( $cb['message']['audio'] ) || ! empty( $cb['message']['document'] );
            $result = $has_media
                ? self::edit_caption( $chat, $message_id, $text, $keyboard )
                : self::edit_text( $chat, $message_id, $text, $keyboard );
            if ( ! self::telegram_ok( $result ) && ! is_wp_error( $result ) ) { self::send( $chat, $text, $keyboard ); }
            return;
        }
        if ( 'prompt:search' === $data ) {
            self::answer_cb( $callback_id );
            self::send( $chat, '🎵 نام آهنگ یا خواننده را همین‌جا بنویسید؛ مثلاً: محسن یگانه — بی‌تاب', self::home_kb() );
            return;
        }
        if ( 'prompt:artist' === $data ) {
            self::answer_cb( $callback_id );
            set_transient( 'ahangban_tg_flow_' . (int) $user_id, array( 'action' => 'await_artist' ), 10 * MINUTE_IN_SECONDS );
            self::send( $chat, '🎤 نام خواننده را در پیام بعدی بنویسید؛ مثلاً: معین یا گوگوش', self::home_kb() );
            return;
        }

        if ( self::starts_with( $data, 'song:' ) ) {
            $song_id = substr( $data, 5 );
            if ( ! ctype_digit( $song_id ) || (int) $song_id <= 0 ) { self::answer_cb( $callback_id, 'شناسه نامعتبر است.' ); return; }
            self::answer_cb( $callback_id );
            self::handle_song_card( $chat, (int) $song_id );
            return;
        }

        if ( self::starts_with( $data, 'dl:' ) ) {
            $parts = explode( ':', $data );
            if ( 3 !== count( $parts ) || ! ctype_digit( $parts[1] ) ) { self::answer_cb( $callback_id ); return; }
            $song_id = (int) $parts[1];
            $quality = sanitize_key( $parts[2] );
            self::answer_cb( $callback_id, '⏳ در حال آماده‌سازی فایل…' );
            $song = AhangBan_Search::get_single( $song_id );
            if ( ! $song ) { self::send( $chat, '❌ آهنگ منتشرشده‌ای با این شناسه پیدا نشد.', null, null ); return; }
            if ( ! isset( $song['qualities'][ $quality ] ) ) { self::send( $chat, '❌ این کیفیت برای اثر موجود نیست.', null, null ); return; }
            self::send_media( $chat, $song, $quality, $user_id );
            return;
        }

        if ( self::starts_with( $data, 'lyrics:' ) ) {
            $song_id = substr( $data, 7 );
            if ( ! ctype_digit( $song_id ) || (int) $song_id <= 0 ) { self::answer_cb( $callback_id ); return; }
            self::answer_cb( $callback_id );
            $song = AhangBan_Search::get_single( (int) $song_id );
            if ( ! $song || empty( $song['lyrics'] ) ) { self::send( $chat, 'متنی برای این اثر ثبت نشده است.', null, null ); return; }
            $lyrics = str_replace( array( '</p>', '<br />', '<br>', '<br/>' ), "\n", (string) $song['lyrics'] );
            $lyrics = html_entity_decode( wp_strip_all_tags( $lyrics ), ENT_QUOTES, 'UTF-8' );
            $lyrics = trim( preg_replace( '/\n{3,}/u', "\n\n", $lyrics ) );
            $header = '📝 متن ترانهٔ «' . self::esc( $song['song'] ?? $song['title'] ?? '' ) . '»';
            if ( ! empty( $song['artist'] ) ) { $header .= ' از ' . self::esc( $song['artist'] ); }
            $text = $header . "\n" . str_repeat( '─', 20 ) . "\n" . self::esc( self::text_limit( $lyrics, 3600 ) );
            self::send( $chat, $text, null, 'HTML' );
            return;
        }

        if ( self::starts_with( $data, 'related:' ) ) {
            $song_id = substr( $data, 8 );
            if ( ! ctype_digit( $song_id ) || (int) $song_id <= 0 ) { self::answer_cb( $callback_id ); return; }
            self::answer_cb( $callback_id );
            $song = AhangBan_Search::get_single( (int) $song_id );
            $related = is_array( $song ) ? ( $song['related'] ?? array() ) : array();
            if ( empty( $related ) ) { self::send( $chat, 'اثر مرتبطی یافت نشد.', null, null ); return; }
            $rows = array();
            foreach ( array_slice( $related, 0, 10 ) as $item ) {
                $related_id = (int) ( $item['id'] ?? 0 );
                if ( $related_id <= 0 ) { continue; }
                $title = self::text_limit( $item['song'] ?? $item['title'] ?? '', 28 );
                $artist = self::text_limit( $item['artist'] ?? '', 20 );
                $label = $artist ? $title . ' — ' . $artist : $title;
                $rows[] = array( array( 'text' => '🎵 ' . self::text_limit( $label, 58 ), 'callback_data' => 'song:' . $related_id ) );
            }
            $rows[] = array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) );
            $title = self::esc( $song['song'] ?? $song['title'] ?? '' );
            self::send( $chat, '🎧 آثار مرتبط با «' . $title . '»:', array( 'inline_keyboard' => $rows ) );
            return;
        }

        if ( self::starts_with( $data, 'album:' ) ) {
            $song_id = substr( $data, 6 );
            if ( ! ctype_digit( $song_id ) || (int) $song_id <= 0 ) { self::answer_cb( $callback_id ); return; }
            self::answer_cb( $callback_id );
            $song = AhangBan_Search::get_single( (int) $song_id );
            $tracks = is_array( $song ) ? ( $song['album_tracks'] ?? array() ) : array();
            if ( empty( $tracks ) ) { self::send( $chat, 'ترکی برای این آلبوم ثبت نشده است.', null, null ); return; }
            $lines = array( '💿 <b>ترک‌های آلبوم «' . self::esc( $song['song'] ?? $song['title'] ?? '' ) . '»</b>', '' );
            $rows = array();
            foreach ( array_slice( $tracks, 0, 30 ) as $index => $track ) {
                $number = $index + 1;
                $name = self::esc( self::text_limit( $track['song_names'] ?? 'ترک ' . $number, 60 ) );
                $lines[] = $number . '. ' . $name;
                $buttons = array();
                foreach ( array( 'albumlink128' => '⬇️ 128', 'albumlink320' => '⬇️ 320' ) as $field => $label ) {
                    $link = self::safe_media_url( $track[ $field ] ?? '' );
                    if ( $link ) { $buttons[] = array( 'text' => $label . ' #' . $number, 'url' => $link ); }
                }
                if ( $buttons ) { $rows[] = $buttons; }
            }
            $rows[] = array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) );
            self::send( $chat, implode( "\n", $lines ), array( 'inline_keyboard' => $rows ) );
            return;
        }

        if ( self::starts_with( $data, 'browse:' ) ) {
            $parts = explode( ':', $data );
            $kind = $parts[1] ?? '';
            $page = max( 1, min( 1000, (int) ( $parts[2] ?? 1 ) ) );
            self::answer_cb( $callback_id );
            if ( 'latest' === $kind ) { self::handle_latest( $chat, $page ); }
            elseif ( 'popular' === $kind ) { self::handle_popular( $chat, $page ); }
            return;
        }

        if ( self::starts_with( $data, 'search:' ) ) {
            $parts = explode( ':', $data, 3 );
            $page = max( 1, min( 1000, (int) ( $parts[1] ?? 1 ) ) );
            $token = $parts[2] ?? '';
            $query = '';
            if ( preg_match( '/^[A-Za-z0-9]{1,32}$/', $token ) ) {
                $query = (string) get_transient( 'ahangban_search_query_' . $token );
            }
            // Accept old, short-lived base64 pagination buttons during upgrades.
            if ( '' === $query && strlen( $token ) <= 56 ) { $query = base64_decode( $token, true ) ?: ''; }
            self::answer_cb( $callback_id );
            if ( '' === $query ) { self::send( $chat, 'جست‌وجو منقضی شده؛ دوباره عبارت را وارد کنید.', self::main_kb( self::is_admin( $user_id ) ) ); return; }
            self::handle_search( $chat, $query, $page, $user_id );
            return;
        }

        if ( self::starts_with( $data, 'artists:' ) ) {
            $parts = explode( ':', $data );
            self::answer_cb( $callback_id );
            if ( ( $parts[1] ?? '' ) === 'page' ) {
                $page = max( 1, min( 1000, (int) ( $parts[2] ?? 1 ) ) );
                $letter = $parts[3] ?? '';
                self::handle_artists_list( $chat, $page, $letter );
                return;
            }
            if ( ( $parts[1] ?? '' ) === 'letter' ) {
                $letter = $parts[2] ?? '';
                self::handle_artists_list( $chat, 1, $letter );
                return;
            }
        }

        if ( self::starts_with( $data, 'artist:' ) ) {
            $parts = explode( ':', $data );
            $identifier = $parts[1] ?? '';
            if ( '' === $identifier ) { self::answer_cb( $callback_id ); return; }
            if ( 2 === count( $parts ) ) {
                self::answer_cb( $callback_id );
                self::handle_artist( $chat, $identifier, 1, 'new' );
                return;
            }
            if ( 4 === count( $parts ) ) {
                $page = max( 1, min( 1000, (int) $parts[2] ) );
                $sort = in_array( $parts[3], array( 'new', 'old' ), true ) ? $parts[3] : 'new';
                self::answer_cb( $callback_id );
                self::handle_artist( $chat, $identifier, $page, $sort );
                return;
            }
        }
        self::answer_cb( $callback_id );
    }
}
