<?php
/** Local only: wp eval-file wp-content/plugins/tml-multilingual/tests/test-urls.php */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'TML_Urls' ) ) { throw new RuntimeException( 'Activate TML Multilingual first.' ); }
$tml_saved_query = $GLOBALS['wp_query'];
$tml_saved_language = TML_Languages::current();
$tml_ids = array();
$tml_failures = 0;
$tml_defaults = function () { return TML_Languages::defaults(); };
$tml_check = function ( $label, $actual, $expected ) use ( &$tml_failures ) {
    $pass = $actual === $expected;
    echo ( $pass ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    if ( ! $pass ) { ++$tml_failures; }
};
$tml_context = function ( $id ) {
    $query = new WP_Query();
    $query->is_singular = true;
    $query->is_single = true;
    $query->queried_object = get_post( $id );
    $query->queried_object_id = $id;
    $GLOBALS['wp_query'] = $query;
};
add_filter( 'pre_option_tml_settings', $tml_defaults );
try {
    if ( is_admin() ) { throw new RuntimeException( 'Use frontend wp eval-file context.' ); }
    TML_Languages::set_current( 'vi' );
    foreach ( array( 'vi', 'en', 'missing' ) as $tml_label ) {
        $tml_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'TML URL ' . $tml_label . ' ' . wp_generate_uuid4() ), true );
        if ( is_wp_error( $tml_id ) || ! $tml_id ) { throw new RuntimeException( 'Cannot create test post.' ); }
        $tml_ids[] = (int) $tml_id;
    }
    $tml_trid = TML_Elements::set( 'post_post', $tml_ids[0], 'vi' );
    if ( is_wp_error( $tml_trid ) ) { throw new RuntimeException( $tml_trid->get_error_message() ); }
    $tml_result = TML_Elements::set( 'post_post', $tml_ids[1], 'en', $tml_trid );
    if ( is_wp_error( $tml_result ) ) { throw new RuntimeException( $tml_result->get_error_message() ); }
    $tml_context( $tml_ids[0] );
    $tml_check( 'translated post', tml_get_translation_url( 'en' ), TML_Urls::for_language( get_permalink( $tml_ids[1] ), 'en' ) );
    $tml_check( 'language restored', TML_Languages::current(), 'vi' );
    TML_Languages::set_current( 'en' );
    $tml_context( $tml_ids[1] );
    $tml_check( 'reverse translation removes en prefix', tml_get_translation_url( 'vi' ), TML_Urls::for_language( get_permalink( $tml_ids[0] ), 'vi' ) );
    TML_Languages::set_current( 'vi' );
    $tml_context( $tml_ids[2] );
    $tml_missing_url = add_query_arg( 'tml_untranslated', 'en', get_permalink( $tml_ids[2] ) );
    $tml_check( 'missing translation source fallback', tml_get_translation_url( 'en' ), $tml_missing_url );
    $tml_fallback = function ( $url, $lang ) { return $url . '?missing=' . $lang; };
    add_filter( 'tml_missing_translation_url', $tml_fallback, 10, 2 );
    try { $tml_check( 'fallback filter', tml_get_translation_url( 'en' ), $tml_missing_url . '?missing=en' ); }
    finally { remove_filter( 'tml_missing_translation_url', $tml_fallback, 10 ); }
    $tml_context( $tml_ids[0] );
    wp_update_post( array( 'ID' => $tml_ids[1], 'post_status' => 'draft' ) );
    $tml_check( 'draft not public', TML_Urls::published_url( 'en' ), false );
    $tml_check( 'draft absent from switcher', strpos( TML_Switcher::shortcode(), 'hreflang="en"' ), false );
    ob_start();
    TML_SEO::alternates();
    $tml_head = ob_get_clean();
    $tml_check( 'draft absent from hreflang', strpos( $tml_head, 'hreflang="en"' ), false );
    $tml_check( 'current switcher accessible', strpos( TML_Switcher::shortcode(), 'aria-current="true"' ) !== false, true );
    $GLOBALS['wp_query'] = new WP_Query();
    $GLOBALS['wp_query']->is_home = true;
    $tml_front_posts = function () { return 'posts'; };
    add_filter( 'pre_option_show_on_front', $tml_front_posts );
    try {
        $tml_check( 'home en', tml_get_translation_url( 'en' ), TML_Urls::home( 'en' ) );
        TML_Languages::set_current( 'en' );
        $tml_check( 'home vi', tml_get_translation_url( 'vi' ), TML_Urls::home( 'vi' ) );
    } finally { remove_filter( 'pre_option_show_on_front', $tml_front_posts ); }
    $tml_check( 'canonical prefix retained', TML_SEO::canonical( TML_Urls::home( 'vi' ) ), TML_Urls::home( 'en' ) );
} catch ( Exception $tml_error ) {
    ++$tml_failures;
    echo 'FAIL ' . esc_html( $tml_error->getMessage() ) . "\n";
} finally {
    foreach ( $tml_ids as $tml_id ) { wp_delete_post( $tml_id, true ); }
    $GLOBALS['wp_query'] = $tml_saved_query;
    remove_filter( 'pre_option_tml_settings', $tml_defaults );
    TML_Languages::set_current( $tml_saved_language );
}
if ( $tml_failures ) { throw new RuntimeException( 'TML URL failures: ' . (int) $tml_failures ); }
echo "PASS all URL checks\n";
