<?php
/** Local only, frontend context: wp eval-file wp-content/plugins/lingolane/tests/test-strings.php */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'Lingolane_Strings' ) || is_admin() ) { throw new RuntimeException( 'Activate Lingolane; use frontend context.' ); }
global $wpdb;
$lingolane_saved_language = Lingolane_Languages::current();
$lingolane_defaults = function () { return Lingolane_Languages::defaults(); };
$lingolane_domain = 'lingolane-test-' . wp_generate_uuid4();
$lingolane_source = 'Lingolane greeting';
$lingolane_reads = 0;
$lingolane_failures = 0;
$lingolane_count = function ( $sql ) use ( &$lingolane_reads, $wpdb ) {
    if ( strpos( $sql, 'SELECT domain, source_hash, translation FROM ' . $wpdb->prefix . 'lingolane_strings' ) === 0 ) { ++$lingolane_reads; }
    return $sql;
};
$lingolane_check = function ( $name, $actual, $expected ) use ( &$lingolane_failures ) {
    $pass = $actual === $expected;
    echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . "\n";
    if ( ! $pass ) { ++$lingolane_failures; }
};
$lingolane_write = function ( $source, $context, $translation ) use ( $lingolane_domain ) {
    $result = Lingolane_Strings::save( $lingolane_domain, $source, $context, 'en', $translation );
    if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
};
add_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
try {
    $lingolane_result = lingolane_register_string( $lingolane_domain, $lingolane_source );
    if ( is_wp_error( $lingolane_result ) ) { throw new RuntimeException( $lingolane_result->get_error_message() ); }
    $lingolane_write( $lingolane_source, '', 'Hello teacher' );
    $lingolane_write( $lingolane_source, 'button', 'Contact teacher' );
    $lingolane_write( 'One lesson', '', 'A lesson' );
    $lingolane_write( 'Many lessons', '', 'Several lessons' );
    lingolane_register_string( $lingolane_domain, $lingolane_source );
    Lingolane_Languages::set_current( 'en' );
    Lingolane_Strings::invalidate( 'en' );
    add_filter( 'query', $lingolane_count );
    try {
        $lingolane_check( '__ translated and registration preserves translation', __( $lingolane_source, $lingolane_domain ), 'Hello teacher' );
        $lingolane_check( 'helper translated', lingolane__( $lingolane_source, $lingolane_domain ), 'Hello teacher' );
        $lingolane_check( 'context translation', _x( $lingolane_source, 'button', $lingolane_domain ), 'Contact teacher' );
        $lingolane_check( 'missing context fallback', _x( $lingolane_source, 'other', $lingolane_domain ), $lingolane_source );
        $lingolane_check( 'singular', _n( 'One lesson', 'Many lessons', 1, $lingolane_domain ), 'A lesson' );
        $lingolane_check( 'plural', _n( 'One lesson', 'Many lessons', 2, $lingolane_domain ), 'Several lessons' );
        $lingolane_check( 'missing source', __( 'Missing', $lingolane_domain ), 'Missing' );
        $lingolane_check( 'domain isolation', __( $lingolane_source, $lingolane_domain . '-other' ), $lingolane_source );
        $lingolane_check( 'one language SELECT for repeated gettext', $lingolane_reads, 1 );
        Lingolane_Languages::set_current( 'vi' );
        $lingolane_check( 'default unchanged', __( $lingolane_source, $lingolane_domain ), $lingolane_source );
        $lingolane_check( 'default issues no string SELECT', $lingolane_reads, 1 );
    } finally { remove_filter( 'query', $lingolane_count ); }
} catch ( Exception $lingolane_error ) {
    ++$lingolane_failures;
    echo 'FAIL ' . esc_html( $lingolane_error->getMessage() ) . "\n";
} finally {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}lingolane_strings WHERE domain = %s", $lingolane_domain ) );
    Lingolane_Strings::invalidate( 'en' );
    remove_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
    Lingolane_Languages::set_current( $lingolane_saved_language );
}
if ( $lingolane_failures ) { throw new RuntimeException( 'Lingolane string failures: ' . (int) $lingolane_failures ); }
echo "PASS all string checks\n";
