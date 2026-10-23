<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Lingolane_Strings_Admin {
    const PER_PAGE = 30;

    public static function init() {
        add_action( 'admin_post_lingolane_strings', array( __CLASS__, 'handle' ) );
        add_action( 'wp_ajax_lingolane_save_string', array( __CLASS__, 'ajax_save' ) );
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

    public static function handle() {
        global $wpdb;
        if ( ! Lingolane_Admin::can_translate() ) { wp_die( esc_html__( 'You do not have permission to perform this action.', 'lingolane' ), '', array( 'response' => 403 ) ); }
        $operation = self::input( $_POST, 'operation' ); // phpcs:ignore WordPress.Security.NonceVerification
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
            header( 'Content-Disposition: attachment; filename="teachme-strings-' . gmdate( 'Y-m-d' ) . '.csv"' );
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
        $row = self::save_row( absint( self::input( $_POST, 'id' ) ), $translation ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( is_wp_error( $row ) ) { wp_send_json_error( array( 'message' => $row->get_error_message() ), 400 ); }
        $saved = trim( (string) $translation );
        $status = '' !== $saved ? 'own' : ( '' !== Lingolane_Strings::inherited( $row->source, $row->domain, (string) $row->context, $row->language_code ) ? 'inherited' : 'missing' );
        wp_send_json_success( array( 'status' => $status, 'translation' => wp_kses_post( $saved ) ) );
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
        $table = $wpdb->prefix . 'lingolane_strings';
        $before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source_hash) FROM $table WHERE domain = %s", $domain ) );
        foreach ( $found as $item ) { lingolane_register_string( $domain, $item[0], $item[1] ); }
        $after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source_hash) FROM $table WHERE domain = %s", $domain ) );
        // Remove unused entries only when no language has a translation for them.
        $removed = 0;
        $hashes = $wpdb->get_col( $wpdb->prepare( "SELECT source_hash FROM $table WHERE domain = %s GROUP BY source_hash HAVING MAX(translation <> '') = 0", $domain ) );
        foreach ( (array) $hashes as $hash ) {
            if ( ! isset( $found[ $hash ] ) ) {
                $removed += (int) $wpdb->delete( $table, array( 'domain' => $domain, 'source_hash' => $hash, 'translation' => '' ), array( '%s', '%s', '%s' ) ) ? 1 : 0;
            }
        }
        if ( $removed ) { Lingolane_Strings::flush_cache(); }
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
        $table    = $wpdb->prefix . 'lingolane_strings';
        // phpcs:disable WordPress.Security.NonceVerification
        $language = self::input( $_GET, 'language' );
        $domain   = self::input( $_GET, 'domain' );
        $status   = self::input( $_GET, 'status' );
        $keyword  = self::input( $_GET, 'keyword' );
        $page     = max( 1, absint( self::input( $_GET, 'paged' ) ) );
        // phpcs:enable
        $codes = wp_list_pluck( $others, 'code' );
        if ( ! in_array( $language, $codes, true ) ) { $language = $codes[0]; }
        if ( ! in_array( $status, array( 'missing', 'inherited', 'own' ), true ) ) { $status = ''; }
        $domains = $wpdb->get_col( "SELECT DISTINCT domain FROM $table ORDER BY domain" ); // phpcs:ignore WordPress.DB.PreparedSQL
        if ( ! in_array( $domain, (array) $domains, true ) ) { $domain = ''; }

        $where = $wpdb->prepare( 'WHERE language_code = %s', $language );
        if ( '' !== $domain ) { $where .= $wpdb->prepare( ' AND domain = %s', $domain ); }
        if ( '' !== $keyword ) {
            $like = '%' . $wpdb->esc_like( $keyword ) . '%';
            $where .= $wpdb->prepare( ' AND (source LIKE %s OR translation LIKE %s)', $like, $like );
        }

        $inherited = array();
        if ( in_array( $status, array( 'missing', 'inherited' ), true ) ) {
            // Whether the theme already provides a translation is only known in PHP, so filter and page here.
            $candidates = $wpdb->get_results( "SELECT id, domain, context, source FROM $table $where AND translation = '' ORDER BY domain, id LIMIT 5000" ); // phpcs:ignore WordPress.DB.PreparedSQL
            $ids = array();
            foreach ( (array) $candidates as $candidate ) {
                $text = Lingolane_Strings::inherited( $candidate->source, $candidate->domain, (string) $candidate->context, $language );
                if ( ( 'inherited' === $status ) === ( '' !== $text ) ) {
                    $ids[] = (int) $candidate->id;
                    $inherited[ (int) $candidate->id ] = $text;
                }
            }
            $total = count( $ids );
            $slice = array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
            $rows = $slice ? $wpdb->get_results( "SELECT * FROM $table WHERE id IN (" . implode( ',', $slice ) . ') ORDER BY domain, id' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL
        } else {
            if ( 'own' === $status ) { $where .= " AND translation <> ''"; }
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" ); // phpcs:ignore WordPress.DB.PreparedSQL
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table $where ORDER BY translation <> '', domain, id LIMIT %d OFFSET %d", self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) ); // phpcs:ignore WordPress.DB.PreparedSQL
        }
        $pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $stats = Lingolane_Stats::strings();
        $s = isset( $stats[ $language ] ) ? $stats[ $language ] : array( 'total' => 0, 'own' => 0, 'inherited' => 0, 'missing' => 0 );
        $language_name = Lingolane_Languages::name( $language );
        $tabs = array(
            ''          => array( __( 'All', 'lingolane' ), $s['total'] ),
            'missing'   => array( __( 'Untranslated', 'lingolane' ), $s['missing'] ),
            'own'       => array( __( 'Translated', 'lingolane' ), $s['own'] ),
            'inherited' => array( __( 'Provided by theme', 'lingolane' ), $s['inherited'] ),
        );
        $base = array( 'language' => $language, 'domain' => $domain, 'keyword' => $keyword );
        ?>
        <section class="lingolane-card">
            <div class="lingolane-card__head">
                <h2 class="lingolane-card__title"><?php /* translators: %s: language */ echo esc_html( sprintf( __( 'Interface strings · %s', 'lingolane' ), $language_name ) ); ?></h2>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lingolane_scan_theme_strings' ), 'lingolane_scan_theme_strings' ) ); ?>"><?php echo Lingolane_UI::icon( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list ?><?php esc_html_e( 'Scan interface', 'lingolane' ); ?></a>
            </div>

            <ul class="lingolane-segments" aria-label="<?php esc_attr_e( 'Filter by status', 'lingolane' ); ?>">
                <?php foreach ( $tabs as $key => $item ) : ?>
                    <li><a href="<?php echo esc_url( Lingolane_Admin::url( 'strings', array_filter( array_merge( $base, array( 'status' => $key ) ) ) ) ); ?>" class="<?php echo $key === $status ? 'is-active' : ''; ?>"<?php echo $key === $status ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $item[0] ); ?> <span class="lingolane-segments__count"><?php echo esc_html( number_format_i18n( $item[1] ) ); ?></span></a></li>
                <?php endforeach; ?>
            </ul>

            <form method="get" class="lingolane-filters" role="search">
                <input type="hidden" name="page" value="<?php echo esc_attr( Lingolane_Admin::PAGE ); ?>">
                <input type="hidden" name="tab" value="strings">
                <?php if ( $status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"><?php endif; ?>
                <?php if ( count( $others ) > 1 ) : ?>
                    <div class="lingolane-field">
                        <label for="lingolane-s-lang"><?php esc_html_e( 'Translate into', 'lingolane' ); ?></label>
                        <select id="lingolane-s-lang" name="language">
                            <?php foreach ( $others as $item ) : ?>
                                <option value="<?php echo esc_attr( $item['code'] ); ?>" <?php selected( $language, $item['code'] ); ?>><?php echo esc_html( $item['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else : ?>
                    <input type="hidden" name="language" value="<?php echo esc_attr( $language ); ?>">
                <?php endif; ?>
                <?php if ( count( (array) $domains ) > 1 ) : ?>
                    <div class="lingolane-field">
                        <label for="lingolane-s-domain"><?php esc_html_e( 'Source', 'lingolane' ); ?></label>
                        <select id="lingolane-s-domain" name="domain">
                            <option value=""><?php esc_html_e( 'All', 'lingolane' ); ?></option>
                            <?php foreach ( (array) $domains as $item ) : ?>
                                <option value="<?php echo esc_attr( $item ); ?>" <?php selected( $domain, $item ); ?>><?php echo esc_html( $item ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="lingolane-field lingolane-field--grow">
                    <label for="lingolane-s-keyword"><?php esc_html_e( 'Search', 'lingolane' ); ?></label>
                    <input id="lingolane-s-keyword" type="search" name="keyword" value="<?php echo esc_attr( $keyword ); ?>" placeholder="<?php esc_attr_e( 'Source text or translation', 'lingolane' ); ?>">
                </div>
                <div class="lingolane-field lingolane-field--actions">
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'lingolane' ); ?></button>
                    <?php if ( '' !== $keyword || '' !== $domain ) : ?>
                        <a class="button-link" href="<?php echo esc_url( Lingolane_Admin::url( 'strings', array_filter( array( 'language' => $language, 'status' => $status ) ) ) ); ?>"><?php esc_html_e( 'Clear filters', 'lingolane' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <p class="lingolane-kbd-hint"><?php echo wp_kses( __( 'Tip: type the translation, then press <kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>Enter</kbd> to save and jump to the next string.', 'lingolane' ), array( 'kbd' => array() ) ); ?></p>

            <div class="lingolane-table-wrap">
                <table class="lingolane-table lingolane-strings">
                    <thead>
                        <tr>
                            <th scope="col" class="lingolane-strings__source"><?php /* translators: %s: language */ echo esc_html( sprintf( __( 'Source text (%s)', 'lingolane' ), Lingolane_Languages::name( Lingolane_Languages::source_code() ) ) ); ?></th>
                            <th scope="col"><?php /* translators: %s: language */ echo esc_html( sprintf( __( '%s translation', 'lingolane' ), $language_name ) ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( ! $rows ) : ?>
                        <tr><td colspan="2" class="lingolane-table__empty">
                            <?php
                            if ( 'missing' === $status ) {
                                echo Lingolane_UI::icon( 'circle-check' ) . ' ' . esc_html__( 'No untranslated strings left.', 'lingolane' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from a fixed list
                            } elseif ( ! $s['total'] ) {
                                esc_html_e( 'The list is empty. Click “Scan interface” to collect text from your theme, or import a CSV file below.', 'lingolane' );
                            } else {
                                esc_html_e( 'No strings match the filter.', 'lingolane' );
                            }
                            ?>
                        </td></tr>
                    <?php else : foreach ( (array) $rows as $row ) :
                        $id = (int) $row->id;
                        $inherit = array_key_exists( $id, $inherited ) ? $inherited[ $id ] : ( '' === $row->translation ? Lingolane_Strings::inherited( $row->source, $row->domain, (string) $row->context, $language ) : '' );
                        $state = '' !== $row->translation ? 'own' : ( '' !== $inherit ? 'inherited' : 'missing' );
                        $chips = array( 'own' => array( __( 'Translated', 'lingolane' ), 'ok' ), 'inherited' => array( __( 'Provided by theme', 'lingolane' ), 'info' ), 'missing' => array( __( 'Untranslated', 'lingolane' ), 'warn' ) );
                        ?>
                        <tr data-lingolane-row="<?php echo esc_attr( $id ); ?>">
                            <td class="lingolane-strings__source">
                                <div class="lingolane-source" lang="<?php echo esc_attr( Lingolane_Languages::source_code() ); ?>" id="lingolane-src-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $row->source ); ?></div>
                                <span class="lingolane-meta"><?php echo esc_html( $row->domain . ( '' !== (string) $row->context ? ' · ' . $row->context : '' ) ); ?></span>
                            </td>
                            <td>
                                <?php self::form( 'row', 'lingolane-string' ); ?>
                                    <input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
                                    <label class="screen-reader-text" for="lingolane-str-<?php echo esc_attr( $id ); ?>"><?php /* translators: %s: language */ echo esc_html( sprintf( __( '%s translation', 'lingolane' ), $language_name ) ); ?></label>
                                    <textarea id="lingolane-str-<?php echo esc_attr( $id ); ?>" name="translation" rows="<?php echo esc_attr( max( 1, min( 6, (int) ceil( mb_strlen( $row->source ) / 70 ) ) ) ); ?>" lang="<?php echo esc_attr( $language ); ?>" aria-describedby="lingolane-src-<?php echo esc_attr( $id ); ?>" placeholder="<?php echo esc_attr( '' !== $inherit ? $inherit : __( 'Enter translation…', 'lingolane' ) ); ?>" data-inherited="<?php echo esc_attr( $inherit ); ?>"><?php echo esc_textarea( $row->translation ); ?></textarea>
                                    <div class="lingolane-string__bar">
                                        <span class="lingolane-chip lingolane-chip--<?php echo esc_attr( $chips[ $state ][1] ); ?>" data-lingolane-status="<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $chips[ $state ][0] ); ?></span>
                                        <span class="lingolane-string__msg" role="status" aria-live="polite"></span>
                                        <span class="lingolane-string__actions">
                                            <?php if ( '' !== $inherit ) : ?>
                                                <button type="button" class="button-link" data-lingolane-copy><?php esc_html_e( 'Edit the built-in translation', 'lingolane' ); ?></button>
                                            <?php elseif ( 'missing' === $state ) : ?>
                                                <button type="button" class="button-link" data-lingolane-same title="<?php esc_attr_e( 'For names, product codes and similar text that needs no translation', 'lingolane' ); ?>"><?php esc_html_e( 'Keep as is', 'lingolane' ); ?></button>
                                            <?php endif; ?>
                                            <button type="submit" name="delete" value="1" class="button-link button-link-delete" data-lingolane-delete formnovalidate><?php esc_html_e( 'Delete', 'lingolane' ); ?></button>
                                            <button type="submit" class="button button-small button-primary" data-lingolane-save><?php esc_html_e( 'Save', 'lingolane' ); ?></button>
                                        </span>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php Lingolane_Admin::pagination( min( $page, $pages ), $pages, $total, self::PER_PAGE ); ?>
        </section>

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
