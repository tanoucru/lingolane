<?php
/** Batched translation groups for the admin post list. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Tree {
    /** Post IDs that matched the current search, keyed by ID, so their rows can be highlighted. */
    public static $matches = array();

    public static function init() {
        add_filter( 'posts_where', array( __CLASS__, 'posts_where' ), 10, 2 );
        add_filter( 'posts_search', array( __CLASS__, 'posts_search' ), 10, 2 );
        add_filter( 'post_class', array( __CLASS__, 'post_class' ), 10, 3 );
    }

    /** The admin list's main query, unfiltered by language and outside the trash. */
    public static function is_tree_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || $query->get( 'lingolane_tree_off' ) || ! Lingolane_Columns::tree_screen() ) { return false; }
        if ( Lingolane_Languages::is_valid( Lingolane_Columns::requested_language() ) ) { return false; }
        if ( in_array( 'trash', wp_parse_list( $query->get( 'post_status' ) ), true ) || self::narrowed( $query ) ) { return false; }
        return (bool) apply_filters( 'lingolane_tree_mode', true, $query );
    }

    /** Filters on fields a translation does not share with its source (Mine, month, terms): the list stays flat. */
    private static function narrowed( $query ) {
        foreach ( array( 'author', 'author_name', 'author__in', 'm', 'year', 'monthnum', 'w', 'day', 'date_query', 'meta_query', 'post_parent', 'post__in' ) as $var ) {
            if ( $query->get( $var ) ) { return true; }
        }
        return $query->tax_query instanceof WP_Tax_Query && ! empty( $query->tax_query->queries );
    }

    /** Keeps posts with no translation row, sources, and translations whose group has no source this view can show. */
    public static function posts_where( $where, $query ) {
        if ( ! self::is_tree_query( $query ) ) { return $where; }
        global $wpdb;
        $table = $wpdb->prefix . 'lingolane_translations';
        $type = 'post_' . get_current_screen()->post_type;
        return $where . $wpdb->prepare(
            " AND NOT EXISTS ( SELECT 1 FROM $table lingolane_t WHERE lingolane_t.element_type = %s AND lingolane_t.element_id = {$wpdb->posts}.ID AND lingolane_t.source_language_code IS NOT NULL AND EXISTS ( SELECT 1 FROM $table lingolane_s INNER JOIN {$wpdb->posts} lingolane_p ON lingolane_p.ID = lingolane_s.element_id WHERE lingolane_s.trid = lingolane_t.trid AND lingolane_s.element_type = %s AND lingolane_s.source_language_code IS NULL AND ", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from $wpdb.
            $type,
            $type
        ) . self::source_status( $query ) . ' ) )';
    }

    /** Searches every language, then lists the groups whose members matched. */
    public static function posts_search( $search, $query ) {
        if ( ! self::is_tree_query( $query ) ) { return $search; }
        self::$matches = array();
        $term = $query->get( 's' );
        if ( ! is_string( $term ) || '' === trim( $term ) ) { return $search; }
        global $wpdb;
        $post_type = get_current_screen()->post_type;
        $found = new WP_Query( array(
            'post_type' => $post_type,
            'post_status' => $query->get( 'post_status' ),
            'perm' => $query->get( 'perm' ),
            's' => $term,
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'lingolane_tree_off' => true,
        ) );
        $ids = array_values( array_unique( array_map( 'intval', $found->posts ) ) );
        if ( ! $ids ) { return ' AND 1=0'; }
        self::$matches = array_fill_keys( $ids, true );

        $table = $wpdb->prefix . 'lingolane_translations';
        $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sources = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $ids matches the %d list built in $in.
            "SELECT lingolane_t.element_id, lingolane_s.element_id AS source_id FROM $table lingolane_t INNER JOIN $table lingolane_s ON lingolane_s.trid = lingolane_t.trid AND lingolane_s.element_type = lingolane_t.element_type AND lingolane_s.source_language_code IS NULL INNER JOIN {$wpdb->posts} lingolane_p ON lingolane_p.ID = lingolane_s.element_id WHERE lingolane_t.element_type = %s AND lingolane_t.source_language_code IS NOT NULL AND lingolane_t.element_id IN ($in) AND ", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from $wpdb, $in holds only %d placeholders.
            array_merge( array( 'post_' . $post_type ), $ids )
        ) . self::source_status( $query ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- source_status() is prepared.
        $map = wp_list_pluck( $sources, 'source_id', 'element_id' );
        $leaders = array();
        foreach ( $ids as $id ) {
            $leaders[] = isset( $map[ $id ] ) ? (int) $map[ $id ] : $id;
        }
        $leaders = array_values( array_unique( $leaders ) );
        return $wpdb->prepare( " AND {$wpdb->posts}.ID IN (" . implode( ',', array_fill( 0, count( $leaders ), '%d' ) ) . ')', $leaders ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- only %d placeholders, one per leader.
    }

    public static function post_class( $classes, $class, $post_id ) {
        if ( isset( self::$matches[ (int) $post_id ] ) ) { $classes[] = 'lingolane-tree-match'; }
        return $classes;
    }

    /**
     * Statuses a group's source must have to lead its group in this view (alias lingolane_p).
     * A published translation of a draft source stays in the Published view instead of vanishing,
     * and so does one whose source is someone else's private post the user cannot read.
     */
    private static function source_status( $query ) {
        global $wpdb;
        $statuses = array_map( 'sanitize_key', wp_parse_list( $query->get( 'post_status' ) ) );
        if ( ! $statuses || in_array( 'any', $statuses, true ) ) {
            $sql = $wpdb->prepare( 'lingolane_p.post_status NOT IN (%s, %s)', 'trash', 'auto-draft' );
        } else {
            $sql = $wpdb->prepare( 'lingolane_p.post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', $statuses ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- only %s placeholders, one per status.
        }
        // Same cases where WP_Query hides other users' private posts: the All view and readable status views.
        $type = get_post_type_object( get_current_screen()->post_type );
        if ( ( ! $statuses || 'readable' === $query->get( 'perm' ) ) && $type && ! current_user_can( $type->cap->read_private_posts ) ) {
            $sql .= $wpdb->prepare( ' AND ( lingolane_p.post_status <> %s OR lingolane_p.post_author = %d )', 'private', get_current_user_id() );
        }
        return $sql;
    }

    public static function groups( $post_type, $leader_ids ) {
        global $wpdb;
        $post_type = sanitize_key( $post_type );
        $leader_ids = array_values( array_unique( array_filter( array_map( 'absint', $leader_ids ) ) ) );
        if ( ! $leader_ids || Lingolane_Shared::post_type( $post_type ) ) { return array(); }

        $table = $wpdb->prefix . 'lingolane_translations';
        $type = 'post_' . $post_type;
        $in = implode( ',', array_fill( 0, count( $leader_ids ), '%d' ) );
        $args = array_merge( array( $type ), $leader_ids );
        $leaders = $wpdb->get_results( $wpdb->prepare(
            "SELECT element_id, trid, language_code FROM $table WHERE element_type = %s AND element_id IN ($in)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix, $in holds only %d placeholders.
            $args
        ) );
        $trids = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $leaders, 'trid' ) ) ) ) );
        if ( ! $trids ) { return array(); }

        // Join post status and prime post caches in the same batch, leaving room for a cold settings read.
        $in = implode( ',', array_fill( 0, count( $trids ), '%d' ) );
        $args = array_merge( array( $type ), $trids, array( $post_type, 'trash' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $args matches the %d list built in $in.
            "SELECT p.*, t.trid, t.language_code FROM $table t INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id WHERE t.element_type = %s AND t.trid IN ($in) AND p.post_type = %s AND p.post_status <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from $wpdb, $in holds only %d placeholders.
            $args
        ) );
        $posts = array();
        $members = array();
        foreach ( $rows as $row ) {
            $members[ (int) $row->trid ][ $row->language_code ] = (int) $row->ID;
            unset( $row->trid, $row->language_code );
            $posts[ (int) $row->ID ] = new WP_Post( $row );
        }
        update_post_cache( $posts );

        $languages = array_column( Lingolane_Languages::all(), 'code' );
        // Same test as Lingolane_Shared::page_ids(), run on the rows above so it costs no query.
        $shared_slugs = 'page' === $post_type ? Lingolane_Shared::page_slugs() : array();
        $groups = array();
        foreach ( $leaders as $leader ) {
            $id = (int) $leader->element_id;
            $trid = (int) $leader->trid;
            if ( ! $trid || ! $leader->language_code || ! isset( $posts[ $id ] ) ) { continue; }
            $post = $posts[ $id ];
            if ( $shared_slugs && 'publish' === $post->post_status && ! $post->post_parent && in_array( $post->post_name, $shared_slugs, true ) ) { continue; }
            $translations = $members[ $trid ];
            unset( $translations[ $leader->language_code ] );
            $groups[ $id ] = array(
                'trid' => $trid,
                'lang' => $leader->language_code,
                'members' => $translations,
                'missing' => array_values( array_diff( $languages, array_merge( array( $leader->language_code ), array_keys( $translations ) ) ) ),
                'done' => 1 + count( $translations ),
                'total' => count( $languages ),
            );
        }
        return $groups;
    }
}
