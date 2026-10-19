<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_API {
    const NS = 'ahangban/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        // CORS for bot server (allow all origins with auth — bot is server-side)
        add_filter( 'rest_pre_serve_request', array( __CLASS__, 'cors' ), 10, 4 );
    }

    public static function cors( $served, $result, $request, $server ) {
        $route = $request->get_route();
        if ( strpos( $route, '/ahangban/' ) === 0 ) {
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, X-AhangBan-Token, Content-Type' );
            if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
                status_header( 200 );
                exit;
            }
        }
        return $served;
    }

    public static function register_routes() {
        // Health — no auth
        register_rest_route( self::NS, '/health', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'health' ),
            'permission_callback' => '__return_true',
        ) );

        // Search
        register_rest_route( self::NS, '/search', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'search' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
            'args' => array(
                'q' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'type' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'page' => array( 'required' => false, 'type' => 'integer', 'default' => 1 ),
                'per_page' => array( 'required' => false, 'type' => 'integer', 'default' => 5 ),
            ),
        ) );

        // Song detail
        register_rest_route( self::NS, '/song/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'song' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        // Artists list
        register_rest_route( self::NS, '/artists', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'artists' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        // Artist detail
        register_rest_route( self::NS, '/artist/(?P<identifier>[^/]+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'artist' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        // Latest
        register_rest_route( self::NS, '/latest', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'latest' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        // Popular
        register_rest_route( self::NS, '/popular', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'popular' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        // Cache clear
        register_rest_route( self::NS, '/cache/clear', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'cache_clear' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );
    }

    public static function check_auth( WP_REST_Request $request ) {
        $rl = AhangBan_Auth::check_rate_limit( $request );
        if ( is_wp_error( $rl ) ) { return $rl; }
        return AhangBan_Auth::verify_request( $request );
    }

    public static function health( WP_REST_Request $request ) {
        return rest_ensure_response( array(
            'status' => 'ok',
            'version' => AHANGBAN_CONNECTOR_VERSION,
            'wp' => get_bloginfo( 'version' ),
            'php' => PHP_VERSION,
            'taxonomy_artist' => taxonomy_exists( 'artist' ),
            'taxonomy'       => AhangBan_Search::get_taxonomy(),
            'taxonomy_exists'=> taxonomy_exists( AhangBan_Search::get_taxonomy() ),
            'legacy_singer'  => taxonomy_exists( 'singer' ),
            'post_count' => (int) wp_count_posts( 'post' )->publish,
            'time' => current_time( 'mysql', true ),
        ) );
    }

    public static function search( WP_REST_Request $request ) {
        $q = $request->get_param( 'q' );
        if ( null === $q || '' === trim( $q ) ) {
            return new WP_Error( 'empty_query', 'عبارت جست‌وجو خالی است.', array( 'status' => 400 ) );
        }
        if ( mb_strlen( $q ) > 100 ) {
            return new WP_Error( 'too_long', 'عبارت جست‌وجو بیش از حد طولانی است.', array( 'status' => 400 ) );
        }
        $type = $request->get_param( 'type' ) ?: '';
        $page = (int) ( $request->get_param( 'page' ) ?: 1 );
        $per_page = (int) ( $request->get_param( 'per_page' ) ?: 5 );

        $result = AhangBan_Search::query_posts( $q, $type, $page, $per_page );
        return rest_ensure_response( $result );
    }

    public static function song( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        $data = AhangBan_Search::get_single( $id );
        if ( ! $data ) {
            return new WP_Error( 'not_found', 'آهنگ یافت نشد یا منتشر نشده است.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( $data );
    }

    public static function artists( WP_REST_Request $request ) {
        $params = array(
            'q' => $request->get_param( 'q' ),
            'letter' => $request->get_param( 'letter' ),
            'sort' => $request->get_param( 'sort' ),
            'page' => $request->get_param( 'page' ),
            'per_page' => $request->get_param( 'per_page' ),
        );
        $result = AhangBan_Search::get_artists( $params );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    public static function artist( WP_REST_Request $request ) {
        $identifier = $request->get_param( 'identifier' );
        $page = (int) ( $request->get_param( 'page' ) ?: 1 );
        $per_page = (int) ( $request->get_param( 'per_page' ) ?: 10 );
        $sort = $request->get_param( 'sort' ) ?: 'new';

        $result = AhangBan_Search::get_artist_detail( $identifier, $page, $per_page, $sort );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    public static function latest( WP_REST_Request $request ) {
        $page = (int) ( $request->get_param( 'page' ) ?: 1 );
        $per_page = (int) ( $request->get_param( 'per_page' ) ?: 5 );
        $result = AhangBan_Search::get_latest( $page, $per_page );
        return rest_ensure_response( $result );
    }

    public static function popular( WP_REST_Request $request ) {
        $page = (int) ( $request->get_param( 'page' ) ?: 1 );
        $per_page = (int) ( $request->get_param( 'per_page' ) ?: 5 );
        $result = AhangBan_Search::get_popular( $page, $per_page );
        return rest_ensure_response( $result );
    }

    public static function cache_clear( WP_REST_Request $request ) {
        AhangBan_Cache::clear_all();
        return rest_ensure_response( array( 'status' => 'ok', 'message' => 'کش پاک شد.' ) );
    }
}
