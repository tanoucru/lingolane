<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Lingolane_String_Locations {
    private static $cached_groups = array();

    public static function occurrence_hash( $occ ) {
        $canonical = sprintf(
            '%s|%s|%s|%d|%s|%s|%s',
            isset( $occ['group_key'] ) ? (string) $occ['group_key'] : '',
            isset( $occ['subgroup'] ) ? (string) $occ['subgroup'] : '',
            isset( $occ['provider'] ) ? (string) $occ['provider'] : '',
            isset( $occ['object_id'] ) ? (int) $occ['object_id'] : 0,
            isset( $occ['field'] ) ? (string) $occ['field'] : '',
            isset( $occ['page_path'] ) ? (string) $occ['page_path'] : '',
            isset( $occ['locator'] ) ? (string) $occ['locator'] : ''
        );
        return hash( 'sha256', $canonical );
    }

    public static function record( $identity, $occurrence ) {
        global $wpdb;
        if ( ! is_array( $identity ) || ! is_array( $occurrence ) ) {
            return new WP_Error( 'lingolane_invalid_data', __( 'Identity and occurrence must be arrays.', 'lingolane' ) );
        }
        $domain  = isset( $identity['domain'] ) ? sanitize_text_field( (string) $identity['domain'] ) : '';
        $source  = isset( $identity['source'] ) ? (string) $identity['source'] : '';
        $context = isset( $identity['context'] ) ? sanitize_text_field( (string) $identity['context'] ) : '';

        if ( '' === $domain || '' === $source ) {
            return new WP_Error( 'lingolane_invalid_identity', __( 'Domain and source cannot be empty.', 'lingolane' ) );
        }

        $source_hash = Lingolane_Strings::hash( $source, $context );

        // Ensure string is registered in strings table so joins succeed.
        $default_lang = Lingolane_Languages::default_code();
        Lingolane_Strings::save( $domain, $source, $context, $default_lang, '', true );

        $group_key = isset( $occurrence['group_key'] ) ? sanitize_key( (string) $occurrence['group_key'] ) : 'other';
        if ( '' === $group_key ) { $group_key = 'other'; }
        $subgroup  = isset( $occurrence['subgroup'] ) ? sanitize_text_field( (string) $occurrence['subgroup'] ) : '';
        $provider  = isset( $occurrence['provider'] ) ? sanitize_key( (string) $occurrence['provider'] ) : '';
        $object_id = isset( $occurrence['object_id'] ) ? absint( $occurrence['object_id'] ) : 0;
        $field     = isset( $occurrence['field'] ) ? sanitize_text_field( (string) $occurrence['field'] ) : '';
        $page_path = isset( $occurrence['page_path'] ) ? esc_url_raw( (string) $occurrence['page_path'] ) : '/';
        $locator   = isset( $occurrence['locator'] ) ? sanitize_text_field( (string) $occurrence['locator'] ) : '';
        $supported = ! isset( $occurrence['supported'] ) || (bool) $occurrence['supported'] ? 1 : 0;

        $occ_normalized = array(
            'group_key' => $group_key,
            'subgroup'  => $subgroup,
            'provider'  => $provider,
            'object_id' => $object_id,
            'field'     => $field,
            'page_path' => $page_path,
            'locator'   => $locator,
        );
        $occ_hash = self::occurrence_hash( $occ_normalized );
        $now      = current_time( 'mysql', true );

        $table = $wpdb->prefix . 'lingolane_string_locations';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier from prefix.
        $res = $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (domain, source_hash, occurrence_hash, group_key, subgroup, provider, object_id, field, page_path, locator, supported, last_seen)
             VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE supported = VALUES(supported), last_seen = VALUES(last_seen)",
            $domain, $source_hash, $occ_hash, $group_key, $subgroup, $provider, $object_id, $field, $page_path, $locator, $supported, $now
        ) );
        // phpcs:enable

        self::invalidate();
        return false !== $res;
    }

    public static function known_groups() {
        return array(
            'site'    => __( 'Site Info', 'lingolane' ),
            'header'  => __( 'Header', 'lingolane' ),
            'menu'    => __( 'Menus', 'lingolane' ),
            'footer'  => __( 'Footer', 'lingolane' ),
            'widgets' => __( 'Widgets', 'lingolane' ),
            'theme'   => __( 'Theme', 'lingolane' ),
            'plugin'  => __( 'Plugins', 'lingolane' ),
            'terms'   => __( 'Categories & Tags', 'lingolane' ),
            'media'   => __( 'Media', 'lingolane' ),
            'other'   => __( 'Other', 'lingolane' ),
        );
    }

    public static function groups( $language ) {
        global $wpdb;
        if ( isset( self::$cached_groups[ $language ] ) ) {
            return self::$cached_groups[ $language ];
        }

        $loc_table = $wpdb->prefix . 'lingolane_string_locations';
        $str_table = $wpdb->prefix . 'lingolane_strings';

        $known = self::known_groups();
        $result = array();
        foreach ( array_keys( $known ) as $key ) {
            $result[ $key ] = array(
                'total'       => 0,
                'own'         => 0,
                'inherited'   => 0,
                'missing'     => 0,
                'unsupported' => 0,
                'percent'     => 0,
            );
        }

        // Query distinct identities per group, joined with translation in $language.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.group_key,
                    COUNT(DISTINCT CONCAT(l.domain, ':', l.source_hash)) as total_count,
                    COUNT(DISTINCT CASE WHEN s.translation IS NOT NULL AND s.translation != '' THEN CONCAT(l.domain, ':', l.source_hash) END) as own_count,
                    COUNT(DISTINCT CASE WHEN l.supported = 0 THEN CONCAT(l.domain, ':', l.source_hash) END) as unsupported_count
             FROM $loc_table l
             LEFT JOIN $str_table s ON l.domain = s.domain AND l.source_hash = s.source_hash AND s.language_code = %s
             GROUP BY l.group_key",
            $language
        ), ARRAY_A );
        // phpcs:enable

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $g = $row['group_key'];
                if ( ! isset( $result[ $g ] ) ) {
                    $result[ $g ] = array(
                        'total'       => 0,
                        'own'         => 0,
                        'inherited'   => 0,
                        'missing'     => 0,
                        'unsupported' => 0,
                        'percent'     => 0,
                    );
                }
                $tot = (int) $row['total_count'];
                $own = (int) $row['own_count'];
                $uns = (int) $row['unsupported_count'];
                $miss = max( 0, $tot - $own );
                $pct  = $tot > 0 ? (int) round( ( $own * 100 ) / $tot ) : 0;

                $result[ $g ]['total']       = $tot;
                $result[ $g ]['own']         = $own;
                $result[ $g ]['missing']     = $miss;
                $result[ $g ]['unsupported'] = $uns;
                $result[ $g ]['percent']     = $pct;
            }
        }

        self::$cached_groups[ $language ] = $result;
        return $result;
    }

    public static function page( $group, $subgroup, $language, $status = 'all', $keyword = '', $page = 1, $per_page = 20 ) {
        global $wpdb;
        $loc_table = $wpdb->prefix . 'lingolane_string_locations';
        $str_table = $wpdb->prefix . 'lingolane_strings';

        $where = array( 'l.group_key = %s' );
        $params = array( $group );

        if ( '' !== (string) $subgroup ) {
            $where[] = 'l.subgroup = %s';
            $params[] = $subgroup;
        }

        if ( 'missing' === $status ) {
            $where[] = "(s.translation IS NULL OR s.translation = '')";
        } elseif ( 'translated' === $status ) {
            $where[] = "(s.translation IS NOT NULL AND s.translation != '')";
        } elseif ( 'unsupported' === $status ) {
            $where[] = 'l.supported = 0';
        }

        if ( '' !== (string) $keyword ) {
            $like = '%' . $wpdb->esc_like( $keyword ) . '%';
            $where[] = '(s_base.source LIKE %s OR s.translation LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // Join s_base to get source string text reliably.
        $def_lang = Lingolane_Languages::default_code();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
        $count_sql = "SELECT COUNT(DISTINCT CONCAT(l.domain, ':', l.source_hash))
                      FROM $loc_table l
                      LEFT JOIN $str_table s ON l.domain = s.domain AND l.source_hash = s.source_hash AND s.language_code = " . $wpdb->prepare( '%s', $language ) . "
                      LEFT JOIN $str_table s_base ON l.domain = s_base.domain AND l.source_hash = s_base.source_hash AND s_base.language_code = " . $wpdb->prepare( '%s', $def_lang ) . "
                      WHERE $where_sql";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql structure is constructed safely and parameterized.
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

        $offset = max( 0, ( $page - 1 ) * $per_page );

        $query_sql = "SELECT l.domain, l.source_hash,
                             COALESCE(MAX(s_base.source), MAX(s.source)) as source,
                             COALESCE(MAX(s_base.context), MAX(s.context), '') as context,
                             COALESCE(MAX(s.translation), '') as translation,
                             MIN(l.supported) as supported
                      FROM $loc_table l
                      LEFT JOIN $str_table s ON l.domain = s.domain AND l.source_hash = s.source_hash AND s.language_code = " . $wpdb->prepare( '%s', $language ) . "
                      LEFT JOIN $str_table s_base ON l.domain = s_base.domain AND l.source_hash = s_base.source_hash AND s_base.language_code = " . $wpdb->prepare( '%s', $def_lang ) . "
                      WHERE $where_sql
                      GROUP BY l.domain, l.source_hash
                      ORDER BY l.domain ASC, source ASC
                      LIMIT %d OFFSET %d";
        // phpcs:enable

        $query_params = array_merge( $params, array( (int) $per_page, (int) $offset ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query_sql structure is constructed safely and parameterized.
        $rows = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ), ARRAY_A );

        $items = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $items[] = array(
                    'domain'      => $row['domain'],
                    'source_hash' => $row['source_hash'],
                    'source'      => (string) $row['source'],
                    'context'     => (string) $row['context'],
                    'translation' => (string) $row['translation'],
                    'supported'   => (bool) $row['supported'],
                );
            }
        }

        return array(
            'items' => $items,
            'total' => $total,
        );
    }

    public static function forget( $domain, $source_hash ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lingolane_string_locations';
        $wpdb->delete(
            $table,
            array(
                'domain'      => $domain,
                'source_hash' => $source_hash,
            ),
            array( '%s', '%s' )
        );
        self::invalidate();
    }

    public static function invalidate() {
        self::$cached_groups = array();
        wp_cache_delete( 'groups', 'lingolane_locations' );
    }
}
