<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_Cache {
    const PREFIX = 'ahangban_';

    public static function init() {
        // Invalidate on content changes
        add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 3 );
        add_action( 'deleted_post', array( __CLASS__, 'on_delete_post' ) );
        add_action( 'trashed_post', array( __CLASS__, 'on_delete_post' ) );
        add_action( 'created_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
        add_action( 'edited_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
        add_action( 'delete_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
    }

    public static function on_save_post( $post_id, $post, $update ) {
        if ( 'post' !== $post->post_type ) { return; }
        self::bump_version();
    }

    public static function on_delete_post( $post_id ) {
        self::bump_version();
    }

    public static function on_term_change( $term_id, $tt_id, $taxonomy ) {
        if ( 'singer' !== $taxonomy ) { return; }
        self::bump_version();
    }

    public static function bump_version() {
        // Simple version bump — stored in option
        $v = (int) get_option( 'ahangban_cache_version', 0 );
        update_option( 'ahangban_cache_version', $v + 1, false );
        // Also delete known transients (pattern not possible, but version invalidates keys)
    }

    public static function get_version() {
        return (int) get_option( 'ahangban_cache_version', 0 );
    }

    public static function make_key( $base, array $params = array() ) {
        ksort( $params );
        $raw = $base . ':' . wp_json_encode( $params, JSON_UNESCAPED_UNICODE ) . ':v' . self::get_version();
        return self::PREFIX . md5( $raw );
    }

    public static function get( $key ) {
        return get_transient( $key );
    }

    public static function set( $key, $value, $ttl = 300 ) {
        return set_transient( $key, $value, $ttl );
    }

    public static function delete( $key ) {
        return delete_transient( $key );
    }

    public static function clear_all() {
        // Version bump invalidates all cached keys
        self::bump_version();
        global $wpdb;
        // Cleanup old transients that may still be in DB (optional)
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%' ) );
        return true;
    }
}
