<?php
/** Batched translation groups for the admin post list. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Tree {
    /** Post IDs that matched the current search, keyed by ID, so their rows can be highlighted. */
    public static $matches = array();

    /** Leader IDs the list printed, in row order. */
    private static $remembered = array();

    /** Group per leader ID (false when it has none), loaded a window of rows at a time. */
    private static $groups = array();

    /** True while member rows are rendered, so their own hooks do not treat them as leaders. */
    private static $printing = false;

    public static function init() {
        add_filter( 'posts_where', array( __CLASS__, 'posts_where' ), 10, 2 );
        add_filter( 'posts_search', array( __CLASS__, 'posts_search' ), 10, 2 );
        add_filter( 'post_class', array( __CLASS__, 'post_class' ), 10, 3 );
        add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'print_templates' ) );
        add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );
    }

    /** Forgets everything gathered for the current list. */
    public static function reset() {
        self::$remembered = array();
        self::$groups = array();
        self::$printing = false;
        self::$matches = array();
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
        $args = array_merge( array( $type ), $trids, array( $post_type ) );
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $args matches the %d list built in $in.
            "SELECT p.*, t.trid, t.language_code FROM $table t INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id WHERE t.element_type = %s AND t.trid IN ($in) AND p.post_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from $wpdb, $in holds only %d placeholders.
            $args
        ) );
        $posts = array();
        $members = array();
        $assigned = array();
        foreach ( $rows as $row ) {
            $trid = (int) $row->trid;
            $lang = $row->language_code;
            $assigned[ $trid ][ $lang ] = true;
            if ( 'trash' === $row->post_status ) {
                continue;
            }
            $members[ $trid ][ $lang ] = (int) $row->ID;
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
            $translations = isset( $members[ $trid ] ) ? $members[ $trid ] : array();
            unset( $translations[ $leader->language_code ] );
            $existing = isset( $assigned[ $trid ] ) ? array_keys( $assigned[ $trid ] ) : array( $leader->language_code );
            $groups[ $id ] = array(
                'trid' => $trid,
                'lang' => $leader->language_code,
                'members' => $translations,
                'missing' => array_values( array_diff( $languages, $existing ) ),
                'done' => 1 + count( $translations ),
                'total' => count( $languages ),
            );
        }
        return $groups;
    }

    /** The tree list is being rendered: translatable screen and a main query that is in tree mode. */
    private static function tree_mode() {
        return Lingolane_Columns::tree_screen() && isset( $GLOBALS['wp_the_query'] ) && self::is_tree_query( $GLOBALS['wp_the_query'] );
    }

    /** Notes a leader row the list printed, keeping order and dropping repeats. */
    public static function remember( $post_id ) {
        $post_id = (int) $post_id;
        if ( self::$printing || ! $post_id || in_array( $post_id, self::$remembered, true ) ) { return; }
        self::$remembered[] = $post_id;
    }

    /** The group led by $id, loading every row of the current list page with it on a miss. */
    private static function group( $id ) {
        $id = (int) $id;
        if ( ! array_key_exists( $id, self::$groups ) ) {
            $window = array_values( array_unique( array_merge( array( $id ), array_diff( self::page_ids(), array_keys( self::$groups ) ) ) ) );
            $found = self::groups( get_current_screen()->post_type, $window );
            foreach ( $window as $window_id ) {
                self::$groups[ $window_id ] = isset( $found[ $window_id ] ) ? $found[ $window_id ] : false;
            }
        }
        return self::$groups[ $id ];
    }

    /**
     * Row IDs the list shows on this page. A hierarchical list loads every page and paginates while it walks
     * parents before children (WP_Posts_List_Table::_display_rows_hierarchical()), so the walk is repeated here.
     */
    private static function page_ids() {
        $query = $GLOBALS['wp_the_query'];
        $parents = array();
        foreach ( (array) $query->posts as $row ) {
            $id = (int) ( is_object( $row ) ? $row->ID : $row );
            $parents[ $id ] = is_object( $row ) && isset( $row->post_parent ) && (int) $row->post_parent !== $id ? (int) $row->post_parent : 0;
        }
        if ( -1 !== (int) $query->get( 'posts_per_page' ) ) { return array_keys( $parents ); }

        $order = array_keys( $parents );
        if ( empty( $_REQUEST['s'] ) && is_post_type_hierarchical( $query->get( 'post_type' ) ) && 'menu_order title' === $query->get( 'orderby' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, same check as core.
            $children = array();
            foreach ( $parents as $id => $parent ) {
                $children[ $parent ][] = $id;
            }
            $order = array();
            $stack = isset( $children[0] ) ? array_reverse( $children[0] ) : array();
            unset( $children[0] );
            while ( $stack ) {
                $id = array_pop( $stack );
                $order[] = $id;
                if ( isset( $children[ $id ] ) ) {
                    $stack = array_merge( $stack, array_reverse( $children[ $id ] ) );
                    unset( $children[ $id ] );
                }
            }
            // Children whose parent is not listed come last, as core prints them.
            foreach ( $children as $orphans ) {
                $order = array_merge( $order, $orphans );
            }
        }
        $table = isset( $GLOBALS['wp_list_table'] ) ? $GLOBALS['wp_list_table'] : null;
        $per_page = $table ? (int) $table->get_pagination_arg( 'per_page' ) : 0;
        $per_page = $per_page > 0 ? $per_page : 20;
        $ids = array_slice( $order, ( ( $table ? $table->get_pagenum() : 1 ) - 1 ) * $per_page, $per_page );
        // A page that starts inside a subtree also prints that subtree's ancestors.
        for ( $parent = $ids ? $parents[ $ids[0] ] : 0; $parent && isset( $parents[ $parent ] ) && ! in_array( $parent, $ids, true ); $parent = $parents[ $parent ] ) {
            $ids[] = $parent;
        }
        return $ids;
    }

    /** Whether a translation may be shown or counted for the current user: core hides only private statuses it cannot read. */
    private static function readable_member( $post ) {
        if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) { return false; }
        $status = get_post_status_object( $post->post_status );
        return ! $status || ! $status->private || current_user_can( 'read_post', $post->ID );
    }

    /**
     * Prints each printed leader's translation rows, built by the list table itself, in a template the script unfolds.
     * Translations of a source the view hides can each lead the same group (posts_where()), so a post already on
     * the page is not printed again and a group's missing languages are offered once.
     */
    public static function print_templates( $which ) {
        if ( 'bottom' !== $which || ! self::$remembered || self::$printing || ! self::tree_mode() || empty( $GLOBALS['wp_list_table'] ) ) { return; }
        $table = $GLOBALS['wp_list_table'];
        $statuses = array_map( 'sanitize_key', wp_parse_list( $GLOBALS['wp_the_query']->get( 'post_status' ) ) );
        $members = array();
        foreach ( self::$remembered as $leader ) {
            $group = self::group( $leader );
            if ( ! $group ) { continue; }
            foreach ( $group['members'] as $id ) {
                $member = get_post( $id );
                if ( $member ) { $members[ $id ] = $member; }
            }
        }
        if ( $members ) {
            $ids = array_keys( $members );
            update_postmeta_cache( $ids );
            update_object_term_cache( $ids, get_current_screen()->post_type );
            if ( function_exists( 'update_post_author_caches' ) ) { update_post_author_caches( array_values( $members ) ); }
        }
        $languages = Lingolane_Languages::all();
        $saved = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
        $shown = array_fill_keys( self::$remembered, true );
        $offered = array();
        $level = ob_get_level();
        self::$printing = true;
        try {
            foreach ( self::$remembered as $leader ) {
                $group = self::group( $leader );
                if ( ! $group ) { continue; }
                ob_start();
                foreach ( $languages as $language ) {
                    if ( ! isset( $group['members'][ $language['code'] ] ) || isset( $shown[ $group['members'][ $language['code'] ] ] ) ) { continue; }
                    $member = get_post( $group['members'][ $language['code'] ] );
                    if ( ! self::readable_member( $member ) ) { continue; }
                    if ( $statuses && ! in_array( 'any', $statuses, true ) && ! in_array( $member->post_status, $statuses, true ) ) { continue; }
                    $shown[ $member->ID ] = true;
                    $table->single_row( $member, 0 );
                }
                $source = get_post( $leader );
                if ( ! isset( $offered[ $group['trid'] ] ) && $source && 'trash' !== $source->post_status && current_user_can( 'edit_post', $leader ) ) {
                    $offered[ $group['trid'] ] = true;
                    foreach ( $group['missing'] as $code ) {
                        /* translators: %s: language name */
                        $label    = sprintf( __( '%s: not translated yet', 'lingolane' ), Lingolane_Languages::name( $code ) );
                        $flag_svg = class_exists( 'Lingolane_Flags' ) ? Lingolane_Flags::get_flag_svg( $code, 18, 13, 'lingolane-flag--shadow' ) . ' ' : '';
                        echo '<tr class="lingolane-tree-missing" data-lingolane-lang="' . esc_attr( $code ) . '"><td class="check-column"></td><td class="colspanchange column-title" colspan="' . esc_attr( max( 1, $table->get_column_count() - 1 ) ) . '">' . $flag_svg . esc_html( $label ) . ' <a href="' . esc_url( Lingolane_Admin::translate_url( $leader, $code ) ) . '">' . Lingolane_UI::icon( 'plus', 16 ) . ' ' . esc_html__( 'Create', 'lingolane' ) . '</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon and flag are SVGs, the rest is escaped.
                    }
                }
                $rows = ob_get_clean();
                if ( '' !== $rows ) {
                    echo '<template class="lingolane-tree-tpl" data-lingolane-leader="' . esc_attr( $leader ) . '">' . $rows . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rows holds the list table's own rows and the escaped markup above.
                }
            }
        } finally {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
            self::$printing = false;
            if ( $saved instanceof WP_Post ) {
                $GLOBALS['post'] = $saved;
                setup_postdata( $saved );
            } else {
                unset( $GLOBALS['post'] );
            }
        }
    }

    /** Adds the done/total translation count next to a leader's title. */
    public static function post_states( $states, $post ) {
        if ( self::$printing ) { return $states; }
        $inline = wp_doing_ajax() && isset( $_POST['action'] ) && 'inline-save' === $_POST['action'] && Lingolane_Columns::tree_screen(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only display; core verifies inline-save.
        if ( $inline ) {
            $groups = self::groups( $post->post_type, array( $post->ID ) );
            $group = isset( $groups[ $post->ID ] ) ? $groups[ $post->ID ] : false;
        } else {
            if ( ! self::tree_mode() ) { return $states; }
            $group = self::group( $post->ID );
        }
        if ( ! $group ) { return $states; }
        $group['done'] = 1;
        foreach ( $group['members'] as $id ) {
            if ( self::readable_member( get_post( $id ) ) ) { ++$group['done']; }
        }
        /* translators: 1: translated languages, 2: all languages */
        $title = sprintf( __( '%1$d of %2$d languages', 'lingolane' ), $group['done'], $group['total'] );
        $states['lingolane_progress'] = '<span class="lingolane-tree-progress" title="' . esc_attr( $title ) . '">' . esc_html( $group['done'] . '/' . $group['total'] ) . '</span>';
        return $states;
    }
}
