<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_Auth {
    const OPTION_TOKEN = 'ahangban_connector_token';
    const RATE_LIMIT_KEY = 'ahangban_rl_';
    const RATE_LIMIT_MAX = 60; // per minute per IP
    const RATE_LIMIT_TOKEN_MAX = 200;
    const RATE_WINDOW = 60;

    public static function init() {}

    public static function get_token() {
        return (string) get_option( self::OPTION_TOKEN, '' );
    }

    public static function verify_request( WP_REST_Request $request ) {
        $header = $request->get_header( 'authorization' );
        if ( ! $header ) {
            // Also check X-AhangBan-Token for flexibility
            $header = $request->get_header( 'x-ahangban-token' );
        }
        if ( ! $header ) {
            return new WP_Error( 'ahangban_no_auth', 'هویت تأیید نشد.', array( 'status' => 401 ) );
        }
        // Extract Bearer token
        if ( stripos( $header, 'Bearer ' ) === 0 ) {
            $token = trim( substr( $header, 7 ) );
        } else {
            $token = trim( $header );
        }
        $expected = self::get_token();
        if ( '' === $expected || '' === $token ) {
            return new WP_Error( 'ahangban_no_auth', 'توکن تنظیم نشده است.', array( 'status' => 401 ) );
        }
        if ( ! hash_equals( $expected, $token ) ) {
            return new WP_Error( 'ahangban_forbidden', 'توکن نامعتبر.', array( 'status' => 403 ) );
        }
        return true;
    }

    public static function check_rate_limit( WP_REST_Request $request ) {
        $ip = self::client_ip();
        $key_ip = self::RATE_LIMIT_KEY . 'ip_' . md5( $ip );
        $key_token = self::RATE_LIMIT_KEY . 'tok_' . md5( (string) $request->get_header( 'authorization' ) );

        $count_ip = (int) get_transient( $key_ip );
        $count_tok = (int) get_transient( $key_token );

        if ( $count_ip >= self::RATE_LIMIT_MAX || $count_tok >= self::RATE_LIMIT_TOKEN_MAX ) {
            return new WP_Error( 'ahangban_rate_limit', 'درخواست بیش از حد مجاز. کمی صبر کنید.', array( 'status' => 429 ) );
        }

        // Increment
        if ( false === get_transient( $key_ip ) ) {
            set_transient( $key_ip, 1, self::RATE_WINDOW );
        } else {
            // Use atomic increment emulation
            set_transient( $key_ip, $count_ip + 1, self::RATE_WINDOW );
        }
        if ( false === get_transient( $key_token ) ) {
            set_transient( $key_token, 1, self::RATE_WINDOW );
        } else {
            set_transient( $key_token, $count_tok + 1, self::RATE_WINDOW );
        }
        return true;
    }

    public static function client_ip() {
        $keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        foreach ( $keys as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                $v = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
                // XFF may contain list
                if ( strpos( $v, ',' ) !== false ) {
                    $v = trim( explode( ',', $v )[0] );
                }
                if ( filter_var( $v, FILTER_VALIDATE_IP ) ) {
                    return $v;
                }
            }
        }
        return '0.0.0.0';
    }

    // For health endpoint — no auth
    public static function is_health_route( $route ) {
        return strpos( $route, '/ahangban/v1/health' ) !== false;
    }
}
