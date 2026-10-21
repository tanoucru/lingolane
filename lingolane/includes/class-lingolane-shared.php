<?php
/** Application pages and records shared by every language; editorial pages keep translation groups. */
defined( 'ABSPATH' ) || exit;
class Lingolane_Shared {
    const CACHE_KEY = 'lingolane_shared_page_ids';

    public static function init() {
        add_action( 'save_post_page', array( __CLASS__, 'flush' ) );
        add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
        add_action( 'trashed_post', array( __CLASS__, 'flush' ) );
    }

    public static function page_slugs() {
        return array_values( array_filter( array_map( 'sanitize_title', (array) apply_filters( 'lingolane_shared_page_slugs', array() ) ) ) );
    }

    public static function is_gated( $post ) {
        $post = get_post( $post );
        return $post && in_array( $post->post_type, self::gated_post_types(), true );
    }

    /** A registered, published translated record with content, not an unchanged copy of its source sibling. */
    public static function translated_gated( $post, $lang = '' ) {
        $post = get_post( $post );
        $lang = $lang ? $lang : Lingolane_Languages::current();
        if ( ! $post || ! self::is_gated( $post ) ) { return false; }
        $type = 'post_' . $post->post_type;
        if ( ! Lingolane_Elements::get_trid( $type, $post->ID ) ) { return false; }
        $id = Lingolane_Elements::get_translation( $type, $post->ID, $lang );
        $translated = $id ? get_post( $id ) : null;
        if ( ! $translated || 'publish' !== $translated->post_status || $translated->post_type !== $post->post_type || $lang !== Lingolane_Elements::get_language( $type, $id ) ) { return false; }
        $normalize = function ( $content ) { return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( strip_shortcodes( $content ) ), ENT_QUOTES, 'UTF-8' ) ) ); };
        $content = $normalize( $translated->post_content );
        if ( '' === $content ) { return false; }
        $source_id = Lingolane_Elements::get_translation( $type, $id, Lingolane_Languages::source_code() );
        $source = $source_id ? get_post( $source_id ) : null;
        return ! $source || $content !== $normalize( $source->post_content );
    }

    public static function post_types() {
        return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'lingolane_shared_post_types', array() ) ) ) );
    }

    public static function taxonomies() {
        return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'lingolane_shared_taxonomies', array() ) ) ) );
    }

    /** Post types whose untranslated non-default copies are kept out of hreflang and sitemaps. */
    public static function gated_post_types() {
        return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'lingolane_gated_post_types', array() ) ) ) );
    }

    /** Published top-level pages with the slugs above, resolved in one query and cached until a page changes. */
    public static function page_ids() {
        static $ids;
        if ( null !== $ids ) { return $ids; }
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) { return $ids = array_map( 'intval', $cached ); }
        global $wpdb;
        $slugs = self::page_slugs();
        $placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
        $found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_parent = 0 AND post_name IN ($placeholders)", $slugs ) );
        $ids = array_map( 'intval', (array) $found );
        set_transient( self::CACHE_KEY, $ids, DAY_IN_SECONDS );
        return $ids;
    }

    public static function flush() { delete_transient( self::CACHE_KEY ); }

    public static function post( $post ) {
        return $post instanceof WP_Post && ( self::post_type( $post->post_type ) || ( 'page' === $post->post_type && in_array( (int) $post->ID, self::page_ids(), true ) ) );
    }

    public static function post_type( $type ) { return in_array( $type, self::post_types(), true ); }

    public static function taxonomy( $taxonomy ) {
        return in_array( $taxonomy, self::taxonomies(), true );
    }
}
