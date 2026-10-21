<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Strings {
    private static $maps = array();
    private static $config = null;

    public static function init() {
        add_filter( 'gettext', array( __CLASS__, 'gettext' ), 20, 3 );
        add_filter( 'gettext_with_context', array( __CLASS__, 'context' ), 20, 4 );
        add_filter( 'ngettext', array( __CLASS__, 'plural' ), 20, 5 );
        add_action( 'update_option_lingolane_settings', array( __CLASS__, 'reset_config' ) );
    }

    public static function hash( $source, $context = '' ) {
        return md5( $context . "\0" . $source );
    }

    public static function reset_config() { self::$config = null; }

    /** Settings read once per request; gettext runs thousands of times per page. */
    private static function config() {
        if ( null === self::$config ) {
            $settings = get_option( 'lingolane_settings', array() );
            $domains = isset( $settings['allowed_domains'] ) && is_array( $settings['allowed_domains'] ) ? array_values( array_filter( $settings['allowed_domains'], 'is_string' ) ) : array();
            self::$config = array(
                'theme'   => ! isset( $settings['theme_strings_enabled'] ) || (bool) $settings['theme_strings_enabled'],
                'plugins' => ! isset( $settings['plugin_strings_enabled'] ) || (bool) $settings['plugin_strings_enabled'],
                'domains' => ( ! $domains || in_array( 'all', $domains, true ) ) ? null : array_flip( $domains ),
                'theme_domains' => array_flip( array_unique( array( get_template(), get_stylesheet() ) ) ),
            );
        }
        return self::$config;
    }

    public static function invalidate( $language ) {
        unset( self::$maps[ $language ] );
        wp_cache_delete( $language, 'lingolane_strings' );
    }

    public static function flush_cache() {
        self::$maps = array();
        self::$config = null;
        foreach ( Lingolane_Languages::codes() as $code ) {
            wp_cache_delete( $code, 'lingolane_strings' );
        }
    }

    /** Only non-empty translations are loaded; registered-but-untranslated rows cost nothing. */
    private static function map( $language ) {
        global $wpdb;
        if ( ! isset( self::$maps[ $language ] ) ) {
            $map = wp_cache_get( $language, 'lingolane_strings' );
            if ( ! is_array( $map ) ) {
                $map = array();
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT domain, source_hash, translation FROM {$wpdb->prefix}lingolane_strings WHERE language_code = %s AND translation <> ''", $language
                ) );
                foreach ( (array) $rows as $row ) {
                    $map[ $row->domain ][ $row->source_hash ] = $row->translation;
                }
                wp_cache_set( $language, $map, 'lingolane_strings' );
            }
            self::$maps[ $language ] = $map;
        }
        return self::$maps[ $language ];
    }

    public static function translate( $fallback, $source, $domain, $context = '' ) {
        $language = Lingolane_Languages::current();
        if ( $language === Lingolane_Languages::source_code() || is_admin() ) { return $fallback; }
        $config = self::config();
        if ( ! $config[ isset( $config['theme_domains'][ $domain ] ) ? 'theme' : 'plugins' ] ) { return $fallback; }
        if ( null !== $config['domains'] && ! isset( $config['domains'][ $domain ] ) ) { return $fallback; }
        $map = self::map( $language );
        if ( ! isset( $map[ $domain ] ) ) { return $fallback; }
        $hash = self::hash( $source, $context );
        return isset( $map[ $domain ][ $hash ] ) ? $map[ $domain ][ $hash ] : $fallback;
    }

    public static function gettext( $translation, $source, $domain ) {
        return self::translate( $translation, $source, $domain );
    }
    public static function context( $translation, $source, $context, $domain ) {
        return self::translate( $translation, $source, $domain, $context );
    }
    public static function plural( $translation, $single, $plural, $number, $domain ) {
        // Each selected source form has its own registered translation.
        return self::translate( $translation, (float) $number === 1.0 ? $single : $plural, $domain );
    }

    /**
     * Text the site would show for this string in $language without a translation stored here,
     * e.g. from the theme's own catalog or a .mo file. Used by the dashboard to explain coverage.
     */
    public static function inherited( $source, $domain, $context, $language ) {
        $previous = Lingolane_Languages::current();
        Lingolane_Languages::set_current( $language );
        remove_filter( 'gettext', array( __CLASS__, 'gettext' ), 20 );
        remove_filter( 'gettext_with_context', array( __CLASS__, 'context' ), 20 );
        try {
            $text = '' === (string) $context
                ? apply_filters( 'gettext', $source, $source, $domain )
                : apply_filters( 'gettext_with_context', $source, $source, $context, $domain );
        } finally {
            add_filter( 'gettext', array( __CLASS__, 'gettext' ), 20, 3 );
            add_filter( 'gettext_with_context', array( __CLASS__, 'context' ), 20, 4 );
            Lingolane_Languages::set_current( $previous );
        }
        return is_string( $text ) && $text !== $source ? $text : '';
    }

    public static function save( $domain, $source, $context, $language, $translation, $register = false ) {
        global $wpdb;
        if ( ! is_string( $domain ) || ! is_string( $source ) || ! is_string( $context ) || ! is_string( $translation ) || ! Lingolane_Languages::is_valid( $language ) ) {
            return new WP_Error( 'lingolane_string', __( 'Invalid string data.', 'lingolane' ) );
        }
        $domain = sanitize_text_field( $domain );
        $context = sanitize_text_field( $context );
        // The source is an exact gettext lookup key, including markup and whitespace: validate, never rewrite.
        if ( wp_check_invalid_utf8( $source ) !== $source || strpos( $source, "\0" ) !== false ) {
            return new WP_Error( 'lingolane_string', __( 'The source string must be valid UTF-8 text.', 'lingolane' ) );
        }
        $translation = wp_kses_post( $translation );
        if ( $domain === '' || strlen( $domain ) > 100 || strlen( $context ) > 100 || $source === '' ) {
            return new WP_Error( 'lingolane_string', __( 'Domain and source string cannot be empty; domain and context are limited to 100 characters.', 'lingolane' ) );
        }
        $table = $wpdb->prefix . 'lingolane_strings';
        $update = $register ? 'source = VALUES(source)' : 'translation = VALUES(translation), updated_at = VALUES(updated_at)';
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (domain, context, source_hash, source, language_code, translation, updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE $update",
            $domain, $context, self::hash( $source, $context ), $source, $language, $translation, current_time( 'mysql', true )
        ) );
        if ( $result !== 0 ) { self::invalidate( $language ); }
        return false === $result ? new WP_Error( 'lingolane_string_db', __( 'Could not save the string translation.', 'lingolane' ) ) : true;
    }
}

function lingolane_register_string( $domain, $source, $context = '' ) {
    foreach ( Lingolane_Languages::codes() as $language ) {
        if ( $language === Lingolane_Languages::source_code() ) { continue; }
        $result = Lingolane_Strings::save( $domain, $source, $context, $language, '', true );
        if ( is_wp_error( $result ) ) { return $result; }
    }
    return true;
}

function lingolane__( $source, $domain ) {
    return __( $source, $domain ); // phpcs:ignore WordPress.WP.I18n
}
