<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Lingolane_Strings_Admin {
    const PER_PAGE = 30;

    public static function init() {
        add_action( 'admin_post_lingolane_strings', array( __CLASS__, 'handle' ) );
        add_action( 'admin_post_lingolane_save_identity', array( __CLASS__, 'handle_save_identity' ) );
        add_action( 'admin_post_lingolane_save_term_translation', array( __CLASS__, 'handle_save_term_translation' ) );
        add_action( 'admin_post_lingolane_scan_theme_strings', array( __CLASS__, 'handle_scan_theme_strings' ) );
        add_action( 'wp_ajax_lingolane_save_string', array( __CLASS__, 'ajax_save' ) );
        add_action( 'wp_ajax_lingolane_save_identity', array( __CLASS__, 'ajax_save_identity' ) );
        add_action( 'wp_ajax_lingolane_save_term_translation', array( __CLASS__, 'ajax_save_term_translation' ) );
        add_action( 'wp_ajax_lingolane_delete_string', array( __CLASS__, 'ajax_delete' ) );
    }

    private static function input( $data, $key ) {
        return isset( $data[ $key ] ) && ( is_string( $data[ $key ] ) || is_numeric( $data[ $key ] ) ) ? sanitize_text_field( wp_unslash( (string) $data[ $key ] ) ) : '';
    }

    public static function theme_domain() {
        $domain = wp_get_theme( get_template() )->get( 'TextDomain' );
        return is_string( $domain ) && '' !== $domain ? $domain : get_template();
    }

    private static function form( $operation, $class = '' ) {
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
        echo '<input type="hidden" name="action" value="lingolane_strings"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
        wp_nonce_field( 'lingolane_strings_' . $operation );
    }

    /* ------------------------------------------------------------------ requests */

    public static function handle_save_identity() {
        if ( ! Lingolane_Admin::can_translate() ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'lingolane' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'lingolane_strings_save_identity' );
        $domain      = self::input( $_POST, 'domain' );
        $source      = isset( $_POST['source'] ) ? wp_unslash( (string) $_POST['source'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $context     = self::input( $_POST, 'context' );
        $lang_code   = self::input( $_POST, 'language_code' );
        $translation = isset( $_POST['translation'] ) ? wp_unslash( (string) $_POST['translation'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        $result = self::save_identity( $domain, $source, $context, $lang_code, $translation );
        if ( is_wp_error( $result ) ) {
            Lingolane_Admin::flash( 'error', $result->get_error_message() );
        } else {
            Lingolane_Admin::flash( 'success', __( 'Translation saved.', 'lingolane' ) );
        }

        if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
            Lingolane_Admin::back( 'strings' );
        }
        return $result;
    }

    public static function handle_scan_theme_strings() {
        if ( ! Lingolane_Admin::can_translate() ) {
            wp_die( esc_html__( 'Forbidden', 'lingolane' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'lingolane_scan_theme_strings' );
        self::scan_theme_strings();
        $referer = wp_get_referer();
        wp_safe_redirect( $referer ? $referer : Lingolane_Admin::url( 'strings' ) );
        exit;
    }

    public static function handle_save_term_translation() {
        if ( ! Lingolane_Admin::can_translate() ) {
            wp_die( esc_html__( 'Forbidden', 'lingolane' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'lingolane_terms_save_term' );
        $source_id   = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
        $taxonomy    = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
        $language    = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
        $name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

        $res = self::save_term_translation( $source_id, $taxonomy, $language, $name, $description );
        if ( is_wp_error( $res ) ) {
            wp_die( esc_html( $res->get_error_message() ) );
        }
        $referer = wp_get_referer();
        wp_safe_redirect( $referer ? $referer : Lingolane_Admin::url( 'strings' ) );
        exit;
    }

    public static function save_identity( $domain, $source, $context, $language_code, $translation ) {
        if ( ! is_string( $domain ) || '' === $domain || ! is_string( $source ) || '' === $source ) {
            return new WP_Error( 'invalid_identity', __( 'Domain and source cannot be empty.', 'lingolane' ) );
        }
        $result = Lingolane_Strings::save( $domain, $source, (string) $context, $language_code, trim( (string) $translation ) );
        if ( ! is_wp_error( $result ) ) {
            Lingolane_String_Locations::invalidate();
            Lingolane_Stats::flush_strings();
        }
        return $result;
    }

    public static function save_term_translation( $source_id, $taxonomy, $language, $name, $description = '' ) {
        return Lingolane_String_Terms::save( $source_id, $taxonomy, $language, $name, $description );
    }

    public static function get_groups_data( $language ) {
        $groups = Lingolane_String_Locations::groups( $language );
        if ( isset( $groups['terms'] ) ) {
            $term_prog = Lingolane_String_Terms::progress( $language );
            $groups['terms']['total']       = (int) $term_prog['total'];
            $groups['terms']['own']         = (int) $term_prog['completed'];
            $groups['terms']['missing']     = max( 0, $term_prog['total'] - $term_prog['completed'] );
            $groups['terms']['unsupported'] = 0;
            $groups['terms']['percent']     = (int) $term_prog['percent'];
        }
        return $groups;
    }

    public static function page_data( $group, $subgroup, $language, $status = 'all', $keyword = '', $page = 1, $per_page = 30 ) {
        if ( 'terms' === $group ) {
            $taxonomy = ! empty( $subgroup ) ? $subgroup : 'category';
            $res = Lingolane_String_Terms::page( $taxonomy, $language, $keyword, $page, $per_page );
            $items = array();
            $other_langs = array_values( array_diff( Lingolane_Languages::codes(), array( Lingolane_Languages::default_code() ) ) );
            foreach ( $res['items'] as $term_item ) {
                $translations = array();
                foreach ( $other_langs as $code ) {
                    if ( $code === $language ) {
                        $translations[ $code ] = $term_item['translated_name'];
                    } else {
                        $tid = Lingolane_Elements::get_translation( 'tax_' . $taxonomy, $term_item['id'], $code );
                        if ( $tid && (int) $tid !== (int) $term_item['id'] ) {
                            $t_term = get_term( (int) $tid, $taxonomy );
                            $translations[ $code ] = ( $t_term && ! is_wp_error( $t_term ) ) ? $t_term->name : '';
                        } else {
                            $translations[ $code ] = '';
                        }
                    }
                }
                $items[] = array(
                    'id'           => $term_item['id'],
                    'domain'       => 'lingolane-terms',
                    'source'       => $term_item['name'],
                    'context'      => $taxonomy,
                    'description'  => $term_item['description'],
                    'trans_desc'   => $term_item['translated_description'],
                    'translations' => $translations,
                    'supported'    => 1,
                    'occurrences'  => array(),
                );
            }
            return array( 'items' => $items, 'total' => $res['total'] );
        }

        $res = Lingolane_String_Locations::page( $group, $subgroup, $language, $status, $keyword, $page, $per_page );
        $items = array();
        $other_langs = array_values( array_diff( Lingolane_Languages::codes(), array( Lingolane_Languages::default_code() ) ) );

        foreach ( $res['items'] as $row ) {
            $translations = array();
            foreach ( $other_langs as $code ) {
                if ( $code === $language ) {
                    $translations[ $code ] = isset( $row['translation'] ) ? (string) $row['translation'] : '';
                } else {
                    $translations[ $code ] = (string) Lingolane_Strings::get( $row['domain'], $row['source'], (string) $row['context'], $code );
                }
            }
            $row['translations'] = $translations;
            $row['occurrences']  = Lingolane_String_Locations::occurrences( $row['domain'], $row['source_hash'] );
            $items[] = $row;
        }

        return array( 'items' => $items, 'total' => $res['total'] );
    }

    public static function handle() {
        global $wpdb;
        if ( ! Lingolane_Admin::can_translate() ) { wp_die( esc_html__( 'You do not have permission to perform this action.', 'lingolane' ), '', array( 'response' => 403 ) ); }
        $operation = self::input( $_POST, 'operation' ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( 'save_identity' === $operation ) {
            self::handle_save_identity();
            return;
        }
        if ( ! in_array( $operation, array( 'row', 'import', 'export' ), true ) ) { Lingolane_Admin::redirect( 'strings' ); }
        if ( ( 'import' === $operation || 'export' === $operation ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'lingolane' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'lingolane_strings_' . $operation );
        $table = $wpdb->prefix . 'lingolane_strings';

        if ( 'export' === $operation ) {
            $rows = $wpdb->get_results( "SELECT domain, context, source, language_code, translation FROM $table ORDER BY domain, id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
            nocache_headers();
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="lingolane-strings-' . gmdate( 'Y-m-d' ) . '.csv"' );
            $stream = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            fwrite( $stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- BOM so Excel opens UTF-8 correctly.
            fputcsv( $stream, array( 'domain', 'context', 'source', 'language', 'translation' ), ',', '"', '' );
            foreach ( (array) $rows as $row ) {
                // Reversible formula protection for spreadsheet applications.
                foreach ( $row as &$cell ) {
                    if ( is_string( $cell ) && preg_match( "/^(?:['=+@\\-\\t\\r\\n]|\\s+[=+@\\-])/", $cell ) ) { $cell = "'" . $cell; }
                }
                unset( $cell );
                fputcsv( $stream, array_values( $row ), ',', '"', '' );
            }
            fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            exit;
        }

        if ( 'row' === $operation ) {
            $id = absint( self::input( $_POST, 'id' ) ); // phpcs:ignore WordPress.Security.NonceVerification
            $delete = isset( $_POST['delete'] ); // phpcs:ignore WordPress.Security.NonceVerification
            if ( $delete && ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to delete strings.', 'lingolane' ), '', array( 'response' => 403 ) );
            }
            $result = $delete ? self::delete_row( $id ) : self::save_row( $id, isset( $_POST['translation'] ) && is_string( $_POST['translation'] ) ? wp_unslash( $_POST['translation'] ) : null ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            if ( is_wp_error( $result ) ) {
                Lingolane_Admin::flash( 'error', $result->get_error_message() );
            } else {
                Lingolane_Admin::flash( 'success', $delete ? __( 'String deleted.', 'lingolane' ) : __( 'Translation saved.', 'lingolane' ) );
            }
            Lingolane_Admin::back( 'strings' );
        }

        $rows = self::read_csv();
        if ( is_wp_error( $rows ) ) {
            Lingolane_Admin::flash( 'error', $rows->get_error_message() );
            Lingolane_Admin::back( 'strings' );
        }
        $saved = 0;
        foreach ( $rows as $row ) {
            $result = Lingolane_Strings::save( $row[0], $row[2], $row[1], $row[3], $row[4] );
            if ( is_wp_error( $result ) ) {
                /* translators: 1: rows saved before the error, 2: error */
                Lingolane_Admin::flash( 'error', sprintf( __( 'Imported %1$d rows, then stopped: %2$s', 'lingolane' ), $saved, $result->get_error_message() ) );
                Lingolane_Admin::back( 'strings' );
            }
            ++$saved;
        }
        Lingolane_Stats::flush_strings();
        /* translators: %d: rows */
        Lingolane_Admin::flash( 'success', sprintf( __( 'Imported %d translations.', 'lingolane' ), $saved ) );
        Lingolane_Admin::redirect( 'strings' );
    }

    private static function find( $id ) {
        global $wpdb;
        return $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lingolane_strings WHERE id = %d", $id ) ) : null;
    }

    private static function save_row( $id, $translation ) {
        $row = self::find( $id );
        if ( ! $row || ! is_string( $translation ) ) { return new WP_Error( 'lingolane_string', __( 'This string was not found. Reload the page and try again.', 'lingolane' ) ); }
        $result = Lingolane_Strings::save( $row->domain, $row->source, (string) $row->context, $row->language_code, trim( $translation ) );
        if ( ! is_wp_error( $result ) ) { Lingolane_Stats::flush_strings(); }
        return is_wp_error( $result ) ? $result : $row;
    }

    private static function delete_row( $id ) {
        global $wpdb;
        $row = self::find( $id );
        if ( ! $row ) { return new WP_Error( 'lingolane_string', __( 'This string has already been deleted.', 'lingolane' ) ); }
        // A string is registered once per language; remove it everywhere so it does not reappear half-listed.
        $wpdb->delete( $wpdb->prefix . 'lingolane_strings', array( 'domain' => $row->domain, 'source_hash' => $row->source_hash ), array( '%s', '%s' ) );
        Lingolane_Strings::flush_cache();
        Lingolane_Stats::flush_strings();
        return true;
    }

    private static function ajax_guard( $cap = 'lingolane_translate' ) {
        $can = 'lingolane_translate' === $cap ? Lingolane_Admin::can_translate() : current_user_can( $cap );
        if ( ! $can || ! check_ajax_referer( 'lingolane_strings_ajax', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page.', 'lingolane' ) ), 403 );
        }
    }

    public static function ajax_save() {
        self::ajax_guard( 'lingolane_translate' );
        $translation = isset( $_POST['translation'] ) && is_string( $_POST['translation'] ) ? wp_unslash( $_POST['translation'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        if ( null === $translation ) {
            wp_send_json_error( array( 'message' => __( 'Missing translation.', 'lingolane' ) ), 400 );
        }

        if ( isset( $_POST['domain'], $_POST['source'], $_POST['language_code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $domain   = sanitize_text_field( wp_unslash( (string) $_POST['domain'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
            $source   = wp_unslash( (string) $_POST['source'] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            $context  = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['context'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
            $language = sanitize_key( wp_unslash( (string) $_POST['language_code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
            $result   = self::save_identity( $domain, $source, $context, $language, $translation );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
            }
            $saved = trim( (string) $translation );
            wp_send_json_success( array(
                'status'      => '' !== $saved ? 'own' : 'missing',
                'translation' => wp_kses_post( $saved ),
                'groups'      => self::get_groups_data( $language ),
            ) );
        }

        $row = self::save_row( absint( self::input( $_POST, 'id' ) ), $translation ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( is_wp_error( $row ) ) { wp_send_json_error( array( 'message' => $row->get_error_message() ), 400 ); }
        $saved = trim( (string) $translation );
        $status = '' !== $saved ? 'own' : ( '' !== Lingolane_Strings::inherited( $row->source, $row->domain, (string) $row->context, $row->language_code ) ? 'inherited' : 'missing' );
        wp_send_json_success( array( 'status' => $status, 'translation' => wp_kses_post( $saved ) ) );
    }

    public static function ajax_save_identity() {
        if ( ! Lingolane_Admin::can_translate() ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'lingolane' ) ), 403 );
        }
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : ( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $nonce, 'lingolane_strings_save_identity' ) && ! wp_verify_nonce( $nonce, 'lingolane_strings_ajax' ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page.', 'lingolane' ) ), 403 );
        }

        $translation = isset( $_POST['translation'] ) && is_string( $_POST['translation'] ) ? wp_unslash( $_POST['translation'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
        $domain      = self::input( $_POST, 'domain' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $source      = isset( $_POST['source'] ) ? wp_unslash( (string) $_POST['source'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
        $context     = self::input( $_POST, 'context' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $lang_code   = self::input( $_POST, 'language_code' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $result = self::save_identity( $domain, $source, $context, $lang_code, $translation );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        $saved = trim( (string) $translation );
        wp_send_json_success( array(
            'status'      => '' !== $saved ? 'own' : 'missing',
            'translation' => wp_kses_post( $saved ),
            'groups'      => self::get_groups_data( $lang_code ),
        ) );
    }

    public static function ajax_save_term_translation() {
        if ( ! Lingolane_Admin::can_translate() ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'lingolane' ) ), 403 );
        }
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : ( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $nonce, 'lingolane_terms_ajax' ) && ! wp_verify_nonce( $nonce, 'lingolane_terms_save_term' ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page.', 'lingolane' ) ), 403 );
        }

        $source_id   = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $taxonomy    = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $language    = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $result = self::save_term_translation( $source_id, $taxonomy, $language, $name, $description );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success( array(
            'status' => '' !== trim( $name ) ? 'translated' : 'untranslated',
            'name'   => $name,
        ) );
    }

    public static function ajax_delete() {
        self::ajax_guard( 'manage_options' );
        $result = self::delete_row( absint( self::input( $_POST, 'id' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
        wp_send_json_success();
    }

    private static function read_csv() {
        $error = function ( $message ) { return new WP_Error( 'lingolane_csv', $message ); };
        if ( ! isset( $_FILES['csv'] ) || ! is_array( $_FILES['csv'] ) ) { return $error( __( 'No CSV file selected.', 'lingolane' ) ); } // phpcs:ignore WordPress.Security.NonceVerification
        $file = $_FILES['csv']; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        if ( ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || ! is_scalar( $file['error'] ) || (int) $file['error'] !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return $error( __( 'The file upload failed. Please try again.', 'lingolane' ) ); }
        if ( strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) ) !== 'csv' || filesize( $file['tmp_name'] ) > 2 * MB_IN_BYTES ) { return $error( __( 'Only .csv files up to 2 MB are accepted.', 'lingolane' ) ); }
        $stream = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $stream ) { return $error( __( 'Could not read the CSV file.', 'lingolane' ) ); }
        $rows = array();
        try {
            $header = fgetcsv( $stream, 0, ',', '"', '' );
            if ( ! is_array( $header ) || ! isset( $header[0] ) ) { return $error( __( 'The CSV file is empty or has no header row.', 'lingolane' ) ); }
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
            $header = array_map( 'trim', $header );
            $expected = array( 'domain', 'context', 'source', 'language', 'translation' );
            if ( count( $header ) !== count( $expected ) || array_diff( $expected, $header ) ) {
                /* translators: %s: column list */
                return $error( sprintf( __( 'The header row must contain these columns: %s. Nothing was written.', 'lingolane' ), implode( ', ', $expected ) ) );
            }
            $line = 1;
            while ( ( $cells = fgetcsv( $stream, 0, ',', '"', '' ) ) !== false ) {
                ++$line;
                if ( $cells === array( null ) ) { continue; }
                /* translators: %d: line number */
                if ( count( $cells ) !== count( $header ) ) { return $error( sprintf( __( 'Row %d has a different number of columns than the header. Nothing was written.', 'lingolane' ), $line ) ); }
                foreach ( $cells as &$cell ) {
                    /* translators: %d: line number */
                    if ( ! is_string( $cell ) || strpos( $cell, "\0" ) !== false || 1 !== preg_match( '//u', $cell ) ) { return $error( sprintf( __( 'Row %d is not valid UTF-8 text. Save the file with UTF-8 encoding and try again.', 'lingolane' ), $line ) ); }
                    if ( preg_match( "/^'(?:['=+@\\-\\t\\r\\n]|\\s+[=+@\\-])/", $cell ) ) { $cell = substr( $cell, 1 ); }
                }
                unset( $cell );
                $data = array_combine( $header, $cells );
                $domain = sanitize_text_field( $data['domain'] );
                $context = sanitize_text_field( $data['context'] );
                $source = $data['source']; // Exact gettext key; UTF-8 and NUL already validated.
                $language = sanitize_key( $data['language'] );
                if ( $domain === '' || strlen( $domain ) > 100 || strlen( $context ) > 100 || $source === '' || ! Lingolane_Languages::is_valid( $language ) || $language !== $data['language'] ) {
                    /* translators: %d: line number */
                    return $error( sprintf( __( 'Row %d is missing the source string, or its language code is not enabled on this site. Nothing was written.', 'lingolane' ), $line ) );
                }
                $rows[] = array( $domain, $context, $source, $language, wp_kses_post( $data['translation'] ) );
            }
        } finally { fclose( $stream ); } // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $rows ) { return $error( __( 'The file has no data rows.', 'lingolane' ) ); }
        return $rows;
    }

    /** True when the call sits inside a Customizer, menu-location, settings or meta box declaration: admin-only labels. */
    private static function admin_only( $code, $offset ) {
        $before = substr( $code, max( 0, $offset - 1200 ), min( $offset, 1200 ) );
        if ( ! preg_match_all( '/->add_(?:control|section|panel|setting|partial)\s*\(|\bnew\s+WP_Customize_\w+\s*\(|\b(?:register_nav_menus?|register_sidebars?|add_settings_(?:field|section)|add_meta_box|add_(?:menu|submenu|options|theme|management)_page)\s*\(/', $before, $calls, PREG_OFFSET_CAPTURE ) ) { return false; }
        $last = end( $calls[0] );
        return false === strpos( substr( $before, $last[1] ), ');' );
    }

    /**
     * Registers visitor-facing gettext calls of the active theme and drops untranslated entries the theme
     * no longer uses. Strings that already have a translation are never removed.
     */
    public static function scan_theme_strings() {
        global $wpdb;
        $dir = get_template_directory();
        $domain = self::theme_domain();
        $found = array();
        if ( is_dir( $dir ) ) {
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
            $q = '([\'"])((?:\\\\.|(?!\1).)*)\1';
            $patterns = array(
                '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*' . $q . '\s*,\s*([\'"])([A-Za-z0-9_-]+)\3\s*\)/s' => array( 2, 0, 4 ),
                '/\b(?:_x|_ex|esc_html_x|esc_attr_x)\s*\(\s*' . $q . '\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3\s*,\s*([\'"])([A-Za-z0-9_-]+)\5\s*\)/s' => array( 2, 4, 6 ),
            );
            foreach ( $iterator as $file ) {
                $path = $file->getPathname();
                if ( ! $file->isFile() || 'php' !== $file->getExtension() || preg_match( '~[\\\\/](?:node_modules|vendor|tests?)[\\\\/]|customizer\.php$~i', $path ) ) { continue; }
                $code = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
                if ( ! $code ) { continue; }
                foreach ( $patterns as $pattern => $groups ) {
                    if ( ! preg_match_all( $pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) { continue; }
                    foreach ( $matches as $m ) {
                        if ( $m[ $groups[2] ][0] !== $domain || self::admin_only( $code, $m[0][1] ) ) { continue; }
                        $source = stripslashes( $m[ $groups[0] ][0] );
                        $context = $groups[1] ? stripslashes( $m[ $groups[1] ][0] ) : '';
                        $found[ Lingolane_Strings::hash( $source, $context ) ] = array( $source, $context );
                    }
                }
            }
        }
        $table     = $wpdb->prefix . 'lingolane_strings';
        $loc_table = $wpdb->prefix . 'lingolane_string_locations';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $before    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source_hash) FROM $table WHERE domain = %s", $domain ) );
        foreach ( $found as $item ) { lingolane_register_string( $domain, $item[0], $item[1] ); }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source_hash) FROM $table WHERE domain = %s", $domain ) );
        // Remove unused entries only when no language has a translation for them, and no other provider recorded occurrences.
        $removed = 0;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hashes  = $wpdb->get_col( $wpdb->prepare( "SELECT source_hash FROM $table WHERE domain = %s GROUP BY source_hash HAVING MAX(translation <> '') = 0", $domain ) );
        foreach ( (array) $hashes as $hash ) {
            if ( ! isset( $found[ $hash ] ) ) {
                // Check if this string has occurrences in locations from other providers.
                $has_other = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM $loc_table WHERE domain = %s AND source_hash = %s AND provider != 'gettext'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $domain,
                    $hash
                ) );
                if ( $has_other > 0 ) {
                    continue;
                }
                $removed += (int) $wpdb->delete( $table, array( 'domain' => $domain, 'source_hash' => $hash, 'translation' => '' ), array( '%s', '%s', '%s' ) ) ? 1 : 0;
            }
        }
        if ( $removed ) {
            Lingolane_Strings::flush_cache();
            Lingolane_String_Locations::invalidate();
            Lingolane_Stats::flush_strings();
        }
        return array( 'found' => count( $found ), 'added' => max( 0, $after - $before ), 'removed' => $removed );
    }

    /* ------------------------------------------------------------------ screen */

    public static function render_tab_content() {
        global $wpdb;
        if ( ! Lingolane_Admin::can_translate() ) { return; }
        $others = Lingolane_Stats::other_languages();
        if ( ! $others ) {
            echo '<section class="lingolane-card lingolane-empty">' . Lingolane_UI::illustration( 'empty-languages' ) . '<h2>' . esc_html__( 'No language to translate into yet', 'lingolane' ) . '</h2><p>' . esc_html__( 'Add a second language in the Languages tab.', 'lingolane' ) . '</p><a class="button button-primary" href="' . esc_url( Lingolane_Admin::url( 'languages' ) ) . '">' . esc_html__( 'Add a language', 'lingolane' ) . '</a></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled SVG from a fixed list
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        $language = self::input( $_GET, 'language' );
        $group    = self::input( $_GET, 'group' );
        $subgroup = self::input( $_GET, 'subgroup' );
        $status   = self::input( $_GET, 'status' );
        $keyword  = self::input( $_GET, 'keyword' );
        $page     = max( 1, absint( self::input( $_GET, 'paged' ) ) );
        // phpcs:enable

        $codes = wp_list_pluck( $others, 'code' );
        if ( ! in_array( $language, $codes, true ) ) { $language = $codes[0]; }

        $known_groups = Lingolane_String_Locations::known_groups();
        if ( ! array_key_exists( $group, $known_groups ) ) {
            $group = 'site';
        }
        if ( ! in_array( $status, array( 'all', 'missing', 'translated', 'unsupported' ), true ) ) {
            $status = 'all';
        }

        $groups_data = self::get_groups_data( $language );
        $page_data   = self::page_data( $group, $subgroup, $language, $status, $keyword, $page, self::PER_PAGE );
        $items       = $page_data['items'];
        $total       = $page_data['total'];
        $pages       = max( 1, (int) ceil( $total / self::PER_PAGE ) );

        $default_lang_code = Lingolane_Languages::default_code();
        $default_lang_name = Lingolane_Languages::name( $default_lang_code );

        // Subgroups for active navigation
        $plugin_subgroups = Lingolane_String_Locations::subgroups( 'plugin' );
        $menu_subgroups   = Lingolane_String_Locations::subgroups( 'menu' );
        $terms_subgroups  = Lingolane_String_Terms::taxonomies();

        // Banners condition
        $menu_separate = false;
        if ( 'menu' === $group && class_exists( 'Lingolane_String_Sources' ) && method_exists( 'Lingolane_String_Sources', 'menu_is_language_specific' ) ) {
            $menu_separate = Lingolane_String_Sources::menu_is_language_specific( $subgroup, $language );
        }
        $has_unsupported = ( ! empty( $groups_data[ $group ]['unsupported'] ) || 'unsupported' === $status );

        $group_icons = array(
            'site'    => 'globe',
            'header'  => 'layout-dashboard',
            'menu'    => 'files',
            'footer'  => 'layout-dashboard',
            'widgets' => 'sparkles',
            'theme'   => 'settings',
            'plugin'  => 'plus',
            'terms'   => 'book-open',
            'media'   => 'eye',
            'other'   => 'type',
        );

        $discovered_pages = class_exists( 'Lingolane_String_Scan' ) ? Lingolane_String_Scan::pages() : array();
        ?>
        <div class="lingolane-strings-container">
            <div class="lingolane-strings-layout">
                <aside class="lingolane-strings-sidebar">
                    <div class="lingolane-sidebar-header">
                        <h3 class="lingolane-sidebar-title"><?php esc_html_e( 'Location groups', 'lingolane' ); ?></h3>
                        <div class="lingolane-sidebar-actions">
                            <button type="button" class="button button-small button-primary lingolane-scan-pages-btn" title="<?php esc_attr_e( 'Scan frontend pages to discover strings and positions', 'lingolane' ); ?>">
                                <?php echo Lingolane_UI::icon( 'eye', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php esc_html_e( 'Scan pages', 'lingolane' ); ?>
                            </button>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lingolane_scan_theme_strings' ), 'lingolane_scan_theme_strings' ) ); ?>" class="button button-small" title="<?php esc_attr_e( 'Scan active theme code for gettext calls', 'lingolane' ); ?>">
                                <?php esc_html_e( 'Scan theme', 'lingolane' ); ?>
                            </a>
                        </div>
                    </div>

                    <nav class="lingolane-groups-nav" aria-label="<?php esc_attr_e( 'String location groups', 'lingolane' ); ?>">
                        <ul class="lingolane-groups-list">
                            <?php foreach ( $known_groups as $g_key => $g_label ) :
                                $is_active = ( $g_key === $group );
                                $g_stats   = isset( $groups_data[ $g_key ] ) ? $groups_data[ $g_key ] : array( 'total' => 0, 'own' => 0, 'percent' => 0 );
                                $g_url     = Lingolane_Admin::url( 'strings', array( 'group' => $g_key, 'language' => $language ) );
                                $icon_name = isset( $group_icons[ $g_key ] ) ? $group_icons[ $g_key ] : 'type';
                            ?>
                                <li class="lingolane-group-item<?php echo $is_active ? ' is-active' : ''; ?><?php echo 0 === (int) $g_stats['total'] ? ' is-empty' : ''; ?>">
                                    <a href="<?php echo esc_url( $g_url ); ?>" class="lingolane-group-link">
                                        <span class="lingolane-group-icon"><?php echo Lingolane_UI::icon( $icon_name, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                        <span class="lingolane-group-label"><?php echo esc_html( $g_label ); ?></span>
                                        <span class="lingolane-group-count"><?php echo (int) $g_stats['own']; ?>/<?php echo (int) $g_stats['total']; ?></span>
                                    </a>
                                    <div class="lingolane-progress-mini">
                                        <div class="lingolane-progress-bar" style="width: <?php echo (int) $g_stats['percent']; ?>%;"></div>
                                    </div>

                                    <?php if ( $is_active && 'plugin' === $g_key && ! empty( $plugin_subgroups ) ) : ?>
                                        <ul class="lingolane-subgroups-list">
                                            <li class="<?php echo '' === $subgroup ? 'is-active' : ''; ?>">
                                                <a href="<?php echo esc_url( Lingolane_Admin::url( 'strings', array( 'group' => 'plugin', 'language' => $language ) ) ); ?>">
                                                    <?php esc_html_e( 'All plugins', 'lingolane' ); ?>
                                                </a>
                                            </li>
                                            <?php foreach ( $plugin_subgroups as $psg ) : ?>
                                                <li class="<?php echo $subgroup === $psg ? 'is-active' : ''; ?>">
                                                    <a href="<?php echo esc_url( Lingolane_Admin::url( 'strings', array( 'group' => 'plugin', 'subgroup' => $psg, 'language' => $language ) ) ); ?>">
                                                        <?php echo esc_html( $psg ); ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>

                                    <?php if ( $is_active && 'menu' === $g_key && ! empty( $menu_subgroups ) ) : ?>
                                        <ul class="lingolane-subgroups-list">
                                            <li class="<?php echo '' === $subgroup ? 'is-active' : ''; ?>">
                                                <a href="<?php echo esc_url( Lingolane_Admin::url( 'strings', array( 'group' => 'menu', 'language' => $language ) ) ); ?>">
                                                    <?php esc_html_e( 'All menus', 'lingolane' ); ?>
                                                </a>
                                            </li>
                                            <?php foreach ( $menu_subgroups as $msg ) : ?>
                                                <li class="<?php echo $subgroup === $msg ? 'is-active' : ''; ?>">
                                                    <a href="<?php echo esc_url( Lingolane_Admin::url( 'strings', array( 'group' => 'menu', 'subgroup' => $msg, 'language' => $language ) ) ); ?>">
                                                        <?php echo esc_html( $msg ); ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>

                                    <?php if ( $is_active && 'terms' === $g_key && ! empty( $terms_subgroups ) ) : ?>
                                        <ul class="lingolane-subgroups-list">
                                            <?php foreach ( $terms_subgroups as $tax_slug => $tax_name ) :
                                                $tax_active = ( $subgroup === $tax_slug || ( '' === $subgroup && 'category' === $tax_slug ) );
                                            ?>
                                                <li class="<?php echo $tax_active ? 'is-active' : ''; ?>">
                                                    <a href="<?php echo esc_url( Lingolane_Admin::url( 'strings', array( 'group' => 'terms', 'subgroup' => $tax_slug, 'language' => $language ) ) ); ?>">
                                                        <?php echo esc_html( $tax_name ); ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                </aside>

                <main class="lingolane-strings-main">
                    <div class="lingolane-toolbar">
                        <div class="lingolane-toolbar-left">
                            <h2 class="lingolane-group-title">
                                <?php echo esc_html( $known_groups[ $group ] ); ?>
                                <?php if ( '' !== $subgroup ) : ?>
                                    <span class="lingolane-subgroup-chip"><?php echo esc_html( $subgroup ); ?></span>
                                <?php endif; ?>
                            </h2>
                        </div>
                        <div class="lingolane-toolbar-right">
                            <form method="get" class="lingolane-lang-switch-form">
                                <input type="hidden" name="page" value="<?php echo esc_attr( Lingolane_Admin::PAGE ); ?>">
                                <input type="hidden" name="tab" value="strings">
                                <input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
                                <?php if ( '' !== $subgroup ) : ?>
                                    <input type="hidden" name="subgroup" value="<?php echo esc_attr( $subgroup ); ?>">
                                <?php endif; ?>
                                <label for="lingolane-target-lang"><?php esc_html_e( 'Target language:', 'lingolane' ); ?></label>
                                <select id="lingolane-target-lang" name="language" onchange="this.form.submit()">
                                    <?php foreach ( $others as $item ) : ?>
                                        <option value="<?php echo esc_attr( $item['code'] ); ?>" <?php selected( $language, $item['code'] ); ?>>
                                            <?php echo esc_html( $item['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>

                    <div class="lingolane-filter-row">
                        <div class="lingolane-status-pills">
                            <?php
                            $curr_group_stats = isset( $groups_data[ $group ] ) ? $groups_data[ $group ] : array( 'total' => 0, 'missing' => 0, 'own' => 0, 'unsupported' => 0 );
                            $status_links = array(
                                'all'         => array( __( 'All', 'lingolane' ), $curr_group_stats['total'] ),
                                'missing'     => array( __( 'Untranslated', 'lingolane' ), $curr_group_stats['missing'] ),
                                'translated'  => array( __( 'Translated', 'lingolane' ), $curr_group_stats['own'] ),
                            );
                            if ( $curr_group_stats['unsupported'] > 0 ) {
                                $status_links['unsupported'] = array( __( 'Unsupported', 'lingolane' ), $curr_group_stats['unsupported'] );
                            }

                            foreach ( $status_links as $st_key => $st_info ) :
                                $st_active = ( $status === $st_key );
                                $st_url    = Lingolane_Admin::url( 'strings', array_filter( array(
                                    'group'    => $group,
                                    'subgroup' => $subgroup,
                                    'language' => $language,
                                    'status'   => 'all' === $st_key ? null : $st_key,
                                    'keyword'  => $keyword,
                                ) ) );
                            ?>
                                <a href="<?php echo esc_url( $st_url ); ?>" class="lingolane-pill<?php echo $st_active ? ' is-active' : ''; ?>">
                                    <?php echo esc_html( $st_info[0] ); ?>
                                    <span class="lingolane-pill__count"><?php echo (int) $st_info[1]; ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <form method="get" class="lingolane-search-form">
                            <input type="hidden" name="page" value="<?php echo esc_attr( Lingolane_Admin::PAGE ); ?>">
                            <input type="hidden" name="tab" value="strings">
                            <input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
                            <?php if ( '' !== $subgroup ) : ?>
                                <input type="hidden" name="subgroup" value="<?php echo esc_attr( $subgroup ); ?>">
                            <?php endif; ?>
                            <input type="hidden" name="language" value="<?php echo esc_attr( $language ); ?>">
                            <?php if ( 'all' !== $status ) : ?>
                                <input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
                            <?php endif; ?>
                            <input type="search" name="keyword" value="<?php echo esc_attr( $keyword ); ?>" placeholder="<?php esc_attr_e( 'Search source or translation…', 'lingolane' ); ?>">
                            <button type="submit" class="button"><?php esc_html_e( 'Search', 'lingolane' ); ?></button>
                            <button type="button" class="button button-secondary lingolane-batch-ai-btn" style="display:inline-flex; align-items:center; gap:6px; margin-left:8px;" title="<?php esc_attr_e( 'Draft AI translations for every untranslated string on this page', 'lingolane' ); ?>">
                                <?php echo Lingolane_UI::icon( 'sparkles', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php esc_html_e( 'AI-translate missing', 'lingolane' ); ?>
                            </button>
                            <?php if ( '' !== $keyword ) : ?>
                                <a href="<?php echo esc_url( Lingolane_Admin::url( 'strings', array_filter( array( 'group' => $group, 'subgroup' => $subgroup, 'language' => $language, 'status' => $status ) ) ) ); ?>" class="button button-link"><?php esc_html_e( 'Clear', 'lingolane' ); ?></a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if ( $menu_separate ) : ?>
                        <div class="notice notice-info inline lingolane-notice-banner">
                            <p>
                                <strong><?php esc_html_e( 'Notice:', 'lingolane' ); ?></strong>
                                <?php esc_html_e( 'Using a separate menu for this language. Translations entered here apply to the shared menu.', 'lingolane' ); ?>
                                <a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>"><?php esc_html_e( 'Manage menus in WordPress', 'lingolane' ); ?> &rarr;</a>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ( 'terms' === $group ) : ?>
                        <div class="notice notice-info inline lingolane-notice-banner">
                            <p>
                                <strong><?php esc_html_e( 'Taxonomy Terms:', 'lingolane' ); ?></strong>
                                <?php esc_html_e( 'Categories and tags are synchronized directly with WordPress taxonomy elements.', 'lingolane' ); ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ( $has_unsupported ) : ?>
                        <div class="notice notice-warning inline lingolane-notice-banner">
                            <p>
                                <strong><?php esc_html_e( 'Developer assistance required:', 'lingolane' ); ?></strong>
                                <?php esc_html_e( 'Some strings were found in the source code but need a developer to wrap them in a translation function or register them with gettext.', 'lingolane' ); ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ( empty( $items ) ) : ?>
                        <div class="lingolane-empty-state">
                            <h3><?php esc_html_e( 'No strings found in this group', 'lingolane' ); ?></h3>
                            <p><?php esc_html_e( 'Scan frontend pages or your active theme to automatically discover translatable strings.', 'lingolane' ); ?></p>
                            <button type="button" class="button button-primary lingolane-scan-pages-btn">
                                <?php echo Lingolane_UI::icon( 'eye', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php esc_html_e( 'Scan pages now', 'lingolane' ); ?>
                            </button>
                        </div>
                    <?php else : ?>
                        <div class="lingolane-table-wrap">
                            <table class="wp-list-table widefat fixed striped lingolane-multilingual-table">
                                <thead>
                                    <tr>
                                        <?php /* translators: %s: default language name */ ?>
                                        <th scope="col" class="col-source"><?php printf( esc_html__( 'Source text (%s)', 'lingolane' ), esc_html( $default_lang_name ) ); ?></th>
                                        <?php foreach ( $others as $other_l ) : ?>
                                            <th scope="col" class="col-target<?php echo $other_l['code'] === $language ? ' is-active-col' : ''; ?>">
                                                <?php
                                                /* translators: %s: target language name */
                                                printf( esc_html__( '%s translation', 'lingolane' ), esc_html( $other_l['name'] ) );
                                                ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $items as $item ) : ?>
                                        <tr data-domain="<?php echo esc_attr( $item['domain'] ); ?>" data-hash="<?php echo esc_attr( isset( $item['source_hash'] ) ? $item['source_hash'] : '' ); ?>">
                                            <td class="col-source">
                                                <div class="lingolane-source-text" lang="<?php echo esc_attr( $default_lang_code ); ?>">
                                                    <?php echo esc_html( $item['source'] ); ?>
                                                </div>
                                                <div class="lingolane-source-meta">
                                                    <span class="lingolane-chip lingolane-chip--neutral"><?php echo esc_html( $item['domain'] ); ?></span>
                                                    <?php if ( '' !== (string) $item['context'] ) : ?>
                                                        <span class="lingolane-chip lingolane-chip--info" title="<?php esc_attr_e( 'Context', 'lingolane' ); ?>"><?php echo esc_html( $item['context'] ); ?></span>
                                                    <?php endif; ?>

                                                    <?php if ( ! empty( $item['occurrences'] ) ) :
                                                        $first_occ = $item['occurrences'][0];
                                                        $prev_url  = ! empty( $first_occ['id'] ) ? Lingolane_String_Scan::preview_url( (int) $first_occ['id'] ) : '';
                                                        if ( $prev_url && ! is_wp_error( $prev_url ) ) : ?>
                                                            <a href="<?php echo esc_url( $prev_url ); ?>" target="_blank" class="button button-small lingolane-preview-btn" title="<?php esc_attr_e( 'Highlight on frontend page', 'lingolane' ); ?>">
                                                                <?php echo Lingolane_UI::icon( 'eye', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                                <?php esc_html_e( 'View location', 'lingolane' ); ?>
                                                            </a>
                                                        <?php else : ?>
                                                            <button type="button" class="button button-small" disabled title="<?php esc_attr_e( 'Not seen on a page yet', 'lingolane' ); ?>">
                                                                <?php esc_html_e( 'View location', 'lingolane' ); ?>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else : ?>
                                                        <button type="button" class="button button-small" disabled title="<?php esc_attr_e( 'Not seen on a page yet', 'lingolane' ); ?>">
                                                            <?php esc_html_e( 'View location', 'lingolane' ); ?>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ( empty( $item['supported'] ) ) :
                                                        $slug_val = $item['domain'] . ':' . $item['context'] . ':' . $item['source'];
                                                    ?>
                                                        <span class="lingolane-chip lingolane-chip--warning"><?php esc_html_e( 'Needs a developer', 'lingolane' ); ?></span>
                                                        <button type="button" class="button button-small lingolane-copy-slug" data-slug="<?php echo esc_attr( $slug_val ); ?>" title="<?php esc_attr_e( 'Copy identifier for developers', 'lingolane' ); ?>">
                                                            <?php esc_html_e( 'Copy slug', 'lingolane' ); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <?php foreach ( $others as $other_l ) :
                                                $l_code = $other_l['code'];
                                                $val    = isset( $item['translations'][ $l_code ] ) ? (string) $item['translations'][ $l_code ] : '';
                                            ?>
                                                <td class="col-target<?php echo $l_code === $language ? ' is-active-col' : ''; ?>">
                                                    <?php if ( 'terms' === $group ) : ?>
                                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lingolane-term-form">
                                                            <input type="hidden" name="action" value="lingolane_save_term_translation">
                                                            <?php wp_nonce_field( 'lingolane_terms_save_term' ); ?>
                                                            <input type="hidden" name="source_id" value="<?php echo esc_attr( $item['id'] ); ?>">
                                                            <input type="hidden" name="taxonomy" value="<?php echo esc_attr( $item['context'] ); ?>">
                                                            <input type="hidden" name="language" value="<?php echo esc_attr( $l_code ); ?>">
                                                            <div class="lingolane-input-stack">
                                                                <input type="text" name="name" class="regular-text lingolane-term-name-input" value="<?php echo esc_attr( $val ); ?>" placeholder="<?php esc_attr_e( 'Term name', 'lingolane' ); ?>" data-orig="<?php echo esc_attr( $val ); ?>">
                                                                <textarea name="description" rows="2" class="large-text lingolane-term-desc-input" placeholder="<?php esc_attr_e( 'Description (optional)', 'lingolane' ); ?>"><?php echo esc_textarea( isset( $item['trans_desc'] ) ? $item['trans_desc'] : '' ); ?></textarea>
                                <div class="lingolane-row-actions">
                                                                    <button type="submit" class="button button-small button-primary lingolane-save-term-btn"><?php esc_html_e( 'Save', 'lingolane' ); ?></button>
                                                                    <button type="button" class="button button-small lingolane-ai-term-btn" title="<?php esc_attr_e( 'Suggest a translation with AI', 'lingolane' ); ?>">
                                                                        <?php echo Lingolane_UI::icon( 'sparkles', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                                        <?php esc_html_e( 'AI', 'lingolane' ); ?>
                                                                    </button>
                                                                    <span class="lingolane-save-status" aria-live="polite"></span>
                                                                </div>
                                                            </div>
                                                        </form>
                                                    <?php elseif ( empty( $item['supported'] ) ) : ?>
                                                        <div class="lingolane-unsupported-box">
                                                            <textarea readonly class="large-text disabled" rows="2"><?php esc_html_e( 'A developer needs to make this string translatable', 'lingolane' ); ?></textarea>
                                                        </div>
                                                    <?php else : ?>
                                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lingolane-identity-form">
                                                            <input type="hidden" name="action" value="lingolane_save_identity">
                                                            <?php wp_nonce_field( 'lingolane_strings_save_identity' ); ?>
                                                            <input type="hidden" name="domain" value="<?php echo esc_attr( $item['domain'] ); ?>">
                                                            <input type="hidden" name="source" value="<?php echo esc_attr( $item['source'] ); ?>">
                                                            <input type="hidden" name="context" value="<?php echo esc_attr( $item['context'] ); ?>">
                                                            <input type="hidden" name="language_code" value="<?php echo esc_attr( $l_code ); ?>">
                                                            <input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
                                                            <div class="lingolane-input-stack">
                                                                <textarea name="translation" rows="2" class="large-text lingolane-translation-input" lang="<?php echo esc_attr( $l_code ); ?>" data-domain="<?php echo esc_attr( $item['domain'] ); ?>" data-source="<?php echo esc_attr( $item['source'] ); ?>" data-context="<?php echo esc_attr( $item['context'] ); ?>" data-lang="<?php echo esc_attr( $l_code ); ?>" data-orig="<?php echo esc_attr( $val ); ?>" placeholder="<?php esc_attr_e( 'Enter translation…', 'lingolane' ); ?>"><?php echo esc_textarea( $val ); ?></textarea>
                                                                <div class="lingolane-row-actions">
                                                                    <button type="submit" class="button button-small button-primary lingolane-save-btn"><?php esc_html_e( 'Save', 'lingolane' ); ?></button>
                                                                    <button type="button" class="button button-small lingolane-ai-btn" title="<?php esc_attr_e( 'Suggest a translation with AI', 'lingolane' ); ?>">
                                                                        <?php echo Lingolane_UI::icon( 'sparkles', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                                        <?php esc_html_e( 'AI', 'lingolane' ); ?>
                                                                    </button>
                                                                    <span class="lingolane-save-status" aria-live="polite"></span>
                                                                </div>
                                                            </div>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php Lingolane_Admin::pagination( min( $page, $pages ), $pages, $total, self::PER_PAGE ); ?>
                    <?php endif; ?>
                </main>
            </div>
        </div>

        <div id="lingolane-scan-modal" class="lingolane-modal-backdrop" hidden>
            <div class="lingolane-modal" role="dialog" aria-modal="true" aria-labelledby="lingolane-scan-title">
                <div class="lingolane-modal-header">
                    <h2 id="lingolane-scan-title"><?php esc_html_e( 'Scan frontend pages', 'lingolane' ); ?></h2>
                    <button type="button" class="lingolane-modal-close" id="lingolane-modal-close-btn" aria-label="<?php esc_attr_e( 'Close', 'lingolane' ); ?>">
                        <?php echo Lingolane_UI::icon( 'x', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </button>
                </div>
                <div class="lingolane-modal-body">
                    <p class="lingolane-modal-desc">
                        <?php esc_html_e( 'Select pages to scan. Lingolane will discover strings on these pages, map their exact locations, and check whether they can be translated automatically.', 'lingolane' ); ?>
                    </p>

                    <div id="lingolane-pages-selection">
                        <div class="lingolane-pages-toolbar">
                            <label>
                                <input type="checkbox" id="lingolane-select-all-pages" checked>
                                <strong><?php esc_html_e( 'Select all discovered pages', 'lingolane' ); ?></strong>
                            </label>
                        </div>
                        <ul class="lingolane-scan-pages-list">
                            <?php foreach ( $discovered_pages as $dp ) : ?>
                                <li>
                                    <label>
                                        <input type="checkbox" class="lingolane-page-check" value="<?php echo esc_attr( $dp['path'] ); ?>" checked>
                                        <span class="lingolane-page-title"><?php echo esc_html( $dp['title'] ); ?></span>
                                        <code class="lingolane-page-path"><?php echo esc_html( $dp['path'] ); ?></code>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div id="lingolane-scan-progress" class="lingolane-scan-progress" hidden>
                        <div class="lingolane-scan-progress-bar-wrap">
                            <div id="lingolane-scan-progress-bar" class="lingolane-scan-progress-bar" style="width: 0%;"></div>
                        </div>
                        <p id="lingolane-scan-status-text" class="lingolane-scan-status-text"><?php esc_html_e( 'Preparing scan…', 'lingolane' ); ?></p>
                    </div>

                    <div id="lingolane-scan-iframe-container" style="display:none; width:0; height:0;"></div>
                </div>
                <div class="lingolane-modal-footer">
                    <button type="button" class="button" id="lingolane-scan-cancel-btn"><?php esc_html_e( 'Cancel', 'lingolane' ); ?></button>
                    <button type="button" class="button button-primary" id="lingolane-start-scan-btn">
                        <?php echo Lingolane_UI::icon( 'eye', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php esc_html_e( 'Start scan', 'lingolane' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="lingolane-scan-done-btn" style="display:none;">
                        <?php esc_html_e( 'Close & refresh', 'lingolane' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <section class="lingolane-card">
            <h2 class="lingolane-card__title"><?php esc_html_e( 'Bulk translate with CSV', 'lingolane' ); ?></h2>
            <div class="lingolane-grid lingolane-grid--two">
                <div class="lingolane-subcard">
                    <h3><?php esc_html_e( '1. Export file', 'lingolane' ); ?></h3>
                    <p><?php esc_html_e( 'Download all strings and existing translations, then open them in Google Sheets or Excel to translate.', 'lingolane' ); ?></p>
                    <?php self::form( 'export' ); ?>
                        <button type="submit" class="button"><?php echo Lingolane_UI::icon( 'download', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?><?php esc_html_e( 'Download CSV', 'lingolane' ); ?></button>
                    </form>
                </div>
                <div class="lingolane-subcard">
                    <h3><?php esc_html_e( '2. Import again', 'lingolane' ); ?></h3>
                    <?php self::form( 'import', 'lingolane-import' ); ?>
                        <div class="lingolane-field">
                            <label for="lingolane-csv"><?php esc_html_e( 'CSV file (UTF-8, up to 2 MB)', 'lingolane' ); ?></label>
                            <input id="lingolane-csv" type="file" name="csv" accept=".csv,text/csv" required>
                        </div>
                        <p class="lingolane-hint"><?php esc_html_e( 'Use the file exported in step 1 (columns domain, context, source, language, translation).', 'lingolane' ); ?></p>
                        <button type="submit" class="button button-primary"><?php echo Lingolane_UI::icon( 'upload', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?><?php esc_html_e( 'Import translations', 'lingolane' ); ?></button>
                        <p class="lingolane-hint"><?php esc_html_e( 'The file is validated before anything is written; if one row is invalid, no row is saved.', 'lingolane' ); ?></p>
                    </form>
                </div>
            </div>
        </section>
        <?php
    }
}
