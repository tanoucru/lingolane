<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Lingolane_Admin {
    const PAGE = 'lingolane';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_page_access_denied', array( __CLASS__, 'legacy_redirect' ) );
        add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( LINGOLANE_DIR . 'lingolane.php' ), array( __CLASS__, 'action_links' ) );
        add_action( 'admin_post_lingolane_save_default_language', array( __CLASS__, 'save_default_language' ) );
        add_action( 'admin_post_lingolane_save_settings', array( __CLASS__, 'save_default_language' ) );
        add_action( 'admin_post_lingolane_add_language', array( __CLASS__, 'add_language' ) );
        add_action( 'admin_post_lingolane_update_language', array( __CLASS__, 'update_language' ) );
        add_action( 'admin_post_lingolane_delete_language', array( __CLASS__, 'delete_language' ) );
        add_action( 'admin_post_lingolane_save_full_settings', array( __CLASS__, 'save_full_settings' ) );
        add_action( 'admin_post_lingolane_flush_cache', array( __CLASS__, 'flush_cache' ) );
        add_action( 'admin_post_lingolane_scan_theme_strings', array( __CLASS__, 'scan_theme_strings' ) );
    }

    /* ------------------------------------------------------------------ helpers */

    public static function tabs() {
        return array(
            'overview'  => array( __( 'Overview', 'lingolane' ), 'dashicons-dashboard' ),
            'content'   => array( __( 'Posts & pages', 'lingolane' ), 'dashicons-admin-page' ),
            'strings'   => array( __( 'Interface strings', 'lingolane' ), 'dashicons-editor-textcolor' ),
            'languages' => array( __( 'Languages', 'lingolane' ), 'dashicons-translation' ),
            'settings'  => array( __( 'Settings', 'lingolane' ), 'dashicons-admin-generic' ),
            'guide'     => array( __( 'Guide', 'lingolane' ), 'dashicons-sos' ),
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
        set_transient( 'lingolane_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 120 );
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
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'lingolane' ), '', array( 'response' => 403, 'back_link' => true ) );
        }
        check_admin_referer( $nonce );
    }

    private static function post_text( $key ) {
        return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    }

    private static function settings() {
        $settings = get_option( 'lingolane_settings', Lingolane_Languages::defaults() );
        return is_array( $settings ) ? $settings : Lingolane_Languages::defaults();
    }

    public static function status_label( $status ) {
        $labels = array(
            'publish' => __( 'Published', 'lingolane' ),
            'draft'   => __( 'Draft', 'lingolane' ),
            'pending' => __( 'Pending review', 'lingolane' ),
            'future'  => __( 'Scheduled', 'lingolane' ),
            'private' => __( 'Private', 'lingolane' ),
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
    }

    public static function chip( $text, $tone = 'neutral', $title = '' ) {
        return '<span class="lingolane-chip lingolane-chip--' . esc_attr( $tone ) . '"' . ( $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . '>' . esc_html( $text ) . '</span>';
    }

    public static function code( $code ) {
        return '<span class="lingolane-code" lang="' . esc_attr( $code ) . '">' . esc_html( strtoupper( $code ) ) . '</span>';
    }

    public static function translate_url( $post_id, $lang ) {
        return wp_nonce_url( add_query_arg( array( 'action' => 'lingolane_add_translation', 'post' => (int) $post_id, 'lang' => $lang ), admin_url( 'admin-post.php' ) ), 'lingolane_add_' . (int) $post_id . '_' . $lang );
    }

    private static function progress( $percent, $label ) {
        $percent = max( 0, min( 100, (int) $percent ) );
        return '<div class="lingolane-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $percent ) . '" aria-label="' . esc_attr( $label ) . '"><span style="width:' . esc_attr( $percent ) . '%"></span></div>';
    }

    /* ------------------------------------------------------------------ menu & assets */

    public static function menu() {
        add_menu_page( __( 'Lingolane — Languages', 'lingolane' ), __( 'Multilingual', 'lingolane' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ), 'dashicons-translation', 27 );
        foreach ( self::tabs() as $tab => $meta ) {
            add_submenu_page( self::PAGE, $meta[0], $meta[0], 'manage_options', 'overview' === $tab ? self::PAGE : 'admin.php?page=' . self::PAGE . '&tab=' . $tab, 'overview' === $tab ? array( __CLASS__, 'render' ) : null );
        }
    }

    /** Old bookmarks under Settings open the matching tab. */
    public static function legacy_redirect() {
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $map = array( 'lingolane-languages' => 'languages', 'lingolane-strings' => 'strings' );
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
        array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'lingolane' ) . '</a>' );
        return $links;
    }

    public static function enqueue( $hook ) {
        if ( 'toplevel_page_' . self::PAGE !== $hook ) { return; }
        wp_enqueue_style( 'lingolane-admin', LINGOLANE_URL . 'assets/admin.css', array( 'dashicons' ), LINGOLANE_VERSION );
        wp_enqueue_script( 'lingolane-admin', LINGOLANE_URL . 'assets/admin.js', array( 'wp-a11y' ), LINGOLANE_VERSION, true );
        wp_localize_script( 'lingolane-admin', 'lingolaneAdmin', array(
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'lingolane_strings_ajax' ),
            'i18n'  => array(
                'saving'      => __( 'Saving…', 'lingolane' ),
                'saved'       => __( 'Saved', 'lingolane' ),
                'failed'      => __( 'Could not save. Please try again.', 'lingolane' ),
                'unsaved'     => __( 'Not saved', 'lingolane' ),
                'own'         => __( 'Translated', 'lingolane' ),
                'inherited'   => __( 'Provided by theme', 'lingolane' ),
                'missing'     => __( 'Untranslated', 'lingolane' ),
                'deleteAsk'   => __( 'Remove this string from the list? Its saved translations are deleted as well.', 'lingolane' ),
                'deleted'     => __( 'String deleted.', 'lingolane' ),
                'leave'       => __( 'You have unsaved translations. Leave this page?', 'lingolane' ),
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
        self::guard( 'lingolane_save_default_language' );
        $code = sanitize_key( self::post_text( 'default_language' ) );
        if ( ! Lingolane_Languages::is_valid( $code ) ) {
            self::flash( 'error', __( 'The selected language is not in the list.', 'lingolane' ) );
            self::redirect( 'languages' );
        }
        $settings = self::settings();
        // Keep the content's original language when only the unprefixed language changes.
        if ( empty( $settings['source'] ) ) { $settings['source'] = Lingolane_Languages::default_code(); }
        $settings['default'] = $code;
        update_option( 'lingolane_settings', $settings );
        Lingolane_Strings::flush_cache();
        Lingolane_Stats::flush_strings();
        /* translators: %s: language name */
        self::flash( 'success', sprintf( __( '%s is now the default language. Its pages no longer have a prefix in their URLs.', 'lingolane' ), Lingolane_Languages::name( $code ) ) );
        self::redirect( 'languages' );
    }

    private static function valid_locale( $locale ) {
        return (bool) preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[A-Za-z0-9]+)?$/', $locale );
    }

    public static function add_language() {
        self::guard( 'lingolane_add_language' );
        $code   = strtolower( self::post_text( 'lang_code' ) );
        $name   = self::post_text( 'lang_name' );
        $locale = self::post_text( 'lang_locale' );
        if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z]{2,4})?$/D', $code ) ) {
            self::flash( 'error', __( 'The language code is 2–3 lowercase letters, optionally with a region (for example ja, fr, zh-tw).', 'lingolane' ) );
            self::redirect( 'languages' );
        }
        if ( '' === $name || ! self::valid_locale( $locale ) ) {
            self::flash( 'error', __( 'A display name and a valid WordPress locale are required (for example fr_FR, ja).', 'lingolane' ) );
            self::redirect( 'languages' );
        }
        if ( Lingolane_Languages::is_valid( $code ) ) {
            /* translators: %s: language code */
            self::flash( 'error', sprintf( __( 'The code “%s” is already in the list.', 'lingolane' ), $code ) );
            self::redirect( 'languages' );
        }
        $settings = self::settings();
        $settings['languages'] = Lingolane_Languages::all();
        $settings['languages'][] = array( 'code' => $code, 'locale' => $locale, 'name' => $name );
        update_option( 'lingolane_settings', $settings );
        Lingolane_Stats::flush_strings();
        /* translators: 1: language name, 2: URL prefix */
        self::flash( 'success', sprintf( __( 'Added %1$s. Its pages have URLs starting with %2$s; create translations in the Posts & pages tab.', 'lingolane' ), $name, '/' . $code . '/' ) );
        self::redirect( 'languages' );
    }

    public static function update_language() {
        self::guard( 'lingolane_update_language' );
        $code   = sanitize_key( self::post_text( 'lang_code' ) );
        $name   = self::post_text( 'lang_name' );
        $locale = self::post_text( 'lang_locale' );
        if ( ! Lingolane_Languages::is_valid( $code ) || '' === $name || ! self::valid_locale( $locale ) ) {
            self::flash( 'error', __( 'A display name and a valid WordPress locale are required (for example fr_FR, ja).', 'lingolane' ) );
            self::redirect( 'languages' );
        }
        $settings = self::settings();
        $languages = Lingolane_Languages::all();
        foreach ( $languages as &$language ) {
            if ( $language['code'] === $code ) { $language['name'] = $name; $language['locale'] = $locale; }
        }
        unset( $language );
        $settings['languages'] = $languages;
        update_option( 'lingolane_settings', $settings );
        /* translators: %s: language name */
        self::flash( 'success', sprintf( __( 'Updated %s.', 'lingolane' ), $name ) );
        self::redirect( 'languages' );
    }

    public static function delete_language() {
        global $wpdb;
        self::guard( 'lingolane_delete_language' );
        $code = sanitize_key( self::post_text( 'lang_code' ) );
        if ( ! Lingolane_Languages::is_valid( $code ) || $code === Lingolane_Languages::default_code() || $code === Lingolane_Languages::source_code() ) {
            self::flash( 'error', __( 'You cannot remove the default language or the content’s source language.', 'lingolane' ) );
            self::redirect( 'languages' );
        }
        $linked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lingolane_translations WHERE language_code = %s", $code ) );
        $strings = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lingolane_strings WHERE language_code = %s AND translation <> ''", $code ) );
        if ( $linked || $strings ) {
            /* translators: 1: linked content count, 2: string translation count */
            self::flash( 'error', sprintf( __( 'This language still has %1$d linked items and %2$d translated strings. Delete or move those translations first so no data is lost.', 'lingolane' ), $linked, $strings ) );
            self::redirect( 'languages' );
        }
        $name = Lingolane_Languages::name( $code );
        $settings = self::settings();
        $settings['languages'] = array_values( array_filter( Lingolane_Languages::all(), function ( $language ) use ( $code ) { return $language['code'] !== $code; } ) );
        update_option( 'lingolane_settings', $settings );
        $wpdb->delete( $wpdb->prefix . 'lingolane_strings', array( 'language_code' => $code ), array( '%s' ) );
        Lingolane_Strings::flush_cache();
        Lingolane_Stats::flush_strings();
        /* translators: %s: language name */
        self::flash( 'success', sprintf( __( 'Removed %s from the site.', 'lingolane' ), $name ) );
        self::redirect( 'languages' );
    }

    public static function save_full_settings() {
        self::guard( 'lingolane_save_full_settings' );
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
            self::flash( 'error', __( 'Select at least one content type to translate. No changes were saved.', 'lingolane' ) );
            self::redirect( 'settings' );
        }
        $settings['post_types'] = $types;
        $settings['taxonomies'] = $taxonomies;
        $settings['fallback_to_untranslated'] = ! empty( $_POST['fallback_to_untranslated'] ); // phpcs:ignore WordPress.Security.NonceVerification
        $settings['url_mode'] = 'directory';

        update_option( 'lingolane_settings', $settings );
        Lingolane_Strings::flush_cache();
        Lingolane_Stats::flush_strings();
        self::flash( 'success', __( 'Settings saved.', 'lingolane' ) );
        self::redirect( 'settings' );
    }

    public static function flush_cache() {
        self::guard( 'lingolane_flush_cache' );
        Lingolane_Strings::flush_cache();
        Lingolane_Stats::flush_strings();
        Lingolane_Shared::flush();
        delete_transient( 'lingolane_collation' );
        self::flash( 'success', __( 'Cache cleared. The latest translations will show on the next page load.', 'lingolane' ) );
        self::back( 'settings' );
    }

    public static function scan_theme_strings() {
        self::guard( 'lingolane_scan_theme_strings' );
        $result = Lingolane_Strings_Admin::scan_theme_strings();
        Lingolane_Stats::flush_strings();
        $parts = array();
        /* translators: %d: count */
        if ( $result['added'] ) { $parts[] = sprintf( __( 'added %d new strings', 'lingolane' ), $result['added'] ); }
        /* translators: %d: count */
        if ( $result['removed'] ) { $parts[] = sprintf( __( 'removed %d unused untranslated strings', 'lingolane' ), $result['removed'] ); }
        if ( $parts ) {
            /* translators: 1: changes, 2: strings found */
            self::flash( 'success', sprintf( __( 'Interface scan complete: %1$s. There are now %2$d visitor-visible strings.', 'lingolane' ), implode( ', ', $parts ), $result['found'] ) );
        } else {
            /* translators: %d: strings found */
            self::flash( 'info', sprintf( __( 'The list is complete: all %d visitor-visible strings are already listed.', 'lingolane' ), $result['found'] ) );
        }
        self::redirect( 'strings', array( 'status' => 'missing' ) );
    }

    /* ------------------------------------------------------------------ page shell */

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $tab = self::current_tab();
        ?>
        <div class="wrap lingolane-wrap">
            <header class="lingolane-header">
                <div class="lingolane-header__title">
                    <span class="lingolane-header__icon dashicons dashicons-translation" aria-hidden="true"></span>
                    <div>
                        <h1><?php esc_html_e( 'Multilingual', 'lingolane' ); ?></h1>
                        <p><?php esc_html_e( 'Translate posts, pages and interface text.', 'lingolane' ); ?></p>
                    </div>
                </div>
                <div class="lingolane-header__langs" aria-label="<?php esc_attr_e( 'Enabled languages', 'lingolane' ); ?>">
                    <?php foreach ( Lingolane_Languages::all() as $language ) : ?>
                        <a class="lingolane-langpill<?php echo $language['code'] === Lingolane_Languages::default_code() ? ' is-default' : ''; ?>" href="<?php echo esc_url( Lingolane_Urls::home( $language['code'] ) ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( sprintf( /* translators: %s: language */ __( 'Open the %s home page', 'lingolane' ), $language['name'] ) ); ?>">
                            <?php echo self::code( $language['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <span><?php echo esc_html( $language['name'] ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </header>
            <hr class="wp-header-end">
            <?php self::render_notices(); ?>

            <nav class="lingolane-tabs" aria-label="<?php esc_attr_e( 'Management items', 'lingolane' ); ?>">
                <?php foreach ( self::tabs() as $key => $meta ) : ?>
                    <a href="<?php echo esc_url( self::url( $key ) ); ?>" class="lingolane-tabs__item<?php echo $key === $tab ? ' is-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr( $meta[1] ); ?>" aria-hidden="true"></span><?php echo esc_html( $meta[0] ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <main class="lingolane-body">
                <?php
                switch ( $tab ) {
                    case 'content':   self::render_content(); break;
                    case 'strings':   Lingolane_Strings_Admin::render_tab_content(); break;
                    case 'languages': self::render_languages(); break;
                    case 'settings':  self::render_settings(); break;
                    case 'guide':     self::render_guide(); break;
                    default:          self::render_overview();
                }
                ?>
            </main>

            <footer class="lingolane-footer">TeachMe Multilingual · tannguyen ver.<?php echo esc_html( LINGOLANE_VERSION ); ?></footer>
        </div>
        <?php
    }

    private static function render_notices() {
        $key = 'lingolane_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! is_array( $notice ) || empty( $notice['message'] ) ) { return; }
        delete_transient( $key );
        $type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
        printf( '<div class="notice notice-%1$s is-dismissible" role="%2$s"><p>%3$s</p></div>', esc_attr( $type ), 'error' === $type ? 'alert' : 'status', esc_html( $notice['message'] ) );
    }

    /* ------------------------------------------------------------------ overview */

    private static function render_overview() {
        $others  = Lingolane_Stats::other_languages();
        $strings = Lingolane_Stats::strings();
        $shared  = Lingolane_Stats::shared_count();
        ?>
        <?php if ( ! $others ) : ?>
            <section class="lingolane-card lingolane-empty">
                <h2><?php esc_html_e( 'Your site has only one language', 'lingolane' ); ?></h2>
                <p><?php esc_html_e( 'Add a second language to start translating posts and your interface.', 'lingolane' ); ?></p>
                <a class="button button-primary" href="<?php echo esc_url( self::url( 'languages' ) ); ?>"><?php esc_html_e( 'Add a language', 'lingolane' ); ?></a>
            </section>
        <?php endif; ?>

        <div class="lingolane-grid lingolane-grid--cards">
            <?php foreach ( $others as $language ) :
                $code    = $language['code'];
                $content = Lingolane_Stats::content( $code );
                $percent = Lingolane_Stats::percent( $content['published'], $content['total'] );
                $s       = isset( $strings[ $code ] ) ? $strings[ $code ] : array( 'total' => 0, 'own' => 0, 'inherited' => 0, 'missing' => 0 );
                $s_done  = $s['own'] + $s['inherited'];
                $s_pct   = Lingolane_Stats::percent( $s_done, $s['total'] );
                ?>
                <section class="lingolane-card lingolane-langcard">
                    <header class="lingolane-langcard__head">
                        <h2><?php echo self::code( $code ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $language['name'] ); ?></h2>
                        <a href="<?php echo esc_url( Lingolane_Urls::home( $code ) ); ?>" target="_blank" rel="noopener" class="lingolane-link-muted"><?php echo esc_html( wp_parse_url( Lingolane_Urls::home( $code ), PHP_URL_PATH ) ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                    </header>

                    <div class="lingolane-metric">
                        <div class="lingolane-metric__row">
                            <span class="lingolane-metric__label"><?php esc_html_e( 'Posts & pages', 'lingolane' ); ?></span>
                            <strong class="lingolane-metric__value"><?php echo esc_html( $percent ); ?>%</strong>
                        </div>
                        <?php echo self::progress( $percent, __( 'Post and page translation progress', 'lingolane' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <p class="lingolane-metric__note">
                            <?php
                            /* translators: 1: published translations, 2: total source items */
                            echo esc_html( sprintf( __( '%1$s/%2$s published', 'lingolane' ), number_format_i18n( $content['published'] ), number_format_i18n( $content['total'] ) ) );
                            if ( $content['drafts'] ) {
                                /* translators: %s: number of drafts */
                                echo ' · ' . esc_html( sprintf( __( '%s drafts', 'lingolane' ), number_format_i18n( $content['drafts'] ) ) );
                            }
                            ?>
                        </p>
                    </div>

                    <div class="lingolane-metric">
                        <div class="lingolane-metric__row">
                            <span class="lingolane-metric__label"><?php esc_html_e( 'Interface strings', 'lingolane' ); ?></span>
                            <strong class="lingolane-metric__value"><?php echo esc_html( $s_pct ); ?>%</strong>
                        </div>
                        <?php echo self::progress( $s_pct, __( 'Interface string translation progress', 'lingolane' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <p class="lingolane-metric__note">
                            <?php
                            /* translators: 1: translated, 2: total */
                            echo esc_html( sprintf( __( '%1$s/%2$s translated', 'lingolane' ), number_format_i18n( $s_done ), number_format_i18n( $s['total'] ) ) );
                            if ( $s['inherited'] ) {
                                /* translators: %s: count */
                                echo ' · ' . esc_html( sprintf( __( '%s provided by the theme', 'lingolane' ), number_format_i18n( $s['inherited'] ) ) );
                            }
                            ?>
                        </p>
                    </div>

                    <footer class="lingolane-langcard__actions">
                        <?php if ( $content['missing'] ) : ?>
                            <a class="button button-primary" href="<?php echo esc_url( self::url( 'content', array( 'status' => 'missing', 'lang' => $code ) ) ); ?>">
                                <?php /* translators: %s: count */ echo esc_html( sprintf( __( 'Translate the %s missing pages', 'lingolane' ), number_format_i18n( $content['missing'] ) ) ); ?>
                            </a>
                        <?php elseif ( $content['drafts'] ) : ?>
                            <a class="button button-primary" href="<?php echo esc_url( self::url( 'content', array( 'status' => 'draft', 'lang' => $code ) ) ); ?>">
                                <?php /* translators: %s: count */ echo esc_html( sprintf( __( 'Publish %s drafts', 'lingolane' ), number_format_i18n( $content['drafts'] ) ) ); ?>
                            </a>
                        <?php else : ?>
                            <span class="lingolane-done"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'All posts & pages are translated', 'lingolane' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $s['missing'] ) : ?>
                            <a class="button" href="<?php echo esc_url( self::url( 'strings', array( 'status' => 'missing', 'language' => $code ) ) ); ?>">
                                <?php /* translators: %s: count */ echo esc_html( sprintf( __( '%s untranslated strings', 'lingolane' ), number_format_i18n( $s['missing'] ) ) ); ?>
                            </a>
                        <?php endif; ?>
                    </footer>
                </section>
            <?php endforeach; ?>

            <section class="lingolane-card">
                <h2 class="lingolane-card__title"><?php esc_html_e( 'System status', 'lingolane' ); ?></h2>
                <ul class="lingolane-checklist">
                    <?php foreach ( self::health() as $item ) : ?>
                        <li class="is-<?php echo esc_attr( $item[0] ); ?>">
                            <span class="dashicons <?php echo esc_attr( 'ok' === $item[0] ? 'dashicons-yes-alt' : ( 'warn' === $item[0] ? 'dashicons-warning' : 'dashicons-info' ) ); ?>" aria-hidden="true"></span>
                            <div><strong><?php echo esc_html( $item[1] ); ?></strong><span><?php echo wp_kses( $item[2], array( 'a' => array( 'href' => true ), 'code' => array() ) ); ?></span></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( $shared ) : ?>
                    <p class="lingolane-hint">
                        <?php /* translators: %s: count */ echo esc_html( sprintf( __( '%s shared records are shown in every language and need no translation.', 'lingolane' ), number_format_i18n( $shared ) ) ); ?>
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
            $items[] = array( 'ok', __( 'Language directory in URLs', 'lingolane' ), __( 'Example: /en/about-us/. The default language has no prefix.', 'lingolane' ) );
        } else {
            $items[] = array( 'warn', __( 'Pretty permalinks required', 'lingolane' ), sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-permalink.php' ) ), esc_html__( 'Open Settings › Permalinks', 'lingolane' ) ) );
        }
        $tables = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lingolane_strings' ) ) ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lingolane_translations' ) ) );
        $items[] = $tables
            ? array( 'ok', __( 'Translation tables ready', 'lingolane' ), __( 'Translation links and strings are stored separately and do not affect your original content.', 'lingolane' ) )
            : array( 'warn', __( 'Missing tables', 'lingolane' ), __( 'Deactivate and reactivate the plugin to create the tables.', 'lingolane' ) );
        $items[] = array( 'ok', __( 'Multilingual SEO', 'lingolane' ), __( 'hreflang, canonical and lang attributes are added automatically to pages that have translations.', 'lingolane' ) );
        $locations = get_registered_nav_menus();
        $own = array();
        foreach ( Lingolane_Stats::other_languages() as $language ) {
            foreach ( array_keys( $locations ) as $location ) {
                if ( wp_get_nav_menu_object( $location . ' ' . $language['code'] ) ) { $own[] = $language['code']; break; }
            }
        }
        if ( $locations ) {
            $items[] = $own
                ? array( 'ok', __( 'Language-specific menus', 'lingolane' ), sprintf( /* translators: %s: language codes */ esc_html__( 'In use for: %s.', 'lingolane' ), esc_html( strtoupper( implode( ', ', array_unique( $own ) ) ) ) ) )
                : array( 'info', __( 'Shared menu', 'lingolane' ), sprintf( '<a href="%s">%s</a>', esc_url( self::url( 'guide' ) . '#menus' ), esc_html__( 'How to create a menu for each language', 'lingolane' ) ) );
        }
        return $items;
    }

    private static function render_recent() {
        global $wpdb;
        $default = Lingolane_Languages::source_code();
        $type_sql = Lingolane_Query::type_sql( 'post_', 'p.post_type' );
        // $type_sql is built from a literal prefix, a column name and a validated collation; the language uses a placeholder.
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT p.ID, p.post_title, p.post_status, p.post_modified, t.language_code FROM {$wpdb->prefix}lingolane_translations t
            INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id AND t.element_type = $type_sql
            WHERE t.language_code <> %s AND p.post_status NOT IN ('trash','auto-draft','inherit') ORDER BY p.post_modified DESC LIMIT 6",
            $default
        ) );
        if ( ! $rows ) { return; }
        ?>
        <section class="lingolane-card">
            <div class="lingolane-card__head">
                <h2 class="lingolane-card__title"><?php esc_html_e( 'Recently updated translations', 'lingolane' ); ?></h2>
                <a href="<?php echo esc_url( self::url( 'content' ) ); ?>"><?php esc_html_e( 'View all', 'lingolane' ); ?></a>
            </div>
            <ul class="lingolane-recent">
                <?php foreach ( $rows as $row ) : ?>
                    <li>
                        <?php echo self::code( $row->language_code ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <a href="<?php echo esc_url( get_edit_post_link( (int) $row->ID, 'raw' ) ); ?>"><?php echo esc_html( $row->post_title ? $row->post_title : __( '(No title)', 'lingolane' ) ); ?></a>
                        <?php echo self::chip( self::status_label( $row->post_status ), 'publish' === $row->post_status ? 'ok' : 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <time datetime="<?php echo esc_attr( mysql2date( 'c', $row->post_modified ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: human time */ __( '%s ago', 'lingolane' ), human_time_diff( strtotime( $row->post_modified ), current_time( 'timestamp' ) ) ) ); ?></time>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    /* ------------------------------------------------------------------ content matrix */

    private static function render_content() {
        global $wpdb;
        $languages = Lingolane_Languages::all();
        $default   = Lingolane_Languages::source_code();
        $others    = Lingolane_Stats::other_languages();
        $types     = array_values( array_filter( Lingolane_Query::post_types(), 'post_type_exists' ) );
        $editorial = Lingolane_Stats::editorial_types();

        // phpcs:disable WordPress.Security.NonceVerification
        $type    = isset( $_GET['type'] ) && is_string( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
        $status  = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $lang    = isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
        $search  = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        // phpcs:enable
        if ( ! in_array( $type, $types, true ) ) { $type = ''; }
        if ( ! in_array( $status, array( 'missing', 'draft', 'complete' ), true ) ) { $status = ''; }
        $check_langs = Lingolane_Languages::is_valid( $lang ) && $lang !== $default ? array( $lang ) : wp_list_pluck( $others, 'code' );
        if ( ! Lingolane_Languages::is_valid( $lang ) || $lang === $default ) { $lang = ''; }
        $per_page = 20;

        $query_types = $type ? array( $type ) : ( $editorial ? $editorial : $types );
        $from = Lingolane_Stats::source_sql( $query_types, Lingolane_Stats::SOURCE_STATUSES );
        if ( '' !== $search ) {
            $from .= $wpdb->prepare( ' AND (p.post_title LIKE %s OR p.ID = %d)', '%' . $wpdb->esc_like( $search ) . '%', absint( $search ) );
        }
        if ( $status && $check_langs ) {
            $table = $wpdb->prefix . 'lingolane_translations';
            $parts = array();
            foreach ( $check_langs as $code ) {
                $published = Lingolane_Stats::translated_sql( $code );
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
            $found = $wpdb->get_results( "SELECT trid, language_code, element_id FROM {$wpdb->prefix}lingolane_translations WHERE trid IN (" . implode( ',', $trids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
            $ids = array();
            foreach ( (array) $found as $item ) { $groups[ (int) $item->trid ][ $item->language_code ] = (int) $item->element_id; $ids[] = (int) $item->element_id; }
            _prime_post_caches( $ids, false, false );
        }
        $is_shared_view = $type && Lingolane_Shared::post_type( $type );
        $filtered = $type || $status || $search || $lang;
        ?>
        <section class="lingolane-card">
            <div class="lingolane-card__head">
                <h2 class="lingolane-card__title"><?php esc_html_e( 'Post and page translations', 'lingolane' ); ?></h2>
                <span class="lingolane-count"><?php /* translators: %s: count */ echo esc_html( sprintf( _n( '%s item', '%s items', $total, 'lingolane' ), number_format_i18n( $total ) ) ); ?></span>
            </div>

            <form method="get" class="lingolane-filters" role="search">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
                <input type="hidden" name="tab" value="content">
                <div class="lingolane-field">
                    <label for="lingolane-f-type"><?php esc_html_e( 'Content type', 'lingolane' ); ?></label>
                    <select id="lingolane-f-type" name="type">
                        <option value=""><?php esc_html_e( 'Posts & pages to translate', 'lingolane' ); ?></option>
                        <?php foreach ( $types as $item ) :
                            $object = get_post_type_object( $item ); ?>
                            <option value="<?php echo esc_attr( $item ); ?>" <?php selected( $type, $item ); ?>><?php echo esc_html( ( $object ? $object->labels->name : $item ) . ( Lingolane_Shared::post_type( $item ) ? ' · ' . __( 'shared', 'lingolane' ) : '' ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ( count( $others ) > 1 ) : ?>
                    <div class="lingolane-field">
                        <label for="lingolane-f-lang"><?php esc_html_e( 'Languages', 'lingolane' ); ?></label>
                        <select id="lingolane-f-lang" name="lang">
                            <option value=""><?php esc_html_e( 'All languages', 'lingolane' ); ?></option>
                            <?php foreach ( $others as $language ) : ?>
                                <option value="<?php echo esc_attr( $language['code'] ); ?>" <?php selected( $lang, $language['code'] ); ?>><?php echo esc_html( $language['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="lingolane-field">
                    <label for="lingolane-f-status"><?php esc_html_e( 'Status', 'lingolane' ); ?></label>
                    <select id="lingolane-f-status" name="status">
                        <option value=""><?php esc_html_e( 'All', 'lingolane' ); ?></option>
                        <option value="missing" <?php selected( $status, 'missing' ); ?>><?php esc_html_e( 'No published translation', 'lingolane' ); ?></option>
                        <option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Draft translation', 'lingolane' ); ?></option>
                        <option value="complete" <?php selected( $status, 'complete' ); ?>><?php esc_html_e( 'Fully translated', 'lingolane' ); ?></option>
                    </select>
                </div>
                <div class="lingolane-field lingolane-field--grow">
                    <label for="lingolane-f-search"><?php esc_html_e( 'Search', 'lingolane' ); ?></label>
                    <input id="lingolane-f-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Title or ID', 'lingolane' ); ?>">
                </div>
                <div class="lingolane-field lingolane-field--actions">
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'lingolane' ); ?></button>
                    <?php if ( $filtered ) : ?>
                        <a class="button-link" href="<?php echo esc_url( self::url( 'content' ) ); ?>"><?php esc_html_e( 'Clear filters', 'lingolane' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ( $is_shared_view ) : ?>
                <p class="lingolane-banner"><span class="dashicons dashicons-groups" aria-hidden="true"></span><?php esc_html_e( 'Items of this type are shared across all languages: visitors in other languages still see the original. Only create a separate version when the content must be completely different.', 'lingolane' ); ?></p>
            <?php endif; ?>

            <div class="lingolane-table-wrap">
                <table class="lingolane-table lingolane-matrix">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Original content', 'lingolane' ); ?></th>
                            <?php foreach ( $languages as $language ) : ?>
                                <th scope="col" class="lingolane-matrix__lang"><?php echo self::code( $language['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <span><?php echo esc_html( $language['name'] ); ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( ! $rows ) : ?>
                        <tr><td colspan="<?php echo esc_attr( count( $languages ) + 1 ); ?>" class="lingolane-table__empty">
                            <?php
                            if ( 'missing' === $status ) {
                                echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Great, nothing is missing a translation.', 'lingolane' );
                            } else {
                                esc_html_e( 'No items match the filter.', 'lingolane' );
                            }
                            ?>
                        </td></tr>
                    <?php else : foreach ( $rows as $row ) :
                        $id     = (int) $row->ID;
                        $group  = $row->trid && isset( $groups[ (int) $row->trid ] ) ? $groups[ (int) $row->trid ] : array();
                        $shared = Lingolane_Shared::post_type( $row->post_type ) && ! $row->trid;
                        $object = get_post_type_object( $row->post_type );
                        ?>
                        <tr>
                            <td class="lingolane-matrix__title">
                                <a href="<?php echo esc_url( get_edit_post_link( $id, 'raw' ) ); ?>" class="row-title"><?php echo esc_html( $row->post_title ? $row->post_title : __( '(No title)', 'lingolane' ) ); ?></a>
                                <span class="lingolane-meta"><?php echo esc_html( ( $object ? $object->labels->singular_name : $row->post_type ) . ' · #' . $id ); ?></span>
                            </td>
                            <?php foreach ( $languages as $language ) :
                                $code = $language['code']; ?>
                                <td class="lingolane-matrix__cell">
                                    <?php
                                    if ( $code === $default ) {
                                        echo self::chip( self::status_label( $row->post_status ), 'publish' === $row->post_status ? 'ok' : 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput
                                        if ( 'publish' === $row->post_status ) {
                                            echo ' <a class="lingolane-icon-link" href="' . esc_url( get_permalink( $id ) ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'View page', 'lingolane' ) . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'View page', 'lingolane' ) . '</span></a>';
                                        }
                                    } elseif ( isset( $group[ $code ] ) ) {
                                        $tid = $group[ $code ];
                                        $tstatus = get_post_status( $tid );
                                        echo '<a class="lingolane-cell-link" href="' . esc_url( get_edit_post_link( $tid, 'raw' ) ) . '">' . self::chip( self::status_label( $tstatus ), 'publish' === $tstatus ? 'ok' : 'warn' ) . '<span class="lingolane-cell-link__edit">' . esc_html__( 'Edit', 'lingolane' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
                                        if ( 'publish' === $tstatus ) {
                                            echo ' <a class="lingolane-icon-link" href="' . esc_url( get_permalink( $tid ) ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'View translation', 'lingolane' ) . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'View translation', 'lingolane' ) . '</span></a>';
                                        }
                                    } elseif ( $shared ) {
                                        echo self::chip( __( 'Shared', 'lingolane' ), 'muted', __( 'Show the original in every language', 'lingolane' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
                                    } else {
                                        echo '<a class="button button-small lingolane-add" href="' . esc_url( self::translate_url( $id, $code ) ) . '"><span class="dashicons dashicons-plus" aria-hidden="true"></span>' . esc_html__( 'Create translation', 'lingolane' ) . '<span class="screen-reader-text"> ' . esc_html( $language['name'] ) . '</span></a>';
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
        <p class="lingolane-hint"><?php esc_html_e( '“Create translation” copies the content, featured image and terms into a new draft linked to the original. When the translation is ready, click Publish in the editor.', 'lingolane' ); ?></p>
        <?php
    }

    public static function pagination( $paged, $pages, $total, $per_page ) {
        if ( $pages <= 1 ) { return; }
        $links = paginate_links( array(
            'base'      => add_query_arg( 'paged', '%#%' ),
            'format'    => '',
            'current'   => $paged,
            'total'     => $pages,
            'prev_text' => '‹ ' . __( 'Previous', 'lingolane' ),
            'next_text' => __( 'Next', 'lingolane' ) . ' ›',
        ) );
        $first = ( $paged - 1 ) * $per_page + 1;
        $last  = min( $total, $paged * $per_page );
        echo '<nav class="lingolane-pagination" aria-label="' . esc_attr__( 'Pagination', 'lingolane' ) . '"><span>' . esc_html( sprintf( /* translators: 1: first, 2: last, 3: total */ __( '%1$s–%2$s of %3$s', 'lingolane' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) ) ) . '</span><div>' . wp_kses_post( $links ) . '</div></nav>';
    }

    /* ------------------------------------------------------------------ languages */

    private static function render_languages() {
        $languages = Lingolane_Languages::all();
        $default   = Lingolane_Languages::default_code();
        $linked    = Lingolane_Stats::linked_counts();
        ?>
        <section class="lingolane-card">
            <div class="lingolane-card__head">
                <h2 class="lingolane-card__title"><?php esc_html_e( 'Site languages', 'lingolane' ); ?></h2>
            </div>
            <div class="lingolane-table-wrap">
                <table class="lingolane-table lingolane-langs">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Languages', 'lingolane' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'URL', 'lingolane' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Locale', 'lingolane' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Linked content', 'lingolane' ); ?></th>
                            <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'lingolane' ); ?></span></th>
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
                                <?php if ( $is_default ) { echo ' ' . self::chip( __( 'Default', 'lingolane' ), 'brand', __( 'URLs have no language prefix', 'lingolane' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <?php if ( $code === Lingolane_Languages::source_code() ) { echo ' ' . self::chip( __( 'Source language', 'lingolane' ), 'info', __( 'The language your content and interface text are written in', 'lingolane' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </td>
                            <td><code><?php echo esc_html( $is_default ? '/' : '/' . $code . '/' ); ?></code></td>
                            <td><code><?php echo esc_html( $language['locale'] ); ?></code></td>
                            <td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
                            <td class="lingolane-langs__actions">
                                <details class="lingolane-popover">
                                    <summary class="button button-small"><?php esc_html_e( 'Edit', 'lingolane' ); ?></summary>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lingolane-popover__panel">
                                        <input type="hidden" name="action" value="lingolane_update_language">
                                        <input type="hidden" name="lang_code" value="<?php echo esc_attr( $code ); ?>">
                                        <?php wp_nonce_field( 'lingolane_update_language' ); ?>
                                        <div class="lingolane-field">
                                            <label for="lingolane-name-<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Display name', 'lingolane' ); ?></label>
                                            <input id="lingolane-name-<?php echo esc_attr( $code ); ?>" type="text" name="lang_name" value="<?php echo esc_attr( $language['name'] ); ?>" required>
                                        </div>
                                        <div class="lingolane-field">
                                            <label for="lingolane-locale-<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'WordPress locale', 'lingolane' ); ?></label>
                                            <input id="lingolane-locale-<?php echo esc_attr( $code ); ?>" type="text" name="lang_locale" value="<?php echo esc_attr( $language['locale'] ); ?>" required pattern="[a-z]{2,3}(_[A-Z]{2})?(_[A-Za-z0-9]+)?">
                                        </div>
                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'lingolane' ); ?></button>
                                    </form>
                                </details>
                                <?php if ( ! $is_default ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-lingolane-confirm="<?php echo esc_attr( sprintf( /* translators: %s: language */ __( 'Make %s the default language? Every page URL will change: this language loses its prefix and the current default gets one. Do this before search engines have indexed your site.', 'lingolane' ), $language['name'] ) ); ?>">
                                        <input type="hidden" name="action" value="lingolane_save_default_language">
                                        <input type="hidden" name="default_language" value="<?php echo esc_attr( $code ); ?>">
                                        <?php wp_nonce_field( 'lingolane_save_default_language' ); ?>
                                        <button type="submit" class="button button-small"><?php esc_html_e( 'Set as default', 'lingolane' ); ?></button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-lingolane-confirm="<?php echo esc_attr( sprintf( /* translators: %s: language */ __( 'Remove %s from the site? URLs for this language will stop working.', 'lingolane' ), $language['name'] ) ); ?>">
                                        <input type="hidden" name="action" value="lingolane_delete_language">
                                        <input type="hidden" name="lang_code" value="<?php echo esc_attr( $code ); ?>">
                                        <?php wp_nonce_field( 'lingolane_delete_language' ); ?>
                                        <button type="submit" class="button button-small button-link-delete" <?php disabled( $count > 0 ); ?> title="<?php echo esc_attr( $count ? __( 'Has linked content; cannot remove', 'lingolane' ) : __( 'Remove language', 'lingolane' ) ); ?>"><?php esc_html_e( 'Remove', 'lingolane' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="lingolane-card">
            <h2 class="lingolane-card__title"><?php esc_html_e( 'Add a language', 'lingolane' ); ?></h2>
            <p class="lingolane-card__desc"><?php esc_html_e( 'Pick from the list to prefill, or type your own. After adding, create translations for each page in the Posts & pages tab.', 'lingolane' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lingolane-form-grid">
                <input type="hidden" name="action" value="lingolane_add_language">
                <?php wp_nonce_field( 'lingolane_add_language' ); ?>
                <div class="lingolane-field">
                    <label for="lingolane-preset"><?php esc_html_e( 'Common languages', 'lingolane' ); ?></label>
                    <select id="lingolane-preset" data-lingolane-preset>
                        <option value=""><?php esc_html_e( '— Select to prefill —', 'lingolane' ); ?></option>
                        <?php foreach ( self::presets() as $code => $preset ) :
                            if ( Lingolane_Languages::is_valid( $code ) ) { continue; } ?>
                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $preset[0] . ' (' . $code . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lingolane-field">
                    <label for="lang_code"><?php esc_html_e( 'Code (used in URLs)', 'lingolane' ); ?></label>
                    <input type="text" id="lang_code" name="lang_code" placeholder="fr" required pattern="[a-z]{2,3}(-[a-z]{2,4})?" maxlength="8" autocomplete="off" aria-describedby="lang_code_help">
                    <small id="lang_code_help"><?php esc_html_e( '2–3 lowercase letters, for example fr → /fr/', 'lingolane' ); ?></small>
                </div>
                <div class="lingolane-field">
                    <label for="lang_name"><?php esc_html_e( 'Display name', 'lingolane' ); ?></label>
                    <input type="text" id="lang_name" name="lang_name" placeholder="Français" required>
                </div>
                <div class="lingolane-field">
                    <label for="lang_locale"><?php esc_html_e( 'WordPress locale', 'lingolane' ); ?></label>
                    <input type="text" id="lang_locale" name="lang_locale" placeholder="fr_FR" required pattern="[a-z]{2,3}(_[A-Z]{2})?(_[A-Za-z0-9]+)?">
                </div>
                <div class="lingolane-field lingolane-field--actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Add a language', 'lingolane' ); ?></button>
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
        $types    = Lingolane_Query::post_types();
        $taxes    = Lingolane_Query::taxonomies();
        $fallback = ! empty( $settings['fallback_to_untranslated'] );
        $skip_types = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face' );
        $skip_taxes = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lingolane-settings">
            <input type="hidden" name="action" value="lingolane_save_full_settings">
            <?php wp_nonce_field( 'lingolane_save_full_settings' ); ?>

            <section class="lingolane-card lingolane-setting">
                <div class="lingolane-setting__intro">
                    <h2><?php esc_html_e( 'Content to translate', 'lingolane' ); ?></h2>
                    <p><?php esc_html_e( 'Selected types get a Language column in the post list, a “Translations” box in the editor, and are filtered by language on the front end.', 'lingolane' ); ?></p>
                </div>
                <div class="lingolane-setting__control">
                    <?php self::checkbox_group( 'post_types', __( 'Content type', 'lingolane' ), get_post_types( array( 'public' => true ), 'objects' ), $types, $skip_types, array( 'post', 'page' ) ); ?>
                    <?php self::checkbox_group( 'taxonomies', __( 'Taxonomies', 'lingolane' ), get_taxonomies( array( 'public' => true ), 'objects' ), $taxes, $skip_taxes, array( 'category', 'post_tag' ) ); ?>
                    <p class="lingolane-hint"><?php esc_html_e( '“Shared”: records of shared types appear in every language until you create a separate translation for that record.', 'lingolane' ); ?></p>
                </div>
            </section>

            <section class="lingolane-card lingolane-setting">
                <div class="lingolane-setting__intro">
                    <h2><?php esc_html_e( 'When a translation is missing', 'lingolane' ); ?></h2>
                    <p><?php esc_html_e( 'Untranslated pages always open the original with a small notice for visitors and never return a 404 error.', 'lingolane' ); ?></p>
                </div>
                <div class="lingolane-setting__control">
                    <label class="lingolane-switch">
                        <input type="checkbox" name="fallback_to_untranslated" value="1" <?php checked( $fallback ); ?>>
                        <span><strong><?php esc_html_e( 'Show untranslated posts in lists', 'lingolane' ); ?></strong>
                        <?php esc_html_e( 'Blog, archive and search pages in other languages will also list original posts that have no translation. Turn off to list translated posts only.', 'lingolane' ); ?></span>
                    </label>
                </div>
            </section>

            <section class="lingolane-card lingolane-setting">
                <div class="lingolane-setting__intro">
                    <h2><?php esc_html_e( 'Interface strings', 'lingolane' ); ?></h2>
                    <p><?php esc_html_e( 'Fixed text in themes and plugins (buttons, labels, messages). Translations entered in the Interface strings tab take priority over built-in ones.', 'lingolane' ); ?></p>
                </div>
                <div class="lingolane-setting__control">
                    <label class="lingolane-switch">
                        <input type="checkbox" name="theme_strings_enabled" value="1" <?php checked( $theme ); ?>>
                        <span><strong><?php esc_html_e( 'Translate theme text', 'lingolane' ); ?></strong><?php esc_html_e( 'Apply saved translations to your active theme.', 'lingolane' ); ?></span>
                    </label>
                    <label class="lingolane-switch">
                        <input type="checkbox" name="plugin_strings_enabled" value="1" <?php checked( $plugins ); ?>>
                        <span><strong><?php esc_html_e( 'Translate plugin text', 'lingolane' ); ?></strong><?php esc_html_e( 'Apply saved translations to forms and other plugins.', 'lingolane' ); ?></span>
                    </label>
                    <div class="lingolane-field">
                        <label for="lingolane-domains"><?php esc_html_e( 'Translate only these text domains', 'lingolane' ); ?></label>
                        <input id="lingolane-domains" type="text" name="allowed_domains" value="<?php echo esc_attr( $domains ); ?>" placeholder="<?php esc_attr_e( 'Leave empty = every domain with translations', 'lingolane' ); ?>" class="code" aria-describedby="lingolane-domains-help">
                        <small id="lingolane-domains-help"><?php esc_html_e( 'Example: my-theme, my-plugin. Separate with commas. Leave empty if unsure.', 'lingolane' ); ?></small>
                    </div>
                </div>
            </section>

            <div class="lingolane-savebar">
                <button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save settings', 'lingolane' ); ?></button>
            </div>
        </form>

        <section class="lingolane-card lingolane-setting">
            <div class="lingolane-setting__intro">
                <h2><?php esc_html_e( 'Tools', 'lingolane' ); ?></h2>
                <p><?php esc_html_e( 'Use after updating your theme or importing translations in bulk.', 'lingolane' ); ?></p>
            </div>
            <div class="lingolane-setting__control lingolane-tools">
                <div class="lingolane-tool">
                    <div><strong><?php esc_html_e( 'Find new strings in the theme', 'lingolane' ); ?></strong><span><?php esc_html_e( 'Reads the theme code and adds new text to the list to translate. Existing translations are not changed.', 'lingolane' ); ?></span></div>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lingolane_scan_theme_strings' ), 'lingolane_scan_theme_strings' ) ); ?>"><?php esc_html_e( 'Scan interface', 'lingolane' ); ?></a>
                </div>
                <div class="lingolane-tool">
                    <div><strong><?php esc_html_e( 'Clear cache', 'lingolane' ); ?></strong><span><?php esc_html_e( 'Deletes temporary translation data so the site reads it again immediately.', 'lingolane' ); ?></span></div>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lingolane_flush_cache' ), 'lingolane_flush_cache' ) ); ?>"><?php esc_html_e( 'Refresh', 'lingolane' ); ?></a>
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
            $relevant = in_array( $object->name, $selected, true ) || in_array( $object->name, $common, true ) || ( 'post_types' === $name && Lingolane_Shared::post_type( $object->name ) );
            $html = '<label><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $object->name ) . '" ' . checked( in_array( $object->name, $selected, true ), true, false ) . '> ' . esc_html( $object->labels->name ) . ( 'post_types' === $name && Lingolane_Shared::post_type( $object->name ) ? ' <em>' . esc_html__( 'shared', 'lingolane' ) . '</em>' : '' ) . '</label>';
            if ( $relevant ) { $main[] = $html; } else { $more[] = $html; }
        }
        echo '<fieldset><legend>' . esc_html( $legend ) . '</legend><div class="lingolane-checks">' . implode( '', $main ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        if ( $more ) {
            /* translators: %d: count */
            echo '<details class="lingolane-more"><summary>' . esc_html( sprintf( __( 'Other, rarely used types (%d)', 'lingolane' ), count( $more ) ) ) . '</summary><div class="lingolane-checks">' . implode( '', $more ) . '</div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</fieldset>';
    }

    /* ------------------------------------------------------------------ guide */

    private static function render_guide() {
        $code = Lingolane_Stats::other_languages() ? Lingolane_Stats::other_languages()[0]['code'] : 'en';
        $locations = get_registered_nav_menus();
        $location = $locations ? array_keys( $locations )[0] : 'primary';
        ?>
        <div class="lingolane-guide">
            <nav class="lingolane-card lingolane-guide__toc" aria-label="<?php esc_attr_e( 'Guide contents', 'lingolane' ); ?>">
                <h2 class="lingolane-card__title"><?php esc_html_e( 'Content', 'lingolane' ); ?></h2>
                <ol>
                    <li><a href="#translate"><?php esc_html_e( 'Translate a page', 'lingolane' ); ?></a></li>
                    <li><a href="#strings"><?php esc_html_e( 'Translate interface text', 'lingolane' ); ?></a></li>
                    <li><a href="#menus"><?php esc_html_e( 'Language-specific menus', 'lingolane' ); ?></a></li>
                    <li><a href="#switcher"><?php esc_html_e( 'Language switcher', 'lingolane' ); ?></a></li>
                    <li><a href="#shared"><?php esc_html_e( 'Shared records', 'lingolane' ); ?></a></li>
                    <li><a href="#seo"><?php esc_html_e( 'SEO and URLs', 'lingolane' ); ?></a></li>
                </ol>
            </nav>
            <div class="lingolane-guide__body">
                <section class="lingolane-card" id="translate">
                    <h2 class="lingolane-card__title"><?php esc_html_e( '1. Translate a page or post', 'lingolane' ); ?></h2>
                    <ol class="lingolane-steps">
                        <li><?php echo wp_kses( sprintf( /* translators: %s: link */ __( 'Open the %s tab and find the page to translate (filter by “No published translation” to see what is left).', 'lingolane' ), '<a href="' . esc_url( self::url( 'content', array( 'status' => 'missing' ) ) ) . '">' . esc_html__( 'Posts & pages', 'lingolane' ) . '</a>' ), array( 'a' => array( 'href' => true ) ) ); ?></li>
                        <li><?php esc_html_e( 'Click “Create translation” in the language column. A draft is created with all the content, images and terms of the original.', 'lingolane' ); ?></li>
                        <li><?php esc_html_e( 'Translate the title, content and slug in the editor, then click Publish.', 'lingolane' ); ?></li>
                    </ol>
                    <p class="lingolane-hint"><?php esc_html_e( 'You can also do this in the editor: the “Translations” box in the right column shows the post’s language and buttons for missing translations. The Language column in the Pages/Posts lists has the same buttons.', 'lingolane' ); ?></p>
                </section>

                <section class="lingolane-card" id="strings">
                    <h2 class="lingolane-card__title"><?php esc_html_e( '2. Translate interface text', 'lingolane' ); ?></h2>
                    <p><?php esc_html_e( 'Buttons, form labels and messages live in theme/plugin code, so they cannot be edited in the editor.', 'lingolane' ); ?></p>
                    <ol class="lingolane-steps">
                        <li><?php esc_html_e( 'In the Interface strings tab, filter by “Untranslated”.', 'lingolane' ); ?></li>
                        <li><?php esc_html_e( 'Type the translation, then click Save or press Ctrl/⌘ + Enter (it moves to the next string).', 'lingolane' ); ?></li>
                        <li><?php esc_html_e( 'Strings marked “Provided by theme” are already translated by the theme; only enter one to change the wording.', 'lingolane' ); ?></li>
                    </ol>
                    <p class="lingolane-hint"><?php esc_html_e( 'Bulk translate: export CSV, edit in Google Sheets/Excel, then import again. Columns: domain, context, source, language, translation.', 'lingolane' ); ?></p>
                </section>

                <section class="lingolane-card" id="menus">
                    <h2 class="lingolane-card__title"><?php esc_html_e( '3. Language-specific menus', 'lingolane' ); ?></h2>
                    <p><?php echo wp_kses( sprintf( /* translators: 1: example name */ __( 'Create a new menu under Appearance › Menus and name it using the pattern <code>location language-code</code>, for example %1$s. When visitors view that language, this menu replaces the original at the matching location.', 'lingolane' ), '<code>' . esc_html( $location . ' ' . $code ) . '</code>' ), array( 'code' => array() ) ); ?></p>
                    <?php if ( $locations ) : ?>
                        <p class="lingolane-hint"><?php echo esc_html( sprintf( /* translators: %s: list */ __( 'Theme menu locations: %s', 'lingolane' ), implode( ', ', array_keys( $locations ) ) ) ); ?></p>
                    <?php endif; ?>
                </section>

                <section class="lingolane-card" id="switcher">
                    <h2 class="lingolane-card__title"><?php esc_html_e( '4. Language switcher', 'lingolane' ); ?></h2>
                    <p><?php esc_html_e( 'To place a language switcher anywhere:', 'lingolane' ); ?></p>
                    <ul class="lingolane-steps">
                        <li><?php echo wp_kses( __( 'Shortcode: <code>[lingolane_switcher]</code>, dropdown style <code>[lingolane_switcher style="dropdown"]</code>, codes only <code>[lingolane_switcher show="code"]</code>.', 'lingolane' ), array( 'code' => array() ) ); ?></li>
                        <li><?php esc_html_e( 'Widget: Appearance › Widgets › “Select language”.', 'lingolane' ); ?></li>
                        <li><?php echo wp_kses( __( 'In a menu: add a Custom Link with the URL <code>#lingolane-switcher</code> and the label <code>{{lingolane}}</code>; it expands into one item per language.', 'lingolane' ), array( 'code' => array() ) ); ?></li>
                    </ul>
                    <p class="lingolane-hint"><?php esc_html_e( 'The switcher always links to the matching translation of the current page; untranslated pages link to the original with a notice.', 'lingolane' ); ?></p>
                </section>

                <section class="lingolane-card" id="shared">
                    <h2 class="lingolane-card__title"><?php esc_html_e( '5. Shared records and account pages', 'lingolane' ); ?></h2>
                    <p><?php esc_html_e( 'Records of shared post types, shared taxonomies and shared pages (for example account and checkout pages) are shown in every language, so visitors in any language can still find them. Fixed text on those pages is translated through Interface strings; content written by users stays unchanged.', 'lingolane' ); ?></p>
                </section>

                <section class="lingolane-card" id="seo">
                    <h2 class="lingolane-card__title"><?php esc_html_e( '6. SEO and URLs', 'lingolane' ); ?></h2>
                    <ul class="lingolane-steps">
                        <li><?php echo wp_kses( sprintf( /* translators: %s: example */ __( 'The default language has no prefix; other languages live in their own directory, for example %s.', 'lingolane' ), '<code>' . esc_html( wp_parse_url( Lingolane_Urls::home( $code ), PHP_URL_PATH ) ) . '</code>' ), array( 'code' => array() ) ); ?></li>
                        <li><?php esc_html_e( 'Every translated page automatically gets hreflang, x-default and canonical tags in the right language; Yoast SEO uses the same values.', 'lingolane' ); ?></li>
                        <li><?php esc_html_e( 'Internal links inside content automatically switch to the matching translation when one exists.', 'lingolane' ); ?></li>
                    </ul>
                </section>
            </div>
        </div>
        <?php
    }
}
