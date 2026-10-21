<?php
/** Local only: wp eval-file wp-content/plugins/lingolane/tests/test-urls.php */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'Lingolane_Urls' ) ) { throw new RuntimeException( 'Activate Lingolane first.' ); }
$lingolane_saved_query = $GLOBALS['wp_query'];
$lingolane_saved_language = Lingolane_Languages::current();
$lingolane_ids = array();
$lingolane_failures = 0;
$lingolane_defaults = function () { return array( 'default' => 'vi', 'languages' => array( array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ), array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ) ), 'url_mode' => 'directory' ); };
$lingolane_check = function ( $label, $actual, $expected ) use ( &$lingolane_failures ) {
    $pass = $actual === $expected;
    echo ( $pass ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    if ( ! $pass ) { ++$lingolane_failures; }
};
$lingolane_context = function ( $id ) {
    $query = new WP_Query();
    $query->is_singular = true;
    $query->is_single = true;
    $query->queried_object = get_post( $id );
    $query->queried_object_id = $id;
    $GLOBALS['wp_query'] = $query;
};
add_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
try {
    if ( is_admin() ) { throw new RuntimeException( 'Use frontend wp eval-file context.' ); }
    Lingolane_Languages::set_current( 'vi' );
    foreach ( array( 'vi', 'en', 'missing' ) as $lingolane_label ) {
        $lingolane_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Lingolane URL ' . $lingolane_label . ' ' . wp_generate_uuid4() ), true );
        if ( is_wp_error( $lingolane_id ) || ! $lingolane_id ) { throw new RuntimeException( 'Cannot create test post.' ); }
        $lingolane_ids[] = (int) $lingolane_id;
    }
    $lingolane_trid = Lingolane_Elements::set( 'post_post', $lingolane_ids[0], 'vi' );
    if ( is_wp_error( $lingolane_trid ) ) { throw new RuntimeException( $lingolane_trid->get_error_message() ); }
    $lingolane_result = Lingolane_Elements::set( 'post_post', $lingolane_ids[1], 'en', $lingolane_trid );
    if ( is_wp_error( $lingolane_result ) ) { throw new RuntimeException( $lingolane_result->get_error_message() ); }
    $lingolane_context( $lingolane_ids[0] );
    $lingolane_check( 'translated post', lingolane_get_translation_url( 'en' ), Lingolane_Urls::for_language( get_permalink( $lingolane_ids[1] ), 'en' ) );
    $lingolane_check( 'language restored', Lingolane_Languages::current(), 'vi' );
    Lingolane_Languages::set_current( 'en' );
    $lingolane_context( $lingolane_ids[1] );
    $lingolane_check( 'reverse translation removes en prefix', lingolane_get_translation_url( 'vi' ), Lingolane_Urls::for_language( get_permalink( $lingolane_ids[0] ), 'vi' ) );
    Lingolane_Languages::set_current( 'vi' );
    $lingolane_context( $lingolane_ids[2] );
    $lingolane_missing_url = add_query_arg( 'lingolane_untranslated', 'en', get_permalink( $lingolane_ids[2] ) );
    $lingolane_check( 'missing translation source fallback', lingolane_get_translation_url( 'en' ), $lingolane_missing_url );
    $lingolane_fallback = function ( $url, $lang ) { return $url . '?missing=' . $lang; };
    add_filter( 'lingolane_missing_translation_url', $lingolane_fallback, 10, 2 );
    try { $lingolane_check( 'fallback filter', lingolane_get_translation_url( 'en' ), $lingolane_missing_url . '?missing=en' ); }
    finally { remove_filter( 'lingolane_missing_translation_url', $lingolane_fallback, 10 ); }
    $lingolane_context( $lingolane_ids[0] );
    wp_update_post( array( 'ID' => $lingolane_ids[1], 'post_status' => 'draft' ) );
    $lingolane_check( 'draft not public', Lingolane_Urls::published_url( 'en' ), false );
    $lingolane_check( 'draft absent from switcher', strpos( Lingolane_Switcher::shortcode(), 'hreflang="en"' ), false );
    ob_start();
    Lingolane_SEO::alternates();
    $lingolane_head = ob_get_clean();
    $lingolane_check( 'draft absent from hreflang', strpos( $lingolane_head, 'hreflang="en"' ), false );
    $lingolane_check( 'current switcher accessible', strpos( Lingolane_Switcher::shortcode(), 'aria-current="true"' ) !== false, true );
    $GLOBALS['wp_query'] = new WP_Query();
    $GLOBALS['wp_query']->is_home = true;
    $lingolane_front_posts = function () { return 'posts'; };
    add_filter( 'pre_option_show_on_front', $lingolane_front_posts );
    try {
        $lingolane_check( 'home en', lingolane_get_translation_url( 'en' ), Lingolane_Urls::home( 'en' ) );
        Lingolane_Languages::set_current( 'en' );
        $lingolane_check( 'home vi', lingolane_get_translation_url( 'vi' ), Lingolane_Urls::home( 'vi' ) );
    } finally { remove_filter( 'pre_option_show_on_front', $lingolane_front_posts ); }
    $lingolane_check( 'canonical prefix retained', Lingolane_SEO::canonical( Lingolane_Urls::home( 'vi' ) ), Lingolane_Urls::home( 'en' ) );
} catch ( Exception $lingolane_error ) {
    ++$lingolane_failures;
    echo 'FAIL ' . esc_html( $lingolane_error->getMessage() ) . "\n";
} finally {
    foreach ( $lingolane_ids as $lingolane_id ) { wp_delete_post( $lingolane_id, true ); }
    $GLOBALS['wp_query'] = $lingolane_saved_query;
    remove_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
    Lingolane_Languages::set_current( $lingolane_saved_language );
}
if ( $lingolane_failures ) { throw new RuntimeException( 'Lingolane URL failures: ' . (int) $lingolane_failures ); }
echo "PASS all URL checks\n";
