<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared admin UI helpers (SVG icons from the bundled Lucide subset).
 */
class Lingolane_UI {
    private static $icons = null;
    private static $used = array();

    /** Names of the available icons. */
    public static function icons() {
        if ( null === self::$icons ) {
            self::$icons = require LINGOLANE_DIR . 'assets/icons.php';
        }
        return array_keys( self::$icons );
    }

    /**
     * SVG icon. Decorative unless $label is given. Unknown names return ''.
     *
     * @param string $name   Icon name from icons().
     * @param int    $size   Pixel size, clamped to 12-48.
     * @param string $label  Accessible label ('' = decorative).
     * @param bool   $inline Embed elements instead of referencing the footer sprite.
     */
    public static function icon( $name, $size = 20, $label = '', $inline = false ) {
        self::icons();
        if ( ! isset( self::$icons[ $name ] ) ) {
            _doing_it_wrong( 'Lingolane_UI::icon', esc_html( sprintf( 'Unknown icon "%s".', $name ) ), '1.0.0' );
            return '';
        }
        $inner = self::$icons[ $name ];
        if ( ! $inline ) {
            self::$used[ $name ] = true;
            $inner = '<use href="#lingolane-i-' . esc_attr( $name ) . '"></use>';
        }
        $size  = max( 12, min( 48, (int) $size ) );
        $a11y  = '' === $label ? 'aria-hidden="true" focusable="false"' : 'role="img"';
        $title = '' === $label ? '' : '<title>' . esc_html( $label ) . '</title>';
        return sprintf(
            '<svg class="lingolane-icon lingolane-icon--%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" %3$s>%4$s%5$s</svg>',
            esc_attr( $name ),
            $size,
            $a11y,
            $title,
            $inner
        );
    }

    /** Sprite containing each referenced icon once, sorted by name. */
    public static function sprite() {
        if ( ! self::$used ) { return ''; }
        $names = array_keys( self::$used );
        sort( $names, SORT_STRING );
        $sprite = '<svg xmlns="http://www.w3.org/2000/svg" class="lingolane-sprite" aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden">';
        foreach ( $names as $name ) {
            $sprite .= '<symbol id="lingolane-i-' . esc_attr( $name ) . '" viewBox="0 0 24 24">' . self::$icons[ $name ] . '</symbol>';
        }
        return $sprite . '</svg>';
    }

    /** Print the footer sprite and clear its references. */
    public static function print_sprite() {
        echo self::sprite(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bundled SVG; names escaped in sprite().
        self::reset();
    }

    /** Clear the used icon names. */
    public static function reset() {
        self::$used = array();
    }

    /**
     * Decorative inline SVG illustration from assets/illustrations/. Unknown names return ''.
     *
     * @param string $name One of welcome, empty-languages, all-done.
     */
    public static function illustration( $name ) {
        static $cache = array();
        if ( ! in_array( $name, array( 'welcome', 'empty-languages', 'all-done' ), true ) ) {
            _doing_it_wrong( 'Lingolane_UI::illustration', esc_html( sprintf( 'Unknown illustration "%s".', $name ) ), '1.0.0' );
            return '';
        }
        if ( ! isset( $cache[ $name ] ) ) {
            $svg = file_get_contents( LINGOLANE_DIR . 'assets/illustrations/' . $name . '.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local bundled asset
            $cache[ $name ] = $svg ? '<div class="lingolane-illustration" aria-hidden="true">' . trim( $svg ) . '</div>' : '';
        }
        return $cache[ $name ];
    }
}
