<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class TML_Install {
    const DB_VERSION = '0.4.0';

    public static function maybe_upgrade() {
        if ( get_option( 'tml_db_version' ) !== self::DB_VERSION ) { self::activate(); }
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // Identifiers derive solely from WordPress; dbDelta needs literal DDL, not value placeholders.
        $table = $wpdb->prefix . 'tml_translations';
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
        $strings = $wpdb->prefix . 'tml_strings';
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
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $strings ) ) ) === $strings && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
            update_option( 'tml_db_version', self::DB_VERSION );
        }
        add_option( 'tml_settings', TML_Languages::defaults() );
    }
}
