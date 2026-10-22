<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared admin UI helpers (inline SVG icons from the bundled Lucide subset).
 */
class Lingolane_UI {
    private static $icons = null;

    /** Names of the available icons. */
    public static function icons() {
        if ( null === self::$icons ) {
            self::$icons = require LINGOLANE_DIR . 'assets/icons.php';
        }
        return array_keys( self::$icons );
    }

    /**
     * Inline SVG icon. Decorative unless $label is given. Unknown names return ''.
     *
     * @param string $name  Icon name from icons().
     * @param int    $size  Pixel size, clamped to 12-48.
     * @param string $label Accessible label ('' = decorative).
     */
    public static function icon( $name, $size = 20, $label = '' ) {
        self::icons();
        if ( ! isset( self::$icons[ $name ] ) ) {
            _doing_it_wrong( 'Lingolane_UI::icon', esc_html( sprintf( 'Unknown icon "%s".', $name ) ), '1.0.0' );
            return '';
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
            self::$icons[ $name ]
        );
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
