<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AhangBan Telegram — وب‌هوک PHP داخل وردپرس (بدون نیاز به سرور جدا)
 * برای هاست اشتراکی دایرکت‌ادمین: کافیست وب‌هوک ست شود، پیام‌ها مستقیم روی همین هاست هندل می‌شوند.
 */
class AhangBan_Telegram {
    const NS = 'ahangban/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
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

    public static function handle_webhook( WP_REST_Request $req ) {
        // GET برای تست سلامت
        if ( 'GET' === $req->get_method() ) {
            return rest_ensure_response( array(
                'status' => 'ok',
                'message' => 'وب‌هوک آهنگ‌بان فعال است. تلگرام باید POST کند.',
                'configured' => AhangBan_Settings::is_bot_configured(),
                'mode' => AhangBan_Settings::get_mode(),
            ) );
        }

        // احراز secret header (اختیاری ولی توصیه‌شده)
        $secret = $req->get_header( 'x-telegram-bot-api-secret-token' );
        $expected = AhangBan_Settings::get_bot_secret();
        if ( '' !== $expected && '' !== $secret && ! hash_equals( $expected, $secret ) ) {
            return new WP_Error( 'forbidden', 'Secret نامعتبر', array( 'status' => 403 ) );
        }

        $body = $req->get_body();
        if ( '' === $body ) { $body = file_get_contents( 'php://input' ); }
        $update = json_decode( $body, true );
        if ( ! is_array( $update ) ) {
            return rest_ensure_response( array( 'ok' => true, 'note' => 'empty' ) );
        }

        // جلوگیری از پردازش تکراری
        $uid = isset( $update['update_id'] ) ? (int) $update['update_id'] : 0;
        if ( $uid ) {
            $key = 'ahangban_tg_' . $uid;
            if ( get_transient( $key ) ) {
                return rest_ensure_response( array( 'ok' => true, 'duplicate' => true ) );
            }
            set_transient( $key, 1, 300 );
        }

        // پردازش (سریع پاسخ بده تا تلگرام تایم‌اوت نخورد)
        try {
            self::process_update( $update );
        } catch ( Exception $e ) {
            error_log( '[AhangBan Telegram] ' . $e->getMessage() );
        }

        return rest_ensure_response( array( 'ok' => true ) );
    }

    public static function webhook_status( $req ) {
        $token = AhangBan_Settings::get_bot_token();
        if ( '' === $token ) {
            return new WP_Error( 'no_token', 'توکن ربات تنظیم نشده.', array( 'status' => 400 ) );
        }
        $resp = wp_remote_get( 'https://api.telegram.org/bot' . $token . '/getWebhookInfo', array( 'timeout' => 10 ) );
        if ( is_wp_error( $resp ) ) { return $resp; }
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return rest_ensure_response( $data );
    }

    // ---------- Telegram API helpers ----------
    private static function api_call( $method, $params = array() ) {
        $token = AhangBan_Settings::get_bot_token();
        if ( '' === $token ) { return new WP_Error( 'no_token', 'توکن تنظیم نشده' ); }
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $resp = wp_remote_post( $url, array(
            'timeout' => 12,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
        ) );
        if ( is_wp_error( $resp ) ) {
            error_log( '[AhangBan Telegram] API error: ' . $resp->get_error_message() );
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $json = json_decode( $body, true );
        if ( $code >= 400 || ( isset( $json['ok'] ) && ! $json['ok'] ) ) {
            error_log( '[AhangBan Telegram] API '. $method .' failed: '. $body );
        }
        return $json;
    }

    private static function send( $chat_id, $text, $kb = null, $parse = 'HTML' ) {
        $p = array( 'chat_id' => $chat_id, 'text' => $text );
        if ( $parse ) { $p['parse_mode'] = $parse; }
        $p['disable_web_page_preview'] = false;
        if ( $kb ) { $p['reply_markup'] = $kb; }
        return self::api_call( 'sendMessage', $p );
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

    // ---------- Core logic ----------
    public static function process_update( $u ) {
        // Rate limit ساده per sender
        $from_id = 0;
        if ( isset( $u['message']['from']['id'] ) ) { $from_id = (int) $u['message']['from']['id']; }
        elseif ( isset( $u['callback_query']['from']['id'] ) ) { $from_id = (int) $u['callback_query']['from']['id']; }
        if ( $from_id ) {
            $rl_key = 'ahangban_rl_tg_' . $from_id;
            $cnt = (int) get_transient( $rl_key );
            if ( $cnt >= 20 ) { return; } // 20 msg/min
            set_transient( $rl_key, $cnt + 1, 60 );
            if ( 0 === $cnt ) { set_transient( $rl_key, 1, 60 ); }
        }

        if ( isset( $u['callback_query'] ) ) {
            self::handle_callback( $u['callback_query'] );
            return;
        }
        if ( isset( $u['message']['text'] ) ) {
            $msg = $u['message'];
            $text = trim( (string) $msg['text'] );
            $chat = (int) $msg['chat']['id'];

            if ( '' !== $text && '/' === $text[0] ) {
                $cmd = strtolower( strtok( $text, ' @' ) );
                $arg = trim( substr( $text, strlen( $cmd ) ) );
                switch ( $cmd ) {
                    case '/start': self::handle_start( $chat, $msg ); return;
                    case '/help': self::handle_help( $chat ); return;
                    case '/ping': self::handle_ping( $chat ); return;
                    case '/latest': self::handle_latest( $chat, 1 ); return;
                    case '/popular': self::handle_popular( $chat, 1 ); return;
                    case '/search':
                        if ( '' === $arg ) { self::send( $chat, "🔍 استفاده: /search <نام>\nمثال: /search محسن یگانه" ); return; }
                        self::handle_search( $chat, $arg, 1 ); return;
                    case '/artist':
                        if ( '' === $arg ) { self::send( $chat, "🎤 استفاده: /artist <نام>\nمثال: /artist گوگوش" ); return; }
                        self::handle_artist_by_name( $chat, $arg, 1 ); return;
                    case '/stats':
                    case '/admin':
                        self::handle_stats( $chat, $from_id ); return;
                }
            }
            // دکمه‌های کیبورد ریپلای
            $shortcuts = array(
                '🔍 جست‌وجوی آهنگ' => function() use ($chat){ self::send($chat, "🎵 نام آهنگ را بنویسید (مثلاً: بی‌تاب محسن یگانه):"); },
                '👤 جست‌وجوی خواننده' => function() use ($chat){ self::send($chat, "🎤 نام خواننده را بنویسید (مثلاً: معین، گوگوش):"); },
                '🆕 جدیدترین‌ها' => function() use ($chat){ self::handle_latest($chat,1); },
                '🔥 محبوب‌ترین‌ها' => function() use ($chat){ self::handle_popular($chat,1); },
                '🎤 فهرست خوانندگان' => function() use ($chat){ self::handle_artists_list($chat,1); },
                'ℹ️ راهنما' => function() use ($chat){ self::handle_help($chat); },
            );
            if ( isset( $shortcuts[ $text ] ) ) { $shortcuts[ $text ](); return; }

            // پیش‌فرض: جست‌وجو
            self::handle_search( $chat, $text, 1 );
        }
    }

    // ---- Helpers: branding ----
    private static function welcome_text( $name = '' ) {
        $n = $name ? ' '. $name : '';
        return "سلام{$n} عزیز! 👋\n\nبه <b>ربات رسمی آهنگ‌بان</b> خوش آمدید 🎶\nاینجا جدیدترین آهنگ‌ها را جست‌وجو، گوش دهید و دانلود کنید — مستقیم از آرشیو واقعی سایت.\n\n✨ امکانات:\n• 🔍 جست‌وجو بر اساس نام آهنگ یا خواننده\n• 🎤 مرور همهٔ خوانندگان الفبایی\n• 🆕 جدیدترین و 🔥 محبوب‌ترین\n• 💿 کیفیت ۱۲۸/۳۲۰ + ویدیو\n• 📝 متن ترانه و آثار مرتبط\n\nنام آهنگ یا خواننده را بنویسید یا یکی از دکمه‌ها را بزنید.";
    }

    private static function help_text() {
        return "ℹ️ <b>راهنمای ربات آهنگ‌بان</b>\n\n🔍 <b>جست‌وجو:</b> نام آهنگ/خواننده را تایپ کن.\n🎤 <b>فهرست خوانندگان:</b> دکمهٔ «فهرست خوانندگان».\n🆕 <b>جدیدترین / محبوب:</b> آخرین یا پرشنونده‌ترین.\n🎵 <b>کارت آهنگ:</b> کاور + کیفیت‌ها + متن + صفحه سایت.\n\n📌 دستورات:\n/start — شروع\n/search <عبارت>\n/latest — جدیدترین\n/popular — محبوب‌ترین\n/artist <نام>\n/help\n/ping\n\n🔗 https://ahangban.ir";
    }

    private static function main_kb() {
        return array(
            'inline_keyboard' => array(
                array( array( 'text' => '🔍 جست‌وجو', 'switch_inline_query_current_chat' => '' ) ),
                array( array( 'text' => '🆕 جدیدترین‌ها', 'callback_data' => 'browse:latest:1' ), array( 'text' => '🔥 محبوب‌ترین‌ها', 'callback_data' => 'browse:popular:1' ) ),
                array( array( 'text' => '🎤 همهٔ خوانندگان', 'callback_data' => 'artists:page:1' ) ),
                array( array( 'text' => 'ℹ️ راهنما', 'callback_data' => 'help' ) ),
            )
        );
    }

    private static function reply_kb() {
        return array(
            'keyboard' => array(
                array( array( 'text' => '🔍 جست‌وجوی آهنگ' ), array( 'text' => '👤 جست‌وجوی خواننده' ) ),
                array( array( 'text' => '🆕 جدیدترین‌ها' ), array( 'text' => '🔥 محبوب‌ترین‌ها' ) ),
                array( array( 'text' => '🎤 فهرست خوانندگان' ), array( 'text' => 'ℹ️ راهنما' ) ),
            ),
            'resize_keyboard' => true,
        );
    }

    private static function home_kb() {
        return array( 'inline_keyboard' => array( array( array( 'text' => '🏠 خانه', 'callback_data' => 'home' ) ) ) );
    }

    private static function esc( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }

    private static function song_caption( $s ) {
        $title = self::esc( $s['title'] ?? $s['song'] ?? '—' );
        $song  = self::esc( $s['song'] ?? '' );
        $artist= self::esc( $s['artist'] ?? '' );
        $type  = self::esc( $s['type'] ?? '' );
        $date  = self::esc( $s['date_jalali'] ?? '' );
        $views = isset( $s['views'] ) ? (int) $s['views'] : 0;
        $link  = $s['permalink'] ?? '';
        $lines = array( "🎵 <b>{$title}</b>" );
        if ( $song && $song !== $title ) { $lines[] = "🎶 اثر: <b>{$song}</b>"; }
        if ( $artist ) { $lines[] = "👤 خواننده: <b>{$artist}</b>"; }
        if ( $type ) { $lines[] = "🏷 نوع: {$type}"; }
        if ( $date ) { $lines[] = "📅 انتشار: {$date}"; }
        if ( $views ) { $lines[] = "👁 بازدید: ". number_format_i18n( $views ); }
        if ( $link ) { $lines[] = '🔗 <a href="'. esc_url( $link ) .'">صفحه در آهنگ‌بان</a>'; }
        return implode( "\n", $lines );
    }

    private static function artist_caption( $term, $buckets = null ) {
        $name = self::esc( $term['name'] ?? '—' );
        $count= isset($term['count']) ? (int)$term['count'] : 0;
        $desc = $term['description'] ?? '';
        if ( $desc ) {
            $desc = wp_strip_all_tags( $desc );
            if ( mb_strlen( $desc ) > 300 ) { $desc = mb_substr( $desc, 0, 300 ) . '…'; }
            $desc = self::esc( $desc );
        }
        $lines = array( "🎤 <b>{$name}</b>" );
        if ( $count ) { $lines[] = "🎵 تعداد آثار: <b>{$count}</b>"; }
        if ( $buckets ) {
            $parts = array();
            foreach ( array('audio'=>'آهنگ','album'=>'آلبوم','video'=>'موزیک‌ویدیو','other'=>'سایر') as $k=>$lb) {
                if ( ! empty( $buckets[ $k ] ) ) { $parts[] = $buckets[$k].' '.$lb; }
            }
            if ( $parts ) { $lines[] = "📊 ". implode(' · ', $parts ); }
        }
        if ( $desc ) { $lines[] = "\n". $desc; }
        if ( ! empty( $term['link'] ) ) { $lines[] = "\n". '🔗 <a href="'. esc_url($term['link']).'">صفحه در سایت</a>'; }
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
        foreach ( array_slice($artists,0,2) as $a) {
            $rows[] = array( array('text'=>'👤 '. $a['name'],'callback_data'=>'artist:'.($a['slug'] ?? $a['term_id'])) );
        }
        if( !empty($song['lyrics']) ){ $rows[] = array( array('text'=>'📝 متن ترانه','callback_data'=>"lyrics:{$id}") ); }
        if( !empty($song['related']) ){ $rows[] = array( array('text'=>'🎧 آثار مرتبط','callback_data'=>"related:{$id}") ); }
        if( !empty($song['permalink']) ){ $rows[] = array( array('text'=>'🔗 صفحه در سایت','url'=>$song['permalink']) ); }
        $rows[] = array( array('text'=>'🔍 جست‌وجوی جدید','switch_inline_query_current_chat'=>''), array('text'=>'🏠 خانه','callback_data'=>'home') );
        return array('inline_keyboard'=>$rows);
    }

    private static function is_valid_query( $t ) {
        $t = trim($t);
        if ( ''===$t || mb_strlen($t)<2 || mb_strlen($t)>100 ) { return false; }
        if ( preg_match('~https?://~i',$t) ) { return false; }
        return true;
    }

    // ---- Handlers ----
    private static function handle_start( $chat, $msg ) {
        $name = $msg['from']['first_name'] ?? '';
        self::send( $chat, self::welcome_text( $name ), self::main_kb() );
        // reply keyboard هم برای راحتی
        self::api_call( 'sendMessage', array( 'chat_id'=>$chat, 'text'=>"⬇️ میانبر سریع:", 'reply_markup'=> self::reply_kb() ) );
    }

    private static function handle_help( $chat ) {
        self::send( $chat, self::help_text(), self::main_kb() );
    }

    private static function handle_ping( $chat ) {
        $h = array( 'status'=>'ok','wp'=>get_bloginfo('version'),'php'=>PHP_VERSION,'taxonomy'=>AhangBan_Search::get_taxonomy(),'exists'=>taxonomy_exists(AhangBan_Search::get_taxonomy()),'count'=> (int) wp_count_posts('post')->publish );
        $t = "✅ اتصال برقرار است\n🔧 وردپرس: {$h['wp']}\n🐍 PHP: {$h['php']}\n🎤 تاکسونومی: {$h['taxonomy']} ".($h['exists']?'فعال':'غیرفعال')."\n🎵 آثار: {$h['count']}";
        self::send( $chat, $t );
    }

    private static function handle_search( $chat, $q, $page ) {
        $q = trim($q);
        if ( ! self::is_valid_query($q) ) { self::send($chat,"⚠️ عبارت نامعتبر — حداقل ۲ و حداکثر ۱۰۰ نویسه، بدون لینک."); return; }
        $data = AhangBan_Search::query_posts( $q, '', $page, 5 );
        $results = $data['results'] ?? array();
        $total = (int)($data['total'] ?? 0);
        if ( empty($results) ) {
            $msg = "😕 نتیجه‌ای برای «".self::esc($q)."» یافت نشد.\n\nپیشنهاد:\n• املای نام را چک کنید (ی/ي، ک/ك فرقی نمی‌کند)\n• بخشی از نام را بنویسید\n• از «فهرست خوانندگان» استفاده کنید";
            self::send( $chat, $msg, self::home_kb() );
            return;
        }
        $per = (int)($data['per_page'] ?? 5);
        $pages = max(1, (int)ceil($total/$per));
        $head = "🔍 نتایج «<b>".self::esc($q)."</b>» — ".number_format_i18n($total)." مورد (صفحه {$page})";
        $lines = array($head,'');
        $start = ($page-1)*$per;
        foreach ($results as $i=>$r){
            $idx = $start+$i+1;
            $t = self::esc($r['song'] ?? $r['title'] ?? '—');
            $a = self::esc($r['artist'] ?? '');
            $ty= self::esc($r['type'] ?? '');
            $lines[] = $idx.". <b>{$t}</b>".($a?" — {$a}":"")." <i>({$ty})</i>";
        }
        if($pages>1){ $lines[]=""; $lines[]="📄 صفحه {$page} از {$pages}"; }
        $text = implode("\n",$lines);
        $rows=array();
        foreach($results as $r){ $sid=(int)($r['id']??0); $tt=mb_substr($r['song']??$r['title']??'',0,30); $ar=mb_substr($r['artist']??'',0,20); $lb=$ar? $tt.' — '.$ar : $tt; $rows[]=array(array('text'=>'🎵 '.$lb,'callback_data'=>"song:{$sid}")); }
        $nav=array();
        if($page>1){ $nav[]=array('text'=>'◀️ قبلی','callback_data'=>"search:".($page-1).":".base64_encode($q)); }
        $nav[]=array('text'=>"{$page}/{$pages}",'callback_data'=>'noop');
        if($page<$pages){ $nav[]=array('text'=>'بعدی ▶️','callback_data'=>"search:".($page+1).":".base64_encode($q)); }
        $rows[]=$nav;
        $rows[]=array(array('text'=>'🏠 خانه','callback_data'=>'home'));
        self::send( $chat, $text, array('inline_keyboard'=>$rows) );
    }

    private static function handle_latest( $chat, $page ) {
        $data = AhangBan_Search::get_latest( $page, 5 );
        self::send_browse( $chat, $data, $page, 'latest', '🆕 جدیدترین آهنگ‌ها' );
    }
    private static function handle_popular( $chat, $page ) {
        $data = AhangBan_Search::get_popular( $page, 5 );
        self::send_browse( $chat, $data, $page, 'popular', '🔥 محبوب‌ترین‌های ماه' );
    }
    private static function send_browse( $chat, $data, $page, $kind, $title ) {
        $results = $data['results'] ?? array();
        $total=(int)($data['total'] ?? count($results));
        $per=(int)($data['per_page'] ?? 5);
        $pages=max(1,(int)ceil($total/$per));
        if(empty($results)){ self::send($chat,"فعلاً موردی یافت نشد."); return; }
        $lines=array("{$title} — صفحه {$page} از {$pages}",'');
        $start=($page-1)*$per;
        foreach($results as $i=>$r){ $idx=$start+$i+1; $t=self::esc($r['song']??$r['title']??'—'); $a=self::esc($r['artist']??''); $ty=self::esc($r['type']??''); $lines[]="{$idx}. <b>{$t}</b>".($a?" — {$a}":"")." <i>({$ty})</i>"; }
        $rows=array();
        foreach($results as $r){ $sid=(int)($r['id']??0); $tt=mb_substr($r['song']??$r['title']??'',0,30); $ar=mb_substr($r['artist']??'',0,20); $lb=$ar? $tt.' — '.$ar : $tt; $rows[]=array(array('text'=>'🎵 '.$lb,'callback_data'=>"song:{$sid}")); }
        $nav=array();
        if($page>1){ $nav[]=array('text'=>'◀️ قبلی','callback_data'=>"browse:{$kind}:".($page-1)); }
        $nav[]=array('text'=>"{$page}/{$pages}",'callback_data'=>'noop');
        if($page<$pages){ $nav[]=array('text'=>'بعدی ▶️','callback_data'=>"browse:{$kind}:".($page+1)); }
        $rows[]=$nav;
        $rows[]=array(array('text'=>'🏠 خانه','callback_data'=>'home'));
        self::send($chat, implode("\n",$lines), array('inline_keyboard'=>$rows));
    }

    private static function handle_artists_list( $chat, $page, $letter='', $q='' ) {
        $data = AhangBan_Search::get_artists( array('q'=>$q,'letter'=>$letter,'sort'=>'name','page'=>$page,'per_page'=>10) );
        if(is_wp_error($data)){ self::send($chat,"❌ ". $data->get_error_message()); return; }
        $results=$data['results']??array(); $total=(int)($data['total']??0); $per=(int)($data['per_page']??10); $pages=max(1,(int)ceil($total/max(1,$per)));
        if(empty($results)){ self::send($chat,"😕 خواننده‌ای یافت نشد."); return; }
        $head="🎤 <b>فهرست خوانندگان</b> — ".number_format_i18n($total)." هنرمند";
        if($letter) $head.=" (حرف «".self::esc($letter)."»)";
        $head.="\nصفحه {$page} از {$pages}\n";
        $lines=array($head);
        $rows=array();
        foreach($results as $it){ $lines[]="• <b>".self::esc($it['name'])."</b> — {$it['count']} اثر"; $rows[]=array(array('text'=>"🎤 {$it['name']} ({$it['count']})",'callback_data'=>"artist:".($it['slug']??$it['term_id']))); }
        // حروف الفبا (۸تایی)
        $letters = preg_split('//u','ابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی',-1,PREG_SPLIT_NO_EMPTY);
        $lr=array();
        foreach(array_slice($letters,0,8) as $ch){ $lr[]=array('text'=>$ch,'callback_data'=>"artists:letter:{$ch}"); if(count($lr)==4){ $rows[]=$lr; $lr=array(); }}
        if($lr) $rows[]=$lr;
        for($i=8;$i<count($letters);$i+=6){ $chunk=array_slice($letters,$i,6); $r=array(); foreach($chunk as $c){ $r[]=array('text'=>$c,'callback_data'=>"artists:letter:{$c}"); } $rows[]=$r; }
        $nav=array();
        if($page>1){ $nav[]=array('text'=>'◀️ قبلی','callback_data'=>"artists:page:".($page-1)); }
        $nav[]=array('text'=>"{$page}/{$pages}",'callback_data'=>'noop');
        if($page<$pages){ $nav[]=array('text'=>'بعدی ▶️','callback_data'=>"artists:page:".($page+1)); }
        $rows[]=$nav;
        $rows[]=array(array('text'=>'🏠 خانه','callback_data'=>'home'));
        self::send($chat, implode("\n",$lines), array('inline_keyboard'=>$rows));
    }

    private static function handle_artist_by_name( $chat, $name, $page ) {
        // جست‌وجو در artists سپس نمایش اولین تطابق
        $list = AhangBan_Search::get_artists( array('q'=>$name,'page'=>1,'per_page'=>5,'sort'=>'name') );
        if(is_wp_error($list) || empty($list['results'])){ self::send($chat,"😕 خواننده‌ای با نام «".self::esc($name)."» یافت نشد."); return; }
        $first = $list['results'][0];
        self::handle_artist( $chat, $first['slug'] ?? $first['term_id'], $page, 'new' );
    }

    private static function handle_artist( $chat, $slug, $page, $sort ) {
        $data = AhangBan_Search::get_artist_detail( $slug, $page, 6, $sort );
        if(is_wp_error($data)){ self::send($chat,"❌ ". $data->get_error_message()); return; }
        $term=$data['term']??array(); $buckets=$data['buckets']??array(); $results=$data['results']??array(); $total=(int)($data['total']??0); $per=(int)($data['per_page']??6); $pages=max(1,(int)ceil($total/max(1,$per)));
        $cap = self::artist_caption($term,$buckets);
        if($results){
            $cap.="\n\n🎵 <b>آثار (صفحه {$page} از {$pages}):</b>\n";
            $start=($page-1)*$per;
            foreach($results as $i=>$s){ $idx=$start+$i+1; $t=self::esc($s['song']??$s['title']??'—'); $ar=self::esc($s['artist']??''); $ty=self::esc($s['type']??''); $cap.="\n{$idx}. <b>{$t}</b>".($ar?" — {$ar}":"")." <i>({$ty})</i>"; }
        } else { $cap.="\n\n😕 هنوز اثری ثبت نشده."; }
        $rows=array();
        foreach($results as $s){ $sid=(int)($s['id']??0); $tt=mb_substr($s['song']??$s['title']??'',0,30); $rows[]=array(array('text'=>'🎵 '.$tt,'callback_data'=>"song:{$sid}")); }
        $sortRow=array(
            array('text'=>'🕐 جدیدترین'.($sort==='new'?' ✅':''),'callback_data'=>"artist:{$slug}:1:new"),
            array('text'=>'📅 قدیمی‌ترین'.($sort==='old'?' ✅':''),'callback_data'=>"artist:{$slug}:1:old"),
        );
        $rows[]=$sortRow;
        $nav=array();
        if($page>1){ $nav[]=array('text'=>'◀️ قبلی','callback_data'=>"artist:{$slug}:".($page-1).":{$sort}"); }
        $nav[]=array('text'=>"{$page}/{$pages}",'callback_data'=>'noop');
        if($page<$pages){ $nav[]=array('text'=>'بعدی ▶️','callback_data'=>"artist:{$slug}:".($page+1).":{$sort}"); }
        if($nav) $rows[]=$nav;
        if(!empty($term['link'])){ $rows[]=array(array('text'=>'🔗 صفحه در سایت','url'=>$term['link'])); }
        $rows[]=array(array('text'=>'🏠 خانه','callback_data'=>'home'));
        $kb=array('inline_keyboard'=>$rows);
        $photo = $term['image'] ?? '';
        if($photo && $page==1){
            $r=self::send_photo($chat,$photo,$cap,$kb);
            if(is_wp_error($r) || (isset($r['ok']) && !$r['ok'])){ self::send($chat,$cap,$kb); }
        } else { self::send($chat,$cap,$kb); }
    }

    private static function handle_stats( $chat, $from_id ) {
        if(!AhangBan_Settings::is_admin($from_id)){ self::send($chat,"⛔ این دستور فقط برای ادمین است."); return; }
        $users = 0; // بدون DB جدا در حالت وب‌هوک — شمار ساده
        $h = array('wp'=>get_bloginfo('version'),'php'=>PHP_VERSION,'taxonomy'=>AhangBan_Search::get_taxonomy(),'exists'=>taxonomy_exists(AhangBan_Search::get_taxonomy()),'count'=>(int)wp_count_posts('post')->publish);
        $t="📊 <b>آمار آهنگ‌بان</b>\n\n👥 حالت: وب‌هوک (بدون DB جدا)\n🔧 WP: {$h['wp']} | PHP: {$h['php']}\n🎤 تاکسونومی: {$h['taxonomy']} ".($h['exists']?'فعال':'غیرفعال')."\n🎵 آثار: {$h['count']}";
        self::send($chat,$t);
    }

    private static function handle_callback( $cb ) {
        $data = $cb['data'] ?? '';
        $uid  = $cb['id'] ?? '';
        $chat = (int)($cb['message']['chat']['id'] ?? 0);
        $mid  = (int)($cb['message']['message_id'] ?? 0);
        if(''===$data || !$chat) { if($uid) self::answer_cb($uid); return; }

        // noop/home/help
        if('noop'===$data){ self::answer_cb($uid,'—'); return; }
        if('home'===$data){ self::answer_cb($uid); self::handle_start($chat, $cb['message']); // reuse
            // edit to home
            self::edit_text($chat,$mid,"🏠 <b>منوی اصلی آهنگ‌بان</b>\nنام آهنگ/خواننده را بنویسید یا گزینه‌ای را انتخاب کنید.", self::main_kb());
            return;
        }
        if('help'===$data){ self::answer_cb($uid); self::send($chat, self::help_text(), self::main_kb()); return; }

        // song:123
        if(str_starts_with($data,'song:')){
            $id=(int)substr($data,5); self::answer_cb($uid);
            $song=AhangBan_Search::get_single($id);
            if(!$song){ self::send($chat,"❌ آهنگ یافت نشد."); return; }
            $cap=self::song_caption($song);
            $kb=self::song_kb($song);
            $photo=$song['cover'] ?? '';
            if($photo){
                $r=self::send_photo($chat,$photo,$cap,$kb);
                if(is_wp_error($r) || (isset($r['ok'])&&!$r['ok'])){ self::send($chat,$cap,$kb); }
            } else { self::send($chat,$cap,$kb); }
            return;
        }
        // dl:123:320
        if(str_starts_with($data,'dl:')){
            $parts=explode(':',$data);
            if(count($parts)!==3){ self::answer_cb($uid); return; }
            [$_,$sid,$qual]=$parts; $sid=(int)$sid; self::answer_cb($uid,'⏳ بررسی لینک…');
            $song=AhangBan_Search::get_single($sid);
            if(!$song){ self::send($chat,"❌ آهنگ یافت نشد."); return; }
            $quals=$song['qualities']??array();
            $url=$quals[$qual] ?? '';
            if(''===$url){ self::send($chat,"❌ کیفیت {$qual} موجود نیست.\nموجود: ". implode(', ',array_keys($quals))); return; }
            // اگر عکس خصوصی باشد، اجازه نده
            $host=parse_url($url,PHP_URL_HOST) ?: '';
            if(in_array($host,array('127.0.0.1','localhost','0.0.0.0'),true) || str_starts_with($host,'10.') || str_starts_with($host,'192.168.')){ self::send($chat,"⛔ لینک نامعتبر."); return; }
            // ارسال مستقیم via URL (تلگرام خودش fetch می‌کند) — بدون HEAD سنگین
            $r=self::api_call('sendAudio', array('chat_id'=>$chat,'audio'=>$url,'caption'=>"🎵 ".($song['song']??$song['title'])." — ".($song['artist']??'')."\n💿 کیفیت: {$qual}\n🔗 ".($song['permalink']??''),'title'=>$song['song']??$song['title']??'آهنگ','performer'=>$song['artist']??'آهنگ‌بان'));
            if(is_wp_error($r) || (isset($r['ok'])&&!$r['ok'])){
                // fallback لینک
                self::send($chat,"⚠️ ارسال مستقیم ممکن نشد.\n🔗 لینک دانلود {$qual}:\n{$url}\n\nصفحه اثر: ".($song['permalink']??''));
            }
            return;
        }
        if(str_starts_with($data,'lyrics:')){
            $id=(int)substr($data,7); self::answer_cb($uid);
            $song=AhangBan_Search::get_single($id);
            if(!$song){ self::send($chat,"❌ یافت نشد."); return; }
            $lyrics = $song['lyrics'] ?? '';
            if(''===$lyrics){ self::send($chat,"متنی برای این اثر ثبت نشده."); return; }
            $text = wp_strip_all_tags( str_replace(array('</p>','<br />','<br>','<br/>'),"\n",$lyrics) );
            $text = html_entity_decode($text,ENT_QUOTES,'UTF-8');
            $text = trim(preg_replace('/\n{3,}/',"\n\n",$text));
            $head="📝 متن ترانهٔ «".self::esc($song['song']??$song['title']??'')."»".(!empty($song['artist'])?" از ".self::esc($song['artist']):'');
            $full=$head."\n".str_repeat('─',20)."\n".$text;
            if(mb_strlen($full)>4000){ $full=mb_substr($full,0,4000)."…\n\n(متن کامل در سایت)"; }
            self::send($chat,$full);
            return;
        }
        if(str_starts_with($data,'related:')){
            $id=(int)substr($data,8); self::answer_cb($uid);
            $song=AhangBan_Search::get_single($id);
            $rel=$song['related']??array();
            if(empty($rel)){ self::send($chat,"اثر مرتبطی یافت نشد."); return; }
            $rows=array();
            foreach($rel as $r){ $sid=(int)($r['id']??0); $tt=mb_substr($r['song']??$r['title']??'',0,30); $ar=mb_substr($r['artist']??'',0,20); $lb=$ar? $tt.' — '.$ar : $tt; $rows[]=array(array('text'=>'🎵 '.$lb,'callback_data'=>"song:{$sid}")); }
            $rows[]=array(array('text'=>'🏠 خانه','callback_data'=>'home'));
            self::send($chat,"🎧 آثار مرتبط با «".self::esc($song['song']??$song['title']??'')."»:", array('inline_keyboard'=>$rows));
            return;
        }
        if(str_starts_with($data,'album:')){
            $id=(int)substr($data,6); self::answer_cb($uid);
            $song=AhangBan_Search::get_single($id);
            $tracks=$song['album_tracks']??array();
            if(empty($tracks)){ self::send($chat,"ترکی یافت نشد."); return; }
            $lines=array("💿 <b>ترک‌های آلبوم «".self::esc($song['song']??$song['title']??'')."»</b>",'');
            $rows=array();
            foreach($tracks as $i=>$t){ $idx=$i+1; $nm=self::esc($t['song_names'] ?? "ترک {$idx}"); $lines[]="{$idx}. {$nm}"; $r=array(); if(!empty($t['albumlink128'])){ $r[]=array('text'=>"⬇️128 #{$idx}",'url'=>$t['albumlink128']); } if(!empty($t['albumlink320'])){ $r[]=array('text'=>"⬇️320 #{$idx}",'url'=>$t['albumlink320']); } if($r) $rows[]=$r; }
            $rows[]=array(array('text'=>'🏠 خانه','callback_data'=>'home'));
            self::send($chat, implode("\n",$lines), array('inline_keyboard'=>$rows));
            return;
        }
        if(str_starts_with($data,'browse:')){
            $p=explode(':',$data); // browse:latest:2
            $kind=$p[1]??'latest'; $pg=(int)($p[2]??1); self::answer_cb($uid);
            if('latest'===$kind){ self::handle_latest($chat,$pg); } else { self::handle_popular($chat,$pg); }
            return;
        }
        if(str_starts_with($data,'search:')){
            // search:2:base64(q)
            $p=explode(':',$data,3);
            $pg=(int)($p[1]??1); $qb64=$p[2]??''; $q=base64_decode($qb64,true) ?: '';
            self::answer_cb($uid);
            self::handle_search($chat,$q,$pg);
            return;
        }
        if(str_starts_with($data,'artists:')){
            $parts=explode(':',$data);
            if($parts[1]==='page'){ $pg=(int)($parts[2]??1); self::answer_cb($uid); self::handle_artists_list($chat,$pg); return; }
            if($parts[1]==='letter'){ $ch=$parts[2]??''; self::answer_cb($uid); self::handle_artists_list($chat,1,$ch); return; }
        }
        if(str_starts_with($data,'artist:')){
            $parts=explode(':',$data);
            // artist:slug  or artist:slug:2:new
            if(count($parts)==2){ self::answer_cb($uid); self::handle_artist($chat,$parts[1],1,'new'); return; }
            if(count($parts)==4){ self::answer_cb($uid); self::handle_artist($chat,$parts[1],(int)$parts[2],$parts[3]); return; }
        }
        self::answer_cb($uid);
    }
}
