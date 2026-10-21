<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class TML_Metabox {
    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
        add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
        add_action( 'admin_post_tml_add_translation', array( __CLASS__, 'create' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
    }

    public static function enqueue( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, TML_Query::post_types(), true ) ) { return; }
        wp_enqueue_style( 'tml-admin', TML_URL . 'assets/admin.css', array( 'dashicons' ), TML_VERSION );
    }

    public static function notices() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_GET['tml_duplicated'] ) && '1' === $_GET['tml_duplicated'] ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Đã tạo bản dịch nháp.', 'tml-multilingual' ) . '</strong> ' . esc_html__( 'Nội dung, ảnh và phân loại đã được sao chép từ bản gốc. Dịch tiêu đề, nội dung và đường dẫn rồi bấm Đăng.', 'tml-multilingual' ) . '</p></div>';
        }
        // phpcs:enable
        $key = 'tml_metabox_error_' . get_current_user_id();
        $error = get_transient( $key );
        if ( is_string( $error ) && '' !== $error ) {
            delete_transient( $key );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
        }
    }

    public static function register() {
        foreach ( TML_Query::post_types() as $type ) {
            add_meta_box( 'tml-translations', __( 'Bản dịch', 'tml-multilingual' ), array( __CLASS__, 'render' ), $type, 'side', 'high' );
        }
    }

    public static function render( $post ) {
        $type    = 'post_' . $post->post_type;
        $current = TML_Elements::get_language( $type, $post->ID );
        $trid    = TML_Elements::get_trid( $type, $post->ID );
        $shared  = TML_Shared::post( $post ) && ! $trid;
        $source  = null;
        if ( $trid ) {
            foreach ( TML_Elements::get_group( $trid ) as $code => $id ) {
                if ( $code === TML_Languages::source_code() && (int) $id !== (int) $post->ID ) { $source = get_post( $id ); }
            }
        }
        wp_nonce_field( 'tml_save_' . $post->ID, 'tml_nonce' );
        ?>
        <div class="tml-box">
            <label for="tml-language" class="tml-box__label"><?php esc_html_e( 'Ngôn ngữ của bài này', 'tml-multilingual' ); ?></label>
            <select id="tml-language" name="tml_language" class="widefat">
                <?php foreach ( TML_Languages::all() as $language ) : ?>
                    <option value="<?php echo esc_attr( $language['code'] ); ?>" <?php selected( $current, $language['code'] ); ?>><?php echo esc_html( $language['name'] ); ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ( $source ) : ?>
                <p class="tml-box__source">
                    <?php esc_html_e( 'Bản gốc:', 'tml-multilingual' ); ?>
                    <a href="<?php echo esc_url( get_edit_post_link( $source->ID, 'raw' ) ); ?>"><?php echo esc_html( $source->post_title ? $source->post_title : '#' . $source->ID ); ?></a>
                    <?php if ( 'publish' === $source->post_status ) : ?>
                        · <a href="<?php echo esc_url( get_permalink( $source ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'xem song song', 'tml-multilingual' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(mở tab mới)', 'tml-multilingual' ); ?></span></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ( $shared ) : ?>
                <p class="tml-box__note"><?php esc_html_e( 'Đang dùng chung: bài này hiển thị ở mọi ngôn ngữ. Chỉ tạo bản dịch nếu cần nội dung riêng cho từng ngôn ngữ.', 'tml-multilingual' ); ?></p>
            <?php endif; ?>

            <ul class="tml-box__list">
                <?php foreach ( TML_Languages::all() as $language ) :
                    $code = $language['code'];
                    if ( $code === $current ) { continue; }
                    $id = TML_Elements::get_translation( $type, $post->ID, $code );
                    ?>
                    <li>
                        <span class="tml-box__lang"><?php echo TML_Admin::code( $code ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $language['name'] ); ?></span>
                        <?php if ( $id && current_user_can( 'edit_post', $id ) ) :
                            $status = get_post_status( $id ); ?>
                            <span class="tml-box__action">
                                <?php echo TML_Admin::chip( TML_Admin::status_label( $status ), 'publish' === $status ? 'ok' : 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $id, 'raw' ) ); ?>"><?php esc_html_e( 'Sửa', 'tml-multilingual' ); ?><span class="screen-reader-text"> <?php echo esc_html( $language['name'] ); ?></span></a>
                            </span>
                        <?php elseif ( ! $id && 'auto-draft' !== $post->post_status ) : ?>
                            <a class="button button-small" href="<?php echo esc_url( TML_Admin::translate_url( $post->ID, $code ) ); ?>"><?php esc_html_e( 'Tạo bản dịch', 'tml-multilingual' ); ?><span class="screen-reader-text"> <?php echo esc_html( $language['name'] ); ?></span></a>
                        <?php elseif ( ! $id ) : ?>
                            <span class="tml-box__muted"><?php esc_html_e( 'Lưu bài trước', 'tml-multilingual' ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    public static function save( $id, $post ) {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! in_array( $post->post_type, TML_Query::post_types(), true ) || ! current_user_can( 'edit_post', $id ) ) { return; }
        if ( ! isset( $_POST['tml_nonce'], $_POST['tml_language'] ) || ! is_string( $_POST['tml_nonce'] ) || ! is_string( $_POST['tml_language'] ) ) { return; }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tml_nonce'] ) ), 'tml_save_' . $id ) ) { return; }
        $lang = sanitize_key( wp_unslash( $_POST['tml_language'] ) );
        if ( ! TML_Languages::is_valid( $lang ) ) { return; }
        $type = 'post_' . $post->post_type;
        $trid = TML_Elements::get_trid( $type, $id );
        // Keep source_language_code intact when an existing translation is saved unchanged.
        if ( $trid && $lang === TML_Elements::get_language( $type, $id ) ) { return; }
        if ( ! $trid && $lang === TML_Languages::source_code() ) { return; }
        $result = TML_Elements::set( $type, $id, $lang, $trid );
        if ( is_wp_error( $result ) ) {
            /* translators: %s: language */
            set_transient( 'tml_metabox_error_' . get_current_user_id(), sprintf( __( 'Chưa đổi được ngôn ngữ: nhóm bản dịch này đã có bản %s. Bài đã được lưu với ngôn ngữ cũ.', 'tml-multilingual' ), TML_Languages::name( $lang ) ), 120 );
        }
    }

    public static function create() {
        $id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
        $lang = isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
        if ( ! $id || ! current_user_can( 'edit_post', $id ) ) { wp_die( esc_html__( 'Bạn không có quyền sửa nội dung này.', 'tml-multilingual' ), '', array( 'response' => 403, 'back_link' => true ) ); }
        check_admin_referer( 'tml_add_' . $id . '_' . $lang );
        $post = get_post( $id );
        if ( ! $post || ! in_array( $post->post_type, TML_Query::post_types(), true ) || ! TML_Languages::is_valid( $lang ) ) { wp_die( esc_html__( 'Nội dung hoặc ngôn ngữ không hợp lệ.', 'tml-multilingual' ), '', array( 'response' => 400, 'back_link' => true ) ); }
        $object = get_post_type_object( $post->post_type );
        if ( ! $object || ! current_user_can( $object->cap->create_posts ) ) { wp_die( esc_html__( 'Bạn không có quyền tạo nội dung loại này.', 'tml-multilingual' ), '', array( 'response' => 403, 'back_link' => true ) ); }

        $existing = TML_Elements::get_translation( 'post_' . $post->post_type, $id, $lang );
        if ( $existing && (int) $existing !== $id ) {
            if ( ! current_user_can( 'edit_post', $existing ) ) { wp_die( esc_html__( 'Bạn không có quyền sửa bản dịch này.', 'tml-multilingual' ), '', array( 'response' => 403, 'back_link' => true ) ); }
            wp_safe_redirect( get_edit_post_link( $existing, 'raw' ) );
            exit;
        }

        $new = TML_Elements::duplicate_post( $id, $lang, 'draft' );
        if ( is_wp_error( $new ) ) { wp_die( esc_html( $new->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) ); }

        wp_safe_redirect( add_query_arg( 'tml_duplicated', '1', get_edit_post_link( $new, 'raw' ) ) );
        exit;
    }
}
