<?php
/**
 * Aliases for the pre-release "TML_" API so themes and plugins written against it keep working.
 * Deprecated; will be removed in a future major version.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

foreach ( array( 'Languages', 'Elements', 'Router', 'Query', 'Urls', 'Switcher', 'SEO', 'Links', 'Strings' ) as $lingolane_legacy_class ) {
    if ( ! class_exists( 'TML_' . $lingolane_legacy_class, false ) && class_exists( 'Lingolane_' . $lingolane_legacy_class, false ) ) {
        class_alias( 'Lingolane_' . $lingolane_legacy_class, 'TML_' . $lingolane_legacy_class );
    }
}
unset( $lingolane_legacy_class );

if ( ! class_exists( 'TML_Shared', false ) && class_exists( 'Lingolane_Shared', false ) ) {
    class TML_Shared extends Lingolane_Shared {
        public static function page_slugs() {
            $slugs = parent::page_slugs();
            if ( ! empty( $slugs ) ) { return $slugs; }
            return array( 'create', 'edit', 'remove', 'my-listings', 'my-favorites', 'profile', 'reset-password', 'change-password', 'logout', 'transactions', 'payment', 'invoices', 'my-watchdogs', 'package-information', 'listing-slider', 'form-redirect', 'report-spam-abuse-or-inappropriate-content' );
        }

        public static function is_tutor( $post ) {
            $post = get_post( $post );
            if ( ! $post ) { return false; }
            $taxonomy = get_taxonomy( 'listing_categories' );
            $types = $taxonomy && ! empty( $taxonomy->object_type ) ? $taxonomy->object_type : self::post_types();
            return in_array( $post->post_type, $types, true );
        }

        public static function translated_tutor( $post, $lang = 'vi' ) {
            $post = get_post( $post );
            if ( ! $post || ! self::is_tutor( $post ) ) { return false; }
            $type = 'post_' . $post->post_type;
            if ( ! Lingolane_Elements::get_trid( $type, $post->ID ) ) { return false; }
            $id = Lingolane_Elements::get_translation( $type, $post->ID, $lang );
            $translated = $id ? get_post( $id ) : null;
            if ( ! $translated || 'publish' !== $translated->post_status || $translated->post_type !== $post->post_type || $lang !== Lingolane_Elements::get_language( $type, $id ) ) { return false; }
            $normalize = function ( $content ) { return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( strip_shortcodes( $content ) ), ENT_QUOTES, 'UTF-8' ) ) ); };
            $content = $normalize( $translated->post_content );
            if ( '' === $content || 0 === strpos( $content, 'draft' ) ) { return false; }
            return $content !== $normalize( $post->post_content );
        }

        public static function post_types() {
            $types = parent::post_types();
            return ! empty( $types ) ? $types : array( 'tutor', 'listing' );
        }

        public static function taxonomies() {
            $tax = parent::taxonomies();
            return ! empty( $tax ) ? $tax : array( 'listing_categories', 'locations' );
        }
    }
}

// Default bridge filters for shared records & application page slugs
add_filter( 'lingolane_shared_page_slugs', function ( $slugs ) {
    if ( empty( $slugs ) ) {
        return array( 'create', 'edit', 'remove', 'my-listings', 'my-favorites', 'profile', 'reset-password', 'change-password', 'logout', 'transactions', 'payment', 'invoices', 'my-watchdogs', 'package-information', 'listing-slider', 'form-redirect', 'report-spam-abuse-or-inappropriate-content' );
    }
    return $slugs;
}, 5 );

add_filter( 'lingolane_shared_post_types', function ( $types ) {
    if ( empty( $types ) ) {
        return array( 'tutor', 'listing' );
    }
    return $types;
}, 5 );

add_filter( 'lingolane_shared_taxonomies', function ( $tax ) {
    if ( empty( $tax ) ) {
        return array( 'listing_categories', 'locations' );
    }
    return $tax;
}, 5 );

add_filter( 'lingolane_gated_post_types', function ( $types ) {
    if ( empty( $types ) ) {
        return array( 'tutor', 'listing' );
    }
    return $types;
}, 5 );

if ( ! function_exists( 'tml_get_translation_url' ) ) {
    function tml_get_translation_url( $lang ) { return lingolane_get_translation_url( $lang ); }
}
if ( ! function_exists( 'tml_register_string' ) ) {
    function tml_register_string( $domain, $source, $context = '' ) { return lingolane_register_string( $domain, $source, $context ); }
}
add_action( 'init', function () { add_shortcode( 'tml_switcher', array( 'Lingolane_Switcher', 'shortcode' ) ); }, 20 );

// Bridge legacy query parameter for themes checking $_GET['tml_untranslated'].
// phpcs:disable WordPress.Security.NonceVerification.Recommended
if ( ! is_admin() && isset( $_GET['lingolane_untranslated'] ) && ! isset( $_GET['tml_untranslated'] ) ) {
    $_GET['tml_untranslated'] = sanitize_key( wp_unslash( $_GET['lingolane_untranslated'] ) );
}
// phpcs:enable
