#!/usr/bin/env bash
# Prepare the tree-list QA data on Local check. Developed by @tannguyen.
set -euo pipefail
cd "$(dirname "$0")/.."
WPC=${WPC:-/tmp/wpc.sh}

# Refuse a wrapper pointing elsewhere before installing anything.
site_url=$("$WPC" option get home)
if [ "${site_url%/}" != "http://check.local" ]; then
  printf 'Expected http://check.local, got %s\n' "$site_url" >&2
  exit 1
fi
zip=$(bin/build.sh)
"$WPC" plugin install "$zip" --force --activate
fixture=$(mktemp)
trap 'rm -f "$fixture"' EXIT
cat >"$fixture" <<'PHP'
<?php
if ( ! class_exists( 'Lingolane_Languages' ) || ! class_exists( 'Lingolane_Elements' ) ) {
    WP_CLI::error( 'Lingolane must be active.' );
}
if ( ! Lingolane_Languages::is_valid( 'en' ) || ! Lingolane_Languages::is_valid( 'vi' ) ) {
    WP_CLI::error( 'Enable en and vi before preparing the tree fixture.' );
}
if ( ! defined( 'EMPTY_TRASH_DAYS' ) || ! EMPTY_TRASH_DAYS ) {
    WP_CLI::error( 'Trash must be enabled; the fixture never permanently deletes posts.' );
}
$settings = get_option( 'lingolane_settings', Lingolane_Languages::defaults() );
if ( ! is_array( $settings ) ) {
    WP_CLI::error( 'Invalid Lingolane settings; refusing to reset them.' );
}
if ( ! Lingolane_Languages::is_valid( 'fr' ) ) {
    $settings['languages'] = Lingolane_Languages::all();
    $settings['languages'][] = array( 'code' => 'fr', 'locale' => 'fr_FR', 'name' => 'Français' );
    update_option( 'lingolane_settings', $settings );
    if ( ! Lingolane_Languages::is_valid( 'fr' ) ) {
        WP_CLI::error( 'Could not enable fr.' );
    }
}

function lingolane_tree_fixture_post( $key, $title, $type = 'page', $status = 'publish', $parent = 0 ) {
    $ids = get_posts( array(
        'post_type'        => $type,
        'post_status'      => array_values( get_post_stati() ),
        'meta_key'         => '_lingolane_fixture',
        'meta_value'       => $key,
        'posts_per_page'   => 2,
        'fields'          => 'ids',
        'suppress_filters' => true,
    ) );
    if ( count( $ids ) > 1 ) {
        WP_CLI::error( 'Duplicate fixture key: ' . $key );
    }
    if ( $ids ) {
        WP_CLI::log( 'Reusing ' . $key . ': ' . $ids[0] );
        return (int) $ids[0];
    }
    $id = wp_insert_post( array(
        'post_type'    => $type,
        'post_status'  => $status,
        'post_title'   => 'Tree fixture – ' . $title,
        'post_content' => 'Tree fixture – offline-prepared QA data.',
        'post_parent'  => $parent,
        'meta_input'   => array( '_lingolane_fixture' => $key ),
    ), true );
    if ( is_wp_error( $id ) || ! $id ) {
        WP_CLI::error( is_wp_error( $id ) ? $id->get_error_message() : 'Could not create ' . $key );
    }
    if ( get_post_meta( $id, '_lingolane_fixture', true ) !== $key ) {
        WP_CLI::error( 'Could not save fixture key for post ' . $id . '; inspect before retrying.' );
    }
    WP_CLI::log( 'Created ' . $key . ': ' . $id );
    return (int) $id;
}

function lingolane_tree_fixture_pair( $key, $title, $type = 'page', $vi_status = 'publish', $parents = array( 0, 0 ) ) {
    $en = lingolane_tree_fixture_post( $key . '-en', $title . ' EN', $type, 'publish', $parents[0] );
    $vi = lingolane_tree_fixture_post( $key . '-vi', $title . ' VI', $type, $vi_status, $parents[1] );
    $element_type = 'post_' . $type;
    $trid = Lingolane_Elements::set( $element_type, $en, 'en', Lingolane_Elements::get_trid( $element_type, $en ) );
    if ( is_wp_error( $trid ) ) {
        WP_CLI::error( $trid->get_error_message() );
    }
    $result = Lingolane_Elements::set( $element_type, $vi, 'vi', $trid, 'en' );
    if ( is_wp_error( $result ) ) {
        WP_CLI::error( $result->get_error_message() );
    }
    return array( $en, $vi );
}

$first = lingolane_tree_fixture_pair( 'page-a', 'Page A' );
lingolane_tree_fixture_pair( 'page-b', 'Page B', 'page', 'draft' );
lingolane_tree_fixture_pair( 'page-c', 'Page C' );
lingolane_tree_fixture_pair( 'page-child', 'Child page', 'page', 'publish', $first );
$orphan = lingolane_tree_fixture_pair( 'page-orphan', 'Orphan page' );
if ( 'trash' !== get_post_status( $orphan[0] ) && ! wp_trash_post( $orphan[0] ) ) {
    WP_CLI::error( 'Could not trash the orphan source.' );
}
lingolane_tree_fixture_post( 'page-unassigned', 'Page without language' );
lingolane_tree_fixture_pair( 'post-a', 'Post A', 'post' );
lingolane_tree_fixture_pair( 'post-b', 'Post B', 'post' );
WP_CLI::success( 'Tree fixture ready; existing fixture posts were reused.' );
PHP
"$WPC" eval-file "$fixture"
printf '%s\n' 'http://check.local/wp-admin/edit.php?post_type=page'
