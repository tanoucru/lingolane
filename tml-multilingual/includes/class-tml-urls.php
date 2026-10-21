<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class TML_Urls {
    /** Replace a language directory only inside this site's home path. */
    public static function for_language( $url, $lang ) {
        if ( ! TML_Languages::is_valid( $lang ) ) { return ''; }
        $root = rtrim( get_option( 'home' ), '/' );
        if ( strpos( $url, $root . '/' ) !== 0 && $url !== $root && strpos( $url, $root . '?' ) !== 0 ) { return $url; }
        $relative = substr( $url, strlen( $root ) );
        $codes = implode( '|', array_map( 'preg_quote', TML_Languages::codes() ) );
        $relative = preg_replace( '~^/(?:' . $codes . ')(?=/|[?#]|$)~', '', $relative );
        if ( $relative === '' || $relative[0] === '?' || $relative[0] === '#' ) { $relative = '/' . $relative; }
        return $root . ( $lang === TML_Languages::default_code() ? '' : '/' . $lang ) . $relative;
    }
    public static function home( $lang ) { return self::for_language( home_url( '/' ), $lang ); }

    /** null = no translation, false = translation exists but is not public. */
    public static function published_url( $lang ) {
        if ( ! TML_Languages::is_valid( $lang ) ) { return false; }
        $object = get_queried_object();
        $previous = TML_Languages::current();
        TML_Languages::set_current( $lang );
        try {
            if ( is_singular() || ( is_front_page() && $object instanceof WP_Post ) || ( is_home() && $object instanceof WP_Post ) ) {
                if ( ! ( $object instanceof WP_Post ) ) { return null; }
                $id = TML_Elements::get_translation( 'post_' . $object->post_type, $object->ID, $lang );
                if ( ! $id && ( ( TML_Shared::post( $object ) && ! TML_Elements::get_trid( 'post_' . $object->post_type, $object->ID ) ) || $lang === TML_Elements::get_language( 'post_' . $object->post_type, $object->ID ) ) ) { $id = $object->ID; }
                if ( ! $id ) { return null; }
                $post = get_post( $id );
                if ( ! $post || $post->post_status !== 'publish' ) { return false; }
                $url = is_front_page() ? self::home( $lang ) : get_permalink( $post );
            } elseif ( is_category() || is_tag() || is_tax() ) {
                if ( ! ( $object instanceof WP_Term ) ) { return null; }
                $id = TML_Elements::get_translation( 'tax_' . $object->taxonomy, $object->term_id, $lang );
                if ( ! $id && TML_Shared::taxonomy( $object->taxonomy ) && ! TML_Elements::get_trid( 'tax_' . $object->taxonomy, $object->term_id ) ) { $id = $object->term_id; }
                if ( ! $id ) { return null; }
                $url = get_term_link( (int) $id, $object->taxonomy );
            } elseif ( is_search() ) {
                // Keep only public search state; never copy arbitrary URL parameters/nonces.
                $args = array( 's' => rawurlencode( get_search_query( false ) ) );
                $types = array_values( array_intersect( (array) get_query_var( 'post_type' ), get_post_types( array( 'public' => true, 'exclude_from_search' => false ) ) ) );
                if ( $types ) { $args['post_type'] = $types; }
                $page = max( 1, (int) get_query_var( 'paged' ) );
                if ( $page > 1 ) { $args['paged'] = $page; }
                $url = add_query_arg( $args, self::home( $lang ) );
            } elseif ( is_front_page() ) {
                $url = self::home( $lang );
            } elseif ( is_home() || is_archive() ) {
                global $wp;
                // Router has removed the language prefix from the parsed request.
                $url = home_url( '/' . ltrim( $wp->request, '/' ) );
                $url = user_trailingslashit( $url );
            } else { return null; }
            return is_wp_error( $url ) || ! $url ? null : self::for_language( $url, $lang );
        } finally { TML_Languages::set_current( $previous ); }
    }
    public static function translation_url( $lang ) {
        $lang = is_string( $lang ) ? sanitize_key( $lang ) : '';
        if ( ! TML_Languages::is_valid( $lang ) ) { return ''; }
        $url = self::published_url( $lang );
        if ( ! $url && is_singular() ) {
            $post = get_queried_object();
            if ( $post instanceof WP_Post && 'publish' === $post->post_status && ! TML_Elements::get_translation( 'post_' . $post->post_type, $post->ID, $lang ) ) {
                $source = self::for_language( get_permalink( $post ), TML_Elements::get_language( 'post_' . $post->post_type, $post->ID ) );
                return apply_filters( 'tml_missing_translation_url', add_query_arg( 'tml_untranslated', $lang, $source ), $lang );
            }
        }
        return $url ? $url : apply_filters( 'tml_missing_translation_url', self::home( $lang ), $lang );
    }
}
function tml_get_translation_url( $lang ) { return TML_Urls::translation_url( $lang ); }
