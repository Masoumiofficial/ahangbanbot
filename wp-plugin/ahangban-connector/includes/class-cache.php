<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_Cache {
    const PREFIX = 'ahangban_cache_';

    public static function init() {
        // Bump the cache namespace on plugin updates so changed payloads (e.g. high-res covers) appear immediately.
        $build = (string) get_option( 'ahangban_connector_cache_build', '' );
        if ( ! defined( 'AHANGBAN_CONNECTOR_VERSION' ) || $build !== AHANGBAN_CONNECTOR_VERSION ) {
            self::bump_version();
            if ( defined( 'AHANGBAN_CONNECTOR_VERSION' ) ) { update_option( 'ahangban_connector_cache_build', AHANGBAN_CONNECTOR_VERSION, false ); }
        }
        // Invalidate on content changes
        // Run after post metadata (including ACF fields) has been saved.
        add_action( 'wp_after_insert_post', array( __CLASS__, 'on_after_insert_post' ), 10, 4 );
        add_action( 'deleted_post', array( __CLASS__, 'on_delete_post' ) );
        add_action( 'trashed_post', array( __CLASS__, 'on_delete_post' ) );
        add_action( 'created_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
        add_action( 'edited_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
        add_action( 'delete_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
        add_action( 'added_term_meta', array( __CLASS__, 'on_term_meta_change' ), 10, 4 );
        add_action( 'updated_term_meta', array( __CLASS__, 'on_term_meta_change' ), 10, 4 );
        add_action( 'deleted_term_meta', array( __CLASS__, 'on_term_meta_change' ), 10, 4 );
    }

    public static function on_after_insert_post( $post_id, $post, $update, $post_before ) {
        if ( ! is_object( $post ) || 'post' !== $post->post_type ) { return; }
        self::bump_version();
    }

    public static function on_delete_post( $post_id ) {
        self::bump_version();
    }

    public static function on_term_change( $term_id, $tt_id, $taxonomy ) {
        if ( AhangBan_Search::get_taxonomy() !== $taxonomy && 'singer' !== $taxonomy ) { return; }
        self::bump_version();
    }

    public static function on_term_meta_change( $meta_id, $term_id, $meta_key, $meta_value ) {
        $image_key = 'aimg2';
        $social_keys = array( 'atelegram', 'ainstagram', 'atwitter', 'ayoutube' );
        if ( $image_key !== $meta_key && ! in_array( $meta_key, $social_keys, true ) ) { return; }
        $term = get_term( (int) $term_id );
        if ( ! $term || is_wp_error( $term ) ) { return; }
        if ( AhangBan_Search::get_taxonomy() !== $term->taxonomy && 'singer' !== $term->taxonomy ) { return; }
        self::bump_version();
    }

    public static function bump_version() {
        // Atomic bump avoids losing invalidations when two posts are saved concurrently.
        global $wpdb;
        $option_name = 'ahangban_cache_version';
        if ( isset( $wpdb->options ) ) {
            $result = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
                 ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
                $option_name
            ) );
            if ( false !== $result ) {
                wp_cache_delete( $option_name, 'options' );
                // The option may have been cached as missing before the first atomic insert.
                wp_cache_delete( 'notoptions', 'options' );
                return;
            }
        }
        // Defensive fallback for unusual database wrappers; do not leave the key unchanged.
        $version = (int) get_option( $option_name, 0 );
        update_option( $option_name, $version + 1, false );
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
