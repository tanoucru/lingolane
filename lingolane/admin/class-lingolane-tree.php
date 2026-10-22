<?php
/** Batched translation groups for the admin post list. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Tree {
    public static function init() {}

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
