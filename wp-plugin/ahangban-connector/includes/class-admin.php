<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_Admin {
    const CAP = 'manage_options';
    const SLUG = 'ahangban-connector';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
    }

    public static function menu() {
        add_menu_page(
            'اتصال آهنگ‌بان',
            'آهنگ‌بان API',
            self::CAP,
            self::SLUG,
            array( __CLASS__, 'render' ),
            'dashicons-rest-api',
            81
        );
    }

    public static function handle_actions() {
        if ( ! current_user_can( self::CAP ) ) { return; }
        if ( empty( $_POST['ahangban_action'] ) ) { return; }
        check_admin_referer( 'ahangban_connector_action' );

        $action = sanitize_key( $_POST['ahangban_action'] );
        if ( 'regenerate' === $action ) {
            $new = bin2hex( random_bytes( 32 ) );
            update_option( AhangBan_Auth::OPTION_TOKEN, $new, false );
            // Invalidate cache version
            AhangBan_Cache::bump_version();
            add_settings_error( 'ahangban_connector', 'regenerated', 'توکن جدید ساخته شد. ربات را با توکن جدید به‌روزرسانی کنید.', 'updated' );
        } elseif ( 'clear_cache' === $action ) {
            AhangBan_Cache::clear_all();
            add_settings_error( 'ahangban_connector', 'cleared', 'کش پاک شد.', 'updated' );
        }
    }

    public static function render() {
        if ( ! current_user_can( self::CAP ) ) { wp_die( 'دسترسی غیرمجاز' ); }
        settings_errors( 'ahangban_connector' );
        $token = AhangBan_Auth::get_token();
        $health_url = rest_url( 'ahangban/v1/health' );
        $base = rest_url( 'ahangban/v1/' );
        // Mask token for display
        $masked = $token ? substr( $token, 0, 8 ) . str_repeat( '•', 48 ) . substr( $token, -8 ) : '— توکن موجود نیست —';
        ?>
        <div class="wrap" dir="rtl" style="text-align:right">
            <h1>🔌 اتصال آهنگ‌بان — API ربات تلگرام</h1>
            <p>این افزونه داده‌های منتشرشدهٔ سایت را از طریق REST امن در اختیار ربات تلگرام قرار می‌دهد. نیازی به ویرایش قالب نیست.</p>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:20px;margin:16px 0;max-width:900px">
                <h2>وضعیت سامانه</h2>
                <table class="widefat" style="max-width:800px">
                    <tr><th>نسخهٔ افزونه</th><td><?php echo esc_html( AHANGBAN_CONNECTOR_VERSION ); ?></td></tr>
                    <tr><th>وردپرس</th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?> — PHP <?php echo esc_html( PHP_VERSION ); ?></td></tr>
                    <tr><th>تاکسونومی singer</th><td><?php echo taxonomy_exists( 'singer' ) ? '✅ فعال' : '❌ ثبت نشده'; ?></td></tr>
                    <tr><th>تعداد آثار منتشرشده</th><td><?php echo esc_html( number_format_i18n( (int) wp_count_posts( 'post' )->publish ) ); ?></td></tr>
                    <tr><th>تعداد خوانندگان</th><td><?php echo esc_html( taxonomy_exists( 'singer' ) ? number_format_i18n( (int) wp_count_terms( array( 'taxonomy' => 'singer', 'hide_empty' => false ) ) ) : '—' ); ?></td></tr>
                    <tr><th>نسخهٔ کش</th><td><?php echo esc_html( (string) AhangBan_Cache::get_version() ); ?></td></tr>
                    <tr><th>آدرس پایه API</th><td><code dir="ltr"><?php echo esc_html( $base ); ?></code></td></tr>
                </table>

                <h3>بررسی سلامت (بدون احراز هویت)</h3>
                <p><code dir="ltr"><?php echo esc_url( $health_url ); ?></code> — باید JSON با <code>status: ok</code> برگرداند.</p>
                <p><a class="button" href="<?php echo esc_url( $health_url ); ?>" target="_blank">باز کردن Health</a></p>
            </div>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:20px;margin:16px 0;max-width:900px">
                <h2>توکن احراز هویت</h2>
                <p>ربات با این توکن به API متصل می‌شود. آن را فقط در متغیر محیطی <code>AHANGBAN_API_TOKEN</code> ربات قرار دهید و هرگز در گیت کامیت نکنید.</p>
                <p>
                    <strong>ماسک‌شده:</strong> <code dir="ltr" style="user-select:all"><?php echo esc_html( $masked ); ?></code><br>
                    <button type="button" class="button" onclick="document.getElementById('ahangban-token-raw').style.display='block'; this.style.display='none'">نمایش کامل توکن</button>
                </p>
                <div id="ahangban-token-raw" style="display:none;background:#f6f7f7;border:1px dashed #c3c4c7;padding:12px;border-radius:8px;margin:8px 0">
                    <code dir="ltr" style="word-break:break-all;user-select:all;font-size:13px"><?php echo esc_html( $token ); ?></code>
                    <p style="margin:8px 0 0;color:#d63638"><strong>هشدار:</strong> توکن را کپی کرده و این صفحه را ببندید. از اشتراک اسکرین‌شات با توکن کامل خودداری کنید.</p>
                </div>

                <form method="post" style="margin-top:12px">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="regenerate">
                    <button type="submit" class="button button-secondary" onclick="return confirm('توکن فعلی باطل و توکن جدید ساخته می‌شود. ربات تا به‌روزرسانی توکن از کار می‌افتد. ادامه می‌دهید؟')">🔄 ساخت توکن جدید</button>
                </form>
            </div>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:20px;margin:16px 0;max-width:900px">
                <h2>مستندات سریع API</h2>
                <p>همهٔ درخواست‌ها به جز <code>/health</code> نیاز به هدر <code>Authorization: Bearer &lt;TOKEN&gt;</code> دارند.</p>
                <table class="widefat" style="max-width:900px">
                    <thead><tr><th>Endpoint</th><th>توضیح</th></tr></thead>
                    <tr><td><code>GET /search?q=محسن&amp;page=1&amp;per_page=5</code></td><td>جست‌وجوی آهنگ/خواننده (نرمال‌سازی فارسی + رتبه‌بندی)</td></tr>
                    <tr><td><code>GET /song/{id}</code></td><td>کارت کامل اثر + کیفیت‌ها + آثار مرتبط</td></tr>
                    <tr><td><code>GET /artists?q=&amp;letter=م&amp;sort=name&amp;page=1</code></td><td>فهرست خوانندگان با حرف و جست‌وجو</td></tr>
                    <tr><td><code>GET /artist/{slug_or_id}?page=1&amp;sort=new</code></td><td>پروفایل خواننده + آثار صفحه‌بندی</td></tr>
                    <tr><td><code>GET /latest?page=1&amp;per_page=5</code></td><td>جدیدترین آثار</td></tr>
                    <tr><td><code>GET /popular?page=1&amp;per_page=5</code></td><td>محبوب‌ترین ماه گذشته</td></tr>
                    <tr><td><code>POST /cache/clear</code></td><td>پاک‌سازی کش (نیاز به توکن)</td></tr>
                </table>
                <form method="post" style="margin-top:12px">
                    <?php wp_nonce_field( 'ahangban_connector_action' ); ?>
                    <input type="hidden" name="ahangban_action" value="clear_cache">
                    <button type="submit" class="button">🧹 پاک‌سازی کش</button>
                </form>
            </div>

            <div style="background:#fffbe5;border:1px solid #f0c040;border-radius:12px;padding:20px;margin:16px 0;max-width:900px">
                <h2>نکات امنیتی</h2>
                <ul style="list-style:disc;margin-right:20px">
                    <li>این افزونه فقط داده‌های <code>publish</code> را برمی‌گرداند — پیش‌نویس و خصوصی هرگز.</li>
                    <li>محدودیت نرخ: ۶۰ درخواست/دقیقه/IP و ۲۰۰/دقیقه/توکن.</li>
                    <li>توکن در <code>wp_options</code> با <code>autoload=no</code> ذخیره می‌شود.</li>
                    <li>هر تغییر پست/ترم، کش را به‌صورت خودکار باطل می‌کند.</li>
                </ul>
            </div>
        </div>
        <?php
    }
}
