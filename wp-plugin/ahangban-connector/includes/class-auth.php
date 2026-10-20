<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_Auth {
    // Kept only so the one-time migration can remove the legacy database secret.
    const OPTION_TOKEN = 'ahangban_connector_token';
    const RATE_LIMIT_KEY = 'ahangban_rl_v2_';
    const RATE_LIMIT_MAX = 60; // per minute per verified client IP
    const RATE_LIMIT_TOKEN_MAX = 200;
    const RATE_WINDOW = 60;

    public static function init() {}

    /** The API token is deliberately read from the PHP process environment only. */
    public static function get_token() {
        $token = self::environment_value( 'AHANGBAN_API_TOKEN' );
        return preg_match( '/^[a-f0-9]{64}$/i', $token ) ? $token : '';
    }

    private static function environment_value( $name ) {
        $value = getenv( $name );
        if ( false !== $value && '' !== trim( (string) $value ) ) { return trim( (string) $value ); }
        if ( isset( $_SERVER[ $name ] ) && '' !== trim( (string) $_SERVER[ $name ] ) ) { return trim( (string) $_SERVER[ $name ] ); }
        if ( isset( $_ENV[ $name ] ) && '' !== trim( (string) $_ENV[ $name ] ) ) { return trim( (string) $_ENV[ $name ] ); }
        return '';
    }

    private static function request_token( WP_REST_Request $request ) {
        $header = trim( (string) $request->get_header( 'authorization' ) );
        if ( '' === $header ) { $header = trim( (string) $request->get_header( 'x-ahangban-token' ) ); }
        if ( '' === $header ) { return ''; }
        if ( preg_match( '/^Bearer\s+(.+)$/i', $header, $match ) ) { return trim( $match[1] ); }
        return $header;
    }

    public static function verify_request( WP_REST_Request $request ) {
        $token = self::request_token( $request );
        if ( '' === $token ) {
            return new WP_Error( 'ahangban_no_auth', 'هویت تأیید نشد.', array( 'status' => 401 ) );
        }
        $expected = self::get_token();
        if ( '' === $expected ) {
            return new WP_Error( 'ahangban_auth_not_configured', 'توکن API در محیط PHP پیکربندی نشده است.', array( 'status' => 503 ) );
        }
        if ( ! hash_equals( $expected, $token ) ) {
            return new WP_Error( 'ahangban_forbidden', 'توکن نامعتبر.', array( 'status' => 403 ) );
        }
        return true;
    }

    /** Atomic per-Telegram-user limiter; a DB failure fails closed. */
    public static function allow_telegram_update( $user_id, $max = 20 ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) { return true; }
        $count = self::increment_counter( 'tg', (string) $user_id );
        return false !== $count && $count <= max( 1, (int) $max );
    }

    /**
     * Atomic fixed-window counters stored in this WordPress database.
     * The token bucket is incremented only after a constant-time token match, so arbitrary
     * Authorization values cannot create an unbounded number of option rows.
     */
    public static function check_rate_limit( WP_REST_Request $request ) {
        $ip_count = self::increment_counter( 'ip', self::client_ip() );
        if ( false === $ip_count ) {
            return new WP_Error( 'ahangban_rate_limit_unavailable', 'کنترل نرخ درخواست موقتاً در دسترس نیست.', array( 'status' => 503 ) );
        }
        if ( $ip_count > self::RATE_LIMIT_MAX ) {
            return new WP_Error( 'ahangban_rate_limit', 'درخواست بیش از حد مجاز. کمی صبر کنید.', array( 'status' => 429 ) );
        }

        $expected = self::get_token();
        $token = self::request_token( $request );
        if ( '' !== $expected && '' !== $token && hash_equals( $expected, $token ) ) {
            $token_count = self::increment_counter( 'token', $expected );
            if ( false === $token_count ) {
                return new WP_Error( 'ahangban_rate_limit_unavailable', 'کنترل نرخ درخواست موقتاً در دسترس نیست.', array( 'status' => 503 ) );
            }
            if ( $token_count > self::RATE_LIMIT_TOKEN_MAX ) {
                return new WP_Error( 'ahangban_rate_limit', 'درخواست بیش از حد مجاز. کمی صبر کنید.', array( 'status' => 429 ) );
            }
        }
        return true;
    }

    private static function increment_counter( $scope, $identity ) {
        global $wpdb;
        if ( ! isset( $wpdb->options ) ) { return false; }

        $bucket = (int) floor( time() / self::RATE_WINDOW );
        $option_name = self::RATE_LIMIT_KEY . sanitize_key( $scope ) . '_' . md5( (string) $identity ) . '_' . $bucket;
        $sql = $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
            $option_name
        );
        if ( false === $wpdb->query( $sql ) ) { return false; }

        // The atomic SQL write bypasses the Options API; discard any stale local option cache.
        wp_cache_delete( $option_name, 'options' );
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
        if ( null === $count || ! is_numeric( $count ) ) { return false; }

        self::cleanup_rate_counters( $bucket );
        return (int) $count;
    }

    private static function cleanup_rate_counters( $bucket ) {
        if ( get_transient( 'ahangban_rl_v2_cleanup' ) ) { return; }
        set_transient( 'ahangban_rl_v2_cleanup', 1, 5 * MINUTE_IN_SECONDS );

        global $wpdb;
        $prefix = $wpdb->esc_like( self::RATE_LIMIT_KEY ) . '%';
        $current_bucket_suffix = '%' . $wpdb->esc_like( '_' . (string) (int) $bucket );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
            $prefix,
            $current_bucket_suffix
        ) );
    }

    /**
     * Use REMOTE_ADDR unless the connecting proxy is explicitly trusted. Forwarded headers
     * supplied by an arbitrary client are otherwise trivially spoofable.
     */
    public static function client_ip() {
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
        if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) { return '0.0.0.0'; }
        if ( ! self::is_trusted_proxy( $remote ) ) { return $remote; }

        $cloudflare_ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) : '';
        if ( filter_var( $cloudflare_ip, FILTER_VALIDATE_IP ) ) { return $cloudflare_ip; }

        $forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
        if ( '' !== $forwarded ) {
            $chain = array_map( 'trim', explode( ',', $forwarded ) );
            $chain[] = $remote;
            foreach ( array_reverse( $chain ) as $candidate ) {
                if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) { continue; }
                if ( ! self::is_trusted_proxy( $candidate ) ) { return $candidate; }
            }
        }

        $real_ip = isset( $_SERVER['HTTP_X_REAL_IP'] ) ? trim( (string) $_SERVER['HTTP_X_REAL_IP'] ) : '';
        return filter_var( $real_ip, FILTER_VALIDATE_IP ) ? $real_ip : $remote;
    }

    private static function is_trusted_proxy( $ip ) {
        $raw = self::environment_value( 'AHANGBAN_TRUSTED_PROXY_IPS' );
        if ( '' === $raw ) { return false; }
        foreach ( preg_split( '/[\s,;]+/', trim( (string) $raw ) ) as $trusted ) {
            $trusted = trim( $trusted );
            if ( '' !== $trusted && self::ip_matches_network( $ip, $trusted ) ) { return true; }
        }
        return false;
    }

    private static function ip_matches_network( $ip, $network ) {
        if ( $ip === $network ) { return true; }
        $parts = explode( '/', $network, 2 );
        if ( 2 !== count( $parts ) || ! ctype_digit( $parts[1] ) ) { return false; }

        $address_bin = @inet_pton( $ip );
        $network_bin = @inet_pton( $parts[0] );
        if ( false === $address_bin || false === $network_bin || strlen( $address_bin ) !== strlen( $network_bin ) ) { return false; }

        $max_bits = strlen( $address_bin ) * 8;
        $prefix = (int) $parts[1];
        if ( $prefix <= 0 || $prefix > $max_bits ) { return false; }

        $whole_bytes = (int) floor( $prefix / 8 );
        $remaining_bits = $prefix % 8;
        if ( $whole_bytes > 0 && substr( $address_bin, 0, $whole_bytes ) !== substr( $network_bin, 0, $whole_bytes ) ) { return false; }
        if ( $remaining_bits > 0 ) {
            $mask = ( 0xff << ( 8 - $remaining_bits ) ) & 0xff;
            if ( ( ord( $address_bin[ $whole_bytes ] ) & $mask ) !== ( ord( $network_bin[ $whole_bytes ] ) & $mask ) ) { return false; }
        }
        return true;
    }

    // Retained for compatibility with code that used the old health-route helper.
    public static function is_health_route( $route ) {
        return false !== strpos( (string) $route, '/ahangban/v1/health' );
    }
}
