<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Query {
    const DEFAULT_POST_TYPES = array( 'post', 'page' );
    const DEFAULT_TAXONOMIES = array( 'category', 'post_tag' );

    public static function post_types() {
        $settings = get_option( 'lingolane_settings', array() );
        $types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) && ! empty( $settings['post_types'] ) ? $settings['post_types'] : self::DEFAULT_POST_TYPES;
        return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'lingolane_translatable_post_types', $types ) ) ) );
    }

    public static function taxonomies() {
        $settings = get_option( 'lingolane_settings', array() );
        $taxonomies = isset( $settings['taxonomies'] ) && is_array( $settings['taxonomies'] ) && ! empty( $settings['taxonomies'] ) ? $settings['taxonomies'] : self::DEFAULT_TAXONOMIES;
        return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'lingolane_translatable_taxonomies', $taxonomies ) ) ) );
    }

    public static function init() {
        add_action( 'pre_get_posts', array( __CLASS__, 'prepare' ) );
        add_filter( 'posts_clauses', array( __CLASS__, 'posts' ), 10, 2 );
        add_filter( 'terms_clauses', array( __CLASS__, 'terms' ), 10, 3 );
        add_filter( 'term_exists_default_query_args', array( __CLASS__, 'term_exists_args' ) );
        add_filter( 'lingolane_fallback_to_untranslated', array( __CLASS__, 'fallback_setting' ), 5 );
    }

    /** Listings may include default-language items that have no translation yet (Settings > Content). */
    public static function fallback_setting( $fallback ) {
        $settings = get_option( 'lingolane_settings', array() );
        return $fallback || ! empty( $settings['fallback_to_untranslated'] );
    }

    public static function prepare( $query ) {
        $query->set( '_lingolane_language', '' );
        if ( is_admin() || $query->get( 'suppress_filters' ) ) { return; }
        $types = (array) $query->get( 'post_type' );
        if ( $query->is_main_query() || array_intersect( $types, self::post_types() ) ) {
            $query->set( '_lingolane_language', Lingolane_Languages::current() );
        }
    }

    /**
     * Collation of the plugin's element_type column. Core tables may use a different collation
     * (utf8mb4_unicode_ci vs utf8mb4_unicode_520_ci), so comparisons are forced to this one.
     */
    public static function collation() {
        global $wpdb;
        static $name;
        if ( null !== $name ) { return $name; }
        $cached = get_transient( 'lingolane_collation' );
        if ( is_string( $cached ) ) { return $name = $cached; }
        $table = $wpdb->prefix . 'lingolane_translations';
        $found = $wpdb->get_var( $wpdb->prepare( 'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, 'element_type' ) );
        $name = ( is_string( $found ) && preg_match( '/^[A-Za-z0-9_]+$/', $found ) ) ? $found : '';
        set_transient( 'lingolane_collation', $name, WEEK_IN_SECONDS );
        return $name;
    }

    /** SQL expression for an element type built from a core column, comparable with the plugin table. */
    public static function type_sql( $prefix, $column ) {
        $collation = self::collation();
        return "CONCAT('" . $prefix . "', $column)" . ( $collation ? " COLLATE $collation" : '' );
    }

    /** Correlated EXISTS avoids multiplying rows, preserving counts and pagination. */
    private static function condition( $type_sql, $id_sql, $lang ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lingolane_translations';
        $exists = "SELECT 1 FROM $table lingolane_t WHERE lingolane_t.element_type = $type_sql AND lingolane_t.element_id = $id_sql";
        $sql = $wpdb->prepare( "EXISTS ($exists AND lingolane_t.language_code = %s)", $lang );
        // Unassigned content is in the source language.
        if ( $lang === Lingolane_Languages::source_code() || apply_filters( 'lingolane_fallback_to_untranslated', false, $lang ) ) {
            $sql .= " OR NOT EXISTS ($exists)";
        }
        return '(' . $sql . ')';
    }

    public static function posts( $clauses, $query ) {
        global $wpdb;
        if ( $query->get( 'suppress_filters' ) ) { return $clauses; }
        $lang = is_admin() ? $query->get( '_lingolane_admin_language' ) : $query->get( '_lingolane_language' );
        if ( ! Lingolane_Languages::is_valid( $lang ) ) { return $clauses; }
        $types = self::post_types();
        if ( ! $types ) { return $clauses; }
        $list = $wpdb->prepare( implode( ',', array_fill( 0, count( $types ), '%s' ) ), $types );
        $type_sql = self::type_sql( 'post_', "{$wpdb->posts}.post_type" );
        $condition = self::condition( $type_sql, "{$wpdb->posts}.ID", $lang );
        // Unassigned application records are shared; a registered translation of another language never is.
        $shared_types = Lingolane_Shared::post_types();
        $shared_list = $shared_types ? $wpdb->prepare( implode( ',', array_fill( 0, count( $shared_types ), '%s' ) ), $shared_types ) : "''";
        $shared_ids = implode( ',', array_merge( array( 0 ), Lingolane_Shared::page_ids() ) );
        $table = $wpdb->prefix . 'lingolane_translations';
        $condition = "($condition OR (({$wpdb->posts}.post_type IN ($shared_list) OR {$wpdb->posts}.ID IN ($shared_ids)) AND NOT EXISTS (SELECT 1 FROM $table shared WHERE shared.element_id = {$wpdb->posts}.ID AND shared.element_type = $type_sql)))";
        $front_id = (int) get_option( 'page_on_front' );
        if ( $front_id && ! Lingolane_Elements::get_translation( 'post_page', $front_id, $lang ) ) {
            $condition = "({$wpdb->posts}.ID = $front_id OR $condition)";
        }
        $clauses['where'] .= " AND ({$wpdb->posts}.post_type NOT IN ($list) OR $condition)";
        return $clauses;
    }

    public static function term_exists_args( $args ) {
        $args['lingolane_skip'] = true;
        return $args;
    }

    public static function terms( $clauses, $taxonomies, $args ) {
        global $wpdb;
        // term_exists() must see every term, otherwise wp_insert_term()/wp_delete_term() misbehave on the frontend.
        if ( is_admin() || ! empty( $args['suppress_filters'] ) || ! empty( $args['lingolane_skip'] ) ) { return $clauses; }
        $types = array_intersect( $taxonomies, self::taxonomies() );
        if ( ! $types ) { return $clauses; }
        $list = $wpdb->prepare( implode( ',', array_fill( 0, count( $types ), '%s' ) ), array_values( $types ) );
        $type_sql = self::type_sql( 'tax_', 'tt.taxonomy' );
        $condition = self::condition( $type_sql, 't.term_id', Lingolane_Languages::current() );
        $table = $wpdb->prefix . 'lingolane_translations';
        $shared = Lingolane_Shared::taxonomies();
        if ( $shared ) {
            $shared_list = $wpdb->prepare( implode( ',', array_fill( 0, count( $shared ), '%s' ) ), $shared );
            $condition = "($condition OR (tt.taxonomy IN ($shared_list) AND NOT EXISTS (SELECT 1 FROM $table shared WHERE shared.element_type = $type_sql AND shared.element_id = t.term_id)))";
        }
        $clauses['where'] .= " AND (tt.taxonomy NOT IN ($list) OR $condition)";
        return $clauses;
    }
}
