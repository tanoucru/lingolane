<?php
/** Local only, frontend context: wp eval-file wp-content/plugins/tml-multilingual/tests/test-strings.php */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'TML_Strings' ) || is_admin() ) { throw new RuntimeException( 'Activate TML; use frontend context.' ); }
global $wpdb;
$tml_saved_language = TML_Languages::current();
$tml_defaults = function () { return TML_Languages::defaults(); };
$tml_domain = 'tml-test-' . wp_generate_uuid4();
$tml_source = 'TML greeting';
$tml_reads = 0;
$tml_failures = 0;
$tml_count = function ( $sql ) use ( &$tml_reads, $wpdb ) {
    if ( strpos( $sql, 'SELECT domain, source_hash, translation FROM ' . $wpdb->prefix . 'tml_strings' ) === 0 ) { ++$tml_reads; }
    return $sql;
};
$tml_check = function ( $name, $actual, $expected ) use ( &$tml_failures ) {
    $pass = $actual === $expected;
    echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . "\n";
    if ( ! $pass ) { ++$tml_failures; }
};
$tml_write = function ( $source, $context, $translation ) use ( $tml_domain ) {
    $result = TML_Strings::save( $tml_domain, $source, $context, 'en', $translation );
    if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
};
add_filter( 'pre_option_tml_settings', $tml_defaults );
try {
    $tml_result = tml_register_string( $tml_domain, $tml_source );
    if ( is_wp_error( $tml_result ) ) { throw new RuntimeException( $tml_result->get_error_message() ); }
    $tml_write( $tml_source, '', 'Hello teacher' );
    $tml_write( $tml_source, 'button', 'Contact teacher' );
    $tml_write( 'One lesson', '', 'A lesson' );
    $tml_write( 'Many lessons', '', 'Several lessons' );
    tml_register_string( $tml_domain, $tml_source );
    TML_Languages::set_current( 'en' );
    TML_Strings::invalidate( 'en' );
    add_filter( 'query', $tml_count );
    try {
        $tml_check( '__ translated and registration preserves translation', __( $tml_source, $tml_domain ), 'Hello teacher' );
        $tml_check( 'helper translated', tml__( $tml_source, $tml_domain ), 'Hello teacher' );
        $tml_check( 'context translation', _x( $tml_source, 'button', $tml_domain ), 'Contact teacher' );
        $tml_check( 'missing context fallback', _x( $tml_source, 'other', $tml_domain ), $tml_source );
        $tml_check( 'singular', _n( 'One lesson', 'Many lessons', 1, $tml_domain ), 'A lesson' );
        $tml_check( 'plural', _n( 'One lesson', 'Many lessons', 2, $tml_domain ), 'Several lessons' );
        $tml_check( 'missing source', __( 'Missing', $tml_domain ), 'Missing' );
        $tml_check( 'domain isolation', __( $tml_source, $tml_domain . '-other' ), $tml_source );
        $tml_check( 'one language SELECT for repeated gettext', $tml_reads, 1 );
        TML_Languages::set_current( 'vi' );
        $tml_check( 'default unchanged', __( $tml_source, $tml_domain ), $tml_source );
        $tml_check( 'default issues no string SELECT', $tml_reads, 1 );
    } finally { remove_filter( 'query', $tml_count ); }
} catch ( Exception $tml_error ) {
    ++$tml_failures;
    echo 'FAIL ' . esc_html( $tml_error->getMessage() ) . "\n";
} finally {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}tml_strings WHERE domain = %s", $tml_domain ) );
    TML_Strings::invalidate( 'en' );
    remove_filter( 'pre_option_tml_settings', $tml_defaults );
    TML_Languages::set_current( $tml_saved_language );
}
if ( $tml_failures ) { throw new RuntimeException( 'TML string failures: ' . (int) $tml_failures ); }
echo "PASS all string checks\n";
