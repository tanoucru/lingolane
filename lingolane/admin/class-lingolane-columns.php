<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Columns {
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ), 100 );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'dropdown' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'filter' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function enqueue( $hook ) {
        if ( 'edit.php' !== $hook ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, Lingolane_Query::post_types(), true ) ) { return; }
        wp_enqueue_style( 'lingolane-admin', LINGOLANE_URL . 'assets/admin.css', array( 'dashicons' ), LINGOLANE_VERSION );
    }

    public static function register() {
        foreach ( Lingolane_Query::post_types() as $type ) {
            add_filter( 'manage_' . $type . '_posts_columns', array( __CLASS__, 'columns' ) );
            add_action( 'manage_' . $type . '_posts_custom_column', array( __CLASS__, 'render' ), 10, 2 );
        }
    }

    public static function columns( $columns ) {
        $columns['lingolane_language'] = __( 'Languages', 'lingolane' );
        return $columns;
    }

    public static function render( $column, $id ) {
        if ( 'lingolane_language' !== $column ) { return; }
        $post = get_post( $id );
        if ( ! $post ) { return; }
        $type    = 'post_' . $post->post_type;
        $current = Lingolane_Elements::get_language( $type, $id );
        $trid    = Lingolane_Elements::get_trid( $type, $id );
        $shared  = Lingolane_Shared::post( $post ) && ! $trid;

        echo '<div class="lingolane-col">';
        /* translators: %s: language */
        echo '<span class="lingolane-code lingolane-code--current" title="' . esc_attr( sprintf( __( 'This post is written in %s', 'lingolane' ), Lingolane_Languages::name( $current ) ) ) . '">' . esc_html( strtoupper( $current ) ) . '</span>';
        if ( $shared ) {
            echo '<span class="lingolane-chip lingolane-chip--muted" title="' . esc_attr__( 'Shown in every language', 'lingolane' ) . '">' . esc_html__( 'Shared', 'lingolane' ) . '</span>';
        } else {
            foreach ( Lingolane_Languages::all() as $language ) {
                $code = $language['code'];
                if ( $code === $current ) { continue; }
                $trans_id = $trid ? Lingolane_Elements::get_translation( $type, $id, $code ) : 0;
                if ( $trans_id ) {
                    if ( ! current_user_can( 'edit_post', $trans_id ) ) { continue; }
                    $status = get_post_status( $trans_id );
                    /* translators: 1: language, 2: status */
                    $label = sprintf( __( 'Edit %1$s version (%2$s)', 'lingolane' ), $language['name'], Lingolane_Admin::status_label( $status ) );
                    echo '<a class="lingolane-code lingolane-code--' . ( 'publish' === $status ? 'ok' : 'warn' ) . '" href="' . esc_url( get_edit_post_link( $trans_id, 'raw' ) ) . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html( strtoupper( $code ) ) . '</a>';
                } elseif ( current_user_can( 'edit_post', $id ) && 'trash' !== $post->post_status ) {
                    /* translators: %s: language */
                    $label = sprintf( __( 'Create %s translation', 'lingolane' ), $language['name'] );
                    echo '<a class="lingolane-code lingolane-code--add" href="' . esc_url( Lingolane_Admin::translate_url( $id, $code ) ) . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '"><span aria-hidden="true">+</span>' . esc_html( strtoupper( $code ) ) . '</a>';
                }
            }
        }
        echo '</div>';
    }

    private static function requested_language() {
        return isset( $_GET['lingolane_lang'] ) && is_string( $_GET['lingolane_lang'] ) ? sanitize_key( wp_unslash( $_GET['lingolane_lang'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
    }

    public static function dropdown( $post_type ) {
        if ( ! in_array( $post_type, Lingolane_Query::post_types(), true ) ) { return; }
        $current = self::requested_language();
        echo '<label class="screen-reader-text" for="lingolane-lang-filter">' . esc_html__( 'Filter by language', 'lingolane' ) . '</label><select name="lingolane_lang" id="lingolane-lang-filter"><option value="all">' . esc_html__( 'All languages', 'lingolane' ) . '</option>';
        foreach ( Lingolane_Languages::all() as $language ) {
            echo '<option value="' . esc_attr( $language['code'] ) . '" ' . selected( $current, $language['code'], false ) . '>' . esc_html( $language['name'] ) . '</option>';
        }
        echo '</select>';
    }

    public static function filter( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || ! function_exists( 'get_current_screen' ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || $screen->base !== 'edit' || ! in_array( $screen->post_type, Lingolane_Query::post_types(), true ) ) { return; }
        $lang = self::requested_language();
        if ( Lingolane_Languages::is_valid( $lang ) ) { $query->set( '_lingolane_admin_language', $lang ); }
    }
}
