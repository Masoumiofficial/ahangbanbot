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
        // Remove tashkeel + kashida
        $text = preg_replace( '/[\x{064B}-\x{0652}\x{0640}\x{0670}]/u', '', $text );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( $text );
    }

    /**
     * Build a WP_Query for search with Persian-aware LIKE and relevance ranking.
     */
    public static function query_posts( $q, $type = '', $page = 1, $per_page = 5 ) {
        $q = trim( (string) $q );
        if ( mb_strlen( $q ) > 100 ) { $q = mb_substr( $q, 0, 100 ); }
        $norm = self::normalize( $q );
        if ( '' === $norm ) {
            return array( 'results' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per_page );
        }

        $page = max( 1, (int) $page );
        $per_page = min( 20, max( 1, (int) $per_page ) );
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
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => false,
            'suppress_filters' => false,
            's'              => $norm,
        );

        // If type filter provided, add meta_query for musics_type
        if ( '' !== $type ) {
            $type_map = self::type_filter_values( $type );
            if ( ! empty( $type_map ) ) {
                $args['meta_query'] = array(
                    array(
                        'key'     => 'musics_type',
                        'value'   => $type_map,
                        'compare' => 'IN',
                    ),
                );
            }
        }

        // Improve search: add filter to search in meta song/artist as well
        add_filter( 'posts_search', array( __CLASS__, 'filter_posts_search' ), 10, 2 );
        // Store norm for filter
        global $ahangban_search_norm;
        $ahangban_search_norm = $norm;

        $query = new WP_Query( $args );

        remove_filter( 'posts_search', array( __CLASS__, 'filter_posts_search' ), 10 );
        $ahangban_search_norm = null;

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

        // Extend search to include meta song/artist
        // We'll inject OR conditions for postmeta
        // Keep original search but add meta joins
        // Instead of modifying JOIN here, we use a subquery via posts_where
        // Simpler: add WHERE clause that checks meta values
        $search = " AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('song','artist') AND meta_value LIKE %s) OR {$wpdb->posts}.post_content LIKE %s ) ";
        $search = $wpdb->prepare( $search, $like, $like, $like );
        return $search;
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

    public static function rank_results( array $results, $norm ) {
        $norm_lc = mb_strtolower( $norm, 'UTF-8' );
        foreach ( $results as &$r ) {
            $score = 0;
            $song_n = mb_strtolower( self::normalize( $r['song'] ), 'UTF-8' );
            $artist_n = mb_strtolower( self::normalize( $r['artist'] ), 'UTF-8' );
            $title_n = mb_strtolower( self::normalize( $r['title'] ), 'UTF-8' );

            if ( $song_n === $norm_lc || $artist_n === $norm_lc ) { $score += 100; }
            elseif ( mb_strpos( $song_n, $norm_lc, 0, 'UTF-8' ) === 0 ) { $score += 80; }
            elseif ( mb_strpos( $artist_n, $norm_lc, 0, 'UTF-8' ) === 0 ) { $score += 75; }
            elseif ( false !== mb_strpos( $song_n, $norm_lc, 0, 'UTF-8' ) ) { $score += 60; }
            elseif ( false !== mb_strpos( $artist_n, $norm_lc, 0, 'UTF-8' ) ) { $score += 55; }
            elseif ( false !== mb_strpos( $title_n, $norm_lc, 0, 'UTF-8' ) ) { $score += 40; }

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
        $post = get_post( $id );
        if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
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
            $q = new WP_Query( array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 3,
                'post__not_in' => array( $id ),
                'tax_query' => array( array( 'taxonomy' => self::get_taxonomy(), 'field' => 'term_id', 'terms' => $term_ids ) ),
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

        return $data;
    }

    public static function get_latest( $page = 1, $per_page = 5 ) {
        $page = max( 1, (int) $page );
        $per_page = min( 20, max( 1, (int) $per_page ) );
        $cache_key = AhangBan_Cache::make_key( 'latest', array( 'page' => $page, 'per_page' => $per_page ) );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) { return $cached; }

        $q = new WP_Query( array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => false,
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
        $page = max( 1, (int) $page );
        $per_page = min( 20, max( 1, (int) $per_page ) );
        $cache_key = AhangBan_Cache::make_key( 'popular', array( 'page' => $page, 'per_page' => $per_page ) );
        $cached = AhangBan_Cache::get( $cache_key );
        if ( false !== $cached ) { return $cached; }

        $q = new WP_Query( array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'meta_key' => 'views',
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
            'date_query' => array( array( 'column' => 'post_date_gmt', 'after' => '1 month ago' ) ),
            'no_found_rows' => false,
        ) );
        // Fallback if not enough results in last month
        if ( ! $q->have_posts() ) {
            $q = new WP_Query( array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'meta_key' => 'views',
                'orderby' => 'meta_value_num',
                'order' => 'DESC',
                'no_found_rows' => false,
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

    public static function get_artists( $params ) {
        $q = isset( $params['q'] ) ? trim( (string) $params['q'] ) : '';
        $letter = isset( $params['letter'] ) ? trim( (string) $params['letter'] ) : '';
        $sort = isset( $params['sort'] ) ? sanitize_key( $params['sort'] ) : 'name';
        $page = max( 1, (int) ( $params['page'] ?? 1 ) );
        $per_page = min( 50, max( 1, (int) ( $params['per_page'] ?? 20 ) ) );

        if ( ! in_array( $sort, array( 'name', 'songs' ), true ) ) { $sort = 'name'; }

        $cache_key = AhangBan_Cache::make_key( 'artists', array( 'q' => $q, 'letter' => $letter, 'sort' => $sort, 'page' => $page, 'per_page' => $per_page ) );
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

        // Preload meta
        if ( $terms ) {
            update_termmeta_cache( wp_list_pluck( $terms, 'term_id' ) );
        }

        // Build items with normalization
        $items = array();
        $alphabet = 'ابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی';
        $chars = preg_split( '//u', $alphabet, -1, PREG_SPLIT_NO_EMPTY );
        $index = array();
        foreach ( $chars as $i => $c ) { $index[ $c ] = $i; }

        foreach ( $terms as $term ) {
            $norm = self::normalize( $term->name );
            $first_chars = preg_split( '//u', $norm, -1, PREG_SPLIT_NO_EMPTY );
            $first = $first_chars ? $first_chars[0] : '#';
            $letter_key = isset( $index[ $first ] ) ? $first : '#';

            // The known source field is `aimg2`; resolve attachment IDs to a larger display size.
            $artist_image = function_exists( 'get_field' ) ? get_field( 'aimg2', $term ) : '';
            if ( empty( $artist_image ) ) { $artist_image = get_term_meta( $term->term_id, 'aimg2', true ); }
            $artist_images = self::artist_image_urls( $artist_image );

            $link = get_term_link( $term );
            $link = is_wp_error( $link ) ? '' : $link;

            $items[] = array(
                'term_id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'link' => $link ? esc_url_raw( $link ) : '',
                'count' => (int) $term->count,
                'image' => $artist_images['image'],
                'image_fallback' => $artist_images['image_fallback'],
                'letter' => $letter_key,
                'norm' => $norm,
                'sort_key' => self::artist_sort_key( $term->name, $index ),
            );
        }

        // Sort
        usort( $items, function ( $a, $b ) use ( $sort ) {
            if ( 'songs' === $sort && $a['count'] !== $b['count'] ) {
                return $b['count'] - $a['count'];
            }
            return strcmp( $a['sort_key'], $b['sort_key'] );
        } );

        // Filter by letter
        if ( '' !== $letter ) {
            $letter = self::normalize( $letter );
            // Take first char
            $lc = preg_split( '//u', $letter, -1, PREG_SPLIT_NO_EMPTY );
            $letter = $lc ? $lc[0] : '';
            if ( '' !== $letter ) {
                $items = array_values( array_filter( $items, function ( $it ) use ( $letter ) { return $it['letter'] === $letter; } ) );
            }
        }

        // Filter by q
        if ( '' !== $q ) {
            $qnorm = self::normalize( $q );
            $qnorm_lc = mb_strtolower( $qnorm, 'UTF-8' );
            $items = array_values( array_filter( $items, function ( $it ) use ( $qnorm, $qnorm_lc ) {
                $n = mb_strtolower( $it['norm'], 'UTF-8' );
                return false !== mb_strpos( $n, $qnorm_lc, 0, 'UTF-8' );
            } ) );
        }

        $total = count( $items );
        $offset = ( $page - 1 ) * $per_page;
        $paged_items = array_slice( $items, $offset, $per_page );

        // Remove internal keys from output
        foreach ( $paged_items as &$it ) { unset( $it['norm'], $it['sort_key'] ); }
        unset( $it );

        // Letter counts for UI
        $letter_counts = array();
        // Need full unfiltered for counts but we have filtered items; do quick count from original terms fetch
        // For simplicity, count from items before letter/q filter — we already have $terms count per letter
        // Rebuild from all items before filters (re-fetch logic)
        // Quick: if q is empty, compute from $items pre-filter, else show filtered counts
        // We'll compute from original $terms
        // (Simplified: compute from filtered total for now)

        $payload = array(
            'results' => $paged_items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        );
        AhangBan_Cache::set( $cache_key, $payload, 43200 ); // 12h like template
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
        $sql = $wpdb->prepare(
            "SELECT COALESCE(NULLIF(pm.meta_value, ''), %s) AS raw_type, COUNT(DISTINCT p.ID) AS item_count
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = %s AND tr.term_taxonomy_id = %d
             GROUP BY raw_type",
            'آهنگ', 'musics_type', 'post', 'publish', (int) $term->term_taxonomy_id
        );
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
        $page = max( 1, (int) $page );
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

        // Query posts for this artist
        $order = ( 'old' === $sort ) ? 'ASC' : 'DESC';
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => $order,
            'tax_query' => array( array( 'taxonomy' => self::get_taxonomy(), 'field' => 'term_id', 'terms' => $term->term_id ) ),
            'no_found_rows' => false,
        );
        $q = new WP_Query( $args );
        $total = (int) $q->found_posts;
        $results = array();

        if ( $q->have_posts() ) {
            update_postmeta_cache( wp_list_pluck( $q->posts, 'ID' ) );
            update_post_thumbnail_cache( $q );

            // If sort=title, sort in PHP
            if ( 'title' === $sort ) {
                usort( $q->posts, function ( $a, $b ) {
                    $ta = (string) get_post_meta( $a->ID, 'song', true ) ?: $a->post_title;
                    $tb = (string) get_post_meta( $b->ID, 'song', true ) ?: $b->post_title;
                    return strcmp( self::artist_sort_key( $ta, array() ), self::artist_sort_key( $tb, array() ) );
                } );
            }

            foreach ( $q->posts as $post ) {
                $results[] = self::format_post( $post );
            }
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
