<?php
/** Run on Local: wp eval-file wp-content/plugins/tml-multilingual/tests/test-elements.php */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'TML_Elements' ) || ! class_exists( 'TML_Query' ) ) { echo "FAIL plugin is not active\n"; return; }
$tml_original_language = TML_Languages::current();
$tml_posts = array();
$tml_terms = array();
$tml_failures = 0;
$tml_defaults = function () { return TML_Languages::defaults(); };
$tml_check = function ( $label, $actual, $expected ) use ( &$tml_failures ) {
    $pass = $actual === $expected;
    echo ( $pass ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    if ( ! $pass ) { ++$tml_failures; }
};
add_filter( 'pre_option_tml_settings', $tml_defaults );
try {
    if ( is_admin() ) { throw new RuntimeException( 'Run wp eval-file in frontend context.' ); }
    foreach ( array( 'vi', 'en' ) as $tml_code ) {
        $tml_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'TML temporary ' . $tml_code . ' ' . wp_generate_uuid4() ), true );
        if ( is_wp_error( $tml_id ) || ! $tml_id ) { throw new RuntimeException( 'Cannot create test post.' ); }
        $tml_posts[] = (int) $tml_id;
    }
    $tml_check( 'unregistered defaults to vi', TML_Elements::get_language( 'post_post', $tml_posts[0] ), 'vi' );
    $tml_trid = TML_Elements::set( 'post_post', $tml_posts[1], 'en' );
    if ( is_wp_error( $tml_trid ) ) { throw new RuntimeException( $tml_trid->get_error_message() ); }
    $tml_query = function ( $lang, $suppressed = false ) use ( &$tml_posts ) {
        TML_Languages::set_current( $lang );
        return new WP_Query( array( 'post_type' => 'post', 'post__in' => $tml_posts, 'fields' => 'ids', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => $suppressed ) );
    };
    $tml_q = $tml_query( 'vi' );
    $tml_check( 'vi includes unregistered only', array_map( 'intval', $tml_q->posts ), array( $tml_posts[0] ) );
    $tml_q = $tml_query( 'en' );
    $tml_check( 'en excludes unregistered', array_map( 'intval', $tml_q->posts ), array( $tml_posts[1] ) );
    $tml_check( 'en found_posts', (int) $tml_q->found_posts, 1 );
    $tml_result = TML_Elements::set( 'post_post', $tml_posts[0], 'vi', $tml_trid );
    if ( is_wp_error( $tml_result ) ) { throw new RuntimeException( $tml_result->get_error_message() ); }
    $tml_group = TML_Elements::get_group( $tml_trid );
    ksort( $tml_group );
    $tml_check( 'get_group', $tml_group, array( 'en' => $tml_posts[1], 'vi' => $tml_posts[0] ) );
    $tml_check( 'get_translation', TML_Elements::get_translation( 'post_post', $tml_posts[0], 'en' ), $tml_posts[1] );
    $tml_check( 'duplicate language rejected', is_wp_error( TML_Elements::set( 'post_post', $tml_posts[0], 'en', $tml_trid ) ), true );
    $tml_q = $tml_query( 'en' );
    $tml_check( 'en registered query', array_map( 'intval', $tml_q->posts ), array( $tml_posts[1] ) );
    $tml_q = $tml_query( 'en', true );
    $tml_check( 'suppress_filters bypass and pagination', (int) $tml_q->found_posts, 2 );
    foreach ( array( 'vi', 'en' ) as $tml_code ) {
        $tml_term = wp_insert_term( 'TML temporary ' . $tml_code . ' ' . wp_generate_uuid4(), 'category' );
        if ( is_wp_error( $tml_term ) ) { throw new RuntimeException( $tml_term->get_error_message() ); }
        $tml_terms[] = (int) $tml_term['term_id'];
    }
    $tml_term_trid = TML_Elements::set( 'tax_category', $tml_terms[1], 'en' );
    if ( is_wp_error( $tml_term_trid ) ) { throw new RuntimeException( $tml_term_trid->get_error_message() ); }
    foreach ( array( 'en' => $tml_terms[1], 'vi' => $tml_terms[0] ) as $tml_code => $tml_expected ) {
        TML_Languages::set_current( $tml_code );
        $tml_found = get_terms( array( 'taxonomy' => 'category', 'include' => $tml_terms, 'hide_empty' => false, 'fields' => 'ids' ) );
        if ( is_wp_error( $tml_found ) ) { throw new RuntimeException( $tml_found->get_error_message() ); }
        $tml_check( 'term filter ' . $tml_code, array_map( 'intval', $tml_found ), array( $tml_expected ) );
    }
    wp_delete_term( $tml_terms[1], 'category' );
    $tml_check( 'term deletion cleanup', TML_Elements::get_trid( 'tax_category', $tml_terms[1] ), null );
    wp_delete_post( $tml_posts[1], true );
    $tml_check( 'post deletion cleanup', TML_Elements::get_group( $tml_trid ), array( 'vi' => $tml_posts[0] ) );
} catch ( Exception $tml_error ) {
    ++$tml_failures;
    echo 'FAIL ' . esc_html( $tml_error->getMessage() ) . "\n";
} finally {
    foreach ( $tml_posts as $tml_id ) { wp_delete_post( $tml_id, true ); }
    foreach ( $tml_terms as $tml_id ) { wp_delete_term( $tml_id, 'category' ); }
    remove_filter( 'pre_option_tml_settings', $tml_defaults );
    TML_Languages::set_current( $tml_original_language );
}
if ( $tml_failures ) { throw new RuntimeException( 'TML elements failures: ' . (int) $tml_failures ); }
echo "PASS all elements checks\n";
