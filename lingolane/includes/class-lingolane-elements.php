<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Element types are post_{post_type} and tax_{taxonomy}; term IDs are term_id. */
class Lingolane_Elements {
    public static function init() {
        add_action( 'before_delete_post', array( __CLASS__, 'delete_post' ) );
        add_action( 'delete_term', array( __CLASS__, 'delete_term' ), 10, 3 );
        add_filter( 'the_posts', array( __CLASS__, 'on_the_posts' ) );
        add_filter( 'get_terms', array( __CLASS__, 'on_get_terms' ), 10, 2 );
    }

    /**
     * Prime elements object cache in bulk to prevent N+1 queries.
     *
     * @param string $type Element type (post_{type} or tax_{taxonomy}).
     * @param array  $ids List of element IDs.
     */
    public static function prime_cache( $type, array $ids ) {
        global $wpdb;
        $type = sanitize_key( $type );
        $uncached = array();
        foreach ( $ids as $id ) {
            $id = absint( $id );
            if ( ! $id ) { continue; }
            $key = $wpdb->prefix . ':element:' . $type . ':' . $id;
            wp_cache_get( $key, 'lingolane', false, $found );
            if ( ! $found ) {
                $uncached[ $id ] = $id;
            }
        }
        if ( empty( $uncached ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $uncached ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; results are cached per element below.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d placeholders.
                "SELECT * FROM {$wpdb->prefix}lingolane_translations WHERE element_type = %s AND element_id IN ($placeholders)",
                array_merge( array( $type ), array_values( $uncached ) )
            ),
            ARRAY_A
        );

        $found_ids = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $id = (int) $row['element_id'];
                $found_ids[ $id ] = true;
                $key = $wpdb->prefix . ':element:' . $type . ':' . $id;
                wp_cache_set( $key, $row, 'lingolane', 60 );
            }
        }

        // Cache miss results as null/false so subsequent checks don't hit the DB again
        foreach ( $uncached as $id ) {
            if ( ! isset( $found_ids[ $id ] ) ) {
                $key = $wpdb->prefix . ':element:' . $type . ':' . $id;
                wp_cache_set( $key, false, 'lingolane', 60 );
            }
        }
    }

    public static function on_the_posts( $posts ) {
        if ( empty( $posts ) || ! is_array( $posts ) ) {
            return $posts;
        }
        $by_type = array();
        foreach ( $posts as $p ) {
            if ( $p instanceof WP_Post ) {
                $by_type[ 'post_' . $p->post_type ][] = $p->ID;
            }
        }
        foreach ( $by_type as $type => $ids ) {
            self::prime_cache( $type, $ids );
        }
        return $posts;
    }

    public static function on_get_terms( $terms, $taxonomies ) {
        if ( empty( $terms ) || ! is_array( $terms ) || empty( $taxonomies ) ) {
            return $terms;
        }
        $taxonomy = is_array( $taxonomies ) ? reset( $taxonomies ) : (string) $taxonomies;
        $type = 'tax_' . sanitize_key( $taxonomy );
        $ids = array();
        foreach ( $terms as $term ) {
            if ( $term instanceof WP_Term ) {
                $ids[] = $term->term_id;
            } elseif ( is_numeric( $term ) ) {
                $ids[] = (int) $term;
            }
        }
        if ( ! empty( $ids ) ) {
            self::prime_cache( $type, $ids );
        }
        return $terms;
    }
    private static function row( $type, $id ) {
        global $wpdb;
        $type = sanitize_key( $type );
        $id = absint( $id );
        $key = $wpdb->prefix . ':element:' . $type . ':' . $id;
        $row = wp_cache_get( $key, 'lingolane', false, $found );
        if ( ! $found ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lingolane_translations WHERE element_type = %s AND element_id = %d", $type, $id ), ARRAY_A );
            wp_cache_set( $key, $row, 'lingolane', 60 );
        }
        return $row;
    }
    private static function invalidate( $type, $id, $trid ) {
        global $wpdb;
        wp_cache_delete( $wpdb->prefix . ':element:' . $type . ':' . absint( $id ), 'lingolane' );
        wp_cache_delete( $wpdb->prefix . ':group:' . absint( $trid ), 'lingolane' );
    }
    public static function get_trid( $type, $id ) {
        $row = self::row( $type, $id );
        return $row ? (int) $row['trid'] : null;
    }
    public static function get_language( $type, $id ) {
        $row = self::row( $type, $id );
        return $row ? $row['language_code'] : Lingolane_Languages::source_code();
    }
    /** Returns trid on success or WP_Error. A group contains one element type only. */
    public static function set( $type, $id, $lang, $trid = null, $source = null ) {
        global $wpdb;
        $type = sanitize_key( $type );
        $id = absint( $id );
        $lang = sanitize_key( $lang );
        $source = $source === null ? null : sanitize_key( $source );
        if ( ! $id || strlen( $type ) > 40 || ! preg_match( '/^(post|tax)_[a-z0-9_-]+$/D', $type ) || ! Lingolane_Languages::is_valid( $lang ) || ( $source !== null && ! Lingolane_Languages::is_valid( $source ) ) || ( $trid !== null && ! absint( $trid ) ) ) {
            return new WP_Error( 'lingolane_invalid_element', __( 'Invalid element or language.', 'lingolane' ) );
        }
        $table = $wpdb->prefix . 'lingolane_translations';
        // Serialize group allocation and membership checks, including concurrent admin requests.
        $lock = 'lingolane_' . md5( DB_NAME . $table );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) ) ) {
            return new WP_Error( 'lingolane_lock', __( 'Could not lock the translation group. Please try again.', 'lingolane' ) );
        }
        try {
            $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE element_type = %s AND element_id = %d", $type, $id ), ARRAY_A );
            if ( $trid === null ) {
                $trid = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(trid) FROM $table WHERE %d = %d", 1, 1 ) );
            } else {
                $trid = absint( $trid );
                $conflict = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE trid = %d AND (element_type <> %s OR (language_code = %s AND element_id <> %d)) LIMIT 1", $trid, $type, $lang, $id ) );
                if ( $conflict ) { return new WP_Error( 'lingolane_group_conflict', __( 'The group already has a translation or belongs to a different content type.', 'lingolane' ) ); }
            }
            if ( $source === null ) {
                $written = $wpdb->query( $wpdb->prepare( "INSERT INTO $table (element_type, element_id, trid, language_code, source_language_code) VALUES (%s, %d, %d, %s, NULL) ON DUPLICATE KEY UPDATE trid = VALUES(trid), language_code = VALUES(language_code), source_language_code = NULL", $type, $id, $trid, $lang ) );
            } else {
                $written = $wpdb->query( $wpdb->prepare( "INSERT INTO $table (element_type, element_id, trid, language_code, source_language_code) VALUES (%s, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE trid = VALUES(trid), language_code = VALUES(language_code), source_language_code = VALUES(source_language_code)", $type, $id, $trid, $lang, $source ) );
            }
            if ( false === $written ) { return new WP_Error( 'lingolane_write_failed', __( 'Could not save the translation group.', 'lingolane' ) ); }
            self::invalidate( $type, $id, $trid );
            if ( $old ) { self::invalidate( $type, $id, $old['trid'] ); }
            return $trid;
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }
    public static function get_translation( $type, $id, $lang ) {
        $trid = self::get_trid( $type, $id );
        if ( ! $trid ) { return self::get_language( $type, $id ) === $lang ? absint( $id ) : null; }
        $group = self::get_group( $trid );
        return isset( $group[ $lang ] ) ? $group[ $lang ] : null;
    }
    public static function get_group( $trid ) {
        global $wpdb;
        $key = $wpdb->prefix . ':group:' . absint( $trid );
        $group = wp_cache_get( $key, 'lingolane', false, $found );
        if ( ! $found ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT language_code, element_id FROM {$wpdb->prefix}lingolane_translations WHERE trid = %d", absint( $trid ) ), ARRAY_A );
            $group = array();
            foreach ( $rows as $row ) { $group[ $row['language_code'] ] = (int) $row['element_id']; }
            wp_cache_set( $key, $group, 'lingolane', 60 );
        }
        return $group;
    }
    public static function delete( $type, $id ) {
        global $wpdb;
        $type = sanitize_key( $type );
        $row = self::row( $type, $id );
        $result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}lingolane_translations WHERE element_type = %s AND element_id = %d", $type, absint( $id ) ) );
        if ( false !== $result ) { self::invalidate( $type, $id, $row ? $row['trid'] : 0 ); }
        return $result;
    }
    public static function delete_post( $id ) { self::delete( 'post_' . get_post_type( $id ), $id ); }
    public static function delete_term( $id, $tt_id, $taxonomy ) { self::delete( 'tax_' . $taxonomy, $id ); }

    public static function duplicate_post( $id, $target_lang, $status = 'publish', $new_title = null, $new_content = null, $new_slug = null ) {
        $id = absint( $id );
        $target_lang = sanitize_key( $target_lang );
        if ( ! $id || ! Lingolane_Languages::is_valid( $target_lang ) ) {
            return new WP_Error( 'lingolane_invalid', __( 'Invalid post ID or language.', 'lingolane' ) );
        }
        $post = get_post( $id );
        if ( ! $post ) {
            return new WP_Error( 'lingolane_not_found', __( 'The original post was not found.', 'lingolane' ) );
        }
        $type = 'post_' . $post->post_type;
        $source = self::get_language( $type, $id );
        if ( $source === $target_lang ) {
            return new WP_Error( 'lingolane_same_lang', __( 'The post is already in the target language.', 'lingolane' ) );
        }
        $existing = self::get_translation( $type, $id, $target_lang );
        if ( $existing ) {
            return $existing;
        }

        $trid = self::get_trid( $type, $id );
        if ( ! $trid ) {
            $trid = self::set( $type, $id, $source );
            if ( is_wp_error( $trid ) ) { return $trid; }
        }

        $title   = $new_title !== null ? $new_title : $post->post_title;
        $content = $new_content !== null ? $new_content : $post->post_content;
        $slug    = $new_slug !== null ? sanitize_title( $new_slug ) : sanitize_title( $title );

        $new = wp_insert_post( wp_slash( array(
            'post_type'    => $post->post_type,
            'post_status'  => $status,
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $post->post_excerpt,
            'post_name'    => $slug,
            'post_author'  => $post->post_author ? $post->post_author : get_current_user_id(),
        ) ), true );

        if ( is_wp_error( $new ) ) { return $new; }

        try {
            $all_meta  = get_post_meta( $id );
            $meta_keys = array_keys( $all_meta );
            $meta_keys = array_diff( $meta_keys, array( '_edit_lock', '_edit_last', '_wp_old_slug' ) );
            $meta_keys = apply_filters( 'lingolane_copy_post_metas', $meta_keys, $id, $new, $target_lang );

            foreach ( $meta_keys as $key ) {
                if ( ! isset( $all_meta[ $key ] ) ) { continue; }
                foreach ( $all_meta[ $key ] as $value ) {
                    $val = maybe_unserialize( $value );
                    if ( '_thumbnail_id' === $key && is_numeric( $val ) ) {
                        $trans_thumb = self::get_translation( 'post_attachment', (int) $val, $target_lang );
                        if ( $trans_thumb ) {
                            $val = $trans_thumb;
                        }
                    }
                    $val = apply_filters( 'lingolane_copy_post_meta_value', $val, $key, $id, $new, $target_lang );
                    add_post_meta( $new, $key, wp_slash( $val ) );
                }
            }
            foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
                $terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    $mapped = array();
                    foreach ( $terms as $term ) {
                        $translation = self::get_translation( 'tax_' . $taxonomy, $term, $target_lang );
                        $mapped[] = $translation ? $translation : (int) $term;
                    }
                    wp_set_object_terms( $new, $mapped, $taxonomy );
                }
            }
            $result = self::set( $type, $new, $target_lang, $trid, $source );
            if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
        } catch ( Exception $error ) {
            wp_delete_post( $new, true );
            return new WP_Error( 'lingolane_dup_failed', $error->getMessage() );
        }

        return $new;
    }
}
