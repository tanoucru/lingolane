<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WordPress.org language packs for the site's languages. Only the locales the site uses are downloaded:
 * core right away when a language is added, then the translations of installed themes and plugins.
 * Later updates come through WordPress's own translation updates, which this class extends to every
 * site language (core only checks the admin languages).
 */
class Lingolane_Packs {
    public static function init() {
        add_filter( 'plugins_update_check_locales', array( __CLASS__, 'update_locales' ) );
        add_filter( 'themes_update_check_locales', array( __CLASS__, 'update_locales' ) );
    }

    /** Locales the site serves visitors in, without en_US (WordPress's built-in language). */
    public static function site_locales() {
        $locales = array();
        foreach ( Lingolane_Languages::all() as $language ) {
            if ( 'en_US' !== $language['locale'] ) { $locales[] = $language['locale']; }
        }
        return array_values( array_unique( $locales ) );
    }

    public static function update_locales( $locales ) {
        return array_values( array_unique( array_merge( (array) $locales, self::site_locales() ) ) );
    }

    public static function installed( $locale ) {
        return 'en_US' === $locale || in_array( $locale, get_available_languages(), true );
    }

    /**
     * Downloads the core pack for a locale, then the theme and plugin translations WordPress.org has for it.
     *
     * @return true|WP_Error True when the core pack is in place (theme/plugin translations are best effort).
     */
    public static function install( $locale ) {
        $pre = apply_filters( 'lingolane_pre_install_language_pack', null, $locale );
        if ( null !== $pre ) { return $pre; }
        if ( 'en_US' === $locale ) { return true; }
        if ( ! current_user_can( 'install_languages' ) ) {
            return new WP_Error( 'lingolane_pack_cap', __( 'Your account cannot install translations. An administrator can install them in Dashboard > Updates.', 'lingolane' ) );
        }
        require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! self::installed( $locale ) ) {
            if ( ! wp_can_install_language_pack() ) {
                return new WP_Error( 'lingolane_pack_fs', __( 'WordPress cannot write translation files on this server (file permissions or DISALLOW_FILE_MODS). Translations will be installed by the next automatic update if possible, or from Dashboard > Updates.', 'lingolane' ) );
            }
            if ( ! wp_download_language_pack( $locale ) ) {
                /* translators: %s: WordPress locale, for example fr_FR */
                return new WP_Error( 'lingolane_pack_download', sprintf( __( 'WordPress.org has no translation for %s, or it could not be downloaded. Check the locale or try again later.', 'lingolane' ), $locale ) );
            }
        }
        self::install_extensions( $locale );
        return true;
    }

    /** Theme and plugin translations for one locale. Failures are left to WordPress's automatic updates. */
    private static function install_extensions( $locale ) {
        wp_clean_update_cache();
        wp_update_plugins();
        wp_update_themes();
        $updates = array_values( array_filter( wp_get_translation_updates(), function ( $update ) use ( $locale ) {
            return isset( $update->language, $update->type ) && $update->language === $locale && 'core' !== $update->type;
        } ) );
        if ( ! $updates ) { return; }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
        $upgrader->bulk_upgrade( $updates, array( 'clear_update_cache' => true ) );
    }
}
