<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Languages {
    private static $current = null;

    public static function defaults() {
        return array(
            'default' => 'en',
            'languages' => array(
                array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ),
                array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ),
            ),
            'url_mode' => 'directory',
            'switcher_style'            => 'list',
            'switcher_show'             => 'flag_name',
            'switcher_flag_shape'        => 'rect',
            'untranslated_action'       => 'notice',
            'untranslated_notice'       => '',
            'untranslated_redirect_url' => '',
        );
    }

    public static function all() {
        $settings = get_option( 'lingolane_settings', self::defaults() );
        $rows = isset( $settings['languages'] ) && is_array( $settings['languages'] ) ? $settings['languages'] : array();
        $languages = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['code'], $row['locale'], $row['name'] ) || ! is_string( $row['code'] ) || ! is_string( $row['locale'] ) || ! is_string( $row['name'] ) ) { continue; }
            $code = sanitize_key( $row['code'] );
            if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z]{2,3})?$/D', $code ) ) { continue; }
            $languages[ $code ] = array( 'code' => $code, 'locale' => sanitize_text_field( $row['locale'] ), 'name' => sanitize_text_field( $row['name'] ) );
        }
        return $languages ? array_values( $languages ) : self::defaults()['languages'];
    }

    public static function codes() { return array_column( self::all(), 'code' ); }
    public static function default_code() {
        $settings = get_option( 'lingolane_settings', self::defaults() );
        $code = isset( $settings['default'] ) && is_string( $settings['default'] ) ? sanitize_key( $settings['default'] ) : 'en';
        return self::is_valid( $code ) ? $code : self::codes()[0];
    }
    /**
     * Language the original content and interface strings are written in. Content without a
     * language assignment belongs to it. Independent from the default (unprefixed) language.
     */
    public static function source_code() {
        $settings = get_option( 'lingolane_settings', self::defaults() );
        $code = isset( $settings['source'] ) && is_string( $settings['source'] ) ? sanitize_key( $settings['source'] ) : '';
        return self::is_valid( $code ) ? $code : self::default_code();
    }
    public static function is_valid( $code ) { return is_string( $code ) && in_array( $code, self::codes(), true ); }
    public static function locale( $code ) {
        foreach ( self::all() as $language ) { if ( $language['code'] === $code ) { return $language['locale']; } }
        return '';
    }
    public static function name( $code ) {
        foreach ( self::all() as $language ) { if ( $language['code'] === $code ) { return $language['name']; } }
        return '';
    }
    public static function current() { return self::$current !== null ? self::$current : self::default_code(); }
    public static function set_current( $code ) { self::$current = self::is_valid( $code ) ? $code : self::default_code(); }
}
