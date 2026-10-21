<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Metabox {
    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
        add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
        add_action( 'admin_post_lingolane_add_translation', array( __CLASS__, 'create' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
    }

    public static function enqueue( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, Lingolane_Query::post_types(), true ) ) { return; }
        wp_enqueue_style( 'lingolane-admin', LINGOLANE_URL . 'assets/admin.css', array( 'dashicons' ), LINGOLANE_VERSION );
    }

    public static function notices() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_GET['lingolane_duplicated'] ) && '1' === $_GET['lingolane_duplicated'] ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Draft translation created.', 'lingolane' ) . '</strong> ' . esc_html__( 'Content, images and terms were copied from the original. Translate the title, content and slug, then click Publish.', 'lingolane' ) . '</p></div>';
        }
        // phpcs:enable
        $key = 'lingolane_metabox_error_' . get_current_user_id();
        $error = get_transient( $key );
        if ( is_string( $error ) && '' !== $error ) {
            delete_transient( $key );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
        }
    }

    public static function register() {
        foreach ( Lingolane_Query::post_types() as $type ) {
            add_meta_box( 'lingolane-translations', __( 'Translations', 'lingolane' ), array( __CLASS__, 'render' ), $type, 'side', 'high' );
        }
    }

    public static function render( $post ) {
        $type    = 'post_' . $post->post_type;
        $current = Lingolane_Elements::get_language( $type, $post->ID );
        $trid    = Lingolane_Elements::get_trid( $type, $post->ID );
        $shared  = Lingolane_Shared::post( $post ) && ! $trid;
        $source  = null;
        if ( $trid ) {
            foreach ( Lingolane_Elements::get_group( $trid ) as $code => $id ) {
                if ( $code === Lingolane_Languages::source_code() && (int) $id !== (int) $post->ID ) { $source = get_post( $id ); }
            }
        }
        wp_nonce_field( 'lingolane_save_' . $post->ID, 'lingolane_nonce' );
        ?>
        <div class="lingolane-box">
            <label for="lingolane-language" class="lingolane-box__label"><?php esc_html_e( 'Language of this post', 'lingolane' ); ?></label>
            <select id="lingolane-language" name="lingolane_language" class="widefat">
                <?php foreach ( Lingolane_Languages::all() as $language ) : ?>
                    <option value="<?php echo esc_attr( $language['code'] ); ?>" <?php selected( $current, $language['code'] ); ?>><?php echo esc_html( $language['name'] ); ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ( $source ) : ?>
                <p class="lingolane-box__source">
                    <?php esc_html_e( 'Original:', 'lingolane' ); ?>
                    <a href="<?php echo esc_url( get_edit_post_link( $source->ID, 'raw' ) ); ?>"><?php echo esc_html( $source->post_title ? $source->post_title : '#' . $source->ID ); ?></a>
                    <?php if ( 'publish' === $source->post_status ) : ?>
                        · <a href="<?php echo esc_url( get_permalink( $source ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'view side by side', 'lingolane' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lingolane' ); ?></span></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ( $shared ) : ?>
                <p class="lingolane-box__note"><?php esc_html_e( 'Shared: this post is shown in every language. Only create a translation if you need separate content for each language.', 'lingolane' ); ?></p>
            <?php endif; ?>

            <ul class="lingolane-box__list">
                <?php foreach ( Lingolane_Languages::all() as $language ) :
                    $code = $language['code'];
                    if ( $code === $current ) { continue; }
                    $id = Lingolane_Elements::get_translation( $type, $post->ID, $code );
                    ?>
                    <li>
                        <span class="lingolane-box__lang"><?php echo Lingolane_Admin::code( $code ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $language['name'] ); ?></span>
                        <?php if ( $id && current_user_can( 'edit_post', $id ) ) :
                            $status = get_post_status( $id ); ?>
                            <span class="lingolane-box__action">
                                <?php echo Lingolane_Admin::chip( Lingolane_Admin::status_label( $status ), 'publish' === $status ? 'ok' : 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $id, 'raw' ) ); ?>"><?php esc_html_e( 'Edit', 'lingolane' ); ?><span class="screen-reader-text"> <?php echo esc_html( $language['name'] ); ?></span></a>
                            </span>
                        <?php elseif ( ! $id && 'auto-draft' !== $post->post_status ) : ?>
                            <a class="button button-small" href="<?php echo esc_url( Lingolane_Admin::translate_url( $post->ID, $code ) ); ?>"><?php esc_html_e( 'Create translation', 'lingolane' ); ?><span class="screen-reader-text"> <?php echo esc_html( $language['name'] ); ?></span></a>
                        <?php elseif ( ! $id ) : ?>
                            <span class="lingolane-box__muted"><?php esc_html_e( 'Save the post first', 'lingolane' ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    public static function save( $id, $post ) {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! in_array( $post->post_type, Lingolane_Query::post_types(), true ) || ! current_user_can( 'edit_post', $id ) ) { return; }
        if ( ! isset( $_POST['lingolane_nonce'], $_POST['lingolane_language'] ) || ! is_string( $_POST['lingolane_nonce'] ) || ! is_string( $_POST['lingolane_language'] ) ) { return; }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lingolane_nonce'] ) ), 'lingolane_save_' . $id ) ) { return; }
        $lang = sanitize_key( wp_unslash( $_POST['lingolane_language'] ) );
        if ( ! Lingolane_Languages::is_valid( $lang ) ) { return; }
        $type = 'post_' . $post->post_type;
        $trid = Lingolane_Elements::get_trid( $type, $id );
        // Keep source_language_code intact when an existing translation is saved unchanged.
        if ( $trid && $lang === Lingolane_Elements::get_language( $type, $id ) ) { return; }
        if ( ! $trid && $lang === Lingolane_Languages::source_code() ) { return; }
        $result = Lingolane_Elements::set( $type, $id, $lang, $trid );
        if ( is_wp_error( $result ) ) {
            /* translators: %s: language */
            set_transient( 'lingolane_metabox_error_' . get_current_user_id(), sprintf( __( 'The language was not changed: this translation group already has a %s version. The post was saved with its previous language.', 'lingolane' ), Lingolane_Languages::name( $lang ) ), 120 );
        }
    }

    public static function create() {
        $id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
        $lang = isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
        if ( ! $id || ! current_user_can( 'edit_post', $id ) ) { wp_die( esc_html__( 'You do not have permission to edit this content.', 'lingolane' ), '', array( 'response' => 403, 'back_link' => true ) ); }
        check_admin_referer( 'lingolane_add_' . $id . '_' . $lang );
        $post = get_post( $id );
        if ( ! $post || ! in_array( $post->post_type, Lingolane_Query::post_types(), true ) || ! Lingolane_Languages::is_valid( $lang ) ) { wp_die( esc_html__( 'Invalid content or language.', 'lingolane' ), '', array( 'response' => 400, 'back_link' => true ) ); }
        $object = get_post_type_object( $post->post_type );
        if ( ! $object || ! current_user_can( $object->cap->create_posts ) ) { wp_die( esc_html__( 'You do not have permission to create content of this type.', 'lingolane' ), '', array( 'response' => 403, 'back_link' => true ) ); }

        $existing = Lingolane_Elements::get_translation( 'post_' . $post->post_type, $id, $lang );
        if ( $existing && (int) $existing !== $id ) {
            if ( ! current_user_can( 'edit_post', $existing ) ) { wp_die( esc_html__( 'You do not have permission to edit this translation.', 'lingolane' ), '', array( 'response' => 403, 'back_link' => true ) ); }
            wp_safe_redirect( get_edit_post_link( $existing, 'raw' ) );
            exit;
        }

        $new = Lingolane_Elements::duplicate_post( $id, $lang, 'draft' );
        if ( is_wp_error( $new ) ) { wp_die( esc_html( $new->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) ); }

        wp_safe_redirect( add_query_arg( 'lingolane_duplicated', '1', get_edit_post_link( $new, 'raw' ) ) );
        exit;
    }
}
