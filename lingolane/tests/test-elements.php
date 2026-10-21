<?php
/** Run on Local: wp eval-file wp-content/plugins/lingolane/tests/test-elements.php */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'Lingolane_Elements' ) || ! class_exists( 'Lingolane_Query' ) ) { echo "FAIL plugin is not active\n"; return; }
$lingolane_original_language = Lingolane_Languages::current();
$lingolane_posts = array();
$lingolane_terms = array();
$lingolane_failures = 0;
$lingolane_defaults = function () { return array( 'default' => 'vi', 'languages' => array( array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ), array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ) ), 'url_mode' => 'directory' ); };
$lingolane_check = function ( $label, $actual, $expected ) use ( &$lingolane_failures ) {
    $pass = $actual === $expected;
    echo ( $pass ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    if ( ! $pass ) { ++$lingolane_failures; }
};
add_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
try {
    if ( is_admin() ) { throw new RuntimeException( 'Run wp eval-file in frontend context.' ); }
    foreach ( array( 'vi', 'en' ) as $lingolane_code ) {
        $lingolane_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Lingolane temporary ' . $lingolane_code . ' ' . wp_generate_uuid4() ), true );
        if ( is_wp_error( $lingolane_id ) || ! $lingolane_id ) { throw new RuntimeException( 'Cannot create test post.' ); }
        $lingolane_posts[] = (int) $lingolane_id;
    }
    $lingolane_check( 'unregistered defaults to vi', Lingolane_Elements::get_language( 'post_post', $lingolane_posts[0] ), 'vi' );
    $lingolane_trid = Lingolane_Elements::set( 'post_post', $lingolane_posts[1], 'en' );
    if ( is_wp_error( $lingolane_trid ) ) { throw new RuntimeException( $lingolane_trid->get_error_message() ); }
    $lingolane_query = function ( $lang, $suppressed = false ) use ( &$lingolane_posts ) {
        Lingolane_Languages::set_current( $lang );
        return new WP_Query( array( 'post_type' => 'post', 'post__in' => $lingolane_posts, 'fields' => 'ids', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => $suppressed ) );
    };
    $lingolane_q = $lingolane_query( 'vi' );
    $lingolane_check( 'vi includes unregistered only', array_map( 'intval', $lingolane_q->posts ), array( $lingolane_posts[0] ) );
    $lingolane_q = $lingolane_query( 'en' );
    $lingolane_check( 'en excludes unregistered', array_map( 'intval', $lingolane_q->posts ), array( $lingolane_posts[1] ) );
    $lingolane_check( 'en found_posts', (int) $lingolane_q->found_posts, 1 );
    $lingolane_result = Lingolane_Elements::set( 'post_post', $lingolane_posts[0], 'vi', $lingolane_trid );
    if ( is_wp_error( $lingolane_result ) ) { throw new RuntimeException( $lingolane_result->get_error_message() ); }
    $lingolane_group = Lingolane_Elements::get_group( $lingolane_trid );
    ksort( $lingolane_group );
    $lingolane_check( 'get_group', $lingolane_group, array( 'en' => $lingolane_posts[1], 'vi' => $lingolane_posts[0] ) );
    $lingolane_check( 'get_translation', Lingolane_Elements::get_translation( 'post_post', $lingolane_posts[0], 'en' ), $lingolane_posts[1] );
    $lingolane_check( 'duplicate language rejected', is_wp_error( Lingolane_Elements::set( 'post_post', $lingolane_posts[0], 'en', $lingolane_trid ) ), true );
    $lingolane_q = $lingolane_query( 'en' );
    $lingolane_check( 'en registered query', array_map( 'intval', $lingolane_q->posts ), array( $lingolane_posts[1] ) );
    $lingolane_q = $lingolane_query( 'en', true );
    $lingolane_check( 'suppress_filters bypass and pagination', (int) $lingolane_q->found_posts, 2 );
    foreach ( array( 'vi', 'en' ) as $lingolane_code ) {
        $lingolane_term = wp_insert_term( 'Lingolane temporary ' . $lingolane_code . ' ' . wp_generate_uuid4(), 'category' );
        if ( is_wp_error( $lingolane_term ) ) { throw new RuntimeException( $lingolane_term->get_error_message() ); }
        $lingolane_terms[] = (int) $lingolane_term['term_id'];
    }
    $lingolane_term_trid = Lingolane_Elements::set( 'tax_category', $lingolane_terms[1], 'en' );
    if ( is_wp_error( $lingolane_term_trid ) ) { throw new RuntimeException( $lingolane_term_trid->get_error_message() ); }
    foreach ( array( 'en' => $lingolane_terms[1], 'vi' => $lingolane_terms[0] ) as $lingolane_code => $lingolane_expected ) {
        Lingolane_Languages::set_current( $lingolane_code );
        $lingolane_found = get_terms( array( 'taxonomy' => 'category', 'include' => $lingolane_terms, 'hide_empty' => false, 'fields' => 'ids' ) );
        if ( is_wp_error( $lingolane_found ) ) { throw new RuntimeException( $lingolane_found->get_error_message() ); }
        $lingolane_check( 'term filter ' . $lingolane_code, array_map( 'intval', $lingolane_found ), array( $lingolane_expected ) );
    }
    wp_delete_term( $lingolane_terms[1], 'category' );
    $lingolane_check( 'term deletion cleanup', Lingolane_Elements::get_trid( 'tax_category', $lingolane_terms[1] ), null );
    wp_delete_post( $lingolane_posts[1], true );
    $lingolane_check( 'post deletion cleanup', Lingolane_Elements::get_group( $lingolane_trid ), array( 'vi' => $lingolane_posts[0] ) );
} catch ( Exception $lingolane_error ) {
    ++$lingolane_failures;
    echo 'FAIL ' . esc_html( $lingolane_error->getMessage() ) . "\n";
} finally {
    foreach ( $lingolane_posts as $lingolane_id ) { wp_delete_post( $lingolane_id, true ); }
    foreach ( $lingolane_terms as $lingolane_id ) { wp_delete_term( $lingolane_id, 'category' ); }
    remove_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
    Lingolane_Languages::set_current( $lingolane_original_language );
}
if ( $lingolane_failures ) { throw new RuntimeException( 'Lingolane elements failures: ' . (int) $lingolane_failures ); }
echo "PASS all elements checks\n";
