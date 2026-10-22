<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Support tab: a ticket composer and a system report for the WordPress.org forum.
 * Nothing is sent from the site; the user copies the text and posts it in the forum.
 */
class Lingolane_Support {
    const FORUM    = 'https://wordpress.org/support/plugin/lingolane/';
    const SECURITY = 'https://github.com/tanoucru/lingolane/security/advisories/new';

    /** Plugin folders known to clash with Lingolane: folder => [name, multilingual|cache]. */
    const KNOWN = array(
        'polylang'                    => array( 'Polylang', 'multilingual' ),
        'polylang-pro'                => array( 'Polylang Pro', 'multilingual' ),
        'sitepress-multilingual-cms'  => array( 'WPML', 'multilingual' ),
        'translatepress-multilingual' => array( 'TranslatePress', 'multilingual' ),
        'weglot'                      => array( 'Weglot', 'multilingual' ),
        'gtranslate'                  => array( 'GTranslate', 'multilingual' ),
        'multilingualpress'           => array( 'MultilingualPress', 'multilingual' ),
        'wp-rocket'                   => array( 'WP Rocket', 'cache' ),
        'litespeed-cache'             => array( 'LiteSpeed Cache', 'cache' ),
        'w3-total-cache'              => array( 'W3 Total Cache', 'cache' ),
        'wp-super-cache'              => array( 'WP Super Cache', 'cache' ),
        'wp-fastest-cache'            => array( 'WP Fastest Cache', 'cache' ),
    );

    public static function init() {
        add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
    }

    public static function forum_url() {
        return (string) apply_filters( 'lingolane_support_url', self::FORUM );
    }

    public static function security_url() {
        return (string) apply_filters( 'lingolane_security_url', self::SECURITY );
    }

    /** "Support" link under the plugin description on the Plugins screen. */
    public static function row_meta( $links, $file ) {
        if ( plugin_basename( LINGOLANE_FILE ) !== $file ) { return $links; }
        $links[] = '<a href="' . esc_url( Lingolane_Admin::url( 'support' ) ) . '">' . esc_html__( 'Support', 'lingolane' ) . '</a>';
        return $links;
    }

    /** Active plugins that clash with Lingolane: list of [name, type]. */
    public static function conflicts() {
        $found = array();
        foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
            $folder = is_string( $file ) ? strtok( $file, '/' ) : '';
            if ( isset( self::KNOWN[ $folder ] ) ) { $found[ $folder ] = self::KNOWN[ $folder ]; }
        }
        return array_values( $found );
    }

    private static function conflict_names( $type ) {
        $names = array();
        foreach ( self::conflicts() as $item ) {
            if ( $type === $item[1] ) { $names[] = $item[0]; }
        }
        return $names;
    }

    /**
     * Plain-text environment report. Labels stay in English so forum volunteers can read every report.
     * Never includes the site address, emails or user names.
     */
    public static function report() {
        global $wpdb;
        if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $yes = function ( $value ) { return $value ? 'yes' : 'no'; };
        $list = function ( $items ) { return $items ? implode( ', ', $items ) : '-'; };
        $path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        $settings = get_option( 'lingolane_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $theme = wp_get_theme();
        $parent = $theme->parent();

        $plugins = array();
        $installed = get_plugins();
        foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
            if ( isset( $installed[ $file ] ) ) { $plugins[] = $installed[ $file ]['Name'] . ' ' . $installed[ $file ]['Version']; }
        }

        $languages = array();
        foreach ( Lingolane_Languages::all() as $language ) {
            $languages[] = $language['code'] . ' (' . $language['locale'] . ') ' . $language['name'];
        }

        $sections = array(
            'Environment' => array(
                'Lingolane'              => LINGOLANE_VERSION,
                'WordPress'              => get_bloginfo( 'version' ),
                'PHP'                    => PHP_VERSION,
                'Database server'        => $wpdb->db_version(),
                'Site language'          => get_locale(),
                'Multisite'              => $yes( is_multisite() ),
                'Installed in subfolder' => $yes( '' !== $path ),
                'Permalinks'             => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'Plain',
                'HTTPS'                  => $yes( is_ssl() || 0 === strpos( home_url(), 'https://' ) ),
                'WP_DEBUG'               => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'on' : 'off',
                'Memory limit'           => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ini_get( 'memory_limit' ),
            ),
            'Theme' => array(
                'Active theme' => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
                'Parent theme' => $parent ? $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) : '-',
                'Block theme'  => $yes( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ),
            ),
            'Lingolane settings' => array(
                'Source language'       => Lingolane_Languages::source_code(),
                'Default language'      => Lingolane_Languages::default_code(),
                'Languages'             => implode( '; ', $languages ),
                'URL mode'              => isset( $settings['url_mode'] ) && is_string( $settings['url_mode'] ) ? $settings['url_mode'] : 'directory',
                'Post types'            => $list( Lingolane_Query::post_types() ),
                'Taxonomies'            => $list( Lingolane_Query::taxonomies() ),
                'Untranslated fallback' => $yes( ! empty( $settings['fallback_to_untranslated'] ) ),
                'Theme strings'         => $yes( ! empty( $settings['theme_strings_enabled'] ) ),
                'Plugin strings'        => $yes( ! empty( $settings['plugin_strings_enabled'] ) ),
            ),
            'Possible conflicts' => array(
                'Multilingual plugins' => self::conflict_names( 'multilingual' ) ? implode( ', ', self::conflict_names( 'multilingual' ) ) : 'none detected',
                'Page cache plugins'   => self::conflict_names( 'cache' ) ? implode( ', ', self::conflict_names( 'cache' ) ) : 'none detected',
            ),
        );

        $lines = array();
        foreach ( $sections as $title => $rows ) {
            $lines[] = '### ' . $title;
            foreach ( $rows as $key => $value ) { $lines[] = $key . ': ' . $value; }
            $lines[] = '';
        }
        $lines[] = '### Active plugins (' . count( $plugins ) . ')';
        $lines   = array_merge( $lines, $plugins ? $plugins : array( '-' ) );

        return (string) apply_filters( 'lingolane_support_report', implode( "\n", $lines ) );
    }

    /* ------------------------------------------------------------------ screen */

    public static function render() {
        $multilingual = self::conflict_names( 'multilingual' );
        $cache = self::conflict_names( 'cache' );
        $topics = array(
            'bug'           => __( 'Bug', 'lingolane' ),
            'compatibility' => __( 'Compatibility with a theme or plugin', 'lingolane' ),
            'question'      => __( 'How-to question', 'lingolane' ),
            'feature'       => __( 'Feature request', 'lingolane' ),
        );
        ?>
        <div class="lingolane-support">
            <div class="lingolane-support__body">
                <section class="lingolane-card" aria-labelledby="lingolane-support-before">
                    <h2 class="lingolane-card__title" id="lingolane-support-before"><?php esc_html_e( 'Before you open a ticket', 'lingolane' ); ?></h2>
                    <?php if ( $multilingual ) : ?>
                        <p class="lingolane-banner lingolane-banner--warn"><?php echo Lingolane_UI::icon( 'triangle-alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?>
                            <span><?php /* translators: %s: plugin names */ echo esc_html( sprintf( __( 'Also active: %s. Running two multilingual plugins at once is not supported; deactivate one and test again.', 'lingolane' ), implode( ', ', $multilingual ) ) ); ?></span>
                        </p>
                    <?php endif; ?>
                    <?php if ( $cache ) : ?>
                        <p class="lingolane-banner"><?php echo Lingolane_UI::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?>
                            <span><?php /* translators: %s: plugin names */ echo esc_html( sprintf( __( 'Page cache detected (%s). Purge it after changing languages or translations, then check again.', 'lingolane' ), implode( ', ', $cache ) ) ); ?></span>
                        </p>
                    <?php endif; ?>
                    <ul class="lingolane-checklist">
                        <li class="is-info"><?php echo Lingolane_UI::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?>
                            <div><strong><?php esc_html_e( 'Read the guide', 'lingolane' ); ?></strong><span><?php echo wp_kses( sprintf( /* translators: %s: link */ __( 'Most questions are answered in the %s.', 'lingolane' ), '<a href="' . esc_url( Lingolane_Admin::url( 'guide' ) ) . '">' . esc_html__( 'Guide tab', 'lingolane' ) . '</a>' ), array( 'a' => array( 'href' => true ) ) ); ?></span></div>
                        </li>
                        <li class="is-info"><?php echo Lingolane_UI::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?>
                            <div><strong><?php esc_html_e( 'Update to the latest version', 'lingolane' ); ?></strong><span><?php /* translators: %s: version */ echo esc_html( sprintf( __( 'You are running Lingolane %s. The problem may already be fixed.', 'lingolane' ), LINGOLANE_VERSION ) ); ?></span></div>
                        </li>
                        <li class="is-info"><?php echo Lingolane_UI::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?>
                            <div><strong><?php esc_html_e( 'Clear the Lingolane cache', 'lingolane' ); ?></strong><span><?php esc_html_e( 'Translations are cached; clearing makes the site read them again.', 'lingolane' ); ?></span>
                                <a class="button lingolane-support__inline" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lingolane_flush_cache' ), 'lingolane_flush_cache' ) ); ?>"><?php esc_html_e( 'Clear cache', 'lingolane' ); ?></a>
                            </div>
                        </li>
                        <li class="is-info"><?php echo Lingolane_UI::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?>
                            <div><strong><?php esc_html_e( 'Rule out a conflict', 'lingolane' ); ?></strong><span><?php esc_html_e( 'If possible, switch to a default theme and deactivate other plugins on a staging copy. Say in the ticket whether the problem remains.', 'lingolane' ); ?></span></div>
                        </li>
                    </ul>
                </section>

                <section class="lingolane-card" aria-labelledby="lingolane-support-ticket">
                    <h2 class="lingolane-card__title" id="lingolane-support-ticket"><?php esc_html_e( 'Write your ticket', 'lingolane' ); ?></h2>
                    <form class="lingolane-support__form" data-lingolane-ticket novalidate>
                        <div class="lingolane-support__row">
                            <div class="lingolane-field">
                                <label for="lingolane-ticket-topic"><?php esc_html_e( 'Topic', 'lingolane' ); ?></label>
                                <select id="lingolane-ticket-topic" name="topic">
                                    <?php foreach ( $topics as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="lingolane-field lingolane-field--grow">
                                <label for="lingolane-ticket-title"><?php esc_html_e( 'Title', 'lingolane' ); ?> <span aria-hidden="true">*</span></label>
                                <input type="text" id="lingolane-ticket-title" name="title" maxlength="120" required aria-required="true" placeholder="<?php esc_attr_e( 'Example: Language switcher missing on the shop page', 'lingolane' ); ?>">
                            </div>
                        </div>
                        <div class="lingolane-field">
                            <label for="lingolane-ticket-what"><?php esc_html_e( 'What happened?', 'lingolane' ); ?> <span aria-hidden="true">*</span></label>
                            <textarea id="lingolane-ticket-what" name="what" rows="4" required aria-required="true"></textarea>
                        </div>
                        <div class="lingolane-field">
                            <label for="lingolane-ticket-steps"><?php esc_html_e( 'Steps to reproduce', 'lingolane' ); ?></label>
                            <textarea id="lingolane-ticket-steps" name="steps" rows="3" placeholder="<?php esc_attr_e( "1. Open …\n2. Click …", 'lingolane' ); ?>"></textarea>
                        </div>
                        <div class="lingolane-field">
                            <label for="lingolane-ticket-expected"><?php esc_html_e( 'What did you expect?', 'lingolane' ); ?></label>
                            <textarea id="lingolane-ticket-expected" name="expected" rows="2"></textarea>
                        </div>
                        <div class="lingolane-field">
                            <label for="lingolane-ticket-page"><?php esc_html_e( 'Page where it happens (optional)', 'lingolane' ); ?></label>
                            <input type="url" id="lingolane-ticket-page" name="page" inputmode="url" placeholder="https://" aria-describedby="lingolane-ticket-page-note">
                            <small id="lingolane-ticket-page-note"><?php esc_html_e( 'The forum is public. Leave this empty if the address should stay private.', 'lingolane' ); ?></small>
                        </div>
                        <label class="lingolane-support__check"><input type="checkbox" name="report" value="1" checked> <?php esc_html_e( 'Include the system report', 'lingolane' ); ?></label>

                        <div class="lingolane-field">
                            <label for="lingolane-ticket-preview"><?php esc_html_e( 'Ticket preview', 'lingolane' ); ?></label>
                            <textarea id="lingolane-ticket-preview" class="lingolane-support__code" rows="10" readonly></textarea>
                        </div>
                        <p class="lingolane-support__msg" data-lingolane-ticket-msg role="status"></p>
                        <div class="lingolane-support__actions">
                            <button type="button" class="button" data-lingolane-copy="lingolane-ticket-preview"><?php esc_html_e( 'Copy ticket', 'lingolane' ); ?></button>
                            <a class="button button-primary" href="<?php echo esc_url( self::forum_url() ); ?>" target="_blank" rel="noopener noreferrer" data-lingolane-forum>
                                <?php esc_html_e( 'Copy and open the support forum', 'lingolane' ); ?><?php echo Lingolane_UI::icon( 'external-link', 16, __( '(opens in a new tab)', 'lingolane' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?>
                            </a>
                        </div>
                        <p class="lingolane-hint"><?php esc_html_e( 'In the forum, choose “Create Topic”: paste the title into the Title field and the copied text into the message. Nothing is sent from this site.', 'lingolane' ); ?></p>
                    </form>
                </section>

                <section class="lingolane-card" aria-labelledby="lingolane-support-report">
                    <h2 class="lingolane-card__title" id="lingolane-support-report"><?php esc_html_e( 'System report', 'lingolane' ); ?></h2>
                    <label class="screen-reader-text" for="lingolane-report"><?php esc_html_e( 'System report', 'lingolane' ); ?></label>
                    <textarea id="lingolane-report" class="lingolane-support__code" rows="14" readonly><?php echo esc_textarea( self::report() ); ?></textarea>
                    <div class="lingolane-support__actions">
                        <button type="button" class="button" data-lingolane-copy="lingolane-report"><?php esc_html_e( 'Copy report', 'lingolane' ); ?></button>
                        <button type="button" class="button" data-lingolane-download="lingolane-report"><?php echo Lingolane_UI::icon( 'download', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?><?php esc_html_e( 'Download .txt', 'lingolane' ); ?></button>
                    </div>
                    <p class="lingolane-hint"><?php esc_html_e( 'Includes versions, theme, active plugins and Lingolane settings. It does not include passwords, email addresses, user names or your site address.', 'lingolane' ); ?></p>
                </section>
            </div>

            <aside class="lingolane-support__aside">
                <section class="lingolane-card" aria-labelledby="lingolane-support-channels">
                    <h2 class="lingolane-card__title" id="lingolane-support-channels"><?php esc_html_e( 'Support channels', 'lingolane' ); ?></h2>
                    <ul class="lingolane-support__links">
                        <li><?php echo Lingolane_UI::icon( 'users', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?>
                            <a href="<?php echo esc_url( self::forum_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support forum on WordPress.org', 'lingolane' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lingolane' ); ?></span></a>
                        </li>
                        <li><?php echo Lingolane_UI::icon( 'life-buoy', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?>
                            <a href="<?php echo esc_url( Lingolane_Admin::url( 'guide' ) ); ?>"><?php esc_html_e( 'Guide', 'lingolane' ); ?></a>
                        </li>
                    </ul>
                    <p class="lingolane-hint"><?php esc_html_e( 'Support is given by volunteers in the public forum. Please do not post passwords or private data.', 'lingolane' ); ?></p>
                </section>
                <section class="lingolane-card" aria-labelledby="lingolane-support-security">
                    <h2 class="lingolane-card__title" id="lingolane-support-security"><?php esc_html_e( 'Security issue?', 'lingolane' ); ?></h2>
                    <p class="lingolane-hint"><?php esc_html_e( 'Do not post security problems in the public forum. Report them privately so they can be fixed before details are shared.', 'lingolane' ); ?></p>
                    <a class="button" href="<?php echo esc_url( self::security_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Report privately', 'lingolane' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lingolane' ); ?></span></a>
                </section>
            </aside>
        </div>
        <?php
    }
}
