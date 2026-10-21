<?php
/** Local-only regression: sharing must not expose registered foreign-language records. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) || ! preg_match( '/\.(local|test)$/', wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) ) ) { exit; }
add_filter( 'lingolane_shared_post_types', function () { return array( 'listing', 'science', 'language' ); } );
add_filter( 'lingolane_shared_taxonomies', function () { return array( 'listing_categories', 'locations' ); } );
add_filter( 'lingolane_gated_post_types', function () { return array( 'listing', 'science', 'language' ); } );
add_filter( 'lingolane_translatable_post_types', function ( $types ) { return array_unique( array_merge( $types, array( 'listing', 'science', 'language' ) ) ); } );
add_filter( 'lingolane_translatable_taxonomies', function ( $taxes ) { return array_unique( array_merge( $taxes, array( 'listing_categories', 'locations' ) ) ); } );
$lingolane_fixtures = function () {
    foreach ( array( 'listing', 'science', 'language' ) as $lingolane_type ) {
        if ( ! post_type_exists( $lingolane_type ) ) { register_post_type( $lingolane_type, array( 'public' => true, 'label' => $lingolane_type ) ); }
    }
    foreach ( array( 'listing_categories', 'locations' ) as $lingolane_tax ) {
        if ( ! taxonomy_exists( $lingolane_tax ) ) { register_taxonomy( $lingolane_tax, array( 'listing', 'science', 'language' ), array( 'public' => true, 'label' => $lingolane_tax ) ); }
    }
};
add_action( 'init', $lingolane_fixtures, 1 );
if ( did_action( 'init' ) ) { $lingolane_fixtures(); }
$ids = array(); $terms = array(); $failures = 0; $previous = Lingolane_Languages::current();
$lingolane_defaults = function () { return array( 'default' => 'vi', 'languages' => array( array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ), array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ) ), 'url_mode' => 'directory' ); };
add_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
$check = function ( $label, $ok ) use ( &$failures ) { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { $failures++; } };
try {
    Lingolane_Languages::set_current( 'vi' );
    foreach ( array( 'shared', 'registered', 'draft' ) as $name ) {
        $id = wp_insert_post( array( 'post_type' => 'science', 'post_title' => 'Lingolane shared regression ' . wp_generate_uuid4(), 'post_status' => 'draft' === $name ? 'draft' : 'publish' ) );
        if ( ! $id ) { throw new RuntimeException( 'Cannot create fixture.' ); } $ids[ $name ] = $id;
    }
    Lingolane_Elements::set( 'post_science', $ids['registered'], 'vi' );
    foreach ( array( 'shared', 'registered' ) as $name ) {
        $t = wp_insert_term( 'Lingolane term regression ' . wp_generate_uuid4(), 'listing_categories' );
        if ( is_wp_error( $t ) ) { throw new RuntimeException( 'Cannot create term fixture.' ); }
        $terms[ $name ] = $t['term_id'];
    }
    Lingolane_Elements::set( 'tax_listing_categories', $terms['registered'], 'vi' );
    Lingolane_Languages::set_current( 'en' );
    $q = new WP_Query( array( 'post_type' => 'science', 'post__in' => array_values( $ids ), 'post_status' => 'publish', 'fields' => 'ids' ) );
    $check( 'unregistered tutor visible in English', in_array( $ids['shared'], $q->posts, true ) );
    $check( 'registered Vietnamese tutor isolated', ! in_array( $ids['registered'], $q->posts, true ) );
    $check( 'draft tutor stays private', ! in_array( $ids['draft'], $q->posts, true ) );
    $found = get_terms( array( 'taxonomy' => 'listing_categories', 'hide_empty' => false, 'include' => array_values( $terms ), 'fields' => 'ids' ) );
    $check( 'unregistered subject shared', in_array( (int) $terms['shared'], array_map( 'intval', $found ), true ) );
    $check( 'registered subject isolated', ! in_array( (int) $terms['registered'], array_map( 'intval', $found ), true ) );
    $check( 'shared page URL preserves query and fragment', Lingolane_Links::url( get_option( 'home' ) . '/create/?id=5#form' ) === get_option( 'home' ) . '/en/create/?id=5#form' );
    $check( 'external link unchanged', 'https://example.com/contact/' === Lingolane_Links::url( 'https://example.com/contact/' ) );
    $check( 'assets unchanged', get_option( 'home' ) . '/wp-content/test.png' === Lingolane_Links::url( get_option( 'home' ) . '/wp-content/test.png' ) );
    $contact = get_page_by_path( 'contact' );
    if ( ! $contact ) {
        Lingolane_Languages::set_current( 'vi' );
        $contact_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Contact', 'post_name' => 'contact', 'post_status' => 'publish' ) );
        $ids['contact'] = $contact_id; Lingolane_Elements::set( 'post_page', $contact_id, 'vi' );
        $ids['contact_en'] = Lingolane_Elements::duplicate_post( $contact_id, 'en', 'publish' );
        Lingolane_Languages::set_current( 'en' );
        $contact = get_post( $contact_id );
    }
    $translation = Lingolane_Elements::get_translation( 'post_page', $contact->ID, 'en' );
    $check( 'saved contact link uses actual English translation', Lingolane_Links::url( get_option( 'home' ) . '/contact/' ) === Lingolane_Urls::for_language( get_permalink( $translation ), 'en' ) );
} finally {
    remove_filter( 'pre_option_lingolane_settings', $lingolane_defaults );
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
    foreach ( $terms as $id ) { wp_delete_term( $id, 'listing_categories' ); }
    Lingolane_Languages::set_current( $previous );
}
if ( $failures ) { throw new RuntimeException( 'Shared-language regression failures: ' . $failures ); }
