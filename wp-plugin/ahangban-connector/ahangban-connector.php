<?php
/**
 * Plugin Name: AhangBan Connector — Telegram Bot API
 * Plugin URI:  https://ahangban.ir/
 * Description: اتصال ربات تلگرام به داده‌های واقعی آهنگ‌بان؛ وب‌هوک و polling زمان‌بندی‌شدهٔ WordPress، تنظیمات قابل‌کنترل و تشخیص امن خطاهای Telegram.
 * Version:     1.6.4
 * Author:      AhangBan — Engineering Team
 * License:     GPL v2 or later
 * Text Domain: ahangban-connector
 * Requires PHP: 7.4
 * Requires WP:  5.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AHANGBAN_CONNECTOR_VERSION', '1.6.4' );
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

register_deactivation_hook( __FILE__, array( 'AhangBan_Telegram', 'deactivate' ) );

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
