<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Router {
    private static $language_root = false;
    private static $redirect = '';

    public static function init() {
        add_action( 'plugins_loaded', array( __CLASS__, 'route' ), 1 );
        add_filter( 'home_url', array( __CLASS__, 'filter_url' ), 10, 4 );
        // Only visitor-facing addresses get a language prefix. site_url() is the location of WordPress
        // itself (core scripts and styles, wp-admin, wp-login.php) and must stay unprefixed.
        add_filter( 'locale', array( __CLASS__, 'filter_locale' ) );
        add_filter( 'redirect_canonical', array( __CLASS__, 'canonical' ), 10, 2 );
        add_filter( 'option_page_on_front', array( __CLASS__, 'filter_page_on_front' ) );
        add_filter( 'option_page_for_posts', array( __CLASS__, 'filter_page_for_posts' ) );
        add_action( 'template_redirect', array( __CLASS__, 'redirect_prefix' ), 0 );
        add_action( 'template_redirect', array( __CLASS__, 'redirect_untranslated' ), 1 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
    }

    public static function is_conflicted() {
        return defined( 'POLYLANG_VERSION' ) || class_exists( 'Polylang' ) || defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
    }

    public static function is_plain_permalink() {
        return '' === (string) get_option( 'permalink_structure' );
    }

    public static function admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( self::is_conflicted() ) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>' .
                 esc_html__( 'Lingolane: Phát hiện plugin đa ngôn ngữ khác (Polylang hoặc WPML) đang hoạt động.', 'lingolane' ) .
                 '</strong> ' .
                 esc_html__( 'Bộ định tuyến của Lingolane tạm thời ngừng hoạt động để tránh xung đột URL. Vui lòng tắt plugin kia trước khi dùng Lingolane.', 'lingolane' ) .
                 '</p></div>';
        }
        if ( self::is_plain_permalink() ) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>' .
                 esc_html__( 'Lingolane: Đang sử dụng Cấu trúc đường dẫn mặc định (Plain Permalinks).', 'lingolane' ) .
                 '</strong> ' .
                 esc_html__( 'Để hỗ trợ URL đa ngôn ngữ đẹp dạng /en/, /vi/, vui lòng chuyển sang cấu trúc Tiêu đề bài viết trong Cài đặt > Đường dẫn tĩnh.', 'lingolane' ) .
                 '</p></div>';
        }
    }

    private static function backend() {
        return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON );
    }

    private static function excluded( $path ) {
        return (bool) preg_match( '~^/(?:wp-admin|wp-json|wp-login\.php|wp-content|wp-includes|wp-cron\.php|admin-ajax\.php)(?:/|$)~i', $path );
    }

    private static function base_path( $option = 'home' ) {
        $path = wp_parse_url( get_option( $option ), PHP_URL_PATH );
        return is_string( $path ) ? rtrim( $path, '/' ) : '';
    }

    public static function route() {
        self::$language_root = false;
        self::$redirect = '';
        Lingolane_Languages::set_current( Lingolane_Languages::default_code() );
        if ( self::is_conflicted() || self::backend() || ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) { return; }
        $uri = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        $parts = explode( '?', $uri, 2 );
        $base = self::base_path();
        $path = $parts[0];
        if ( $base !== '' && $path !== $base && strpos( $path, $base . '/' ) !== 0 ) { return; }
        $relative = substr( $path, strlen( $base ) );
        if ( self::excluded( $relative ) ) { return; }
        if ( ! preg_match( '~^/([^/]+)(/.*)?$~D', $relative, $matches ) ) { return; }
        $code = $matches[1];
        $rest = isset( $matches[2] ) ? $matches[2] : '/';
        if ( ! Lingolane_Languages::is_valid( $code ) || self::excluded( $rest ) ) { return; }
        $query = isset( $parts[1] ) ? '?' . $parts[1] : '';
        // The default language has no prefix: /vi/x/ is a duplicate of /x/.
        if ( $code === Lingolane_Languages::default_code() ) {
            self::$redirect = $base . $rest . $query;
            return;
        }
        // A repeated prefix (/en/en/x/ or /en/vi/x/) is a duplicate of /en/x/.
        if ( preg_match( '~^/([^/]+)(/.*)?$~D', $rest, $inner ) && Lingolane_Languages::is_valid( $inner[1] ) ) {
            self::$redirect = $base . '/' . $code . ( isset( $inner[2] ) ? $inner[2] : '/' ) . $query;
        }
        Lingolane_Languages::set_current( $code );
        self::$language_root = ! isset( $matches[2] ) || $matches[2] === '/' || $matches[2] === '';
        $_SERVER['REQUEST_URI'] = $base . $rest . ( isset( $parts[1] ) ? '?' . $parts[1] : '' );
    }

    /** Target of a permanent redirect for a non-canonical language prefix, or '' (path relative to the host). */
    public static function prefix_redirect() { return self::$redirect; }

    public static function redirect_prefix() {
        if ( '' === self::$redirect || self::backend() ) { return; }
        $home = wp_parse_url( get_option( 'home' ) );
        $origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
        wp_safe_redirect( $origin . self::$redirect, 301 );
        exit;
    }

    public static function filter_url( $url, $path = '', $scheme = null, $blog_id = null ) {
        if ( self::backend() || Lingolane_Languages::current() === Lingolane_Languages::default_code() || in_array( $scheme, array( 'login', 'login_post', 'admin', 'rpc' ), true ) ) { return $url; }
        // Use this blog's unfiltered root, including subdirectory installs.
        $option = current_filter() === 'site_url' ? 'siteurl' : 'home';
        $root = $blog_id && (int) $blog_id !== get_current_blog_id() ? get_blog_option( $blog_id, $option ) : get_option( $option );
        $base = wp_parse_url( $root, PHP_URL_PATH );
        $base = is_string( $base ) ? rtrim( $base, '/' ) : '';
        $url_path = wp_parse_url( $url, PHP_URL_PATH );
        $url_path = is_string( $url_path ) ? $url_path : '';
        if ( $base !== '' && $url_path !== $base && strpos( $url_path, $base . '/' ) !== 0 ) { return $url; }
        $relative = substr( $url_path, strlen( $base ) );
        if ( self::excluded( $relative ) ) { return $url; }
        $segments = explode( '/', ltrim( $relative, '/' ) );
        if ( Lingolane_Languages::is_valid( $segments[0] ) ) { return $url; }
        // Insert into the path only; preserve scheme, host, query and fragment verbatim.
        return preg_replace( '~^(https?://[^/?#]+)' . preg_quote( $base, '~' ) . '(?=/|[?#]|$)~i', '$1' . $base . '/' . Lingolane_Languages::current(), $url, 1 );
    }

    public static function filter_locale( $locale ) {
        return self::backend() ? $locale : Lingolane_Languages::locale( Lingolane_Languages::current() );
    }

    public static function canonical( $redirect_url, $requested_url ) {
        if ( self::backend() || Lingolane_Languages::current() === Lingolane_Languages::default_code() ) { return $redirect_url; }
        if ( self::$language_root ) {
            $target = home_url( '/' );
            $query = wp_parse_url( $requested_url, PHP_URL_QUERY );
            $target = $target . ( is_string( $query ) && $query !== '' ? '?' . $query : '' );
            if ( $requested_url && self::filter_url( $requested_url ) === $target ) {
                return false;
            }
            return $target;
        }
        if ( ! $redirect_url ) { return false; }
        $filtered = self::filter_url( $redirect_url );
        if ( $requested_url && $filtered === self::filter_url( $requested_url ) ) {
            return false;
        }
        return $filtered;
    }

    /** The configured front/blog page, swapped for its published translation in the current language. */
    private static function translated_page( $id ) {
        if ( self::backend() || empty( $id ) ) { return $id; }
        $lang = Lingolane_Languages::current();
        if ( $lang === Lingolane_Elements::get_language( 'post_page', (int) $id ) ) { return $id; }
        $trans_id = Lingolane_Elements::get_translation( 'post_page', (int) $id, $lang );
        return ( $trans_id && 'publish' === get_post_status( $trans_id ) ) ? $trans_id : $id;
    }

    public static function filter_page_on_front( $id ) { return self::translated_page( $id ); }

    public static function filter_page_for_posts( $id ) { return self::translated_page( $id ); }

    /** Term archives behave like posts: go to the translation, or to the original with a notice. */
    private static function redirect_term( $vars ) {
        global $wpdb;
        $lang = Lingolane_Languages::current();
        $map = array( 'category_name' => 'category', 'tag' => 'post_tag' );
        $taxonomy = '';
        $slug = '';
        foreach ( $map as $var => $tax ) {
            if ( ! empty( $vars[ $var ] ) && is_string( $vars[ $var ] ) ) { $taxonomy = $tax; $slug = $vars[ $var ]; break; }
        }
        if ( ! $taxonomy && ! empty( $vars['taxonomy'] ) && ! empty( $vars['term'] ) && is_string( $vars['taxonomy'] ) && is_string( $vars['term'] ) ) { $taxonomy = $vars['taxonomy']; $slug = $vars['term']; }
        if ( ! $taxonomy || ! in_array( $taxonomy, Lingolane_Query::taxonomies(), true ) ) { return; }
        $slug = sanitize_title( basename( $slug ) );
        // Direct lookup: the language filter on term queries would hide the original.
        $term_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.slug = %s LIMIT 1", $taxonomy, $slug ) );
        if ( ! $term_id ) { return; }
        $type = 'tax_' . $taxonomy;
        $translation = Lingolane_Elements::get_translation( $type, $term_id, $lang );
        if ( $translation && (int) $translation !== $term_id ) {
            $link = get_term_link( (int) $translation, $taxonomy );
            if ( ! is_wp_error( $link ) ) { wp_safe_redirect( Lingolane_Urls::for_language( $link, $lang ), 301 ); exit; }
        }
        $source_lang = Lingolane_Elements::get_language( $type, $term_id );
        if ( $source_lang === $lang ) { return; }
        $link = get_term_link( $term_id, $taxonomy );
        if ( is_wp_error( $link ) ) { return; }
        $target = Lingolane_Urls::for_language( $link, $source_lang );
        // An unprefixed address is an old link to this page: move it permanently, without a notice.
        if ( $lang === Lingolane_Languages::default_code() ) { wp_safe_redirect( $target, 301 ); exit; }
        wp_safe_redirect( add_query_arg( 'lingolane_untranslated', $lang, $target ), 302 );
        exit;
    }

    public static function redirect_untranslated() {
        if ( self::backend() ) { return; }
        if ( is_404() ) {
            global $wp;
            $path = trim( $wp->request, '/' );
            if ( $path === '' ) { return; }
            self::redirect_term( $wp->query_vars );
            $default_post = get_page_by_path( $path, OBJECT, Lingolane_Query::post_types() );
            if ( $default_post ) {
                $lang = Lingolane_Languages::current();
                $type = 'post_' . $default_post->post_type;
                $trans_id = Lingolane_Elements::get_translation( $type, $default_post->ID, $lang );
                if ( $trans_id ) {
                    $trans_post = get_post( $trans_id );
                    if ( $trans_post && 'publish' === $trans_post->post_status ) {
                        $target = Lingolane_Urls::for_language( get_permalink( $trans_post ), $lang );
                        if ( $target ) {
                            wp_safe_redirect( $target, 301 );
                            exit;
                        }
                    }
                }
                // Missing editorial translations go to the corresponding published source.
                // Do not redirect drafts or invent a translated page.
                if ( ! $trans_id && 'publish' === $default_post->post_status ) {
                    $source_lang = Lingolane_Elements::get_language( $type, $default_post->ID );
                    $target = Lingolane_Urls::for_language( get_permalink( $default_post ), $source_lang );
                    // An unprefixed address is an old link to this page: move it permanently, without a notice.
                    if ( $lang === Lingolane_Languages::default_code() && $source_lang !== $lang ) { wp_safe_redirect( $target, 301 ); exit; }
                    if ( $source_lang !== $lang ) { $target = add_query_arg( 'lingolane_untranslated', $lang, $target ); }
                    wp_safe_redirect( $target, 302 );
                    exit;
                }
            }
        }
    }
}
