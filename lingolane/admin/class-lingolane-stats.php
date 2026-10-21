<?php
/** Read-only figures for the dashboard. Shared tutor records and application pages never need translating. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Stats {
    const STRINGS_KEY = 'lingolane_strings_stats';
    const SOURCE_STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

    /** Translatable types whose items are translated one by one (tutor types are shared). */
    public static function editorial_types() {
        return array_values( array_filter( Lingolane_Query::post_types(), function ( $type ) {
            return ! Lingolane_Shared::post_type( $type ) && post_type_exists( $type );
        } ) );
    }

    public static function other_languages() {
        $default = Lingolane_Languages::source_code();
        return array_values( array_filter( Lingolane_Languages::all(), function ( $language ) use ( $default ) {
            return $language['code'] !== $default;
        } ) );
    }

    /**
     * FROM/WHERE for source items: items in the default language (or not yet assigned) of the given types.
     * Joins the item's own translation row as `s`.
     */
    public static function source_sql( $types, $statuses ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lingolane_translations';
        $type_sql = Lingolane_Query::type_sql( 'post_', 'p.post_type' );
        $ids = implode( ',', array_map( 'intval', array_merge( array( 0 ), Lingolane_Shared::page_ids() ) ) );
        $types = $types ? array_values( (array) $types ) : array( '' );
        $statuses = $statuses ? array_values( (array) $statuses ) : array( '' );
        $type_in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $status_in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        // $table, $type_sql and $ids come from $wpdb->prefix, literals, a validated collation and integers; every value uses a %s placeholder.
        return $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "FROM {$wpdb->posts} p LEFT JOIN $table s ON s.element_type = $type_sql AND s.element_id = p.ID
            WHERE p.post_type IN ($type_in) AND p.post_status IN ($status_in) AND p.ID NOT IN ($ids) AND (s.id IS NULL OR s.language_code = %s)",
            array_merge( $types, $statuses, array( Lingolane_Languages::source_code() ) )
        );
    }

    /** SQL condition: source item has a published translation in $lang. */
    public static function translated_sql( $lang ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lingolane_translations';
        return $wpdb->prepare( "EXISTS (SELECT 1 FROM $table x INNER JOIN {$wpdb->posts} px ON px.ID = x.element_id WHERE x.trid = s.trid AND x.language_code = %s AND px.post_status = 'publish')", $lang );
    }

    /** Per language: published sources, published translations, unpublished translations. */
    public static function content( $lang ) {
        global $wpdb;
        $types = self::editorial_types();
        if ( ! $types ) { return array( 'total' => 0, 'published' => 0, 'drafts' => 0, 'missing' => 0 ); }
        $table = $wpdb->prefix . 'lingolane_translations';
        $src = self::source_sql( $types, array( 'publish' ) );
        // $src is a prepared fragment; $table comes from $wpdb->prefix.
        $row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN px.post_status = 'publish' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN px.ID IS NOT NULL AND px.post_status <> 'publish' THEN 1 ELSE 0 END) AS drafts
            FROM (SELECT p.ID, s.trid $src) src
            LEFT JOIN $table x ON x.trid = src.trid AND x.language_code = %s
            LEFT JOIN {$wpdb->posts} px ON px.ID = x.element_id AND px.post_status NOT IN ('trash','auto-draft','inherit')",
            $lang
        ), ARRAY_A );
        $total = $row ? (int) $row['total'] : 0;
        $published = $row ? (int) $row['published'] : 0;
        $drafts = $row ? (int) $row['drafts'] : 0;
        return array( 'total' => $total, 'published' => $published, 'drafts' => $drafts, 'missing' => max( 0, $total - $published - $drafts ) );
    }

    public static function shared_count() {
        global $wpdb;
        $types = array_values( array_intersect( Lingolane_Shared::post_types(), Lingolane_Query::post_types() ) );
        if ( ! $types ) { return 0; }
        $in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in)", $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %s placeholders.
    }

    /** Linked rows per language code (content and terms). */
    public static function linked_counts() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT language_code, COUNT(*) AS c FROM {$wpdb->prefix}lingolane_translations GROUP BY language_code", ARRAY_A );
        $counts = array();
        foreach ( (array) $rows as $row ) { $counts[ $row['language_code'] ] = (int) $row['c']; }
        return $counts;
    }

    /** Strings per language: total, translated here, provided by the theme catalog, missing. Cached for an hour. */
    public static function strings() {
        $cached = get_transient( self::STRINGS_KEY );
        if ( is_array( $cached ) ) { return $cached; }
        global $wpdb;
        $table = $wpdb->prefix . 'lingolane_strings';
        $stats = array();
        foreach ( self::other_languages() as $language ) {
            $code = $language['code'];
            $own = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE language_code = %s AND translation <> ''", $code ) );
            $empty = $wpdb->get_results( $wpdb->prepare( "SELECT domain, context, source FROM $table WHERE language_code = %s AND translation = '' LIMIT 5000", $code ) );
            $inherited = 0;
            foreach ( (array) $empty as $row ) {
                if ( '' !== Lingolane_Strings::inherited( $row->source, $row->domain, (string) $row->context, $code ) ) { ++$inherited; }
            }
            $total = $own + count( (array) $empty );
            $stats[ $code ] = array( 'total' => $total, 'own' => $own, 'inherited' => $inherited, 'missing' => max( 0, $total - $own - $inherited ) );
        }
        set_transient( self::STRINGS_KEY, $stats, HOUR_IN_SECONDS );
        return $stats;
    }

    public static function flush_strings() { delete_transient( self::STRINGS_KEY ); }

    public static function percent( $part, $total ) {
        return $total > 0 ? (int) floor( 100 * $part / $total ) : 100;
    }
}
