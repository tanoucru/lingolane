<?php
/** Local-only regression: sharing must not expose registered foreign-language records. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) || ! preg_match( '/\.(local|test)$/', wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) ) ) { exit; }
add_filter( 'tml_shared_post_types', function () { return array( 'listing', 'science', 'language' ); } );
add_filter( 'tml_shared_taxonomies', function () { return array( 'listing_categories', 'locations' ); } );
add_filter( 'tml_gated_post_types', function () { return array( 'listing', 'science', 'language' ); } );
$ids = array(); $terms = array(); $failures = 0; $previous = TML_Languages::current();
$tml_defaults = function () { return TML_Languages::defaults(); };
add_filter( 'pre_option_tml_settings', $tml_defaults );
$check = function ( $label, $ok ) use ( &$failures ) { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { $failures++; } };
try {
    TML_Languages::set_current( 'vi' );
    foreach ( array( 'shared', 'registered', 'draft' ) as $name ) {
        $id = wp_insert_post( array( 'post_type' => 'science', 'post_title' => 'TML shared regression ' . wp_generate_uuid4(), 'post_status' => 'draft' === $name ? 'draft' : 'publish' ) );
        if ( ! $id ) { throw new RuntimeException( 'Cannot create fixture.' ); } $ids[ $name ] = $id;
    }
    TML_Elements::set( 'post_science', $ids['registered'], 'vi' );
    foreach ( array( 'shared', 'registered' ) as $name ) {
        $t = wp_insert_term( 'TML term regression ' . wp_generate_uuid4(), 'listing_categories' );
        if ( is_wp_error( $t ) ) { throw new RuntimeException( 'Cannot create term fixture.' ); }
        $terms[ $name ] = $t['term_id'];
    }
    TML_Elements::set( 'tax_listing_categories', $terms['registered'], 'vi' );
    TML_Languages::set_current( 'en' );
    $q = new WP_Query( array( 'post_type' => 'science', 'post__in' => array_values( $ids ), 'post_status' => 'publish', 'fields' => 'ids' ) );
    $check( 'unregistered tutor visible in English', in_array( $ids['shared'], $q->posts, true ) );
    $check( 'registered Vietnamese tutor isolated', ! in_array( $ids['registered'], $q->posts, true ) );
    $check( 'draft tutor stays private', ! in_array( $ids['draft'], $q->posts, true ) );
    $found = get_terms( array( 'taxonomy' => 'listing_categories', 'hide_empty' => false, 'include' => array_values( $terms ), 'fields' => 'ids' ) );
    $check( 'unregistered subject shared', in_array( (int) $terms['shared'], array_map( 'intval', $found ), true ) );
    $check( 'registered subject isolated', ! in_array( (int) $terms['registered'], array_map( 'intval', $found ), true ) );
    $check( 'shared page URL preserves query and fragment', TML_Links::url( get_option( 'home' ) . '/create/?id=5#form' ) === get_option( 'home' ) . '/en/create/?id=5#form' );
    $check( 'external link unchanged', 'https://example.com/contact/' === TML_Links::url( 'https://example.com/contact/' ) );
    $check( 'assets unchanged', get_option( 'home' ) . '/wp-content/test.png' === TML_Links::url( get_option( 'home' ) . '/wp-content/test.png' ) );
    $contact = get_page_by_path( 'contact' ); $translation = TML_Elements::get_translation( 'post_page', $contact->ID, 'en' );
    $check( 'saved contact link uses actual English translation', TML_Links::url( get_option( 'home' ) . '/contact/' ) === TML_Urls::for_language( get_permalink( $translation ), 'en' ) );
} finally {
    remove_filter( 'pre_option_tml_settings', $tml_defaults );
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
    foreach ( $terms as $id ) { wp_delete_term( $id, 'listing_categories' ); }
    TML_Languages::set_current( $previous );
}
if ( $failures ) { throw new RuntimeException( 'Shared-language regression failures: ' . $failures ); }
