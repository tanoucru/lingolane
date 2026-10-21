<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class TML_SEO {
    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'alternates' ) );
        add_filter( 'language_attributes', array( __CLASS__, 'language_attributes' ) );
        add_filter( 'wpseo_canonical', array( __CLASS__, 'canonical' ) );
        add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ) );
        add_filter( 'wpseo_opengraph_url', array( __CLASS__, 'opengraph_url' ) );
        add_filter( 'wpseo_schema_graph', array( __CLASS__, 'schema' ) );
        add_filter( 'wpseo_sitemap_entry', array( __CLASS__, 'sitemap_entry' ), 20, 3 );
    }

    /** Yoast queries all languages; preserve registered translated pages and omit untranslated gated copies. */
    public static function sitemap_entry( $url, $type, $object ) {
        if ( ! $url || 'post' !== $type || ! ( $object instanceof WP_Post ) ) { return $url; }
        $element = 'post_' . $object->post_type;
        $lang = TML_Elements::get_language( $element, $object->ID );
        if ( TML_Shared::is_gated( $object ) && TML_Languages::source_code() !== $lang && ! TML_Shared::translated_gated( $object, $lang ) ) {
            // Unassigned gated records remain in the source-language sitemap only.
            if ( ! TML_Elements::get_trid( $element, $object->ID ) ) { $lang = TML_Languages::source_code(); }
            else { return false; }
        }
        $front = (int) get_option( 'page_on_front' );
        $translated_front = $front ? TML_Elements::get_translation( 'post_page', $front, $lang ) : 0;
        $url['loc'] = ( $object->ID === $front || $object->ID === $translated_front ) ? TML_Urls::home( $lang ) : TML_Urls::for_language( get_permalink( $object ), $lang );
        return $url;
    }

    private static $page_url = '';

    /** Yoast stores permalinks without the language directory; the Open Graph URL must match the page. */
    public static function opengraph_url( $url ) {
        if ( is_admin() || ! is_string( $url ) || '' === $url ) { return $url; }
        self::$page_url = $url;
        return self::canonical( $url );
    }

    /** Same correction for the page's own URL (and its #fragments) in the structured data. */
    public static function schema( $graph ) {
        $raw = self::$page_url;
        if ( '' === $raw || ! is_array( $graph ) ) { return $graph; }
        $fixed = self::canonical( $raw );
        if ( $fixed === $raw ) { return $graph; }
        array_walk_recursive( $graph, function ( &$value ) use ( $raw, $fixed ) {
            if ( is_string( $value ) && ( $value === $raw || 0 === strpos( $value, $raw . '#' ) ) ) { $value = $fixed . substr( $value, strlen( $raw ) ); }
        } );
        return $graph;
    }
    public static function alternates() {
        if ( is_admin() || is_404() || is_search() || is_feed() || is_preview() ) { return; }
        foreach ( TML_Languages::all() as $language ) {
            if ( TML_Languages::source_code() !== $language['code'] && is_singular() && TML_Shared::is_gated( get_queried_object() ) && ! TML_Shared::translated_gated( get_queried_object(), $language['code'] ) ) { continue; }
            $url = TML_Urls::published_url( $language['code'] );
            if ( ! $url ) { continue; }
            echo '<link rel="alternate" hreflang="' . esc_attr( $language['code'] ) . '" href="' . esc_url( $url ) . '" />' . "\n";
        }
        $default = TML_Urls::published_url( TML_Languages::default_code() );
        if ( $default ) { echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $default ) . '" />' . "\n"; }
    }
    public static function language_attributes( $attributes ) {
        if ( is_admin() ) { return $attributes; }
        $lang = esc_attr( TML_Languages::current() );
        if ( preg_match( '/\blang=["\x27]/', $attributes ) ) {
            return preg_replace_callback( '/\b((?:xml:)?lang)=("[^"]*"|\x27[^\x27]*\x27)/', function ( $matches ) use ( $lang ) {
                return $matches[1] . '="' . $lang . '"';
            }, $attributes );
        }
        return trim( $attributes ) . ' lang="' . $lang . '"';
    }
    public static function canonical( $url ) {
        return is_admin() || ! is_string( $url ) || $url === '' ? $url : TML_Urls::for_language( $url, TML_Languages::current() );
    }
}
