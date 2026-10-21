<?php
/** Local only: default (unprefixed) language differs from the source language of the content. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) || ! preg_match( '/\.(local|test)$/', wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) ) ) { exit; }
$failures = 0; $ids = array(); $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null; $previous = TML_Languages::current();
$check = function ( $label, $ok ) use ( &$failures ) { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { $failures++; } };
$settings = function () { $s = TML_Languages::defaults(); $s['default'] = 'en'; $s['source'] = 'vi'; return $s; };
add_filter( 'pre_option_tml_settings', $settings );
TML_Strings::reset_config();
$home = rtrim( get_option( 'home' ), '/' );
$base = rtrim( (string) wp_parse_url( get_option( 'home' ), PHP_URL_PATH ), '/' );
global $wpdb; $domain = 'tml-default-' . wp_generate_uuid4();
try {
    $check( 'default and source differ', 'en' === TML_Languages::default_code() && 'vi' === TML_Languages::source_code() );
    foreach ( array( array( '/vi/about/', 'vi', '/about/', '' ), array( '/about/', 'en', '/about/', '' ), array( '/en/about/', 'en', '/about/', $base . '/about/' ) ) as $case ) {
        $_SERVER['REQUEST_URI'] = $base . $case[0];
        TML_Router::route();
        $check( $case[0] . ' language', TML_Languages::current() === $case[1] );
        $check( $case[0] . ' prefix redirect', TML_Router::prefix_redirect() === $case[3] );
    }
    TML_Languages::set_current( 'vi' );
    $check( 'Vietnamese URLs get /vi/', home_url( '/x/' ) === $home . '/vi/x/' );
    TML_Languages::set_current( 'en' );
    $check( 'English URLs unprefixed', home_url( '/x/' ) === $home . '/x/' );

    $plain = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'TML default plain ' . wp_generate_uuid4() ) );
    $ids[] = $plain;
    $check( 'unassigned content is source language', 'vi' === TML_Elements::get_language( 'post_post', $plain ) );
    TML_Languages::set_current( 'vi' );
    $vi = new WP_Query( array( 'post_type' => 'post', 'p' => $plain, 'fields' => 'ids' ) );
    $check( 'unassigned content listed in Vietnamese', array( $plain ) === array_map( 'intval', $vi->posts ) );
    TML_Languages::set_current( 'en' );
    $en = new WP_Query( array( 'post_type' => 'post', 'p' => $plain, 'fields' => 'ids' ) );
    $check( 'unassigned content not listed in English', array() === $en->posts );
    $check( 'Vietnamese permalink prefixed', 0 === strpos( get_permalink( $plain ), $home . '/vi/' ) );

    TML_Strings::save( $domain, 'Xin chào', '', 'en', 'Hello' );
    TML_Strings::invalidate( 'en' );
    TML_Languages::set_current( 'en' );
    $check( 'strings translated on default English', 'Hello' === __( 'Xin chào', $domain ) );
    TML_Languages::set_current( 'vi' );
    $check( 'source strings untouched in Vietnamese', 'Xin chào' === __( 'Xin chào', $domain ) );
} catch ( Throwable $error ) {
    $failures++; echo 'FAIL ' . $error->getMessage() . "\n";
} finally {
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}tml_strings WHERE domain = %s", $domain ) );
    remove_filter( 'pre_option_tml_settings', $settings );
    TML_Strings::reset_config(); TML_Strings::flush_cache();
    if ( null === $uri ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $uri; }
    TML_Router::route();
    TML_Languages::set_current( $previous );
}
if ( $failures ) { throw new RuntimeException( 'TML default-language failures: ' . (int) $failures ); }
echo "PASS all default-language checks\n";
