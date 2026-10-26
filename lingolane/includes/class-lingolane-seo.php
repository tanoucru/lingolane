<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_SEO {
    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'alternates' ) );
        add_filter( 'language_attributes', array( __CLASS__, 'language_attributes' ) );
        add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ) );

        // Bind third-party SEO adapters on plugins_loaded to handle arbitrary load orders safely.
        add_action( 'plugins_loaded', array( __CLASS__, 'register_seo_adapters' ), 20 );

        // WordPress Core XML Sitemaps (WP 5.5+)
        add_filter( 'wp_sitemaps_posts_entry', array( __CLASS__, 'filter_core_sitemap_post_entry' ), 10, 3 );
        add_filter( 'wp_sitemaps_taxonomies_entry', array( __CLASS__, 'filter_core_sitemap_term_entry' ), 10, 3 );
    }

    public static function register_seo_adapters() {
        // Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) ) {
            add_filter( 'wpseo_canonical', array( __CLASS__, 'canonical' ) );
            add_filter( 'wpseo_opengraph_url', array( __CLASS__, 'opengraph_url' ) );
            add_filter( 'wpseo_schema_graph', array( __CLASS__, 'schema' ) );
            add_filter( 'wpseo_sitemap_entry', array( __CLASS__, 'sitemap_entry' ), 20, 3 );
        }

        // Rank Math SEO
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            add_filter( 'rank_math/canonical', array( __CLASS__, 'canonical' ) );
            add_filter( 'rank_math/opengraph/url', array( __CLASS__, 'canonical' ) );
            add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'rank_math_sitemap_entry' ), 10, 3 );
        }

        // All in One SEO (AIOSEO)
        if ( defined( 'AIOSEO_VERSION' ) ) {
            add_filter( 'aioseo_canonical_url', array( __CLASS__, 'canonical' ) );
        }

        // SEOPress
        if ( defined( 'SEOPRESS_VERSION' ) ) {
            add_filter( 'seopress_titles_canonical', array( __CLASS__, 'canonical' ) );
            add_filter( 'seopress_sitemaps_single_entry', array( __CLASS__, 'rank_math_sitemap_entry' ), 10, 3 );
        }
    }

    /**
     * Format post URL for WordPress Core XML Sitemap by post language.
     *
     * @param array   $entry Sitemap entry array with 'loc'.
     * @param WP_Post $post Post object.
     * @param string  $post_type Post type name.
     * @return array Modified entry.
     */
    public static function filter_core_sitemap_post_entry( $entry, $post, $post_type ) {
        if ( ! is_array( $entry ) || empty( $entry['loc'] ) || ! ( $post instanceof WP_Post ) ) {
            return $entry;
        }
        $lang = Lingolane_Elements::get_language( 'post_' . $post->post_type, $post->ID );
        if ( $lang ) {
            $entry['loc'] = Lingolane_Urls::for_language( $entry['loc'], $lang );
        }
        return $entry;
    }

    /**
     * Format term URL for WordPress Core XML Sitemap by term language.
     *
     * @param array   $entry Sitemap entry array with 'loc'.
     * @param WP_Term $term Term object.
     * @param string  $taxonomy Taxonomy name.
     * @return array Modified entry.
     */
    public static function filter_core_sitemap_term_entry( $entry, $term, $taxonomy ) {
        if ( ! is_array( $entry ) || empty( $entry['loc'] ) || ! ( $term instanceof WP_Term ) ) {
            return $entry;
        }
        $lang = Lingolane_Elements::get_language( 'tax_' . $term->taxonomy, $term->term_id );
        if ( $lang ) {
            $entry['loc'] = Lingolane_Urls::for_language( $entry['loc'], $lang );
        }
        return $entry;
    }

    /**
     * Rank Math / SEOPress sitemap entry adapter.
     */
    public static function rank_math_sitemap_entry( $url, $type, $object ) {
        return self::sitemap_entry( $url, $type, $object );
    }

    /** Yoast queries all languages; preserve registered translated pages and omit untranslated gated copies. */
    public static function sitemap_entry( $url, $type, $object ) {
        if ( ! $url || 'post' !== $type || ! ( $object instanceof WP_Post ) ) { return $url; }
        $element = 'post_' . $object->post_type;
        $lang = Lingolane_Elements::get_language( $element, $object->ID );
        if ( Lingolane_Shared::is_gated( $object ) && Lingolane_Languages::source_code() !== $lang && ! Lingolane_Shared::translated_gated( $object, $lang ) ) {
            // Unassigned gated records remain in the source-language sitemap only.
            if ( ! Lingolane_Elements::get_trid( $element, $object->ID ) ) { $lang = Lingolane_Languages::source_code(); }
            else { return false; }
        }
        $front = (int) get_option( 'page_on_front' );
        $translated_front = $front ? Lingolane_Elements::get_translation( 'post_page', $front, $lang ) : 0;
        $url['loc'] = ( $object->ID === $front || $object->ID === $translated_front ) ? Lingolane_Urls::home( $lang ) : Lingolane_Urls::for_language( get_permalink( $object ), $lang );
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
        foreach ( Lingolane_Languages::all() as $language ) {
            if ( Lingolane_Languages::source_code() !== $language['code'] && is_singular() && Lingolane_Shared::is_gated( get_queried_object() ) && ! Lingolane_Shared::translated_gated( get_queried_object(), $language['code'] ) ) { continue; }
            $url = Lingolane_Urls::published_url( $language['code'] );
            if ( ! $url ) { continue; }
            echo '<link rel="alternate" hreflang="' . esc_attr( $language['code'] ) . '" href="' . esc_url( $url ) . '" />' . "\n";
        }
        $default = Lingolane_Urls::published_url( Lingolane_Languages::default_code() );
        if ( $default ) { echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $default ) . '" />' . "\n"; }
    }
    public static function language_attributes( $attributes ) {
        if ( is_admin() ) { return $attributes; }
        $lang = esc_attr( Lingolane_Languages::current() );
        if ( preg_match( '/\blang=["\x27]/', $attributes ) ) {
            return preg_replace_callback( '/\b((?:xml:)?lang)=("[^"]*"|\x27[^\x27]*\x27)/', function ( $matches ) use ( $lang ) {
                return $matches[1] . '="' . $lang . '"';
            }, $attributes );
        }
        return trim( $attributes ) . ' lang="' . $lang . '"';
    }
    public static function canonical( $url ) {
        return is_admin() || ! is_string( $url ) || $url === '' ? $url : Lingolane_Urls::for_language( $url, Lingolane_Languages::current() );
    }
}
