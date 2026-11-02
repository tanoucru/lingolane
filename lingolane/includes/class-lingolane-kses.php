<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Allowed-HTML lists for the plugin's own SVG markup (flags, icons, illustrations).
 * Pass them to wp_kses() right where the markup is printed.
 */
class Lingolane_Kses {
    /** SVG elements and attributes used by the bundled flags, icons and illustrations. */
    public static function svg() {
        $paint = array(
            'class'            => true,
            'fill'             => true,
            'stroke'           => true,
            'stroke-width'     => true,
            'stroke-linecap'   => true,
            'stroke-linejoin'  => true,
            'stroke-dasharray' => true,
            'opacity'          => true,
            'transform'        => true,
            'style'            => true,
        );
        return array(
            'svg'            => $paint + array(
                'width'       => true,
                'height'      => true,
                'viewbox'     => true,
                'xmlns'       => true,
                'role'        => true,
                'aria-hidden' => true,
                'aria-label'  => true,
                'focusable'   => true,
            ),
            'g'              => $paint,
            'path'           => $paint + array( 'd' => true ),
            'rect'           => $paint + array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ),
            'circle'         => $paint + array( 'cx' => true, 'cy' => true, 'r' => true ),
            'ellipse'        => $paint + array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ),
            'line'           => $paint + array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ),
            'polyline'       => $paint + array( 'points' => true ),
            'polygon'        => $paint + array( 'points' => true ),
            'text'           => $paint + array(
                'x'                 => true,
                'y'                 => true,
                'dominant-baseline' => true,
                'text-anchor'       => true,
                'font-family'       => true,
                'font-size'         => true,
                'font-weight'       => true,
                'letter-spacing'    => true,
            ),
            'title'          => array(),
            'defs'           => array(),
            'lineargradient' => array( 'id' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ),
            'stop'           => array( 'offset' => true, 'class' => true, 'style' => true ),
            'symbol'         => array( 'id' => true, 'viewbox' => true ),
            'use'            => array( 'href' => true ),
        );
    }

    /** svg() plus the elements of Lingolane_Switcher::render(). */
    public static function switcher() {
        $common = array( 'class' => true, 'title' => true, 'role' => true, 'aria-label' => true );
        return self::svg() + array(
            'nav'    => $common,
            'div'    => $common + array( 'data-style' => true ),
            'ul'     => $common,
            'li'     => $common,
            'span'   => $common,
            'a'      => $common + array(
                'href'         => true,
                'hreflang'     => true,
                'lang'         => true,
                'aria-current' => true,
                'data-*'       => true,
            ),
            'button' => $common + array( 'type' => true, 'aria-expanded' => true, 'aria-haspopup' => true ),
            'select' => $common,
            'option' => array( 'value' => true, 'selected' => true ),
        );
    }

    /** Small admin fragments built by Lingolane_Admin: chips, language codes, progress bars, checkbox lists. */
    public static function ui() {
        return array(
            'span'  => array( 'class' => true, 'lang' => true, 'title' => true, 'style' => true ),
            'div'   => array(
                'class'         => true,
                'role'          => true,
                'aria-label'    => true,
                'aria-valuemin' => true,
                'aria-valuemax' => true,
                'aria-valuenow' => true,
            ),
            'label' => array(),
            'input' => array( 'type' => true, 'name' => true, 'value' => true, 'checked' => true ),
            'em'    => array(),
        );
    }

    /** svg() plus the wrapper element of Lingolane_UI::illustration(). */
    public static function illustration() {
        return self::svg() + array( 'div' => array( 'class' => true, 'aria-hidden' => true ) );
    }
}
