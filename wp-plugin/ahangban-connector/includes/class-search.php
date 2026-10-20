<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AhangBan_Search {

    /**
     * نام تاکسونومی هنرمند — سازگار با مهاجرت singer→artist
     * اگر هنوز 'artist' ثبت نشده ولی 'singer' قدیمی وجود دارد، به‌صورت خودکار fallback می‌کند.
     */
    public static function get_taxonomy() {
        if ( taxonomy_exists( 'artist' ) ) { return 'artist'; }
        if ( taxonomy_exists( 'singer' ) ) { return 'singer'; } // سازگاری موقت
        return 'artist'; // پیش‌فرض جدید
    }

    /**
     * Normalize Persian text for search: ی/ي, ک/ك, ه/ة, ZWNJ/ZWJ, Tashkeel, extra spaces.
     */
    public static function normalize( $text ) {
        $text = (string) $text;
        $text = str_replace( array( "\xE2\x80\x8C", "\xE2\x80\x8D" ), ' ', $text ); // ZWNJ, ZWJ
        $text = str_replace( array( 'ي', 'ك', 'ة', 'ۀ', 'ؤ', 'أ', 'إ', 'آ', 'ٱ', 'ئ', 'ى' ), array( 'ی', 'ک', 'ه', 'ه', 'و', 'ا', 'ا', 'ا', 'ا', 'ی', 'ی' ), $text );
        // Remove tashkeel + kashida. Invalid UTF-8 is treated as empty rather than passed to SQL.
        $text = preg_replace( '/[\x{064B}-\x{0652}\x{0640}\x{0670}]/u', '', $text );
        if ( null === $text ) { return ''; }
        $text = preg_replace( '/\s+/u', ' ', $text );
        return null === $text ? '' : trim( $text );
    }

    /** UTF-8 helpers with safe fallbacks for hosts that do not enable mbstring. */
    public static function text_length( $text ) {
        $text = (string) $text;
        if ( function_exists( 'mb_strlen' ) ) { return mb_strlen( $text, 'UTF-8' ); }
        $characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $characters ) ? count( $characters ) : strlen( $text );
    }

    private static function text_substr( $text, $start, $length ) {
        $text = (string) $text;
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $text, (int) $start, (int) $length, 'UTF-8' ); }
        $characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $characters ) ? implode( '', array_slice( $characters, (int) $start, (int) $length ) ) : substr( $text, (int) $start, (int) $length );
    }

    private static function text_lower( $text ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
    }

    private static function text_position( $haystack, $needle ) {
        return function_exists( 'mb_strpos' )
            ? mb_strpos( (string) $haystack, (string) $needle, 0, 'UTF-8' )
            : strpos( (string) $haystack, (string) $needle );
    }

    /** Add the same verified-music constraint to all public music queries. */
    private static function query_music_posts( $args ) {
        $args['ahangban_music_only'] = true;
        add_filter( 'posts_where', array( __CLASS__, 'filter_music_posts_where' ), 10, 2 );
        $title_sort = ! empty( $args['ahangban_sort_title'] );
        if ( $title_sort ) { add_filter( 'posts_clauses', array( __CLASS__, 'filter_artist_title_clauses' ), 10, 2 ); }
        try {
            return new WP_Query( $args );
        } finally {
            remove_filter( 'posts_where', array( __CLASS__, 'filter_music_posts_where' ), 10 );
            if ( $title_sort ) { remove_filter( 'posts_clauses', array( __CLASS__, 'filter_artist_title_clauses' ), 10 ); }
        }
    }

    public static function filter_artist_title_clauses( $clauses, $wp_query ) {
        if ( ! $wp_query->get( 'ahangban_sort_title' ) ) { return $clauses; }
        global $wpdb;
        $clauses['join'] .= " LEFT JOIN (
            SELECT ahangban_song_meta.post_id, ahangban_song_meta.meta_value
            FROM {$wpdb->postmeta} AS ahangban_song_meta
            INNER JOIN (
                SELECT post_id, MIN(meta_id) AS first_meta_id
                FROM {$wpdb->postmeta}
                WHERE meta_key = 'song'
                GROUP BY post_id
            ) AS ahangban_first_song ON ahangban_song_meta.meta_id = ahangban_first_song.first_meta_id
        ) AS ahangban_song_title ON {$wpdb->posts}.ID = ahangban_song_title.post_id";
        $title = "COALESCE(NULLIF(ahangban_song_title.meta_value, ''), {$wpdb->posts}.post_title)";
        $case = 'CASE';
        foreach ( self::artist_alphabet_index() as $character => $position ) {
            $case .= $wpdb->prepare( " WHEN LEFT({$title}, 1) = %s THEN %d", $character, (int) $position );
        }
        $case .= ' ELSE 99 END';
        $clauses['orderby'] = $case . " ASC, {$title} ASC, {$wpdb->posts}.ID ASC";
        return $clauses;
    }

    public static function filter_music_posts_where( $where, $wp_query ) {
        if ( ! $wp_query->get( 'ahangban_music_only' ) ) { return $where; }

        global $wpdb;
        $type_values = array_values( array_unique( array_merge(
            self::type_filter_values( 'audio' ),
            self::type_filter_values( 'video' ),
            self::type_filter_values( 'album' )
        ) ) );
        $media_keys = array( 'music128', 'music320', 'album128', 'album320', 'video480', 'video720', 'video1080' );
        $type_placeholders = implode( ',', array_fill( 0, count( $type_values ), '%s' ) );
        $media_placeholders = implode( ',', array_fill( 0, count( $media_keys ), '%s' ) );
        $sql = " AND (
            EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} AS ahangban_music_type
                WHERE ahangban_music_type.post_id = {$wpdb->posts}.ID
                  AND ahangban_music_type.meta_key = %s
                  AND ahangban_music_type.meta_value IN ({$type_placeholders})
            )
            OR EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} AS ahangban_music_media
                WHERE ahangban_music_media.post_id = {$wpdb->posts}.ID
                  AND ahangban_music_media.meta_key IN ({$media_placeholders})
                  AND ahangban_music_media.meta_value <> ''
            )
        )";
        $values = array_merge( array( 'musics_type' ), $type_values, $media_keys );
        array_unshift( $values, $sql );
        $where .= call_user_func_array( array( $wpdb, 'prepare' ), $values );

        $requested_type = sanitize_key( (string) $wp_query->get( 'ahangban_type_filter' ) );
        if ( in_array( $requested_type, array( 'audio', 'video', 'album' ), true ) ) {
            $filter_values = self::type_filter_values( $requested_type );
            $filter_keys = array(
                'audio' => array( 'music128', 'music320' ),
                'video' => array( 'video480', 'video720', 'video1080' ),
                'album' => array( 'album128', 'album320' ),
            )[ $requested_type ];
            $filter_type_placeholders = implode( ',', array_fill( 0, count( $filter_values ), '%s' ) );
            $filter_media_placeholders = implode( ',', array_fill( 0, count( $filter_keys ), '%s' ) );
            $filter_sql = " AND (
                EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} AS ahangban_filtered_type
                    WHERE ahangban_filtered_type.post_id = {$wpdb->posts}.ID
                      AND ahangban_filtered_type.meta_key = %s
                      AND ahangban_filtered_type.meta_value IN ({$filter_type_placeholders})
                )
                OR EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} AS ahangban_filtered_media
                    WHERE ahangban_filtered_media.post_id = {$wpdb->posts}.ID
                      AND ahangban_filtered_media.meta_key IN ({$filter_media_placeholders})
                      AND ahangban_filtered_media.meta_value <> ''
                )
            )";
            $filter_values = array_merge( array( 'musics_type' ), $filter_values, $filter_keys );
            array_unshift( $filter_values, $filter_sql );
            $where .= call_user_func_array( array( $wpdb, 'prepare' ), $filter_values );
        }
        return $where;
    }

    /**
     * Build a WP_Query for search with Persian-aware LIKE and relevance ranking.
     */
    public static function query_posts( $q, $type = '', $page = 1, $per_page = 5 ) {
        $q = trim( (string) $q );
        if ( self::text_length( $q ) > 100 ) { $q = self::text_substr( $q, 0, 100 ); }
        $norm = self::normalize( $q );
        $page = max( 1, min( 1000, (int) $page ) );
        $per_page = min( 20, max( 1, (int) $per_page ) );
        if ( '' === $norm ) {
            return array( 'results' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per_page );
        }

        $type = sanitize_key( (string) $type );
        if ( ! in_array( $type, array( '', 'audio', 'video', 'album' ), true ) ) { $type = ''; }
        $offset = ( $page - 1 ) * $per_page;

        // Use cache
        $cache_key = AhangBan_Cache::make_key( 'search', array( 'q' => $norm, 'type' => $type, 'page' => $page, 'per_page' => $per_page ) );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        // Build meta + title search
        // We search: post_title, meta song, meta artist
        // To avoid slow LIKE on post_content, we limit to title + meta.

        $args = array(
            'post_type'             => 'post',
            'post_status'           => 'publish',
            'posts_per_page'        => $per_page,
            'offset'                => $offset,
            'orderby'               => 'date',
            'order'                 => 'DESC',
            'no_found_rows'         => false,
            'suppress_filters'      => false,
            's'                     => $norm,
            'ahangban_type_filter'  => $type,
        );

        // Improve search: add filter to search in meta song/artist as well
        add_filter( 'posts_search', array( __CLASS__, 'filter_posts_search' ), 10, 2 );
        // Store norm for the narrowly-scoped search filter.
        global $ahangban_search_norm;
        $ahangban_search_norm = $norm;
        try {
            $query = self::query_music_posts( $args );
        } finally {
            remove_filter( 'posts_search', array( __CLASS__, 'filter_posts_search' ), 10 );
            $ahangban_search_norm = null;
        }

        $total = (int) $query->found_posts;
        $results = array();

        if ( $query->have_posts() ) {
            // Preload meta to avoid N+1
            update_postmeta_cache( wp_list_pluck( $query->posts, 'ID' ) );
            // Also preload thumbnail
            update_post_thumbnail_cache( $query );

            foreach ( $query->posts as $post ) {
                $item = self::format_post( $post, $norm );
                if ( $item ) {
                    $results[] = $item;
                }
            }

            // Deduplicate by ID (defensive)
            $seen = array();
            $deduped = array();
            foreach ( $results as $r ) {
                if ( ! isset( $seen[ $r['id'] ] ) ) {
                    $seen[ $r['id'] ] = true;
                    $deduped[] = $r;
                }
            }
            $results = $deduped;

            // Relevance ranking: exact match on song/artist first
            $results = self::rank_results( $results, $norm );
        }

        $payload = array(
            'results'  => $results,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        );

        AhangBan_Cache::set( $cache_key, $payload, 300 ); // 5 min

        return $payload;
    }

    public static function filter_posts_search( $search, $wp_query ) {
        global $wpdb, $ahangban_search_norm;
        if ( empty( $ahangban_search_norm ) ) { return $search; }
        $norm = $ahangban_search_norm;
        $like = '%' . $wpdb->esc_like( $norm ) . '%';

        // Search indexed title/song/artist fields only. Searching post_content forces a full scan
        // and is not needed for the bot's song/artist lookup.
        $search = " AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN (
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key IN ('song','artist') AND meta_value LIKE %s
        ) ) ";
        return $wpdb->prepare( $search, $like, $like );
    }

    public static function type_filter_values( $type ) {
        $type = strtolower( trim( $type ) );
        $map = array(
            'audio' => array( 'mp3', 'audio', 'ahang', 'آهنگ', 'آهنگ جدید', 'song' ),
            'video' => array( 'mp4', 'video', 'musicvideo', 'music-video', 'موزیک ویدیو', 'موزیک‌ویدیو', 'کلیپ' ),
            'album' => array( 'album', 'آلبوم' ),
        );
        return isset( $map[ $type ] ) ? $map[ $type ] : array();
    }

    public static function format_post( $post, $norm = '' ) {
        $id = (int) $post->ID;
        $song = (string) get_post_meta( $id, 'song', true );
        $artist = (string) get_post_meta( $id, 'artist', true );
        if ( '' === $song ) { $song = $post->post_title; }
        $musics_type = (string) get_post_meta( $id, 'musics_type', true );
        if ( '' === $musics_type ) { $musics_type = 'آهنگ'; }

        // Qualities
        $qualities = array();
        $qmap = array( '128' => 'music128', '320' => 'music320', 'album128' => 'album128', 'album320' => 'album320', '480' => 'video480', '720' => 'video720', '1080' => 'video1080' );
        foreach ( $qmap as $label => $key ) {
            $url = (string) get_post_meta( $id, $key, true );
            // Also try get_field fallback (ACF stores same key)
            if ( '' === $url && function_exists( 'get_field' ) ) {
                $url = (string) get_field( $key, $id );
            }
            if ( '' !== $url ) {
                $qualities[ $label ] = esc_url_raw( $url );
            }
        }

        // Telegram cards need a real display-size image; the theme's `thumb2` is only 190x190.
        // Prefer WordPress `large` (normally up to 1024px), then fall back to medium sizes.
        $cover = '';
        $cover_fallback = '';
        if ( has_post_thumbnail( $id ) ) {
            $thumbnail_id = (int) get_post_thumbnail_id( $id );
            if ( $thumbnail_id > 0 ) {
                $cover = wp_get_attachment_image_url( $thumbnail_id, 'large' );
                if ( ! $cover ) { $cover = wp_get_attachment_image_url( $thumbnail_id, 'full' ); }
                $cover_fallback = wp_get_attachment_image_url( $thumbnail_id, 'medium_large' );
                if ( ! $cover_fallback ) { $cover_fallback = wp_get_attachment_image_url( $thumbnail_id, 'medium' ); }
                if ( $cover_fallback === $cover ) { $cover_fallback = ''; }
            }
        }
        if ( ! $cover ) {
            // No featured image is better than a tiny or invented placeholder.
            $cover = '';
        }

        // Artists terms
        $artists = array();
        $terms = get_the_terms( $id, self::get_taxonomy() );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $link = get_term_link( $t );
                $artists[] = array(
                    'term_id' => (int) $t->term_id,
                    'name'    => $t->name,
                    'slug'    => $t->slug,
                    'link'    => is_wp_error( $link ) ? '' : $link,
                    'count'   => (int) $t->count,
                );
            }
        }

        // Permalink
        $permalink = get_permalink( $id );

        // Views
        $views = (int) get_post_meta( $id, 'views', true );

        return array(
            'id'        => $id,
            'title'     => $post->post_title,
            'song'      => $song,
            'artist'    => $artist,
            'type'      => $musics_type,
            'type_key'  => self::classify_type( $musics_type ),
            'cover'     => $cover ? esc_url_raw( $cover ) : '',
            'cover_fallback' => $cover_fallback ? esc_url_raw( $cover_fallback ) : '',
            'permalink' => $permalink ? esc_url_raw( $permalink ) : '',
            'qualities' => $qualities,
            'artists'   => $artists,
            'date'      => $post->post_date_gmt,
            'date_jalali' => self::format_date( $post->post_date ),
            'views'     => $views,
            'excerpt'   => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 20, '…' ),
        );
    }

    public static function classify_type( $raw ) {
        $key = strtolower( trim( (string) $raw ) );
        $map = array(
            'mp3' => 'audio', 'audio' => 'audio', 'ahang' => 'audio', 'آهنگ' => 'audio', 'آهنگ جدید' => 'audio', 'song' => 'audio',
            'mp4' => 'video', 'video' => 'video', 'musicvideo' => 'video', 'music-video' => 'video', 'موزیک ویدیو' => 'video', 'موزیک‌ویدیو' => 'video', 'کلیپ' => 'video',
            'album' => 'album', 'آلبوم' => 'album',
        );
        return isset( $map[ $key ] ) ? $map[ $key ] : ( isset( $map[ (string) $raw ] ) ? $map[ (string) $raw ] : 'other' );
    }

    /** Match the same conservative definition used for channel announcements. */
    public static function is_music_record( $post ) {
        if ( is_numeric( $post ) ) { $post = get_post( (int) $post ); }
        if ( ! is_object( $post ) || 'post' !== $post->post_type || (int) $post->ID <= 0 ) { return false; }

        $raw_type = trim( (string) get_post_meta( (int) $post->ID, 'musics_type', true ) );
        if ( '' !== $raw_type && in_array( self::classify_type( $raw_type ), array( 'audio', 'video', 'album' ), true ) ) { return true; }
        foreach ( array( 'music128', 'music320', 'album128', 'album320', 'video480', 'video720', 'video1080' ) as $meta_key ) {
            $value = get_post_meta( (int) $post->ID, $meta_key, true );
            if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) { return true; }
        }
        return false;
    }

    public static function rank_results( array $results, $norm ) {
        $norm_lc = self::text_lower( $norm );
        foreach ( $results as &$r ) {
            $score = 0;
            $song_n = self::text_lower( self::normalize( $r['song'] ) );
            $artist_n = self::text_lower( self::normalize( $r['artist'] ) );
            $title_n = self::text_lower( self::normalize( $r['title'] ) );

            if ( $song_n === $norm_lc || $artist_n === $norm_lc ) { $score += 100; }
            elseif ( 0 === self::text_position( $song_n, $norm_lc ) ) { $score += 80; }
            elseif ( 0 === self::text_position( $artist_n, $norm_lc ) ) { $score += 75; }
            elseif ( false !== self::text_position( $song_n, $norm_lc ) ) { $score += 60; }
            elseif ( false !== self::text_position( $artist_n, $norm_lc ) ) { $score += 55; }
            elseif ( false !== self::text_position( $title_n, $norm_lc ) ) { $score += 40; }

            // Prefer audio over other when no type filter
            if ( 'audio' === $r['type_key'] ) { $score += 5; }
            // Boost recent
            $r['_score'] = $score;
        }
        unset( $r );
        usort( $results, function ( $a, $b ) {
            if ( $a['_score'] === $b['_score'] ) {
                return strcmp( $b['date'], $a['date'] );
            }
            return $b['_score'] - $a['_score'];
        } );
        foreach ( $results as &$r ) { unset( $r['_score'] ); }
        return $results;
    }

    public static function format_date( $date_str ) {
        // Keep Gregorian for API; Jalali can be formatted client-side.
        // Try to provide a readable Persian date if possible.
        try {
            $dt = new DateTime( $date_str );
            return $dt->format( 'Y/m/d' );
        } catch ( Exception $e ) {
            return $date_str;
        }
    }

    public static function get_single( $id ) {
        $id = (int) $id;
        if ( $id <= 0 ) { return null; }
        $cache_key = AhangBan_Cache::make_key( 'song', array( 'id' => $id ) );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) { return $cached; }

        $post = get_post( $id );
        if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status || ! self::is_music_record( $post ) ) {
            return null;
        }
        update_postmeta_cache( array( $id ) );
        $data = self::format_post( $post );
        if ( ! $data ) { return null; }

        // Lyrics
        $lyrics = '';
        if ( function_exists( 'get_field' ) ) {
            $lyrics = (string) get_field( 'music_text', $id );
        }
        if ( '' === $lyrics ) {
            $lyrics = (string) get_post_meta( $id, 'music_text', true );
        }
        $data['lyrics'] = $lyrics ? wp_kses_post( $lyrics ) : '';

        // Related (same artist, max 3)
        $related = array();
        $terms = get_the_terms( $id, self::get_taxonomy() );
        if ( $terms && ! is_wp_error( $terms ) ) {
            $term_ids = wp_list_pluck( $terms, 'term_id' );
            $q = self::query_music_posts( array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 3,
                'post__not_in' => array( $id ),
                'tax_query' => array( array( 'taxonomy' => self::get_taxonomy(), 'field' => 'term_id', 'terms' => $term_ids ) ),
                'no_found_rows' => true,
            ) );
            if ( $q->have_posts() ) {
                update_postmeta_cache( wp_list_pluck( $q->posts, 'ID' ) );
                foreach ( $q->posts as $p ) {
                    $related[] = self::format_post( $p );
                }
            }
        }
        $data['related'] = $related;

        // Album tracks (if repeater)
        $album_tracks = array();
        if ( function_exists( 'have_rows' ) && have_rows( 'album', $id ) ) {
            while ( have_rows( 'album', $id ) ) {
                the_row();
                $album_tracks[] = array(
                    'song_names'   => (string) get_sub_field( 'song_names' ),
                    'albumlink128' => esc_url_raw( (string) get_sub_field( 'albumlink128' ) ),
                    'albumlink320' => esc_url_raw( (string) get_sub_field( 'albumlink320' ) ),
                );
            }
        }
        $data['album_tracks'] = $album_tracks;

        AhangBan_Cache::set( $cache_key, $data, 300 );
        return $data;
    }

    public static function get_latest( $page = 1, $per_page = 5 ) {
        $page = max( 1, min( 1000, (int) $page ) );
        $per_page = min( 20, max( 1, (int) $per_page ) );
        $cache_key = AhangBan_Cache::make_key( 'latest', array( 'page' => $page, 'per_page' => $per_page ) );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) { return $cached; }

        $q = self::query_music_posts( array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
        ) );
        $total = (int) $q->found_posts;
        $results = array();
        if ( $q->have_posts() ) {
            update_postmeta_cache( wp_list_pluck( $q->posts, 'ID' ) );
            update_post_thumbnail_cache( $q );
            foreach ( $q->posts as $post ) {
                $results[] = self::format_post( $post );
            }
        }
        $payload = array( 'results' => $results, 'total' => $total, 'page' => $page, 'per_page' => $per_page );
        AhangBan_Cache::set( $cache_key, $payload, 300 );
        return $payload;
    }

    public static function get_popular( $page = 1, $per_page = 5 ) {
        $page = max( 1, min( 1000, (int) $page ) );
        $per_page = min( 20, max( 1, (int) $per_page ) );
        $cache_key = AhangBan_Cache::make_key( 'popular', array( 'page' => $page, 'per_page' => $per_page ) );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) { return $cached; }

        $q = self::query_music_posts( array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'meta_key' => 'views',
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
            'date_query' => array( array( 'column' => 'post_date_gmt', 'after' => '1 month ago' ) ),
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
        ) );
        // Fall back to all-time popularity only when the whole monthly result set is empty.
        if ( 0 === (int) $q->found_posts ) {
            $q = self::query_music_posts( array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'meta_key' => 'views',
                'orderby' => 'meta_value_num',
                'order' => 'DESC',
                'no_found_rows' => false,
                'ignore_sticky_posts' => true,
            ) );
        }
        $total = (int) $q->found_posts;
        $results = array();
        if ( $q->have_posts() ) {
            update_postmeta_cache( wp_list_pluck( $q->posts, 'ID' ) );
            update_post_thumbnail_cache( $q );
            foreach ( $q->posts as $post ) {
                $results[] = self::format_post( $post );
            }
        }
        $payload = array( 'results' => $results, 'total' => $total, 'page' => $page, 'per_page' => $per_page );
        AhangBan_Cache::set( $cache_key, $payload, 300 );
        return $payload;
    }

    /** Resolve an ACF term image to a large Telegram-friendly URL without changing the stored field. */
    private static function artist_image_urls( $value ) {
        $attachment_id = 0;
        $url = '';
        if ( is_array( $value ) ) {
            $attachment_id = (int) ( $value['ID'] ?? $value['id'] ?? 0 );
            $url = isset( $value['url'] ) ? (string) $value['url'] : '';
        } elseif ( is_numeric( $value ) ) {
            $attachment_id = (int) $value;
        } elseif ( is_string( $value ) ) {
            $url = trim( $value );
        }

        if ( $attachment_id > 0 ) {
            $large = wp_get_attachment_image_url( $attachment_id, 'large' );
            $fallback = wp_get_attachment_image_url( $attachment_id, 'medium_large' );
            if ( ! $fallback ) { $fallback = wp_get_attachment_image_url( $attachment_id, 'medium' ); }
            if ( ! $large ) { $large = wp_get_attachment_url( $attachment_id ); }
            return array(
                'image' => $large ? esc_url_raw( $large ) : '',
                'image_fallback' => ( $fallback && $fallback !== $large ) ? esc_url_raw( $fallback ) : '',
            );
        }

        return array( 'image' => $url ? esc_url_raw( $url ) : '', 'image_fallback' => '' );
    }

    private static function artist_alphabet_index() {
        $characters = preg_split( '//u', 'ابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی', -1, PREG_SPLIT_NO_EMPTY );
        $index = array();
        foreach ( is_array( $characters ) ? $characters : array() as $position => $character ) { $index[ $character ] = $position; }
        return $index;
    }

    /** Cache only the small searchable term index; resolve image fields for the requested page only. */
    private static function get_artist_index() {
        $cache_key = AhangBan_Cache::make_key( 'artist_index', array() );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) { return $cached; }
        if ( ! taxonomy_exists( self::get_taxonomy() ) ) {
            return new WP_Error( 'no_taxonomy', 'تاکسونومی artist ثبت نشده است.', array( 'status' => 500 ) );
        }

        $terms = get_terms( array(
            'taxonomy' => self::get_taxonomy(),
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'ASC',
            'number' => 0,
        ) );
        if ( is_wp_error( $terms ) ) { return $terms; }

        $letter_index = self::artist_alphabet_index();
        $items = array();
        foreach ( $terms as $term ) {
            $norm = self::normalize( $term->name );
            $characters = preg_split( '//u', $norm, -1, PREG_SPLIT_NO_EMPTY );
            $first = $characters ? $characters[0] : '#';
            $items[] = array(
                'term_id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'count' => (int) $term->count,
                'letter' => isset( $letter_index[ $first ] ) ? $first : '#',
                'norm' => $norm,
                'sort_key' => self::artist_sort_key( $term->name, $letter_index ),
            );
        }
        usort( $items, function ( $a, $b ) {
            $order = strcmp( $a['sort_key'], $b['sort_key'] );
            return 0 === $order ? ( $a['term_id'] - $b['term_id'] ) : $order;
        } );
        AhangBan_Cache::set( $cache_key, $items, 43200 );
        return $items;
    }

    public static function get_artists( $params ) {
        $q = isset( $params['q'] ) ? trim( (string) $params['q'] ) : '';
        if ( self::text_length( $q ) > 100 ) { $q = self::text_substr( $q, 0, 100 ); }
        $letter = isset( $params['letter'] ) ? trim( (string) $params['letter'] ) : '';
        if ( self::text_length( $letter ) > 4 ) { $letter = self::text_substr( $letter, 0, 4 ); }
        $sort = isset( $params['sort'] ) ? sanitize_key( $params['sort'] ) : 'name';
        $page = max( 1, min( 1000, (int) ( $params['page'] ?? 1 ) ) );
        $per_page = min( 50, max( 1, (int) ( $params['per_page'] ?? 20 ) ) );
        if ( ! in_array( $sort, array( 'name', 'songs' ), true ) ) { $sort = 'name'; }

        $cache_key = AhangBan_Cache::make_key( 'artists', array( 'q' => $q, 'letter' => $letter, 'sort' => $sort, 'page' => $page, 'per_page' => $per_page ) );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) { return $cached; }

        $items = self::get_artist_index();
        if ( is_wp_error( $items ) ) { return $items; }
        if ( 'songs' === $sort ) {
            usort( $items, function ( $a, $b ) {
                if ( $a['count'] !== $b['count'] ) { return $b['count'] - $a['count']; }
                $order = strcmp( $a['sort_key'], $b['sort_key'] );
                return 0 === $order ? ( $a['term_id'] - $b['term_id'] ) : $order;
            } );
        }

        if ( '' !== $letter ) {
            $characters = preg_split( '//u', self::normalize( $letter ), -1, PREG_SPLIT_NO_EMPTY );
            $letter = $characters ? $characters[0] : '';
            if ( '' !== $letter ) {
                $items = array_values( array_filter( $items, function ( $item ) use ( $letter ) { return $item['letter'] === $letter; } ) );
            }
        }

        if ( '' !== $q ) {
            $qnorm = self::normalize( $q );
            $qnorm_lc = self::text_lower( $qnorm );
            $items = array_values( array_filter( $items, function ( $item ) use ( $qnorm_lc ) {
                return false !== self::text_position( self::text_lower( $item['norm'] ), $qnorm_lc );
            } ) );
        }

        $total = count( $items );
        $offset = ( $page - 1 ) * $per_page;
        $paged_items = array_slice( $items, $offset, $per_page );
        if ( $paged_items ) {
            update_termmeta_cache( wp_list_pluck( $paged_items, 'term_id' ) );
        }
        foreach ( $paged_items as &$item ) {
            $term = get_term( (int) $item['term_id'], self::get_taxonomy() );
            $artist_image = '';
            $link = '';
            if ( $term && ! is_wp_error( $term ) ) {
                $artist_image = function_exists( 'get_field' ) ? get_field( 'aimg2', $term ) : '';
                if ( empty( $artist_image ) ) { $artist_image = get_term_meta( $term->term_id, 'aimg2', true ); }
                $link = get_term_link( $term );
                if ( is_wp_error( $link ) ) { $link = ''; }
            }
            $images = self::artist_image_urls( $artist_image );
            $item['link'] = $link ? esc_url_raw( $link ) : '';
            $item['image'] = $images['image'];
            $item['image_fallback'] = $images['image_fallback'];
            unset( $item['norm'], $item['sort_key'] );
        }
        unset( $item );

        $payload = array(
            'results' => $paged_items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        );
        AhangBan_Cache::set( $cache_key, $payload, 43200 );
        return $payload;
    }

    public static function artist_sort_key( $text, $index ) {
        $key = '';
        $chars = preg_split( '//u', self::normalize( $text ), -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $chars ) { return ''; }
        foreach ( $chars as $c ) {
            if ( isset( $index[ $c ] ) ) { $key .= sprintf( '%02d', $index[ $c ] ); }
            elseif ( preg_match( '/[0-9]/', $c ) ) { $key .= '80' . $c; }
            elseif ( preg_match( '/[A-Za-z]/', $c ) ) { $key .= '85' . strtolower( $c ); }
            elseif ( ' ' === $c ) { $key .= '98'; }
            else { $key .= '97'; }
        }
        return $key;
    }

    /** Count artist content types in SQL rather than loading every post and meta row into PHP. */
    private static function artist_buckets( $term ) {
        $cache_key = AhangBan_Cache::make_key( 'artist_buckets', array( 'term_id' => (int) $term->term_id ) );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) { return $cached; }

        global $wpdb;
        $buckets = array( 'audio' => 0, 'video' => 0, 'album' => 0, 'other' => 0 );
        $type_values = array_values( array_unique( array_merge(
            self::type_filter_values( 'audio' ),
            self::type_filter_values( 'video' ),
            self::type_filter_values( 'album' )
        ) ) );
        $media_keys = array( 'music128', 'music320', 'album128', 'album320', 'video480', 'video720', 'video1080' );
        $type_placeholders = implode( ',', array_fill( 0, count( $type_values ), '%s' ) );
        $media_placeholders = implode( ',', array_fill( 0, count( $media_keys ), '%s' ) );
        $sql = "SELECT COALESCE(NULLIF(pm.meta_value, ''), %s) AS raw_type, COUNT(DISTINCT p.ID) AS item_count
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                WHERE p.post_type = %s AND p.post_status = %s AND tr.term_taxonomy_id = %d
                  AND (pm.meta_value IN ({$type_placeholders}) OR EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} AS ahangban_artist_media
                      WHERE ahangban_artist_media.post_id = p.ID
                        AND ahangban_artist_media.meta_key IN ({$media_placeholders})
                        AND ahangban_artist_media.meta_value <> ''
                  ))
                GROUP BY raw_type";
        $values = array_merge(
            array( 'آهنگ', 'musics_type', 'post', 'publish', (int) $term->term_taxonomy_id ),
            $type_values,
            $media_keys
        );
        array_unshift( $values, $sql );
        $sql = call_user_func_array( array( $wpdb, 'prepare' ), $values );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $key = self::classify_type( (string) ( $row['raw_type'] ?? '' ) );
                if ( ! isset( $buckets[ $key ] ) ) { $key = 'other'; }
                $buckets[ $key ] += (int) ( $row['item_count'] ?? 0 );
            }
        }
        AhangBan_Cache::set( $cache_key, $buckets, 600 );
        return $buckets;
    }

    public static function get_artist_detail( $identifier, $page = 1, $per_page = 10, $sort = 'new' ) {
        $page = max( 1, min( 1000, (int) $page ) );
        $per_page = min( 20, max( 1, (int) $per_page ) );
        if ( ! in_array( $sort, array( 'new', 'old', 'title' ), true ) ) { $sort = 'new'; }

        if ( ! taxonomy_exists( self::get_taxonomy() ) ) {
            return new WP_Error( 'no_taxonomy', 'تاکسونومی artist ثبت نشده است.', array( 'status' => 500 ) );
        }

        // Find term by slug or id
        $term = null;
        if ( is_numeric( $identifier ) ) {
            $term = get_term( (int) $identifier, self::get_taxonomy() );
        } else {
            $term = get_term_by( 'slug', sanitize_title( $identifier ), self::get_taxonomy() );
            if ( ! $term ) {
                $term = get_term_by( 'name', $identifier, self::get_taxonomy() );
            }
        }
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'not_found', 'خواننده یافت نشد.', array( 'status' => 404 ) );
        }

        $cache_key = AhangBan_Cache::make_key( 'artist_detail', array( 'id' => $term->term_id, 'page' => $page, 'per_page' => $per_page, 'sort' => $sort ) );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) { return $cached; }

        // Image & description — use the verified ACF term field and preserve an optimized fallback.
        $artist_image = function_exists( 'get_field' ) ? get_field( 'aimg2', $term ) : '';
        if ( empty( $artist_image ) ) { $artist_image = get_term_meta( $term->term_id, 'aimg2', true ); }
        $artist_images = self::artist_image_urls( $artist_image );

        $description = term_description( $term->term_id, self::get_taxonomy() );
        $link = get_term_link( $term );
        $link = is_wp_error( $link ) ? '' : $link;

        // Socials
        $socials = array();
        $social_keys = array( 'atelegram' => 'telegram', 'ainstagram' => 'instagram', 'atwitter' => 'twitter', 'ayoutube' => 'youtube' );
        foreach ( $social_keys as $meta_key => $network ) {
            $val = get_term_meta( $term->term_id, $meta_key, true );
            if ( function_exists( 'get_field' ) ) {
                $acf_val = get_field( $meta_key, $term );
                if ( $acf_val ) { $val = $acf_val; }
            }
            if ( $val ) { $socials[ $network ] = esc_url_raw( $val ); }
        }

        // Apply the chosen ordering in SQL before pagination, including alphabetic song titles.
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'title' === $sort ? 'none' : 'date',
            'order' => 'old' === $sort ? 'ASC' : 'DESC',
            'tax_query' => array( array( 'taxonomy' => self::get_taxonomy(), 'field' => 'term_id', 'terms' => $term->term_id ) ),
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
            'ahangban_sort_title' => 'title' === $sort,
        );
        $q = self::query_music_posts( $args );
        $total = (int) $q->found_posts;
        $results = array();

        if ( $q->have_posts() ) {
            update_postmeta_cache( wp_list_pluck( $q->posts, 'ID' ) );
            update_post_thumbnail_cache( $q );
            foreach ( $q->posts as $post ) { $results[] = self::format_post( $post ); }
        }

        // Bucket counts are cached and aggregated in SQL; this avoids loading an artist's entire archive.
        $buckets = self::artist_buckets( $term );

        $payload = array(
            'term' => array(
                'term_id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'link' => $link ? esc_url_raw( $link ) : '',
                'image' => $artist_images['image'],
                'image_fallback' => $artist_images['image_fallback'],
                'description' => $description ? wp_kses_post( $description ) : '',
                'count' => (int) $term->count,
                'socials' => $socials,
            ),
            'buckets' => $buckets,
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        );

        AhangBan_Cache::set( $cache_key, $payload, 600 );
        return $payload;
    }
}
