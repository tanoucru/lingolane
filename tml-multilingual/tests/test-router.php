<?php
/** Run on Local: wp eval-file wp-content/plugins/tml-multilingual/tests/test-router.php */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'TML_Router' ) ) { echo "FAIL plugin is not active\n"; return; }
$tml_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
$tml_language = TML_Languages::current();
$tml_settings = get_option( 'tml_settings' );
$tml_failures = 0;
// Supply deterministic settings in memory; never change the database.
$tml_defaults = function () { return TML_Languages::defaults(); };
add_filter( 'pre_option_tml_settings', $tml_defaults );
$tml_base = rtrim( (string) wp_parse_url( get_option( 'home' ), PHP_URL_PATH ), '/' );
$tml_check = function ( $name, $actual, $expected ) use ( &$tml_failures ) {
    $pass = $actual === $expected;
    echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . "\n";
    if ( ! $pass ) { ++$tml_failures; }
};
try {
    foreach ( array(
        array( '/en/about-us/', 'en', '/about-us/' ),
        array( '/about-us/', 'vi', '/about-us/' ),
        array( '/en', 'en', '/' ),
        array( '/wp-json/', 'vi', '/wp-json/' ),
        array( '/en/about-us/?a=1&b=2', 'en', '/about-us/?a=1&b=2' ),
        array( '/en/wp-admin/', 'vi', '/en/wp-admin/' ),
        array( '/en/wp-json/', 'vi', '/en/wp-json/' ),
        array( '/english/about/', 'vi', '/english/about/' ),
        array( '/wp-login.php', 'vi', '/wp-login.php' ),
    ) as $tml_case ) {
        $_SERVER['REQUEST_URI'] = $tml_base . $tml_case[0];
        TML_Router::route();
        $tml_check( $tml_case[0] . ' language', TML_Languages::current(), $tml_case[1] );
        $tml_check( $tml_case[0] . ' URI', $_SERVER['REQUEST_URI'], $tml_base . $tml_case[2] );
    }
    foreach ( array(
        array( '/vi/about-us/?a=1', '/about-us/?a=1' ),
        array( '/vi', '/' ),
        array( '/en/en/about-us/', '/en/about-us/' ),
        array( '/en/vi/', '/en/' ),
        array( '/en/about-us/', '' ),
        array( '/about-us/', '' ),
        array( '/vi/wp-json/', '' ),
    ) as $tml_case ) {
        $_SERVER['REQUEST_URI'] = $tml_base . $tml_case[0];
        TML_Router::route();
        $tml_check( $tml_case[0] . ' prefix redirect', TML_Router::prefix_redirect(), '' === $tml_case[1] ? '' : $tml_base . $tml_case[1] );
    }
    TML_Languages::set_current( 'en' );
    $tml_home = rtrim( get_option( 'home' ), '/' );
    $tml_check( 'home_url /x/', home_url( '/x/' ), $tml_home . '/en/x/' );
    $tml_check( 'no double prefix', home_url( '/en/x/' ), $tml_home . '/en/x/' );
    $tml_check( 'REST URL', home_url( '/wp-json/' ), $tml_home . '/wp-json/' );
    $tml_site = rtrim( get_option( 'siteurl' ), '/' );
    $tml_check( 'site_url root unprefixed', site_url(), $tml_site );
    $tml_check( 'core asset URL unprefixed', includes_url( 'js/jquery/jquery.min.js' ), $tml_site . '/wp-includes/js/jquery/jquery.min.js' );
    $tml_check( 'core styles base unprefixed', wp_styles()->base_url, $tml_site );
    $tml_check( 'locale', TML_Router::filter_locale( 'vi' ), 'en_US' );
    $_SERVER['REQUEST_URI'] = $tml_base . '/en?x=1';
    TML_Router::route();
    $tml_check( '/en canonical', TML_Router::canonical( false, $tml_home . '/en?x=1' ), $tml_home . '/en/?x=1' );
    $_SERVER['REQUEST_URI'] = $tml_base . '/en/';
    TML_Router::route();
    $tml_check( 'no canonical loop', TML_Router::canonical( false, $tml_home . '/en/' ), false );
} finally {
    remove_filter( 'pre_option_tml_settings', $tml_defaults );
    TML_Languages::set_current( $tml_language );
    if ( $tml_uri === null ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $tml_uri; }
    // Reset internal route state using the original request.
    TML_Router::route();
    TML_Languages::set_current( $tml_language );
}
if ( $tml_failures ) { throw new RuntimeException( 'TML router failures: ' . (int) $tml_failures ); }
