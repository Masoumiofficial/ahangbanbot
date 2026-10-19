<?php
/**
 * Plugin Name: AhangBan Connector — Telegram Bot API
 * Plugin URI:  https://ahangban.ir/
 * Description: لایهٔ امن اتصال ربات تلگرام به داده‌های واقعی آهنگ‌بان. هیچ تغییری در قالب ایجاد نمی‌کند. از نسخه 1.2.0 تنظیم توکن‌ها بدون SSH از پیشخوان ممکن است و حالت وب‌هوک داخلی (بدون نیاز به سرور جدا) پشتیبانی می‌شود.
 * Version:     1.2.0
 * Author:      AhangBan — Engineering Team
 * License:     GPL v2 or later
 * Text Domain: ahangban-connector
 * Requires PHP: 7.4
 * Requires WP:  5.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AHANGBAN_CONNECTOR_VERSION', '1.2.0' );
define( 'AHANGBAN_CONNECTOR_FILE', __FILE__ );
define( 'AHANGBAN_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'AHANGBAN_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );

require_once AHANGBAN_CONNECTOR_DIR . 'includes/class-auth.php';
require_once AHANGBAN_CONNECTOR_DIR . 'includes/class-settings.php';
require_once AHANGBAN_CONNECTOR_DIR . 'includes/class-cache.php';
require_once AHANGBAN_CONNECTOR_DIR . 'includes/class-search.php';
require_once AHANGBAN_CONNECTOR_DIR . 'includes/class-api.php';
require_once AHANGBAN_CONNECTOR_DIR . 'includes/class-admin.php';
require_once AHANGBAN_CONNECTOR_DIR . 'includes/class-telegram.php';

add_action( 'plugins_loaded', function () {
    AhangBan_Auth::init();
    AhangBan_Settings::init();
    AhangBan_Cache::init();
    AhangBan_API::init();
    AhangBan_Telegram::init();
    if ( is_admin() ) {
        AhangBan_Admin::init();
    }
} );

register_activation_hook( __FILE__, function () {
    // Generate token if not exists
    if ( ! get_option( 'ahangban_connector_token' ) ) {
        $token = bin2hex( random_bytes( 32 ) ); // 64 hex chars
        add_option( 'ahangban_connector_token', $token, '', 'no' );
        // autoload=no for security
        update_option( 'ahangban_connector_token', $token );
        // Ensure not autoloaded (WP 6+ supports add with autoload param)
        if ( function_exists( 'wp_set_option_autoload' ) ) {
            // no-op, already handled
        }
    }
    // Flush rewrite for REST (not strictly needed but safe)
    flush_rewrite_rules( false );
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules( false );
} );
