<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Urls {
    /** Replace a language directory only inside this site's home path. */
    public static function for_language( $url, $lang ) {
        if ( ! Lingolane_Languages::is_valid( $lang ) ) { return ''; }
        $root = rtrim( get_option( 'home' ), '/' );
        if ( strpos( $url, $root . '/' ) !== 0 && $url !== $root && strpos( $url, $root . '?' ) !== 0 ) { return $url; }
        $relative = substr( $url, strlen( $root ) );
        $codes = implode( '|', array_map( 'preg_quote', Lingolane_Languages::codes() ) );
        $relative = preg_replace( '~^/(?:' . $codes . ')(?=/|[?#]|$)~', '', $relative );
        if ( $relative === '' || $relative[0] === '?' || $relative[0] === '#' ) { $relative = '/' . $relative; }
        return $root . ( $lang === Lingolane_Languages::default_code() ? '' : '/' . $lang ) . $relative;
    }
    public static function home( $lang ) { return self::for_language( home_url( '/' ), $lang ); }

    /** null = no translation, false = translation exists but is not public. */
    public static function published_url( $lang ) {
        if ( ! Lingolane_Languages::is_valid( $lang ) ) { return false; }
        $object = get_queried_object();
        $previous = Lingolane_Languages::current();
        Lingolane_Languages::set_current( $lang );
        try {
            if ( is_singular() || ( is_front_page() && $object instanceof WP_Post ) || ( is_home() && $object instanceof WP_Post ) ) {
                if ( ! ( $object instanceof WP_Post ) ) { return null; }
                $id = Lingolane_Elements::get_translation( 'post_' . $object->post_type, $object->ID, $lang );
                if ( ! $id && ( ( Lingolane_Shared::post( $object ) && ! Lingolane_Elements::get_trid( 'post_' . $object->post_type, $object->ID ) ) || $lang === Lingolane_Elements::get_language( 'post_' . $object->post_type, $object->ID ) ) ) { $id = $object->ID; }
                if ( ! $id ) { return null; }
                $post = get_post( $id );
                if ( ! $post || $post->post_status !== 'publish' ) { return false; }
                $url = is_front_page() ? self::home( $lang ) : get_permalink( $post );
            } elseif ( is_category() || is_tag() || is_tax() ) {
                if ( ! ( $object instanceof WP_Term ) ) { return null; }
                $id = Lingolane_Elements::get_translation( 'tax_' . $object->taxonomy, $object->term_id, $lang );
                if ( ! $id && Lingolane_Shared::taxonomy( $object->taxonomy ) && ! Lingolane_Elements::get_trid( 'tax_' . $object->taxonomy, $object->term_id ) ) { $id = $object->term_id; }
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
        } finally { Lingolane_Languages::set_current( $previous ); }
    }
    public static function translation_url( $lang ) {
        $lang = is_string( $lang ) ? sanitize_key( $lang ) : '';
        if ( ! Lingolane_Languages::is_valid( $lang ) ) { return ''; }
        $url = self::published_url( $lang );
        if ( ! $url && is_singular() ) {
            $post = get_queried_object();
            if ( $post instanceof WP_Post && 'publish' === $post->post_status && ! Lingolane_Elements::get_translation( 'post_' . $post->post_type, $post->ID, $lang ) ) {
                $settings = get_option( 'lingolane_settings', array() );
                $action   = isset( $settings['untranslated_action'] ) ? $settings['untranslated_action'] : 'notice';
                if ( 'redirect' === $action && ! empty( $settings['untranslated_redirect_url'] ) ) {
                    return apply_filters( 'lingolane_missing_translation_url', esc_url_raw( $settings['untranslated_redirect_url'] ), $lang );
                }
                $source = self::for_language( get_permalink( $post ), Lingolane_Elements::get_language( 'post_' . $post->post_type, $post->ID ) );
                return apply_filters( 'lingolane_missing_translation_url', add_query_arg( 'lingolane_untranslated', $lang, $source ), $lang );
            }
        }
        return $url ? $url : apply_filters( 'lingolane_missing_translation_url', self::home( $lang ), $lang );
    }

    /**
     * Check whether a translation exists and is published for the current queried page.
     *
     * @param string $lang Language code.
     * @return bool True if translation is available or current page is already in $lang.
     */
    public static function has_translation( $lang ) {
        $lang = is_string( $lang ) ? sanitize_key( $lang ) : '';
        if ( ! Lingolane_Languages::is_valid( $lang ) ) { return false; }
        if ( $lang === Lingolane_Languages::current() ) { return true; }
        if ( is_singular() ) {
            $post = get_queried_object();
            if ( $post instanceof WP_Post ) {
                $post_lang = Lingolane_Elements::get_language( 'post_' . $post->post_type, $post->ID );
                if ( $lang === $post_lang ) { return true; }
                $trans_id = Lingolane_Elements::get_translation( 'post_' . $post->post_type, $post->ID, $lang );
                if ( ! $trans_id ) { return false; }
                $trans_post = get_post( $trans_id );
                return $trans_post && 'publish' === $trans_post->post_status;
            }
        }
        return (bool) self::published_url( $lang );
    }
}
function lingolane_get_translation_url( $lang ) { return Lingolane_Urls::translation_url( $lang ); }
function lingolane_has_translation( $lang ) { return Lingolane_Urls::has_translation( $lang ); }
