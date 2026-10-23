<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Lingolane_Install {
    const DB_VERSION = '0.5.0';

    /** Adopts data written by the pre-release "tml_" builds (tables and settings), once. */
    public static function migrate_legacy() {
        global $wpdb;
        foreach ( array( 'translations', 'strings' ) as $name ) {
            $old = $wpdb->prefix . 'tml_' . $name;
            $new = $wpdb->prefix . 'lingolane_' . $name;
            $has_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) === $old;
            $has_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new ) ) ) === $new;
            if ( $has_old && ! $has_new ) {
                $wpdb->query( "RENAME TABLE `$old` TO `$new`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers come from $wpdb->prefix.
            }
        }
        $settings = get_option( 'tml_settings', null );
        if ( is_array( $settings ) && false === get_option( 'lingolane_settings', false ) ) {
            add_option( 'lingolane_settings', $settings );
        }
    }

    public static function maybe_upgrade() {
        if ( get_option( 'lingolane_db_version' ) !== self::DB_VERSION ) { self::activate(); }
    }

    public static function verify_tables( $tables ) {
        global $wpdb;
        foreach ( (array) $tables as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                return false;
            }
        }
        return true;
    }

    public static function activate() {
        global $wpdb;
        self::migrate_legacy();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // Identifiers derive solely from WordPress; dbDelta needs literal DDL, not value placeholders.
        $table = $wpdb->prefix . 'lingolane_translations';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            element_type varchar(40) NOT NULL,
            element_id bigint(20) unsigned NOT NULL,
            trid bigint(20) unsigned NOT NULL,
            language_code varchar(7) NOT NULL,
            source_language_code varchar(7) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY element (element_type,element_id),
            KEY trid (trid),
            KEY language_code (language_code)
        ) $charset;" );
        $strings = $wpdb->prefix . 'lingolane_strings';
        dbDelta( "CREATE TABLE $strings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            domain varchar(100) NOT NULL,
            context varchar(100) DEFAULT NULL,
            source_hash char(32) NOT NULL,
            source longtext NOT NULL,
            language_code varchar(7) NOT NULL,
            translation longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY string_language (domain,source_hash,language_code)
        ) $charset;" );
        $locations = $wpdb->prefix . 'lingolane_string_locations';
        dbDelta( "CREATE TABLE $locations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            domain varchar(100) NOT NULL,
            source_hash char(32) NOT NULL,
            occurrence_hash char(64) NOT NULL,
            group_key varchar(20) NOT NULL,
            subgroup varchar(100) NOT NULL DEFAULT '',
            provider varchar(30) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            field varchar(100) NOT NULL DEFAULT '',
            page_path text NOT NULL,
            locator text NOT NULL,
            supported tinyint(1) NOT NULL DEFAULT 1,
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY domain_source_occ (domain,source_hash,occurrence_hash),
            KEY group_subgroup (group_key,subgroup),
            KEY group_domain_source (group_key,domain,source_hash)
        ) $charset;" );
        if ( self::verify_tables( array( $table, $strings, $locations ) ) ) {
            update_option( 'lingolane_db_version', self::DB_VERSION );
        }
        add_option( 'lingolane_settings', Lingolane_Languages::defaults() );
    }
}
