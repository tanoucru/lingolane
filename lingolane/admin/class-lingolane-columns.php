<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Columns {
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ), 100 );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'dropdown' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'filter' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
    }

    public static function enqueue( $hook ) {
        if ( 'edit.php' !== $hook ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, Lingolane_Query::post_types(), true ) ) { return; }
        wp_enqueue_style( 'lingolane-admin', LINGOLANE_URL . 'assets/admin.css', array(), LINGOLANE_VERSION );
        if ( self::tree_screen() ) {
            $flags = array();
            if ( class_exists( 'Lingolane_Flags' ) ) {
                foreach ( Lingolane_Languages::all() as $language ) {
                    $code = $language['code'];
                    $name = $language['name'];
                    $flags[ $code ] = array(
                        'name'  => $name,
                        /* translators: 1: language name, 2: uppercase code */
                        'title' => sprintf( __( 'Written in %1$s (%2$s)', 'lingolane' ), $name, strtoupper( $code ) ),
                        'svg'   => Lingolane_Flags::get_flag_svg( $code, 18, 13, 'lingolane-flag--shadow' ),
                    );
                }
            }
            wp_enqueue_script( 'lingolane-tree', LINGOLANE_URL . 'assets/tree.js', array(), LINGOLANE_VERSION, true );
            wp_localize_script( 'lingolane-tree', 'lingolaneTree', array(
                'postType' => $screen->post_type,
                'flags'    => $flags,
                'i18n'     => array(
                    'expandAll'   => __( 'Expand all', 'lingolane' ),
                    'collapseAll' => __( 'Collapse all', 'lingolane' ),
                    /* translators: %s: post title */
                    'show'        => __( 'Show translations of %s', 'lingolane' ),
                    /* translators: %s: post title */
                    'hide'        => __( 'Hide translations of %s', 'lingolane' ),
                    'shared'      => __( 'Shared', 'lingolane' ),
                ),
                'icons'    => array(
                    'open'   => Lingolane_UI::icon( 'chevron-down', 16, '', true ),
                    'closed' => Lingolane_UI::icon( 'chevron-right', 16, '', true ),
                ),
            ) );
        }
    }

    public static function register() {
        foreach ( Lingolane_Query::post_types() as $type ) {
            add_filter( 'manage_' . $type . '_posts_columns', array( __CLASS__, 'columns' ) );
            add_action( 'manage_' . $type . '_posts_custom_column', array( __CLASS__, 'render' ), 10, 2 );
        }
    }

    public static function tree_screen() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen ) {
            return 'edit' === $screen->base
                && in_array( $screen->post_type, Lingolane_Query::post_types(), true )
                && ! in_array( $screen->post_type, Lingolane_Shared::post_types(), true );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only screen detection.
        if ( wp_doing_ajax() && isset( $_POST['screen'] ) && is_string( $_POST['screen'] ) ) {
            $screen_id = sanitize_key( wp_unslash( $_POST['screen'] ) );
            if ( 0 === strpos( $screen_id, 'edit-' ) ) {
                $post_type = substr( $screen_id, 5 );
                return in_array( $post_type, Lingolane_Query::post_types(), true )
                    && ! in_array( $post_type, Lingolane_Shared::post_types(), true );
            }
        }
        // phpcs:enable
        return false;
    }

    public static function body_class( $classes ) {
        return self::tree_screen() ? $classes . ' lingolane-tree' : $classes;
    }

    public static function columns( $columns ) {
        unset( $columns['lingolane_language'] );
        if ( self::tree_screen() ) {
            return $columns;
        }
        $result = array();
        $label  = __( 'Languages', 'lingolane' );
        foreach ( $columns as $key => $title ) {
            $result[ $key ] = $title;
            if ( 'title' === $key ) {
                $result['lingolane_language'] = $label;
            }
        }
        if ( ! isset( $result['lingolane_language'] ) ) {
            $result['lingolane_language'] = $label;
        }
        return $result;
    }

    public static function render( $column, $id ) {
        if ( 'lingolane_language' !== $column ) { return; }
        $post = get_post( $id );
        if ( ! $post ) { return; }
        if ( self::tree_screen() && class_exists( 'Lingolane_Tree' ) ) { Lingolane_Tree::remember( $id ); }
        $type    = 'post_' . $post->post_type;
        $current = Lingolane_Elements::get_language( $type, $id );
        $trid    = Lingolane_Elements::get_trid( $type, $id );
        $shared  = Lingolane_Shared::post( $post ) && ! $trid;

        // In tree mode: display flag for the post's own language only (no clutter of other languages).
        if ( self::tree_screen() ) {
            echo '<div class="lingolane-col lingolane-col--tree">';
            if ( $shared ) {
                echo '<span class="lingolane-chip lingolane-chip--muted" title="' . esc_attr__( 'Shown in every language', 'lingolane' ) . '">' . esc_html__( 'Shared', 'lingolane' ) . '</span>';
            } else {
                $lang_name = Lingolane_Languages::name( $current );
                $flag_svg  = class_exists( 'Lingolane_Flags' ) ? Lingolane_Flags::get_flag_svg( $current, 24, 18, 'lingolane-flag--shadow' ) : '';
                /* translators: 1: language name, 2: uppercase code */
                $title     = sprintf( __( 'Written in %1$s (%2$s)', 'lingolane' ), $lang_name, strtoupper( $current ) );
                echo '<span class="lingolane-tree-flag" title="' . esc_attr( $title ) . '">';
                echo $flag_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG vector flag.
                echo '<span class="lingolane-tree-flag__name">' . esc_html( $lang_name ) . '</span>';
                echo '</span>';
            }
            echo '</div>';
            return;
        }

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

    public static function requested_language() {
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
