<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Switcher {
    public static function init() {
        add_shortcode( 'lingolane_switcher', array( __CLASS__, 'shortcode' ) );
        add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'menu_items' ), 10, 2 );
        add_filter( 'nav_menu_link_attributes', array( __CLASS__, 'menu_attributes' ), 10, 2 );
        add_filter( 'theme_mod_nav_menu_locations', array( __CLASS__, 'menu_locations' ) );
    }
    /** The stylesheet is printed once, only on pages that actually render the switcher. */
    private static function style() {
        static $printed = false;
        if ( $printed ) { return ''; }
        $printed = true;
        $css = file_get_contents( LINGOLANE_DIR . 'assets/switcher.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return $css ? '<style id="lingolane-switcher-css">' . wp_strip_all_tags( $css ) . '</style>' : '';
    }
    public static function register_widget() { register_widget( 'Lingolane_Switcher_Widget' ); }
    public static function languages() {
        return array_filter( Lingolane_Languages::all(), function ( $language ) {
            return false !== Lingolane_Urls::published_url( $language['code'] );
        } );
    }
    private static function attributes( $code ) {
        return ' hreflang="' . esc_attr( $code ) . '" lang="' . esc_attr( $code ) . '"' . ( $code === Lingolane_Languages::current() ? ' aria-current="true"' : '' );
    }
    public static function shortcode( $atts = array() ) {
        $atts = shortcode_atts( array( 'style' => 'list', 'show' => 'name' ), $atts, 'lingolane_switcher' );
        $dropdown = $atts['style'] === 'dropdown';
        $html = self::style() . ( $dropdown ? '<details class="lingolane-switcher"><summary>' . esc_html( Lingolane_Languages::name( Lingolane_Languages::current() ) ) . '</summary>' : '<nav class="lingolane-switcher" aria-label="' . esc_attr__( 'Languages', 'lingolane' ) . '">' );
        $html .= '<ul>';
        foreach ( self::languages() as $language ) {
            $html .= '<li><a href="' . esc_url( lingolane_get_translation_url( $language['code'] ) ) . '"' . self::attributes( $language['code'] ) . '>' . esc_html( $atts['show'] === 'code' ? $language['code'] : $language['name'] ) . '</a></li>';
        }
        return $html . '</ul>' . ( $dropdown ? '</details>' : '</nav>' );
    }
    public static function menu_items( $items, $args ) {
        $result = array();
        $next_id = -1;
        foreach ( $items as $existing ) { $next_id = min( $next_id, (int) $existing->ID - 1 ); }
        foreach ( $items as $item ) {
            if ( ! in_array( $item->url, array( '#lingolane-switcher', '#tml-switcher' ), true ) || ! in_array( $item->title, array( '{{lingolane}}', '{{tml}}' ), true ) ) { $result[] = $item; continue; }
            foreach ( self::languages() as $language ) {
                $copy = clone $item;
                $copy->ID = $next_id--;
                $copy->db_id = $copy->ID;
                $copy->object_id = $copy->ID;
                $copy->title = $language['name'];
                $copy->url = lingolane_get_translation_url( $language['code'] );
                $copy->lingolane_language = $language['code'];
                $copy->current = $language['code'] === Lingolane_Languages::current();
                $copy->classes = array( 'menu-item', 'lingolane-language' );
                if ( $copy->current ) { $copy->classes[] = 'current-menu-item'; }
                $result[] = $copy;
            }
        }
        return $result;
    }
    public static function menu_attributes( $atts, $item ) {
        if ( isset( $item->lingolane_language ) ) {
            $atts['hreflang'] = $item->lingolane_language;
            $atts['lang'] = $item->lingolane_language;
            unset( $atts['aria-current'] );
            if ( $item->current ) { $atts['aria-current'] = 'true'; }
        }
        return $atts;
    }
    public static function menu_locations( $locations ) {
        if ( is_admin() || ! is_array( $locations ) ) { return $locations; }
        foreach ( get_registered_nav_menus() as $location => $label ) {
            $menu = wp_get_nav_menu_object( $location . ' ' . Lingolane_Languages::current() );
            if ( $menu && ! is_wp_error( $menu ) ) { $locations[ $location ] = (int) $menu->term_id; }
        }
        return $locations;
    }
}
class Lingolane_Switcher_Widget extends WP_Widget {
    public function __construct() { parent::__construct( 'lingolane_switcher', __( 'Select language', 'lingolane' ), array( 'description' => __( 'Languages that have a translation of the current page.', 'lingolane' ) ) ); }
    public function widget( $args, $instance ) {
        // Theme wrappers are trusted markup supplied by register_sidebar().
        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo Lingolane_Switcher::shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
