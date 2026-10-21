<?php
/** Run on Local: wp eval-file wp-content/plugins/lingolane/tests/test-router.php */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'Lingolane_Router' ) ) { echo "FAIL plugin is not active\n"; return; }
$lingolane_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
$lingolane_language = Lingolane_Languages::current();
$lingolane_settings = get_option( 'lingolane_settings' );
$lingolane_failures = 0;
// Supply deterministic settings in memory; never change the database.
$lingolane_defaults = function () { return array( 'default' => 'vi', 'languages' => array( array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ), array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ) ), 'url_mode' => 'directory' ); };
add_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
$lingolane_base = rtrim( (string) wp_parse_url( get_option( 'home' ), PHP_URL_PATH ), '/' );
$lingolane_check = function ( $name, $actual, $expected ) use ( &$lingolane_failures ) {
    $pass = $actual === $expected;
    echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . "\n";
    if ( ! $pass ) { ++$lingolane_failures; }
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
    ) as $lingolane_case ) {
        $_SERVER['REQUEST_URI'] = $lingolane_base . $lingolane_case[0];
        Lingolane_Router::route();
        $lingolane_check( $lingolane_case[0] . ' language', Lingolane_Languages::current(), $lingolane_case[1] );
        $lingolane_check( $lingolane_case[0] . ' URI', $_SERVER['REQUEST_URI'], $lingolane_base . $lingolane_case[2] );
    }
    foreach ( array(
        array( '/vi/about-us/?a=1', '/about-us/?a=1' ),
        array( '/vi', '/' ),
        array( '/en/en/about-us/', '/en/about-us/' ),
        array( '/en/vi/', '/en/' ),
        array( '/en/about-us/', '' ),
        array( '/about-us/', '' ),
        array( '/vi/wp-json/', '' ),
    ) as $lingolane_case ) {
        $_SERVER['REQUEST_URI'] = $lingolane_base . $lingolane_case[0];
        Lingolane_Router::route();
        $lingolane_check( $lingolane_case[0] . ' prefix redirect', Lingolane_Router::prefix_redirect(), '' === $lingolane_case[1] ? '' : $lingolane_base . $lingolane_case[1] );
    }
    Lingolane_Languages::set_current( 'en' );
    $lingolane_home = rtrim( get_option( 'home' ), '/' );
    $lingolane_check( 'home_url /x/', home_url( '/x/' ), $lingolane_home . '/en/x/' );
    $lingolane_check( 'no double prefix', home_url( '/en/x/' ), $lingolane_home . '/en/x/' );
    $lingolane_check( 'REST URL', home_url( '/wp-json/' ), $lingolane_home . '/wp-json/' );
    $lingolane_site = rtrim( get_option( 'siteurl' ), '/' );
    $lingolane_check( 'site_url root unprefixed', site_url(), $lingolane_site );
    $lingolane_check( 'core asset URL unprefixed', includes_url( 'js/jquery/jquery.min.js' ), $lingolane_site . '/wp-includes/js/jquery/jquery.min.js' );
    $lingolane_check( 'core styles base unprefixed', wp_styles()->base_url, $lingolane_site );
    $lingolane_check( 'locale', Lingolane_Router::filter_locale( 'vi' ), 'en_US' );
    $_SERVER['REQUEST_URI'] = $lingolane_base . '/en?x=1';
    Lingolane_Router::route();
    $lingolane_check( '/en canonical', Lingolane_Router::canonical( false, $lingolane_home . '/en?x=1' ), $lingolane_home . '/en/?x=1' );
    $_SERVER['REQUEST_URI'] = $lingolane_base . '/en/';
    Lingolane_Router::route();
    $lingolane_check( 'no canonical loop', Lingolane_Router::canonical( false, $lingolane_home . '/en/' ), false );
} finally {
    remove_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
    Lingolane_Languages::set_current( $lingolane_language );
    if ( $lingolane_uri === null ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $lingolane_uri; }
    // Reset internal route state using the original request.
    Lingolane_Router::route();
    Lingolane_Languages::set_current( $lingolane_language );
}
if ( $lingolane_failures ) { throw new RuntimeException( 'Lingolane router failures: ' . (int) $lingolane_failures ); }
