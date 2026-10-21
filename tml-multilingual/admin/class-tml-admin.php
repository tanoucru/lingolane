<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TML_Admin {
    const PAGE = 'tml-multilingual';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_page_access_denied', array( __CLASS__, 'legacy_redirect' ) );
        add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( TML_DIR . 'tml-multilingual.php' ), array( __CLASS__, 'action_links' ) );
        add_action( 'admin_post_tml_save_default_language', array( __CLASS__, 'save_default_language' ) );
        add_action( 'admin_post_tml_save_settings', array( __CLASS__, 'save_default_language' ) );
        add_action( 'admin_post_tml_add_language', array( __CLASS__, 'add_language' ) );
        add_action( 'admin_post_tml_update_language', array( __CLASS__, 'update_language' ) );
        add_action( 'admin_post_tml_delete_language', array( __CLASS__, 'delete_language' ) );
        add_action( 'admin_post_tml_save_full_settings', array( __CLASS__, 'save_full_settings' ) );
        add_action( 'admin_post_tml_flush_cache', array( __CLASS__, 'flush_cache' ) );
        add_action( 'admin_post_tml_scan_theme_strings', array( __CLASS__, 'scan_theme_strings' ) );
    }

    /* ------------------------------------------------------------------ helpers */

    public static function tabs() {
        return array(
            'overview'  => array( __( 'Tổng quan', 'tml-multilingual' ), 'dashicons-dashboard' ),
            'content'   => array( __( 'Bài & trang', 'tml-multilingual' ), 'dashicons-admin-page' ),
            'strings'   => array( __( 'Chuỗi giao diện', 'tml-multilingual' ), 'dashicons-editor-textcolor' ),
            'languages' => array( __( 'Ngôn ngữ', 'tml-multilingual' ), 'dashicons-translation' ),
            'settings'  => array( __( 'Cài đặt', 'tml-multilingual' ), 'dashicons-admin-generic' ),
            'guide'     => array( __( 'Hướng dẫn', 'tml-multilingual' ), 'dashicons-sos' ),
        );
    }

    public static function url( $tab = 'overview', $args = array() ) {
        $base = array( 'page' => self::PAGE );
        if ( 'overview' !== $tab ) { $base['tab'] = $tab; }
        return add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) );
    }

    private static function current_tab() {
        $tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
        return array_key_exists( $tab, self::tabs() ) ? $tab : 'overview';
    }

    /** One-time message shown after a redirect. */
    public static function flash( $type, $message ) {
        set_transient( 'tml_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 120 );
    }

    public static function redirect( $tab, $args = array() ) {
        wp_safe_redirect( self::url( $tab, $args ) );
        exit;
    }

    /** Returns to the screen the form came from (keeps filters and pagination). */
    public static function back( $fallback_tab ) {
        $referer = wp_get_referer();
        wp_safe_redirect( $referer ? $referer : self::url( $fallback_tab ) );
        exit;
    }

    public static function guard( $nonce ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bạn không có quyền thực hiện thao tác này.', 'tml-multilingual' ), '', array( 'response' => 403, 'back_link' => true ) );
        }
        check_admin_referer( $nonce );
    }

    private static function post_text( $key ) {
        return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    }

    private static function settings() {
        $settings = get_option( 'tml_settings', TML_Languages::defaults() );
        return is_array( $settings ) ? $settings : TML_Languages::defaults();
    }

    public static function status_label( $status ) {
        $labels = array(
            'publish' => __( 'Đã xuất bản', 'tml-multilingual' ),
            'draft'   => __( 'Bản nháp', 'tml-multilingual' ),
            'pending' => __( 'Chờ duyệt', 'tml-multilingual' ),
            'future'  => __( 'Hẹn giờ', 'tml-multilingual' ),
            'private' => __( 'Riêng tư', 'tml-multilingual' ),
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
    }

    public static function chip( $text, $tone = 'neutral', $title = '' ) {
        return '<span class="tml-chip tml-chip--' . esc_attr( $tone ) . '"' . ( $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . '>' . esc_html( $text ) . '</span>';
    }

    public static function code( $code ) {
        return '<span class="tml-code" lang="' . esc_attr( $code ) . '">' . esc_html( strtoupper( $code ) ) . '</span>';
    }

    public static function translate_url( $post_id, $lang ) {
        return wp_nonce_url( add_query_arg( array( 'action' => 'tml_add_translation', 'post' => (int) $post_id, 'lang' => $lang ), admin_url( 'admin-post.php' ) ), 'tml_add_' . (int) $post_id . '_' . $lang );
    }

    private static function progress( $percent, $label ) {
        $percent = max( 0, min( 100, (int) $percent ) );
        return '<div class="tml-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $percent ) . '" aria-label="' . esc_attr( $label ) . '"><span style="width:' . esc_attr( $percent ) . '%"></span></div>';
    }

    /* ------------------------------------------------------------------ menu & assets */

    public static function menu() {
        add_menu_page( __( 'TeachMe — Ngôn ngữ', 'tml-multilingual' ), __( 'Đa ngôn ngữ', 'tml-multilingual' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ), 'dashicons-translation', 27 );
        foreach ( self::tabs() as $tab => $meta ) {
            add_submenu_page( self::PAGE, $meta[0], $meta[0], 'manage_options', 'overview' === $tab ? self::PAGE : 'admin.php?page=' . self::PAGE . '&tab=' . $tab, 'overview' === $tab ? array( __CLASS__, 'render' ) : null );
        }
    }

    /** Old bookmarks under Settings open the matching tab. */
    public static function legacy_redirect() {
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $map = array( 'tml-languages' => 'languages', 'tml-strings' => 'strings' );
        if ( isset( $map[ $page ] ) && current_user_can( 'manage_options' ) ) { self::redirect( $map[ $page ] ); }
    }

    public static function submenu_file( $submenu_file, $parent_file ) {
        if ( self::PAGE === $parent_file && isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
            $tab = self::current_tab();
            return 'overview' === $tab ? self::PAGE : 'admin.php?page=' . self::PAGE . '&tab=' . $tab;
        }
        return $submenu_file;
    }

    public static function action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Cài đặt', 'tml-multilingual' ) . '</a>' );
        return $links;
    }

    public static function enqueue( $hook ) {
        if ( 'toplevel_page_' . self::PAGE !== $hook ) { return; }
        wp_enqueue_style( 'tml-admin', TML_URL . 'assets/admin.css', array( 'dashicons' ), TML_VERSION );
        wp_enqueue_script( 'tml-admin', TML_URL . 'assets/admin.js', array( 'wp-a11y' ), TML_VERSION, true );
        wp_localize_script( 'tml-admin', 'tmlAdmin', array(
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'tml_strings_ajax' ),
            'i18n'  => array(
                'saving'      => __( 'Đang lưu…', 'tml-multilingual' ),
                'saved'       => __( 'Đã lưu', 'tml-multilingual' ),
                'failed'      => __( 'Không lưu được. Thử lại.', 'tml-multilingual' ),
                'unsaved'     => __( 'Chưa lưu', 'tml-multilingual' ),
                'own'         => __( 'Đã dịch', 'tml-multilingual' ),
                'inherited'   => __( 'Giao diện có sẵn', 'tml-multilingual' ),
                'missing'     => __( 'Chưa dịch', 'tml-multilingual' ),
                'deleteAsk'   => __( 'Xoá chuỗi này khỏi danh sách? Bản dịch đã lưu của chuỗi cũng bị xoá.', 'tml-multilingual' ),
                'deleted'     => __( 'Đã xoá chuỗi.', 'tml-multilingual' ),
                'leave'       => __( 'Có bản dịch chưa lưu. Rời trang?', 'tml-multilingual' ),
            ),
            'presets' => self::presets(),
        ) );
    }

    /** Common languages for the "add language" picker: code => [name, WordPress locale]. */
    public static function presets() {
        return array(
            'en' => array( 'English', 'en_US' ), 'vi' => array( 'Tiếng Việt', 'vi' ), 'fr' => array( 'Français', 'fr_FR' ),
            'de' => array( 'Deutsch', 'de_DE' ), 'es' => array( 'Español', 'es_ES' ), 'it' => array( 'Italiano', 'it_IT' ),
            'pt' => array( 'Português', 'pt_PT' ), 'ru' => array( 'Русский', 'ru_RU' ), 'ja' => array( '日本語', 'ja' ),
            'ko' => array( '한국어', 'ko_KR' ), 'zh' => array( '中文 (简体)', 'zh_CN' ), 'zh-tw' => array( '中文 (繁體)', 'zh_TW' ),
            'th' => array( 'ไทย', 'th' ), 'id' => array( 'Bahasa Indonesia', 'id_ID' ), 'ms' => array( 'Bahasa Melayu', 'ms_MY' ),
            'hi' => array( 'हिन्दी', 'hi_IN' ), 'ar' => array( 'العربية', 'ar' ), 'nl' => array( 'Nederlands', 'nl_NL' ),
        );
    }

    /* ------------------------------------------------------------------ actions */

    public static function save_default_language() {
        self::guard( 'tml_save_default_language' );
        $code = sanitize_key( self::post_text( 'default_language' ) );
        if ( ! TML_Languages::is_valid( $code ) ) {
            self::flash( 'error', __( 'Ngôn ngữ đã chọn không có trong danh sách.', 'tml-multilingual' ) );
            self::redirect( 'languages' );
        }
        $settings = self::settings();
        // Keep the content's original language when only the unprefixed language changes.
        if ( empty( $settings['source'] ) ) { $settings['source'] = TML_Languages::default_code(); }
        $settings['default'] = $code;
        update_option( 'tml_settings', $settings );
        TML_Strings::flush_cache();
        TML_Stats::flush_strings();
        /* translators: %s: language name */
        self::flash( 'success', sprintf( __( '%s là ngôn ngữ mặc định. Trang của ngôn ngữ này giờ không có tiền tố trong đường dẫn.', 'tml-multilingual' ), TML_Languages::name( $code ) ) );
        self::redirect( 'languages' );
    }

    private static function valid_locale( $locale ) {
        return (bool) preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[A-Za-z0-9]+)?$/', $locale );
    }

    public static function add_language() {
        self::guard( 'tml_add_language' );
        $code   = strtolower( self::post_text( 'lang_code' ) );
        $name   = self::post_text( 'lang_name' );
        $locale = self::post_text( 'lang_locale' );
        if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z]{2,4})?$/D', $code ) ) {
            self::flash( 'error', __( 'Mã ngôn ngữ gồm 2–3 chữ cái thường, có thể thêm vùng (ví dụ: ja, fr, zh-tw).', 'tml-multilingual' ) );
            self::redirect( 'languages' );
        }
        if ( '' === $name || ! self::valid_locale( $locale ) ) {
            self::flash( 'error', __( 'Cần tên hiển thị và locale WordPress hợp lệ (ví dụ: fr_FR, ja).', 'tml-multilingual' ) );
            self::redirect( 'languages' );
        }
        if ( TML_Languages::is_valid( $code ) ) {
            /* translators: %s: language code */
            self::flash( 'error', sprintf( __( 'Mã “%s” đã có trong danh sách.', 'tml-multilingual' ), $code ) );
            self::redirect( 'languages' );
        }
        $settings = self::settings();
        $settings['languages'] = TML_Languages::all();
        $settings['languages'][] = array( 'code' => $code, 'locale' => $locale, 'name' => $name );
        update_option( 'tml_settings', $settings );
        TML_Stats::flush_strings();
        /* translators: 1: language name, 2: URL prefix */
        self::flash( 'success', sprintf( __( 'Đã thêm %1$s. Trang của ngôn ngữ này có địa chỉ bắt đầu bằng %2$s; tạo bản dịch ở tab Bài & trang.', 'tml-multilingual' ), $name, '/' . $code . '/' ) );
        self::redirect( 'languages' );
    }

    public static function update_language() {
        self::guard( 'tml_update_language' );
        $code   = sanitize_key( self::post_text( 'lang_code' ) );
        $name   = self::post_text( 'lang_name' );
        $locale = self::post_text( 'lang_locale' );
        if ( ! TML_Languages::is_valid( $code ) || '' === $name || ! self::valid_locale( $locale ) ) {
            self::flash( 'error', __( 'Cần tên hiển thị và locale WordPress hợp lệ (ví dụ: fr_FR, ja).', 'tml-multilingual' ) );
            self::redirect( 'languages' );
        }
        $settings = self::settings();
        $languages = TML_Languages::all();
        foreach ( $languages as &$language ) {
            if ( $language['code'] === $code ) { $language['name'] = $name; $language['locale'] = $locale; }
        }
        unset( $language );
        $settings['languages'] = $languages;
        update_option( 'tml_settings', $settings );
        /* translators: %s: language name */
        self::flash( 'success', sprintf( __( 'Đã cập nhật %s.', 'tml-multilingual' ), $name ) );
        self::redirect( 'languages' );
    }

    public static function delete_language() {
        global $wpdb;
        self::guard( 'tml_delete_language' );
        $code = sanitize_key( self::post_text( 'lang_code' ) );
        if ( ! TML_Languages::is_valid( $code ) || $code === TML_Languages::default_code() || $code === TML_Languages::source_code() ) {
            self::flash( 'error', __( 'Không thể xoá ngôn ngữ mặc định hoặc ngôn ngữ gốc của nội dung.', 'tml-multilingual' ) );
            self::redirect( 'languages' );
        }
        $linked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tml_translations WHERE language_code = %s", $code ) );
        $strings = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tml_strings WHERE language_code = %s AND translation <> ''", $code ) );
        if ( $linked || $strings ) {
            /* translators: 1: linked content count, 2: string translation count */
            self::flash( 'error', sprintf( __( 'Ngôn ngữ này còn %1$d nội dung liên kết và %2$d chuỗi đã dịch. Xoá hoặc chuyển các bản dịch đó trước để không mất dữ liệu.', 'tml-multilingual' ), $linked, $strings ) );
            self::redirect( 'languages' );
        }
        $name = TML_Languages::name( $code );
        $settings = self::settings();
        $settings['languages'] = array_values( array_filter( TML_Languages::all(), function ( $language ) use ( $code ) { return $language['code'] !== $code; } ) );
        update_option( 'tml_settings', $settings );
        $wpdb->delete( $wpdb->prefix . 'tml_strings', array( 'language_code' => $code ), array( '%s' ) );
        TML_Strings::flush_cache();
        TML_Stats::flush_strings();
        /* translators: %s: language name */
        self::flash( 'success', sprintf( __( 'Đã gỡ %s khỏi website.', 'tml-multilingual' ), $name ) );
        self::redirect( 'languages' );
    }

    public static function save_full_settings() {
        self::guard( 'tml_save_full_settings' );
        $settings = self::settings();

        $settings['theme_strings_enabled']  = ! empty( $_POST['theme_strings_enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification
        $settings['plugin_strings_enabled'] = ! empty( $_POST['plugin_strings_enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification
        $domains = array();
        foreach ( preg_split( '/[\s,]+/', self::post_text( 'allowed_domains' ) ) as $domain ) {
            $clean = sanitize_key( $domain );
            if ( '' !== $clean ) { $domains[] = $clean; }
        }
        $settings['allowed_domains'] = array_values( array_unique( $domains ) );

        $types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $types = array_values( array_filter( array_map( 'sanitize_key', array_filter( $types, 'is_string' ) ), 'post_type_exists' ) );
        $taxonomies = isset( $_POST['taxonomies'] ) && is_array( $_POST['taxonomies'] ) ? wp_unslash( $_POST['taxonomies'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $taxonomies = array_values( array_filter( array_map( 'sanitize_key', array_filter( $taxonomies, 'is_string' ) ), 'taxonomy_exists' ) );
        if ( ! $types ) {
            self::flash( 'error', __( 'Chọn ít nhất một loại nội dung cần dịch. Chưa có thay đổi nào được lưu.', 'tml-multilingual' ) );
            self::redirect( 'settings' );
        }
        $settings['post_types'] = $types;
        $settings['taxonomies'] = $taxonomies;
        $settings['fallback_to_untranslated'] = ! empty( $_POST['fallback_to_untranslated'] ); // phpcs:ignore WordPress.Security.NonceVerification
        $settings['url_mode'] = 'directory';

        update_option( 'tml_settings', $settings );
        TML_Strings::flush_cache();
        TML_Stats::flush_strings();
        self::flash( 'success', __( 'Đã lưu cài đặt.', 'tml-multilingual' ) );
        self::redirect( 'settings' );
    }

    public static function flush_cache() {
        self::guard( 'tml_flush_cache' );
        TML_Strings::flush_cache();
        TML_Stats::flush_strings();
        TML_Shared::flush();
        delete_transient( 'tml_collation' );
        self::flash( 'success', __( 'Đã làm mới bộ nhớ đệm. Bản dịch mới nhất sẽ hiển thị ngay ở lượt tải trang kế tiếp.', 'tml-multilingual' ) );
        self::back( 'settings' );
    }

    public static function scan_theme_strings() {
        self::guard( 'tml_scan_theme_strings' );
        $result = TML_Strings_Admin::scan_theme_strings();
        TML_Stats::flush_strings();
        $parts = array();
        /* translators: %d: count */
        if ( $result['added'] ) { $parts[] = sprintf( __( 'thêm %d chuỗi mới', 'tml-multilingual' ), $result['added'] ); }
        /* translators: %d: count */
        if ( $result['removed'] ) { $parts[] = sprintf( __( 'gỡ %d chuỗi chưa dịch không còn dùng', 'tml-multilingual' ), $result['removed'] ); }
        if ( $parts ) {
            /* translators: 1: changes, 2: strings found */
            self::flash( 'success', sprintf( __( 'Đã quét giao diện: %1$s. Hiện có %2$d chuỗi khách nhìn thấy.', 'tml-multilingual' ), implode( ', ', $parts ), $result['found'] ) );
        } else {
            /* translators: %d: strings found */
            self::flash( 'info', sprintf( __( 'Danh sách đã đầy đủ: %d chuỗi khách nhìn thấy đều đã có.', 'tml-multilingual' ), $result['found'] ) );
        }
        self::redirect( 'strings', array( 'status' => 'missing' ) );
    }

    /* ------------------------------------------------------------------ page shell */

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $tab = self::current_tab();
        ?>
        <div class="wrap tml-wrap">
            <header class="tml-header">
                <div class="tml-header__title">
                    <span class="tml-header__icon dashicons dashicons-translation" aria-hidden="true"></span>
                    <div>
                        <h1><?php esc_html_e( 'Đa ngôn ngữ', 'tml-multilingual' ); ?></h1>
                        <p><?php esc_html_e( 'Bản dịch bài viết, trang và chữ trên giao diện của TeachMe.', 'tml-multilingual' ); ?></p>
                    </div>
                </div>
                <div class="tml-header__langs" aria-label="<?php esc_attr_e( 'Ngôn ngữ đang bật', 'tml-multilingual' ); ?>">
                    <?php foreach ( TML_Languages::all() as $language ) : ?>
                        <a class="tml-langpill<?php echo $language['code'] === TML_Languages::default_code() ? ' is-default' : ''; ?>" href="<?php echo esc_url( TML_Urls::home( $language['code'] ) ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( sprintf( /* translators: %s: language */ __( 'Mở trang chủ %s', 'tml-multilingual' ), $language['name'] ) ); ?>">
                            <?php echo self::code( $language['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <span><?php echo esc_html( $language['name'] ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </header>
            <hr class="wp-header-end">
            <?php self::render_notices(); ?>

            <nav class="tml-tabs" aria-label="<?php esc_attr_e( 'Mục quản lý', 'tml-multilingual' ); ?>">
                <?php foreach ( self::tabs() as $key => $meta ) : ?>
                    <a href="<?php echo esc_url( self::url( $key ) ); ?>" class="tml-tabs__item<?php echo $key === $tab ? ' is-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr( $meta[1] ); ?>" aria-hidden="true"></span><?php echo esc_html( $meta[0] ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <main class="tml-body">
                <?php
                switch ( $tab ) {
                    case 'content':   self::render_content(); break;
                    case 'strings':   TML_Strings_Admin::render_tab_content(); break;
                    case 'languages': self::render_languages(); break;
                    case 'settings':  self::render_settings(); break;
                    case 'guide':     self::render_guide(); break;
                    default:          self::render_overview();
                }
                ?>
            </main>

            <footer class="tml-footer">TeachMe Multilingual · tannguyen ver.<?php echo esc_html( TML_VERSION ); ?></footer>
        </div>
        <?php
    }

    private static function render_notices() {
        $key = 'tml_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! is_array( $notice ) || empty( $notice['message'] ) ) { return; }
        delete_transient( $key );
        $type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
        printf( '<div class="notice notice-%1$s is-dismissible" role="%2$s"><p>%3$s</p></div>', esc_attr( $type ), 'error' === $type ? 'alert' : 'status', esc_html( $notice['message'] ) );
    }

    /* ------------------------------------------------------------------ overview */

    private static function render_overview() {
        $others  = TML_Stats::other_languages();
        $strings = TML_Stats::strings();
        $shared  = TML_Stats::shared_count();
        ?>
        <?php if ( ! $others ) : ?>
            <section class="tml-card tml-empty">
                <h2><?php esc_html_e( 'Website mới có một ngôn ngữ', 'tml-multilingual' ); ?></h2>
                <p><?php esc_html_e( 'Thêm ngôn ngữ thứ hai để bắt đầu dịch bài viết và giao diện.', 'tml-multilingual' ); ?></p>
                <a class="button button-primary" href="<?php echo esc_url( self::url( 'languages' ) ); ?>"><?php esc_html_e( 'Thêm ngôn ngữ', 'tml-multilingual' ); ?></a>
            </section>
        <?php endif; ?>

        <div class="tml-grid tml-grid--cards">
            <?php foreach ( $others as $language ) :
                $code    = $language['code'];
                $content = TML_Stats::content( $code );
                $percent = TML_Stats::percent( $content['published'], $content['total'] );
                $s       = isset( $strings[ $code ] ) ? $strings[ $code ] : array( 'total' => 0, 'own' => 0, 'inherited' => 0, 'missing' => 0 );
                $s_done  = $s['own'] + $s['inherited'];
                $s_pct   = TML_Stats::percent( $s_done, $s['total'] );
                ?>
                <section class="tml-card tml-langcard">
                    <header class="tml-langcard__head">
                        <h2><?php echo self::code( $code ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $language['name'] ); ?></h2>
                        <a href="<?php echo esc_url( TML_Urls::home( $code ) ); ?>" target="_blank" rel="noopener" class="tml-link-muted"><?php echo esc_html( wp_parse_url( TML_Urls::home( $code ), PHP_URL_PATH ) ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                    </header>

                    <div class="tml-metric">
                        <div class="tml-metric__row">
                            <span class="tml-metric__label"><?php esc_html_e( 'Bài & trang', 'tml-multilingual' ); ?></span>
                            <strong class="tml-metric__value"><?php echo esc_html( $percent ); ?>%</strong>
                        </div>
                        <?php echo self::progress( $percent, __( 'Tiến độ dịch bài và trang', 'tml-multilingual' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <p class="tml-metric__note">
                            <?php
                            /* translators: 1: published translations, 2: total source items */
                            echo esc_html( sprintf( __( '%1$s/%2$s đã xuất bản', 'tml-multilingual' ), number_format_i18n( $content['published'] ), number_format_i18n( $content['total'] ) ) );
                            if ( $content['drafts'] ) {
                                /* translators: %s: number of drafts */
                                echo ' · ' . esc_html( sprintf( __( '%s bản nháp', 'tml-multilingual' ), number_format_i18n( $content['drafts'] ) ) );
                            }
                            ?>
                        </p>
                    </div>

                    <div class="tml-metric">
                        <div class="tml-metric__row">
                            <span class="tml-metric__label"><?php esc_html_e( 'Chuỗi giao diện', 'tml-multilingual' ); ?></span>
                            <strong class="tml-metric__value"><?php echo esc_html( $s_pct ); ?>%</strong>
                        </div>
                        <?php echo self::progress( $s_pct, __( 'Tiến độ dịch chuỗi giao diện', 'tml-multilingual' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <p class="tml-metric__note">
                            <?php
                            /* translators: 1: translated, 2: total */
                            echo esc_html( sprintf( __( '%1$s/%2$s có bản dịch', 'tml-multilingual' ), number_format_i18n( $s_done ), number_format_i18n( $s['total'] ) ) );
                            if ( $s['inherited'] ) {
                                /* translators: %s: count */
                                echo ' · ' . esc_html( sprintf( __( '%s lấy từ giao diện', 'tml-multilingual' ), number_format_i18n( $s['inherited'] ) ) );
                            }
                            ?>
                        </p>
                    </div>

                    <footer class="tml-langcard__actions">
                        <?php if ( $content['missing'] ) : ?>
                            <a class="button button-primary" href="<?php echo esc_url( self::url( 'content', array( 'status' => 'missing', 'lang' => $code ) ) ); ?>">
                                <?php /* translators: %s: count */ echo esc_html( sprintf( __( 'Dịch %s trang còn thiếu', 'tml-multilingual' ), number_format_i18n( $content['missing'] ) ) ); ?>
                            </a>
                        <?php elseif ( $content['drafts'] ) : ?>
                            <a class="button button-primary" href="<?php echo esc_url( self::url( 'content', array( 'status' => 'draft', 'lang' => $code ) ) ); ?>">
                                <?php /* translators: %s: count */ echo esc_html( sprintf( __( 'Xuất bản %s bản nháp', 'tml-multilingual' ), number_format_i18n( $content['drafts'] ) ) ); ?>
                            </a>
                        <?php else : ?>
                            <span class="tml-done"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Bài & trang đã dịch đủ', 'tml-multilingual' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $s['missing'] ) : ?>
                            <a class="button" href="<?php echo esc_url( self::url( 'strings', array( 'status' => 'missing', 'language' => $code ) ) ); ?>">
                                <?php /* translators: %s: count */ echo esc_html( sprintf( __( '%s chuỗi chưa dịch', 'tml-multilingual' ), number_format_i18n( $s['missing'] ) ) ); ?>
                            </a>
                        <?php endif; ?>
                    </footer>
                </section>
            <?php endforeach; ?>

            <section class="tml-card">
                <h2 class="tml-card__title"><?php esc_html_e( 'Tình trạng hệ thống', 'tml-multilingual' ); ?></h2>
                <ul class="tml-checklist">
                    <?php foreach ( self::health() as $item ) : ?>
                        <li class="is-<?php echo esc_attr( $item[0] ); ?>">
                            <span class="dashicons <?php echo esc_attr( 'ok' === $item[0] ? 'dashicons-yes-alt' : ( 'warn' === $item[0] ? 'dashicons-warning' : 'dashicons-info' ) ); ?>" aria-hidden="true"></span>
                            <div><strong><?php echo esc_html( $item[1] ); ?></strong><span><?php echo wp_kses( $item[2], array( 'a' => array( 'href' => true ), 'code' => array() ) ); ?></span></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( $shared ) : ?>
                    <p class="tml-hint">
                        <?php /* translators: %s: count */ echo esc_html( sprintf( __( '%s hồ sơ gia sư dùng chung cho mọi ngôn ngữ, không cần tạo bản dịch.', 'tml-multilingual' ), number_format_i18n( $shared ) ) ); ?>
                    </p>
                <?php endif; ?>
            </section>
        </div>

        <?php self::render_recent(); ?>
        <?php
    }

    /** [state, title, detail] tuples; state is ok, warn or info. */
    private static function health() {
        global $wpdb;
        $items = array();
        if ( get_option( 'permalink_structure' ) ) {
            $items[] = array( 'ok', __( 'Đường dẫn theo thư mục ngôn ngữ', 'tml-multilingual' ), __( 'Ví dụ: /en/about-us/. Ngôn ngữ mặc định không có tiền tố.', 'tml-multilingual' ) );
        } else {
            $items[] = array( 'warn', __( 'Cần bật đường dẫn đẹp', 'tml-multilingual' ), sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-permalink.php' ) ), esc_html__( 'Mở Cài đặt › Đường dẫn tĩnh', 'tml-multilingual' ) ) );
        }
        $tables = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'tml_strings' ) ) ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'tml_translations' ) ) );
        $items[] = $tables
            ? array( 'ok', __( 'Bảng dữ liệu bản dịch sẵn sàng', 'tml-multilingual' ), __( 'Liên kết bản dịch và chuỗi được lưu riêng, không ảnh hưởng nội dung gốc.', 'tml-multilingual' ) )
            : array( 'warn', __( 'Thiếu bảng dữ liệu', 'tml-multilingual' ), __( 'Tắt rồi bật lại plugin để tạo bảng.', 'tml-multilingual' ) );
        $items[] = array( 'ok', __( 'SEO đa ngôn ngữ', 'tml-multilingual' ), __( 'Thẻ hreflang, canonical và thuộc tính lang được thêm tự động cho trang có bản dịch.', 'tml-multilingual' ) );
        $locations = get_registered_nav_menus();
        $own = array();
        foreach ( TML_Stats::other_languages() as $language ) {
            foreach ( array_keys( $locations ) as $location ) {
                if ( wp_get_nav_menu_object( $location . ' ' . $language['code'] ) ) { $own[] = $language['code']; break; }
            }
        }
        if ( $locations ) {
            $items[] = $own
                ? array( 'ok', __( 'Menu riêng theo ngôn ngữ', 'tml-multilingual' ), sprintf( /* translators: %s: language codes */ esc_html__( 'Đang dùng cho: %s.', 'tml-multilingual' ), esc_html( strtoupper( implode( ', ', array_unique( $own ) ) ) ) ) )
                : array( 'info', __( 'Menu dùng chung', 'tml-multilingual' ), sprintf( '<a href="%s">%s</a>', esc_url( self::url( 'guide' ) . '#menus' ), esc_html__( 'Cách tạo menu riêng cho từng ngôn ngữ', 'tml-multilingual' ) ) );
        }
        return $items;
    }

    private static function render_recent() {
        global $wpdb;
        $default = TML_Languages::source_code();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, p.post_modified, t.language_code FROM {$wpdb->prefix}tml_translations t
            INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id AND t.element_type = " . TML_Query::type_sql( 'post_', 'p.post_type' ) . "
            WHERE t.language_code <> %s AND p.post_status NOT IN ('trash','auto-draft','inherit') ORDER BY p.post_modified DESC LIMIT 6",
            $default
        ) );
        if ( ! $rows ) { return; }
        ?>
        <section class="tml-card">
            <div class="tml-card__head">
                <h2 class="tml-card__title"><?php esc_html_e( 'Bản dịch cập nhật gần đây', 'tml-multilingual' ); ?></h2>
                <a href="<?php echo esc_url( self::url( 'content' ) ); ?>"><?php esc_html_e( 'Xem tất cả', 'tml-multilingual' ); ?></a>
            </div>
            <ul class="tml-recent">
                <?php foreach ( $rows as $row ) : ?>
                    <li>
                        <?php echo self::code( $row->language_code ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <a href="<?php echo esc_url( get_edit_post_link( (int) $row->ID, 'raw' ) ); ?>"><?php echo esc_html( $row->post_title ? $row->post_title : __( '(Chưa có tiêu đề)', 'tml-multilingual' ) ); ?></a>
                        <?php echo self::chip( self::status_label( $row->post_status ), 'publish' === $row->post_status ? 'ok' : 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <time datetime="<?php echo esc_attr( mysql2date( 'c', $row->post_modified ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: human time */ __( '%s trước', 'tml-multilingual' ), human_time_diff( strtotime( $row->post_modified ), current_time( 'timestamp' ) ) ) ); ?></time>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    /* ------------------------------------------------------------------ content matrix */

    private static function render_content() {
        global $wpdb;
        $languages = TML_Languages::all();
        $default   = TML_Languages::source_code();
        $others    = TML_Stats::other_languages();
        $types     = array_values( array_filter( TML_Query::post_types(), 'post_type_exists' ) );
        $editorial = TML_Stats::editorial_types();

        // phpcs:disable WordPress.Security.NonceVerification
        $type    = isset( $_GET['type'] ) && is_string( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
        $status  = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $lang    = isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
        $search  = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        // phpcs:enable
        if ( ! in_array( $type, $types, true ) ) { $type = ''; }
        if ( ! in_array( $status, array( 'missing', 'draft', 'complete' ), true ) ) { $status = ''; }
        $check_langs = TML_Languages::is_valid( $lang ) && $lang !== $default ? array( $lang ) : wp_list_pluck( $others, 'code' );
        if ( ! TML_Languages::is_valid( $lang ) || $lang === $default ) { $lang = ''; }
        $per_page = 20;

        $query_types = $type ? array( $type ) : ( $editorial ? $editorial : $types );
        $from = TML_Stats::source_sql( $query_types, TML_Stats::SOURCE_STATUSES );
        if ( '' !== $search ) {
            $from .= $wpdb->prepare( ' AND (p.post_title LIKE %s OR p.ID = %d)', '%' . $wpdb->esc_like( $search ) . '%', absint( $search ) );
        }
        if ( $status && $check_langs ) {
            $table = $wpdb->prefix . 'tml_translations';
            $parts = array();
            foreach ( $check_langs as $code ) {
                $published = TML_Stats::translated_sql( $code );
                if ( 'complete' === $status ) {
                    $parts[] = $published;
                } elseif ( 'draft' === $status ) {
                    $parts[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM $table x INNER JOIN {$wpdb->posts} px ON px.ID = x.element_id WHERE x.trid = s.trid AND x.language_code = %s AND px.post_status NOT IN ('publish','trash','auto-draft','inherit'))", $code );
                } else {
                    $parts[] = 'NOT ' . $published;
                }
            }
            $from .= ' AND (' . implode( 'complete' === $status ? ' AND ' : ' OR ', $parts ) . ')';
        }
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) $from" ); // phpcs:ignore WordPress.DB.PreparedSQL
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        $paged = min( $paged, $pages );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_modified, s.trid $from ORDER BY p.post_type = 'page' DESC, p.post_title ASC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ) ); // phpcs:ignore WordPress.DB.PreparedSQL

        // Load every translation group on this page in one query.
        $groups = array();
        $trids = array_filter( array_map( 'intval', wp_list_pluck( (array) $rows, 'trid' ) ) );
        if ( $trids ) {
            $found = $wpdb->get_results( "SELECT trid, language_code, element_id FROM {$wpdb->prefix}tml_translations WHERE trid IN (" . implode( ',', $trids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
            $ids = array();
            foreach ( (array) $found as $item ) { $groups[ (int) $item->trid ][ $item->language_code ] = (int) $item->element_id; $ids[] = (int) $item->element_id; }
            _prime_post_caches( $ids, false, false );
        }
        $is_shared_view = $type && TML_Shared::post_type( $type );
        $filtered = $type || $status || $search || $lang;
        ?>
        <section class="tml-card">
            <div class="tml-card__head">
                <h2 class="tml-card__title"><?php esc_html_e( 'Bản dịch bài viết và trang', 'tml-multilingual' ); ?></h2>
                <span class="tml-count"><?php /* translators: %s: count */ echo esc_html( sprintf( _n( '%s mục', '%s mục', $total, 'tml-multilingual' ), number_format_i18n( $total ) ) ); ?></span>
            </div>

            <form method="get" class="tml-filters" role="search">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
                <input type="hidden" name="tab" value="content">
                <div class="tml-field">
                    <label for="tml-f-type"><?php esc_html_e( 'Loại nội dung', 'tml-multilingual' ); ?></label>
                    <select id="tml-f-type" name="type">
                        <option value=""><?php esc_html_e( 'Bài viết & trang cần dịch', 'tml-multilingual' ); ?></option>
                        <?php foreach ( $types as $item ) :
                            $object = get_post_type_object( $item ); ?>
                            <option value="<?php echo esc_attr( $item ); ?>" <?php selected( $type, $item ); ?>><?php echo esc_html( ( $object ? $object->labels->name : $item ) . ( TML_Shared::post_type( $item ) ? ' · ' . __( 'dùng chung', 'tml-multilingual' ) : '' ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ( count( $others ) > 1 ) : ?>
                    <div class="tml-field">
                        <label for="tml-f-lang"><?php esc_html_e( 'Ngôn ngữ', 'tml-multilingual' ); ?></label>
                        <select id="tml-f-lang" name="lang">
                            <option value=""><?php esc_html_e( 'Mọi ngôn ngữ', 'tml-multilingual' ); ?></option>
                            <?php foreach ( $others as $language ) : ?>
                                <option value="<?php echo esc_attr( $language['code'] ); ?>" <?php selected( $lang, $language['code'] ); ?>><?php echo esc_html( $language['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="tml-field">
                    <label for="tml-f-status"><?php esc_html_e( 'Tình trạng', 'tml-multilingual' ); ?></label>
                    <select id="tml-f-status" name="status">
                        <option value=""><?php esc_html_e( 'Tất cả', 'tml-multilingual' ); ?></option>
                        <option value="missing" <?php selected( $status, 'missing' ); ?>><?php esc_html_e( 'Chưa có bản dịch xuất bản', 'tml-multilingual' ); ?></option>
                        <option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Bản dịch đang nháp', 'tml-multilingual' ); ?></option>
                        <option value="complete" <?php selected( $status, 'complete' ); ?>><?php esc_html_e( 'Đã dịch đủ', 'tml-multilingual' ); ?></option>
                    </select>
                </div>
                <div class="tml-field tml-field--grow">
                    <label for="tml-f-search"><?php esc_html_e( 'Tìm', 'tml-multilingual' ); ?></label>
                    <input id="tml-f-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Tiêu đề hoặc ID', 'tml-multilingual' ); ?>">
                </div>
                <div class="tml-field tml-field--actions">
                    <button type="submit" class="button"><?php esc_html_e( 'Lọc', 'tml-multilingual' ); ?></button>
                    <?php if ( $filtered ) : ?>
                        <a class="button-link" href="<?php echo esc_url( self::url( 'content' ) ); ?>"><?php esc_html_e( 'Xoá bộ lọc', 'tml-multilingual' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ( $is_shared_view ) : ?>
                <p class="tml-banner"><span class="dashicons dashicons-groups" aria-hidden="true"></span><?php esc_html_e( 'Hồ sơ loại này dùng chung cho mọi ngôn ngữ: khách xem bản tiếng Anh vẫn thấy hồ sơ gốc. Chỉ tạo bản riêng khi cần nội dung khác hẳn.', 'tml-multilingual' ); ?></p>
            <?php endif; ?>

            <div class="tml-table-wrap">
                <table class="tml-table tml-matrix">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Nội dung gốc', 'tml-multilingual' ); ?></th>
                            <?php foreach ( $languages as $language ) : ?>
                                <th scope="col" class="tml-matrix__lang"><?php echo self::code( $language['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <span><?php echo esc_html( $language['name'] ); ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( ! $rows ) : ?>
                        <tr><td colspan="<?php echo esc_attr( count( $languages ) + 1 ); ?>" class="tml-table__empty">
                            <?php
                            if ( 'missing' === $status ) {
                                echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Tuyệt vời, không còn mục nào thiếu bản dịch.', 'tml-multilingual' );
                            } else {
                                esc_html_e( 'Không có mục nào khớp bộ lọc.', 'tml-multilingual' );
                            }
                            ?>
                        </td></tr>
                    <?php else : foreach ( $rows as $row ) :
                        $id     = (int) $row->ID;
                        $group  = $row->trid && isset( $groups[ (int) $row->trid ] ) ? $groups[ (int) $row->trid ] : array();
                        $shared = TML_Shared::post_type( $row->post_type ) && ! $row->trid;
                        $object = get_post_type_object( $row->post_type );
                        ?>
                        <tr>
                            <td class="tml-matrix__title">
                                <a href="<?php echo esc_url( get_edit_post_link( $id, 'raw' ) ); ?>" class="row-title"><?php echo esc_html( $row->post_title ? $row->post_title : __( '(Chưa có tiêu đề)', 'tml-multilingual' ) ); ?></a>
                                <span class="tml-meta"><?php echo esc_html( ( $object ? $object->labels->singular_name : $row->post_type ) . ' · #' . $id ); ?></span>
                            </td>
                            <?php foreach ( $languages as $language ) :
                                $code = $language['code']; ?>
                                <td class="tml-matrix__cell">
                                    <?php
                                    if ( $code === $default ) {
                                        echo self::chip( self::status_label( $row->post_status ), 'publish' === $row->post_status ? 'ok' : 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput
                                        if ( 'publish' === $row->post_status ) {
                                            echo ' <a class="tml-icon-link" href="' . esc_url( get_permalink( $id ) ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Xem trang', 'tml-multilingual' ) . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Xem trang', 'tml-multilingual' ) . '</span></a>';
                                        }
                                    } elseif ( isset( $group[ $code ] ) ) {
                                        $tid = $group[ $code ];
                                        $tstatus = get_post_status( $tid );
                                        echo '<a class="tml-cell-link" href="' . esc_url( get_edit_post_link( $tid, 'raw' ) ) . '">' . self::chip( self::status_label( $tstatus ), 'publish' === $tstatus ? 'ok' : 'warn' ) . '<span class="tml-cell-link__edit">' . esc_html__( 'Sửa', 'tml-multilingual' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
                                        if ( 'publish' === $tstatus ) {
                                            echo ' <a class="tml-icon-link" href="' . esc_url( get_permalink( $tid ) ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Xem bản dịch', 'tml-multilingual' ) . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Xem bản dịch', 'tml-multilingual' ) . '</span></a>';
                                        }
                                    } elseif ( $shared ) {
                                        echo self::chip( __( 'Dùng chung', 'tml-multilingual' ), 'muted', __( 'Hiển thị hồ sơ gốc ở mọi ngôn ngữ', 'tml-multilingual' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
                                    } else {
                                        echo '<a class="button button-small tml-add" href="' . esc_url( self::translate_url( $id, $code ) ) . '"><span class="dashicons dashicons-plus" aria-hidden="true"></span>' . esc_html__( 'Tạo bản dịch', 'tml-multilingual' ) . '<span class="screen-reader-text"> ' . esc_html( $language['name'] ) . '</span></a>';
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php self::pagination( $paged, $pages, $total, $per_page ); ?>
        </section>
        <p class="tml-hint"><?php esc_html_e( '“Tạo bản dịch” sao chép nội dung, ảnh đại diện và phân loại sang một bản nháp mới đã liên kết với bài gốc. Dịch xong, bấm Đăng trong trình soạn thảo.', 'tml-multilingual' ); ?></p>
        <?php
    }

    public static function pagination( $paged, $pages, $total, $per_page ) {
        if ( $pages <= 1 ) { return; }
        $links = paginate_links( array(
            'base'      => add_query_arg( 'paged', '%#%' ),
            'format'    => '',
            'current'   => $paged,
            'total'     => $pages,
            'prev_text' => '‹ ' . __( 'Trước', 'tml-multilingual' ),
            'next_text' => __( 'Sau', 'tml-multilingual' ) . ' ›',
        ) );
        $first = ( $paged - 1 ) * $per_page + 1;
        $last  = min( $total, $paged * $per_page );
        echo '<nav class="tml-pagination" aria-label="' . esc_attr__( 'Phân trang', 'tml-multilingual' ) . '"><span>' . esc_html( sprintf( /* translators: 1: first, 2: last, 3: total */ __( '%1$s–%2$s trên %3$s', 'tml-multilingual' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) ) ) . '</span><div>' . wp_kses_post( $links ) . '</div></nav>';
    }

    /* ------------------------------------------------------------------ languages */

    private static function render_languages() {
        $languages = TML_Languages::all();
        $default   = TML_Languages::default_code();
        $linked    = TML_Stats::linked_counts();
        ?>
        <section class="tml-card">
            <div class="tml-card__head">
                <h2 class="tml-card__title"><?php esc_html_e( 'Ngôn ngữ của website', 'tml-multilingual' ); ?></h2>
            </div>
            <div class="tml-table-wrap">
                <table class="tml-table tml-langs">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Ngôn ngữ', 'tml-multilingual' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Địa chỉ', 'tml-multilingual' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Locale', 'tml-multilingual' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Nội dung liên kết', 'tml-multilingual' ); ?></th>
                            <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Thao tác', 'tml-multilingual' ); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $languages as $language ) :
                        $code = $language['code'];
                        $is_default = $code === $default;
                        $count = isset( $linked[ $code ] ) ? $linked[ $code ] : 0;
                        ?>
                        <tr>
                            <td>
                                <?php echo self::code( $code ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <strong><?php echo esc_html( $language['name'] ); ?></strong>
                                <?php if ( $is_default ) { echo ' ' . self::chip( __( 'Mặc định', 'tml-multilingual' ), 'brand', __( 'Địa chỉ không có tiền tố ngôn ngữ', 'tml-multilingual' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <?php if ( $code === TML_Languages::source_code() ) { echo ' ' . self::chip( __( 'Ngôn ngữ gốc', 'tml-multilingual' ), 'info', __( 'Ngôn ngữ viết nội dung và chữ giao diện gốc', 'tml-multilingual' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </td>
                            <td><code><?php echo esc_html( $is_default ? '/' : '/' . $code . '/' ); ?></code></td>
                            <td><code><?php echo esc_html( $language['locale'] ); ?></code></td>
                            <td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
                            <td class="tml-langs__actions">
                                <details class="tml-popover">
                                    <summary class="button button-small"><?php esc_html_e( 'Sửa', 'tml-multilingual' ); ?></summary>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tml-popover__panel">
                                        <input type="hidden" name="action" value="tml_update_language">
                                        <input type="hidden" name="lang_code" value="<?php echo esc_attr( $code ); ?>">
                                        <?php wp_nonce_field( 'tml_update_language' ); ?>
                                        <div class="tml-field">
                                            <label for="tml-name-<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Tên hiển thị', 'tml-multilingual' ); ?></label>
                                            <input id="tml-name-<?php echo esc_attr( $code ); ?>" type="text" name="lang_name" value="<?php echo esc_attr( $language['name'] ); ?>" required>
                                        </div>
                                        <div class="tml-field">
                                            <label for="tml-locale-<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Locale WordPress', 'tml-multilingual' ); ?></label>
                                            <input id="tml-locale-<?php echo esc_attr( $code ); ?>" type="text" name="lang_locale" value="<?php echo esc_attr( $language['locale'] ); ?>" required pattern="[a-z]{2,3}(_[A-Z]{2})?(_[A-Za-z0-9]+)?">
                                        </div>
                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Lưu', 'tml-multilingual' ); ?></button>
                                    </form>
                                </details>
                                <?php if ( ! $is_default ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-tml-confirm="<?php echo esc_attr( sprintf( /* translators: %s: language */ __( 'Đặt %s làm ngôn ngữ mặc định? Địa chỉ của mọi trang sẽ đổi: ngôn ngữ này bỏ tiền tố, ngôn ngữ hiện tại nhận tiền tố. Nên làm trước khi website có lượt truy cập từ Google.', 'tml-multilingual' ), $language['name'] ) ); ?>">
                                        <input type="hidden" name="action" value="tml_save_default_language">
                                        <input type="hidden" name="default_language" value="<?php echo esc_attr( $code ); ?>">
                                        <?php wp_nonce_field( 'tml_save_default_language' ); ?>
                                        <button type="submit" class="button button-small"><?php esc_html_e( 'Đặt mặc định', 'tml-multilingual' ); ?></button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-tml-confirm="<?php echo esc_attr( sprintf( /* translators: %s: language */ __( 'Gỡ %s khỏi website? Đường dẫn của ngôn ngữ này sẽ ngừng hoạt động.', 'tml-multilingual' ), $language['name'] ) ); ?>">
                                        <input type="hidden" name="action" value="tml_delete_language">
                                        <input type="hidden" name="lang_code" value="<?php echo esc_attr( $code ); ?>">
                                        <?php wp_nonce_field( 'tml_delete_language' ); ?>
                                        <button type="submit" class="button button-small button-link-delete" <?php disabled( $count > 0 ); ?> title="<?php echo esc_attr( $count ? __( 'Còn nội dung liên kết, không thể gỡ', 'tml-multilingual' ) : __( 'Gỡ ngôn ngữ', 'tml-multilingual' ) ); ?>"><?php esc_html_e( 'Gỡ', 'tml-multilingual' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="tml-card">
            <h2 class="tml-card__title"><?php esc_html_e( 'Thêm ngôn ngữ', 'tml-multilingual' ); ?></h2>
            <p class="tml-card__desc"><?php esc_html_e( 'Chọn từ danh sách để điền sẵn, hoặc tự nhập. Sau khi thêm, tạo bản dịch cho từng trang ở tab Bài & trang.', 'tml-multilingual' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tml-form-grid">
                <input type="hidden" name="action" value="tml_add_language">
                <?php wp_nonce_field( 'tml_add_language' ); ?>
                <div class="tml-field">
                    <label for="tml-preset"><?php esc_html_e( 'Ngôn ngữ phổ biến', 'tml-multilingual' ); ?></label>
                    <select id="tml-preset" data-tml-preset>
                        <option value=""><?php esc_html_e( '— Chọn để điền nhanh —', 'tml-multilingual' ); ?></option>
                        <?php foreach ( self::presets() as $code => $preset ) :
                            if ( TML_Languages::is_valid( $code ) ) { continue; } ?>
                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $preset[0] . ' (' . $code . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tml-field">
                    <label for="lang_code"><?php esc_html_e( 'Mã (dùng trong đường dẫn)', 'tml-multilingual' ); ?></label>
                    <input type="text" id="lang_code" name="lang_code" placeholder="fr" required pattern="[a-z]{2,3}(-[a-z]{2,4})?" maxlength="8" autocomplete="off" aria-describedby="lang_code_help">
                    <small id="lang_code_help"><?php esc_html_e( '2–3 chữ cái thường, ví dụ fr → /fr/', 'tml-multilingual' ); ?></small>
                </div>
                <div class="tml-field">
                    <label for="lang_name"><?php esc_html_e( 'Tên hiển thị', 'tml-multilingual' ); ?></label>
                    <input type="text" id="lang_name" name="lang_name" placeholder="Français" required>
                </div>
                <div class="tml-field">
                    <label for="lang_locale"><?php esc_html_e( 'Locale WordPress', 'tml-multilingual' ); ?></label>
                    <input type="text" id="lang_locale" name="lang_locale" placeholder="fr_FR" required pattern="[a-z]{2,3}(_[A-Z]{2})?(_[A-Za-z0-9]+)?">
                </div>
                <div class="tml-field tml-field--actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Thêm ngôn ngữ', 'tml-multilingual' ); ?></button>
                </div>
            </form>
        </section>
        <?php
    }

    /* ------------------------------------------------------------------ settings */

    private static function render_settings() {
        $settings = self::settings();
        $theme    = ! isset( $settings['theme_strings_enabled'] ) || $settings['theme_strings_enabled'];
        $plugins  = ! isset( $settings['plugin_strings_enabled'] ) || $settings['plugin_strings_enabled'];
        $domains  = isset( $settings['allowed_domains'] ) && is_array( $settings['allowed_domains'] ) ? implode( ', ', $settings['allowed_domains'] ) : '';
        $types    = TML_Query::post_types();
        $taxes    = TML_Query::taxonomies();
        $fallback = ! empty( $settings['fallback_to_untranslated'] );
        $skip_types = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face' );
        $skip_taxes = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tml-settings">
            <input type="hidden" name="action" value="tml_save_full_settings">
            <?php wp_nonce_field( 'tml_save_full_settings' ); ?>

            <section class="tml-card tml-setting">
                <div class="tml-setting__intro">
                    <h2><?php esc_html_e( 'Nội dung cần dịch', 'tml-multilingual' ); ?></h2>
                    <p><?php esc_html_e( 'Loại được chọn có cột Ngôn ngữ trong danh sách bài, hộp “Bản dịch” trong trình soạn thảo và được lọc theo ngôn ngữ ngoài website.', 'tml-multilingual' ); ?></p>
                </div>
                <div class="tml-setting__control">
                    <?php self::checkbox_group( 'post_types', __( 'Loại nội dung', 'tml-multilingual' ), get_post_types( array( 'public' => true ), 'objects' ), $types, $skip_types, array( 'post', 'page' ) ); ?>
                    <?php self::checkbox_group( 'taxonomies', __( 'Phân loại', 'tml-multilingual' ), get_taxonomies( array( 'public' => true ), 'objects' ), $taxes, $skip_taxes, array( 'category', 'post_tag' ) ); ?>
                    <p class="tml-hint"><?php esc_html_e( '“Dùng chung”: hồ sơ gia sư hiển thị ở mọi ngôn ngữ cho tới khi bạn tạo bản dịch riêng cho hồ sơ đó.', 'tml-multilingual' ); ?></p>
                </div>
            </section>

            <section class="tml-card tml-setting">
                <div class="tml-setting__intro">
                    <h2><?php esc_html_e( 'Khi chưa có bản dịch', 'tml-multilingual' ); ?></h2>
                    <p><?php esc_html_e( 'Trang chưa dịch luôn mở bản gốc kèm thông báo nhỏ cho khách, không bao giờ báo lỗi 404.', 'tml-multilingual' ); ?></p>
                </div>
                <div class="tml-setting__control">
                    <label class="tml-switch">
                        <input type="checkbox" name="fallback_to_untranslated" value="1" <?php checked( $fallback ); ?>>
                        <span><strong><?php esc_html_e( 'Hiện bài chưa dịch trong danh sách', 'tml-multilingual' ); ?></strong>
                        <?php esc_html_e( 'Trang blog, chuyên mục và tìm kiếm ở ngôn ngữ khác sẽ liệt kê cả bài gốc chưa có bản dịch. Tắt để chỉ liệt kê bài đã dịch.', 'tml-multilingual' ); ?></span>
                    </label>
                </div>
            </section>

            <section class="tml-card tml-setting">
                <div class="tml-setting__intro">
                    <h2><?php esc_html_e( 'Chuỗi giao diện', 'tml-multilingual' ); ?></h2>
                    <p><?php esc_html_e( 'Chữ cố định trong theme và plugin (nút, nhãn, thông báo). Bản dịch nhập ở tab Chuỗi giao diện được ưu tiên hơn bản có sẵn.', 'tml-multilingual' ); ?></p>
                </div>
                <div class="tml-setting__control">
                    <label class="tml-switch">
                        <input type="checkbox" name="theme_strings_enabled" value="1" <?php checked( $theme ); ?>>
                        <span><strong><?php esc_html_e( 'Dịch chữ của theme', 'tml-multilingual' ); ?></strong><?php esc_html_e( 'Áp dụng bản dịch đã lưu cho theme TeachMe.', 'tml-multilingual' ); ?></span>
                    </label>
                    <label class="tml-switch">
                        <input type="checkbox" name="plugin_strings_enabled" value="1" <?php checked( $plugins ); ?>>
                        <span><strong><?php esc_html_e( 'Dịch chữ của plugin', 'tml-multilingual' ); ?></strong><?php esc_html_e( 'Áp dụng bản dịch đã lưu cho form, danh bạ gia sư và các plugin khác.', 'tml-multilingual' ); ?></span>
                    </label>
                    <div class="tml-field">
                        <label for="tml-domains"><?php esc_html_e( 'Chỉ dịch các miền (text domain)', 'tml-multilingual' ); ?></label>
                        <input id="tml-domains" type="text" name="allowed_domains" value="<?php echo esc_attr( $domains ); ?>" placeholder="<?php esc_attr_e( 'Để trống = mọi miền có bản dịch', 'tml-multilingual' ); ?>" class="code" aria-describedby="tml-domains-help">
                        <small id="tml-domains-help"><?php esc_html_e( 'Ví dụ: teachme, inventor. Phân cách bằng dấu phẩy. Để trống nếu không chắc.', 'tml-multilingual' ); ?></small>
                    </div>
                </div>
            </section>

            <div class="tml-savebar">
                <button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Lưu cài đặt', 'tml-multilingual' ); ?></button>
            </div>
        </form>

        <section class="tml-card tml-setting">
            <div class="tml-setting__intro">
                <h2><?php esc_html_e( 'Công cụ', 'tml-multilingual' ); ?></h2>
                <p><?php esc_html_e( 'Dùng khi vừa cập nhật theme hoặc nhập bản dịch hàng loạt.', 'tml-multilingual' ); ?></p>
            </div>
            <div class="tml-setting__control tml-tools">
                <div class="tml-tool">
                    <div><strong><?php esc_html_e( 'Tìm chuỗi mới trong giao diện', 'tml-multilingual' ); ?></strong><span><?php esc_html_e( 'Đọc mã theme và thêm chữ mới vào danh sách cần dịch. Không thay đổi bản dịch đã có.', 'tml-multilingual' ); ?></span></div>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tml_scan_theme_strings' ), 'tml_scan_theme_strings' ) ); ?>"><?php esc_html_e( 'Quét giao diện', 'tml-multilingual' ); ?></a>
                </div>
                <div class="tml-tool">
                    <div><strong><?php esc_html_e( 'Làm mới bộ nhớ đệm', 'tml-multilingual' ); ?></strong><span><?php esc_html_e( 'Xoá dữ liệu tạm của bản dịch để website đọc lại ngay.', 'tml-multilingual' ); ?></span></div>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tml_flush_cache' ), 'tml_flush_cache' ) ); ?>"><?php esc_html_e( 'Làm mới', 'tml-multilingual' ); ?></a>
                </div>
            </div>
        </section>
        <?php
    }

    /** Checked, shared and common items first; anything else (e.g. unused directory types) folded away. */
    private static function checkbox_group( $name, $legend, $objects, $selected, $skip, $common ) {
        $main = array();
        $more = array();
        foreach ( $objects as $object ) {
            if ( in_array( $object->name, $skip, true ) ) { continue; }
            $relevant = in_array( $object->name, $selected, true ) || in_array( $object->name, $common, true ) || ( 'post_types' === $name && TML_Shared::post_type( $object->name ) );
            $html = '<label><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $object->name ) . '" ' . checked( in_array( $object->name, $selected, true ), true, false ) . '> ' . esc_html( $object->labels->name ) . ( 'post_types' === $name && TML_Shared::post_type( $object->name ) ? ' <em>' . esc_html__( 'dùng chung', 'tml-multilingual' ) . '</em>' : '' ) . '</label>';
            if ( $relevant ) { $main[] = $html; } else { $more[] = $html; }
        }
        echo '<fieldset><legend>' . esc_html( $legend ) . '</legend><div class="tml-checks">' . implode( '', $main ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        if ( $more ) {
            /* translators: %d: count */
            echo '<details class="tml-more"><summary>' . esc_html( sprintf( __( 'Loại khác ít dùng (%d)', 'tml-multilingual' ), count( $more ) ) ) . '</summary><div class="tml-checks">' . implode( '', $more ) . '</div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</fieldset>';
    }

    /* ------------------------------------------------------------------ guide */

    private static function render_guide() {
        $code = TML_Stats::other_languages() ? TML_Stats::other_languages()[0]['code'] : 'en';
        $locations = get_registered_nav_menus();
        $location = $locations ? array_keys( $locations )[0] : 'primary';
        ?>
        <div class="tml-guide">
            <nav class="tml-card tml-guide__toc" aria-label="<?php esc_attr_e( 'Mục lục hướng dẫn', 'tml-multilingual' ); ?>">
                <h2 class="tml-card__title"><?php esc_html_e( 'Nội dung', 'tml-multilingual' ); ?></h2>
                <ol>
                    <li><a href="#translate"><?php esc_html_e( 'Dịch một trang', 'tml-multilingual' ); ?></a></li>
                    <li><a href="#strings"><?php esc_html_e( 'Dịch chữ trên giao diện', 'tml-multilingual' ); ?></a></li>
                    <li><a href="#menus"><?php esc_html_e( 'Menu theo ngôn ngữ', 'tml-multilingual' ); ?></a></li>
                    <li><a href="#switcher"><?php esc_html_e( 'Nút chuyển ngôn ngữ', 'tml-multilingual' ); ?></a></li>
                    <li><a href="#shared"><?php esc_html_e( 'Hồ sơ dùng chung', 'tml-multilingual' ); ?></a></li>
                    <li><a href="#seo"><?php esc_html_e( 'SEO và đường dẫn', 'tml-multilingual' ); ?></a></li>
                </ol>
            </nav>
            <div class="tml-guide__body">
                <section class="tml-card" id="translate">
                    <h2 class="tml-card__title"><?php esc_html_e( '1. Dịch một trang hoặc bài viết', 'tml-multilingual' ); ?></h2>
                    <ol class="tml-steps">
                        <li><?php echo wp_kses( sprintf( /* translators: %s: link */ __( 'Mở tab %s, tìm trang cần dịch (lọc “Chưa có bản dịch xuất bản” để thấy việc còn lại).', 'tml-multilingual' ), '<a href="' . esc_url( self::url( 'content', array( 'status' => 'missing' ) ) ) . '">' . esc_html__( 'Bài & trang', 'tml-multilingual' ) . '</a>' ), array( 'a' => array( 'href' => true ) ) ); ?></li>
                        <li><?php esc_html_e( 'Bấm “Tạo bản dịch” ở cột ngôn ngữ. Một bản nháp được tạo với toàn bộ nội dung, ảnh và phân loại của bản gốc.', 'tml-multilingual' ); ?></li>
                        <li><?php esc_html_e( 'Dịch tiêu đề, nội dung và đường dẫn (slug) trong trình soạn thảo, sau đó bấm Đăng.', 'tml-multilingual' ); ?></li>
                    </ol>
                    <p class="tml-hint"><?php esc_html_e( 'Cũng có thể làm ngay trong trình soạn thảo: hộp “Bản dịch” ở cột phải hiển thị ngôn ngữ của bài và nút tạo bản dịch còn thiếu. Cột Ngôn ngữ trong danh sách Trang/Bài viết cũng có nút tương tự.', 'tml-multilingual' ); ?></p>
                </section>

                <section class="tml-card" id="strings">
                    <h2 class="tml-card__title"><?php esc_html_e( '2. Dịch chữ trên giao diện', 'tml-multilingual' ); ?></h2>
                    <p><?php esc_html_e( 'Nút, nhãn form, thông báo… nằm trong mã theme/plugin nên không sửa trong trình soạn thảo được.', 'tml-multilingual' ); ?></p>
                    <ol class="tml-steps">
                        <li><?php esc_html_e( 'Ở tab Chuỗi giao diện, lọc “Chưa dịch”.', 'tml-multilingual' ); ?></li>
                        <li><?php esc_html_e( 'Gõ bản dịch rồi bấm Lưu hoặc Ctrl/⌘ + Enter (tự chuyển sang chuỗi kế tiếp).', 'tml-multilingual' ); ?></li>
                        <li><?php esc_html_e( 'Chuỗi “Giao diện có sẵn” đã được theme dịch; chỉ nhập khi muốn thay câu chữ.', 'tml-multilingual' ); ?></li>
                    </ol>
                    <p class="tml-hint"><?php esc_html_e( 'Dịch hàng loạt: Xuất CSV, sửa trong Google Sheets/Excel, rồi Nhập lại. Cột: domain, context, source, language, translation.', 'tml-multilingual' ); ?></p>
                </section>

                <section class="tml-card" id="menus">
                    <h2 class="tml-card__title"><?php esc_html_e( '3. Menu theo ngôn ngữ', 'tml-multilingual' ); ?></h2>
                    <p><?php echo wp_kses( sprintf( /* translators: 1: example name */ __( 'Tạo menu mới ở Giao diện › Menu và đặt tên theo mẫu <code>vị-trí mã-ngôn-ngữ</code>, ví dụ %1$s. Khi khách xem bản tiếng đó, menu này tự thay menu gốc ở vị trí tương ứng.', 'tml-multilingual' ), '<code>' . esc_html( $location . ' ' . $code ) . '</code>' ), array( 'code' => array() ) ); ?></p>
                    <?php if ( $locations ) : ?>
                        <p class="tml-hint"><?php echo esc_html( sprintf( /* translators: %s: list */ __( 'Vị trí menu của theme: %s', 'tml-multilingual' ), implode( ', ', array_keys( $locations ) ) ) ); ?></p>
                    <?php endif; ?>
                </section>

                <section class="tml-card" id="switcher">
                    <h2 class="tml-card__title"><?php esc_html_e( '4. Nút chuyển ngôn ngữ', 'tml-multilingual' ); ?></h2>
                    <p><?php esc_html_e( 'Header của theme TeachMe đã có sẵn nút chuyển. Để đặt thêm ở nơi khác:', 'tml-multilingual' ); ?></p>
                    <ul class="tml-steps">
                        <li><?php echo wp_kses( __( 'Shortcode: <code>[tml_switcher]</code>, dạng thả xuống <code>[tml_switcher style="dropdown"]</code>, chỉ hiện mã <code>[tml_switcher show="code"]</code>.', 'tml-multilingual' ), array( 'code' => array() ) ); ?></li>
                        <li><?php esc_html_e( 'Widget: Giao diện › Widget › “Chọn ngôn ngữ”.', 'tml-multilingual' ); ?></li>
                        <li><?php echo wp_kses( __( 'Trong menu: thêm Liên kết tự tạo với URL <code>#tml-switcher</code> và nhãn <code>{{tml}}</code>; mục này tự tách thành từng ngôn ngữ.', 'tml-multilingual' ), array( 'code' => array() ) ); ?></li>
                    </ul>
                    <p class="tml-hint"><?php esc_html_e( 'Nút luôn dẫn tới đúng bản dịch của trang đang xem; trang chưa dịch dẫn về bản gốc kèm ghi chú.', 'tml-multilingual' ); ?></p>
                </section>

                <section class="tml-card" id="shared">
                    <h2 class="tml-card__title"><?php esc_html_e( '5. Hồ sơ gia sư và trang tài khoản dùng chung', 'tml-multilingual' ); ?></h2>
                    <p><?php esc_html_e( 'Hồ sơ gia sư, chuyên mục môn học, khu vực và các trang tài khoản (đăng hồ sơ, hồ sơ của tôi, đổi mật khẩu…) dùng chung cho mọi ngôn ngữ, nên khách tiếng Anh vẫn tìm được gia sư. Phần chữ cố định trên các trang này được dịch qua Chuỗi giao diện; nội dung do gia sư viết giữ nguyên.', 'tml-multilingual' ); ?></p>
                </section>

                <section class="tml-card" id="seo">
                    <h2 class="tml-card__title"><?php esc_html_e( '6. SEO và đường dẫn', 'tml-multilingual' ); ?></h2>
                    <ul class="tml-steps">
                        <li><?php echo wp_kses( sprintf( /* translators: %s: example */ __( 'Ngôn ngữ mặc định không có tiền tố; ngôn ngữ khác nằm trong thư mục riêng, ví dụ %s.', 'tml-multilingual' ), '<code>' . esc_html( wp_parse_url( TML_Urls::home( $code ), PHP_URL_PATH ) ) . '</code>' ), array( 'code' => array() ) ); ?></li>
                        <li><?php esc_html_e( 'Mỗi trang có bản dịch tự có thẻ hreflang, x-default và canonical đúng ngôn ngữ; Yoast SEO dùng chung các giá trị này.', 'tml-multilingual' ); ?></li>
                        <li><?php esc_html_e( 'Liên kết nội bộ trong bài được tự chuyển sang bản dịch tương ứng khi có.', 'tml-multilingual' ); ?></li>
                    </ul>
                </section>
            </div>
        </div>
        <?php
    }
}
