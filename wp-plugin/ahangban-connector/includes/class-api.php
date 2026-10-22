<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_API {
    const NS = 'ahangban/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        // Deliberately minimal and public: do not expose host versions, taxonomy names, or content counts.
        register_rest_route( self::NS, '/health', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'health' ),
            'permission_callback' => '__return_true',
        ) );

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

        register_rest_route( self::NS, '/song/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'song' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        register_rest_route( self::NS, '/channel/publish', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'channel_publish' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
            'args' => array(
                'post_id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
            ),
        ) );

        register_rest_route( self::NS, '/artists', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'artists' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        register_rest_route( self::NS, '/artist/(?P<identifier>[^/]+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'artist' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        register_rest_route( self::NS, '/latest', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'latest' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        register_rest_route( self::NS, '/popular', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'popular' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );

        register_rest_route( self::NS, '/cache/clear', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'cache_clear' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );
    }

    public static function check_auth( WP_REST_Request $request ) {
        $rate_limit = AhangBan_Auth::check_rate_limit( $request );
        if ( is_wp_error( $rate_limit ) ) { return $rate_limit; }
        return AhangBan_Auth::verify_request( $request );
    }

    public static function health( WP_REST_Request $request ) {
        return rest_ensure_response( array( 'status' => 'ok' ) );
    }

    private static function text_length( $text ) {
        $text = (string) $text;
        if ( function_exists( 'mb_strlen' ) ) { return mb_strlen( $text, 'UTF-8' ); }
        $characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $characters ) ? count( $characters ) : strlen( $text );
    }

    public static function search( WP_REST_Request $request ) {
        $q = trim( (string) $request->get_param( 'q' ) );
        $length = self::text_length( $q );
        if ( $length < 2 ) {
            return new WP_Error( 'empty_query', 'عبارت جست‌وجو باید دست‌کم دو نویسه داشته باشد.', array( 'status' => 400 ) );
        }
        if ( $length > 100 ) {
            return new WP_Error( 'too_long', 'عبارت جست‌وجو بیش از حد طولانی است.', array( 'status' => 400 ) );
        }

        $type = sanitize_key( (string) $request->get_param( 'type' ) );
        if ( '' !== $type && ! in_array( $type, array( 'audio', 'video', 'album' ), true ) ) {
            return new WP_Error( 'invalid_type', 'نوع اثر باید audio، video یا album باشد.', array( 'status' => 400 ) );
        }
        $page = max( 1, min( 1000, (int) $request->get_param( 'page' ) ) );
        $per_page = max( 1, min( 20, (int) $request->get_param( 'per_page' ) ) );

        $result = AhangBan_Search::query_posts( $q, $type, $page, $per_page );
        if ( is_wp_error( $result ) ) { return $result; }
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

    /** Publish one explicitly selected, already-published music post to the configured channel. */
    public static function channel_publish( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'post_id' );
        if ( $post_id <= 0 ) {
            return new WP_Error( 'invalid_post_id', 'شناسهٔ پست نامعتبر است.', array( 'status' => 400 ) );
        }
        $result = AhangBan_Telegram::publish_channel_post( $post_id );
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }

    public static function artists( WP_REST_Request $request ) {
        $q = sanitize_text_field( (string) $request->get_param( 'q' ) );
        if ( self::text_length( $q ) > 100 ) {
            return new WP_Error( 'too_long', 'عبارت خواننده بیش از ۱۰۰ نویسه است.', array( 'status' => 400 ) );
        }
        $letter = sanitize_text_field( (string) $request->get_param( 'letter' ) );
        if ( self::text_length( $letter ) > 4 ) {
            return new WP_Error( 'invalid_letter', 'فیلتر حرف نامعتبر است.', array( 'status' => 400 ) );
        }
        $result = AhangBan_Search::get_artists( array(
            'q' => $q,
            'letter' => $letter,
            'sort' => sanitize_key( (string) $request->get_param( 'sort' ) ),
            'page' => $request->get_param( 'page' ),
            'per_page' => $request->get_param( 'per_page' ),
        ) );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    public static function artist( WP_REST_Request $request ) {
        $identifier = (string) $request->get_param( 'identifier' );
        if ( strlen( $identifier ) > 200 ) {
            return new WP_Error( 'invalid_artist', 'شناسهٔ خواننده بیش از حد طولانی است.', array( 'status' => 400 ) );
        }
        $page = max( 1, min( 1000, (int) $request->get_param( 'page' ) ) );
        $requested_per_page = $request->get_param( 'per_page' );
        $per_page = max( 1, min( 20, ( null === $requested_per_page || '' === $requested_per_page ) ? 10 : (int) $requested_per_page ) );
        $sort = sanitize_key( (string) $request->get_param( 'sort' ) );
        $result = AhangBan_Search::get_artist_detail( $identifier, $page, $per_page, $sort ?: 'new' );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    public static function latest( WP_REST_Request $request ) {
        $page = max( 1, min( 1000, (int) $request->get_param( 'page' ) ) );
        $requested_per_page = $request->get_param( 'per_page' );
        $per_page = max( 1, min( 20, ( null === $requested_per_page || '' === $requested_per_page ) ? 5 : (int) $requested_per_page ) );
        return rest_ensure_response( AhangBan_Search::get_latest( $page, $per_page ) );
    }

    public static function popular( WP_REST_Request $request ) {
        $page = max( 1, min( 1000, (int) $request->get_param( 'page' ) ) );
        $requested_per_page = $request->get_param( 'per_page' );
        $per_page = max( 1, min( 20, ( null === $requested_per_page || '' === $requested_per_page ) ? 5 : (int) $requested_per_page ) );
        return rest_ensure_response( AhangBan_Search::get_popular( $page, $per_page ) );
    }

    public static function cache_clear( WP_REST_Request $request ) {
        AhangBan_Cache::clear_all();
        return rest_ensure_response( array( 'status' => 'ok', 'message' => 'کش پاک شد.' ) );
    }
}
