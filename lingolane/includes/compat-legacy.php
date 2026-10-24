<?php
/**
 * Aliases for the pre-release "TML_" API so themes and plugins written against it keep working.
 * Deprecated; will be removed in a future major version.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

foreach ( array( 'Languages', 'Elements', 'Shared', 'Router', 'Query', 'Urls', 'Switcher', 'SEO', 'Links', 'Strings' ) as $lingolane_legacy_class ) {
    if ( ! class_exists( 'TML_' . $lingolane_legacy_class, false ) && class_exists( 'Lingolane_' . $lingolane_legacy_class, false ) ) {
        class_alias( 'Lingolane_' . $lingolane_legacy_class, 'TML_' . $lingolane_legacy_class );
    }
}
unset( $lingolane_legacy_class );

if ( ! function_exists( 'tml_get_translation_url' ) ) {
    function tml_get_translation_url( $lang ) { return lingolane_get_translation_url( $lang ); }
}
if ( ! function_exists( 'tml_register_string' ) ) {
    function tml_register_string( $domain, $source, $context = '' ) { return lingolane_register_string( $domain, $source, $context ); }
}
add_action( 'init', function () { add_shortcode( 'tml_switcher', array( 'Lingolane_Switcher', 'shortcode' ) ); }, 20 );

// Bridge legacy query parameter for themes checking $_GET['tml_untranslated'].
// phpcs:disable WordPress.Security.NonceVerification.Recommended
if ( ! is_admin() && isset( $_GET['lingolane_untranslated'] ) && ! isset( $_GET['tml_untranslated'] ) ) {
    $_GET['tml_untranslated'] = sanitize_key( wp_unslash( $_GET['lingolane_untranslated'] ) );
}
// phpcs:enable
