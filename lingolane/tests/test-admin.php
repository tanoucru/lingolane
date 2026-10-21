<?php
/** Local only: wp eval-file wp-content/plugins/lingolane/tests/test-admin.php */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) || ! preg_match( '/\.(local|test)$/', wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) ) ) { exit; }
add_filter( 'lingolane_shared_post_types', function () { return array( 'listing', 'science', 'language' ); } );
add_filter( 'lingolane_shared_taxonomies', function () { return array( 'listing_categories', 'locations' ); } );
add_filter( 'lingolane_gated_post_types', function () { return array( 'listing', 'science', 'language' ); } );
require_once ABSPATH . 'wp-admin/includes/admin.php';
foreach ( array( 'stats', 'admin', 'strings-admin', 'metabox', 'columns' ) as $lingolane_file ) { require_once LINGOLANE_DIR . 'admin/class-lingolane-' . $lingolane_file . '.php'; }
$ids = array(); $failures = 0; $previous = Lingolane_Languages::current(); $saved_post = $_POST;
$check = function ( $label, $ok ) use ( &$failures ) { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { $failures++; } };
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
try {
    Lingolane_Languages::set_current( 'vi' );

    // Saving a shared tutor record in the editor keeps it shared.
    $tutor = wp_insert_post( array( 'post_type' => 'science', 'post_title' => 'Lingolane admin tutor ' . wp_generate_uuid4(), 'post_status' => 'publish' ) );
    $ids[] = $tutor;
    $_POST = array( 'lingolane_nonce' => wp_create_nonce( 'lingolane_save_' . $tutor ), 'lingolane_language' => 'vi' );
    Lingolane_Metabox::save( $tutor, get_post( $tutor ) );
    $check( 'editor save keeps shared tutor unregistered', null === Lingolane_Elements::get_trid( 'post_science', $tutor ) );
    $check( 'shared tutor still shared', Lingolane_Shared::post( get_post( $tutor ) ) );

    // Choosing another language registers it.
    $page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Lingolane admin page ' . wp_generate_uuid4(), 'post_status' => 'publish' ) );
    $ids[] = $page;
    $_POST = array( 'lingolane_nonce' => wp_create_nonce( 'lingolane_save_' . $page ), 'lingolane_language' => 'en' );
    Lingolane_Metabox::save( $page, get_post( $page ) );
    $check( 'editor save assigns chosen language', 'en' === Lingolane_Elements::get_language( 'post_page', $page ) );

    // Duplicate creates a linked draft and counts as a draft translation.
    $before = Lingolane_Stats::content( 'en' );
    $source = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Lingolane admin source ' . wp_generate_uuid4(), 'post_status' => 'publish' ) );
    $ids[] = $source;
    $copy = Lingolane_Elements::duplicate_post( $source, 'en', 'draft' );
    $ids[] = $copy;
    $after = Lingolane_Stats::content( 'en' );
    $check( 'duplicate is linked', (int) Lingolane_Elements::get_translation( 'post_page', $source, 'en' ) === (int) $copy );
    $check( 'stats count new source', $after['total'] === $before['total'] + 1 );
    $check( 'stats count draft translation', $after['drafts'] === $before['drafts'] + 1 );
    wp_update_post( array( 'ID' => $copy, 'post_status' => 'publish' ) );
    $published = Lingolane_Stats::content( 'en' );
    $check( 'stats count published translation', $published['published'] === $before['published'] + 1 && $published['drafts'] === $before['drafts'] );
    $check( 'shared types excluded from editorial types', ! in_array( 'science', Lingolane_Stats::editorial_types(), true ) );

    // Listing fallback setting is honoured.
    $settings = get_option( 'lingolane_settings' );
    $with = function () use ( $settings ) { return array_merge( (array) $settings, array( 'fallback_to_untranslated' => true ) ); };
    add_filter( 'pre_option_lingolane_settings', $with );
    $check( 'fallback setting enables listing fallback', true === apply_filters( 'lingolane_fallback_to_untranslated', false, 'en' ) );
    remove_filter( 'pre_option_lingolane_settings', $with );
    $without = function () use ( $settings ) { return array_merge( (array) $settings, array( 'fallback_to_untranslated' => false ) ); };
    add_filter( 'pre_option_lingolane_settings', $without );
    $check( 'fallback off by default', false === apply_filters( 'lingolane_fallback_to_untranslated', false, 'en' ) );
    remove_filter( 'pre_option_lingolane_settings', $without );

    // Scanner skips Customizer labels but keeps visitor text.
    $method = new ReflectionMethod( 'Lingolane_Strings_Admin', 'admin_only' );
    $method->setAccessible( true );
    $code = "<?php \$wp_customize->add_control( 'x', array( 'label' => __( 'Admin label', 'teachme' ) ) );\necho esc_html__( 'Visitor text', 'teachme' );";
    $check( 'customizer label is admin-only', $method->invoke( null, $code, strpos( $code, "__( 'Admin" ) ) );
    $check( 'visitor text is kept', ! $method->invoke( null, $code, strpos( $code, "esc_html__( 'Visitor" ) ) );
    $menus = "<?php register_nav_menus( array( 'main' => __( 'Main menu', 'teachme' ) ) );";
    $check( 'menu location label is admin-only', $method->invoke( null, $menus, strpos( $menus, "__( 'Main" ) ) );
} catch ( Exception $error ) {
    $failures++;
    echo 'FAIL ' . $error->getMessage() . "\n";
} finally {
    foreach ( array_reverse( $ids ) as $id ) { if ( $id ) { wp_delete_post( $id, true ); } }
    $_POST = $saved_post;
    Lingolane_Languages::set_current( $previous );
}
if ( $failures ) { throw new RuntimeException( 'Lingolane admin failures: ' . (int) $failures ); }
echo "PASS all admin checks\n";
