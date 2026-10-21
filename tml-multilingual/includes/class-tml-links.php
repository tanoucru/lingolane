<?php
/** Resolve saved internal links without altering external, admin or media URLs. */
defined( 'ABSPATH' ) || exit;
class TML_Links {
    public static function init() {
        add_filter( 'render_block', array( __CLASS__, 'block' ), 100, 2 );
        add_filter( 'page_link', array( __CLASS__, 'permalink' ), 10, 2 );
        add_filter( 'post_link', array( __CLASS__, 'permalink' ), 10, 2 );
        add_filter( 'post_type_link', array( __CLASS__, 'permalink' ), 10, 2 );
    }
    public static function permalink( $url, $post ) {
        if ( is_admin() ) { return $url; }
        $post = get_post( $post );
        if ( ! $post ) { return $url; }
        $language = TML_Elements::get_language( 'post_' . $post->post_type, $post->ID );
        if ( TML_Shared::post( $post ) && ! TML_Elements::get_trid( 'post_' . $post->post_type, $post->ID ) ) { $language = TML_Languages::current(); }
        return TML_Urls::for_language( $url, $language );
    }
    public static function url( $url ) {
        $root = rtrim( get_option( 'home' ), '/' );
        if ( is_string( $url ) && 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
            $parts = wp_parse_url( $root );
            $url = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $url;
        }
        if ( is_string( $url ) && 0 === strpos( $root, 'https://' ) && 0 === strpos( $url, 'http://' . substr( $root, 8 ) . '/' ) ) { $url = 'https://' . substr( $url, 7 ); }
        if ( ! is_string( $url ) || strpos( $url, $root . '/' ) !== 0 ) { return $url; }
        $plain = TML_Urls::for_language( $url, TML_Languages::default_code() );
        $relative = substr( $plain, strlen( $root ) );
        $path = (string) wp_parse_url( $relative, PHP_URL_PATH );
        if ( preg_match( '~^/(?:wp-admin|wp-json|wp-login\.php|wp-content|wp-includes)(?:/|$)~', $path ) ) { return $url; }
        $lang = TML_Languages::current();
        if ( '/' === $path ) { return TML_Urls::for_language( $plain, $lang ); }
        static $pages = array();
        if ( ! array_key_exists( $path, $pages ) ) { $pages[ $path ] = get_page_by_path( trim( $path, '/' ) ); }
        $post = $pages[ $path ];
        if ( ! $post || 'publish' !== $post->post_status ) { return TML_Urls::for_language( $plain, $lang ); }
        $id = TML_Elements::get_translation( 'post_page', $post->ID, $lang );
        if ( $id && 'publish' === get_post_status( $id ) ) { $target = get_permalink( $id ); }
        elseif ( $id ) { return TML_Urls::home( $lang ); }
        else { $target = TML_Urls::for_language( get_permalink( $post ), TML_Shared::post( $post ) ? $lang : TML_Elements::get_language( 'post_page', $post->ID ) ); }
        $query = wp_parse_url( $url, PHP_URL_QUERY );
        $fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );
        return $target . ( $query ? '?' . $query : '' ) . ( $fragment ? '#' . $fragment : '' );
    }
    public static function block( $html, $block ) {
        if ( is_admin() || ! empty( $block['innerBlocks'] ) || ! is_string( $html ) || false === stripos( $html, '<a' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $html; }
        $tags = new WP_HTML_Tag_Processor( $html );
        while ( $tags->next_tag( 'A' ) ) {
            if ( $tags->get_attribute( 'hreflang' ) ) { continue; }
            $url = $tags->get_attribute( 'href' );
            if ( is_string( $url ) ) { $tags->set_attribute( 'href', self::url( $url ) ); }
        }
        return $tags->get_updated_html();
    }
}
