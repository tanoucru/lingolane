<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TML_Strings_Admin {
    const PER_PAGE = 30;

    public static function init() {
        add_action( 'admin_post_tml_strings', array( __CLASS__, 'handle' ) );
        add_action( 'wp_ajax_tml_save_string', array( __CLASS__, 'ajax_save' ) );
        add_action( 'wp_ajax_tml_delete_string', array( __CLASS__, 'ajax_delete' ) );
    }

    private static function input( $data, $key ) {
        return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? sanitize_text_field( wp_unslash( $data[ $key ] ) ) : '';
    }

    public static function theme_domain() {
        $domain = wp_get_theme( get_template() )->get( 'TextDomain' );
        return is_string( $domain ) && '' !== $domain ? $domain : get_template();
    }

    private static function form( $operation, $class = '' ) {
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
        echo '<input type="hidden" name="action" value="tml_strings"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
        wp_nonce_field( 'tml_strings_' . $operation );
    }

    /* ------------------------------------------------------------------ requests */

    public static function handle() {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Bạn không có quyền thực hiện thao tác này.', 'tml-multilingual' ), '', array( 'response' => 403 ) ); }
        $operation = self::input( $_POST, 'operation' ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! in_array( $operation, array( 'row', 'import', 'export' ), true ) ) { TML_Admin::redirect( 'strings' ); }
        check_admin_referer( 'tml_strings_' . $operation );
        $table = $wpdb->prefix . 'tml_strings';

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
            $result = $delete ? self::delete_row( $id ) : self::save_row( $id, isset( $_POST['translation'] ) && is_string( $_POST['translation'] ) ? wp_unslash( $_POST['translation'] ) : null ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            if ( is_wp_error( $result ) ) {
                TML_Admin::flash( 'error', $result->get_error_message() );
            } else {
                TML_Admin::flash( 'success', $delete ? __( 'Đã xoá chuỗi.', 'tml-multilingual' ) : __( 'Đã lưu bản dịch.', 'tml-multilingual' ) );
            }
            TML_Admin::back( 'strings' );
        }

        $rows = self::read_csv();
        if ( is_wp_error( $rows ) ) {
            TML_Admin::flash( 'error', $rows->get_error_message() );
            TML_Admin::back( 'strings' );
        }
        $saved = 0;
        foreach ( $rows as $row ) {
            $result = TML_Strings::save( $row[0], $row[2], $row[1], $row[3], $row[4] );
            if ( is_wp_error( $result ) ) {
                /* translators: 1: rows saved before the error, 2: error */
                TML_Admin::flash( 'error', sprintf( __( 'Đã nhập %1$d dòng rồi dừng: %2$s', 'tml-multilingual' ), $saved, $result->get_error_message() ) );
                TML_Admin::back( 'strings' );
            }
            ++$saved;
        }
        TML_Stats::flush_strings();
        /* translators: %d: rows */
        TML_Admin::flash( 'success', sprintf( __( 'Đã nhập %d bản dịch.', 'tml-multilingual' ), $saved ) );
        TML_Admin::redirect( 'strings' );
    }

    private static function find( $id ) {
        global $wpdb;
        return $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tml_strings WHERE id = %d", $id ) ) : null;
    }

    private static function save_row( $id, $translation ) {
        $row = self::find( $id );
        if ( ! $row || ! is_string( $translation ) ) { return new WP_Error( 'tml_string', __( 'Không tìm thấy chuỗi này. Tải lại trang rồi thử lại.', 'tml-multilingual' ) ); }
        $result = TML_Strings::save( $row->domain, $row->source, (string) $row->context, $row->language_code, trim( $translation ) );
        if ( ! is_wp_error( $result ) ) { TML_Stats::flush_strings(); }
        return is_wp_error( $result ) ? $result : $row;
    }

    private static function delete_row( $id ) {
        global $wpdb;
        $row = self::find( $id );
        if ( ! $row ) { return new WP_Error( 'tml_string', __( 'Chuỗi này đã được xoá.', 'tml-multilingual' ) ); }
        // A string is registered once per language; remove it everywhere so it does not reappear half-listed.
        $wpdb->delete( $wpdb->prefix . 'tml_strings', array( 'domain' => $row->domain, 'source_hash' => $row->source_hash ), array( '%s', '%s' ) );
        TML_Strings::flush_cache();
        TML_Stats::flush_strings();
        return true;
    }

    private static function ajax_guard() {
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'tml_strings_ajax', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Phiên làm việc đã hết hạn. Tải lại trang.', 'tml-multilingual' ) ), 403 );
        }
    }

    public static function ajax_save() {
        self::ajax_guard();
        $translation = isset( $_POST['translation'] ) && is_string( $_POST['translation'] ) ? wp_unslash( $_POST['translation'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $row = self::save_row( absint( self::input( $_POST, 'id' ) ), $translation ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( is_wp_error( $row ) ) { wp_send_json_error( array( 'message' => $row->get_error_message() ), 400 ); }
        $saved = trim( (string) $translation );
        $status = '' !== $saved ? 'own' : ( '' !== TML_Strings::inherited( $row->source, $row->domain, (string) $row->context, $row->language_code ) ? 'inherited' : 'missing' );
        wp_send_json_success( array( 'status' => $status, 'translation' => wp_kses_post( $saved ) ) );
    }

    public static function ajax_delete() {
        self::ajax_guard();
        $result = self::delete_row( absint( self::input( $_POST, 'id' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
        wp_send_json_success();
    }

    private static function read_csv() {
        $error = function ( $message ) { return new WP_Error( 'tml_csv', $message ); };
        if ( ! isset( $_FILES['csv'] ) || ! is_array( $_FILES['csv'] ) ) { return $error( __( 'Chưa chọn file CSV.', 'tml-multilingual' ) ); } // phpcs:ignore WordPress.Security.NonceVerification
        $file = $_FILES['csv']; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        if ( ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || ! is_scalar( $file['error'] ) || (int) $file['error'] !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return $error( __( 'Tải file lên không thành công. Thử lại.', 'tml-multilingual' ) ); }
        if ( strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) ) !== 'csv' || filesize( $file['tmp_name'] ) > 2 * MB_IN_BYTES ) { return $error( __( 'Chỉ nhận file .csv tối đa 2 MB.', 'tml-multilingual' ) ); }
        $stream = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $stream ) { return $error( __( 'Không đọc được file CSV.', 'tml-multilingual' ) ); }
        $rows = array();
        try {
            $header = fgetcsv( $stream, 0, ',', '"', '' );
            if ( ! is_array( $header ) || ! isset( $header[0] ) ) { return $error( __( 'File CSV trống hoặc thiếu dòng tiêu đề.', 'tml-multilingual' ) ); }
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
            $header = array_map( 'trim', $header );
            $expected = array( 'domain', 'context', 'source', 'language', 'translation' );
            if ( count( $header ) !== count( $expected ) || array_diff( $expected, $header ) ) {
                /* translators: %s: column list */
                return $error( sprintf( __( 'Dòng tiêu đề phải gồm các cột: %s. Chưa có dữ liệu nào được ghi.', 'tml-multilingual' ), implode( ', ', $expected ) ) );
            }
            $line = 1;
            while ( ( $cells = fgetcsv( $stream, 0, ',', '"', '' ) ) !== false ) {
                ++$line;
                if ( $cells === array( null ) ) { continue; }
                /* translators: %d: line number */
                if ( count( $cells ) !== count( $header ) ) { return $error( sprintf( __( 'Dòng %d có số cột không khớp tiêu đề. Chưa có dữ liệu nào được ghi.', 'tml-multilingual' ), $line ) ); }
                foreach ( $cells as &$cell ) {
                    /* translators: %d: line number */
                    if ( ! is_string( $cell ) || strpos( $cell, "\0" ) !== false || ! seems_utf8( $cell ) ) { return $error( sprintf( __( 'Dòng %d không phải văn bản UTF-8 hợp lệ. Lưu file với mã hoá UTF-8 rồi thử lại.', 'tml-multilingual' ), $line ) ); }
                    if ( preg_match( "/^'(?:['=+@\\-\\t\\r\\n]|\\s+[=+@\\-])/", $cell ) ) { $cell = substr( $cell, 1 ); }
                }
                unset( $cell );
                $data = array_combine( $header, $cells );
                $domain = sanitize_text_field( $data['domain'] );
                $context = sanitize_text_field( $data['context'] );
                $source = $data['source']; // Exact gettext key; UTF-8 and NUL already validated.
                $language = sanitize_key( $data['language'] );
                if ( $domain === '' || strlen( $domain ) > 100 || strlen( $context ) > 100 || $source === '' || ! TML_Languages::is_valid( $language ) || $language !== $data['language'] ) {
                    /* translators: %d: line number */
                    return $error( sprintf( __( 'Dòng %d thiếu chuỗi gốc hoặc mã ngôn ngữ không có trên website. Chưa có dữ liệu nào được ghi.', 'tml-multilingual' ), $line ) );
                }
                $rows[] = array( $domain, $context, $source, $language, wp_kses_post( $data['translation'] ) );
            }
        } finally { fclose( $stream ); } // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $rows ) { return $error( __( 'File không có dòng dữ liệu nào.', 'tml-multilingual' ) ); }
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
                        $found[ TML_Strings::hash( $source, $context ) ] = array( $source, $context );
                    }
                }
            }
        }
        $table = $wpdb->prefix . 'tml_strings';
        $before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source_hash) FROM $table WHERE domain = %s", $domain ) );
        foreach ( $found as $item ) { tml_register_string( $domain, $item[0], $item[1] ); }
        $after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source_hash) FROM $table WHERE domain = %s", $domain ) );
        // Remove unused entries only when no language has a translation for them.
        $removed = 0;
        $hashes = $wpdb->get_col( $wpdb->prepare( "SELECT source_hash FROM $table WHERE domain = %s GROUP BY source_hash HAVING MAX(translation <> '') = 0", $domain ) );
        foreach ( (array) $hashes as $hash ) {
            if ( ! isset( $found[ $hash ] ) ) {
                $removed += (int) $wpdb->delete( $table, array( 'domain' => $domain, 'source_hash' => $hash, 'translation' => '' ), array( '%s', '%s', '%s' ) ) ? 1 : 0;
            }
        }
        if ( $removed ) { TML_Strings::flush_cache(); }
        return array( 'found' => count( $found ), 'added' => max( 0, $after - $before ), 'removed' => $removed );
    }

    /* ------------------------------------------------------------------ screen */

    public static function render_tab_content() {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $others = TML_Stats::other_languages();
        if ( ! $others ) {
            echo '<section class="tml-card tml-empty"><h2>' . esc_html__( 'Chưa có ngôn ngữ để dịch', 'tml-multilingual' ) . '</h2><p>' . esc_html__( 'Thêm ngôn ngữ thứ hai ở tab Ngôn ngữ.', 'tml-multilingual' ) . '</p></section>';
            return;
        }
        $table    = $wpdb->prefix . 'tml_strings';
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
                $text = TML_Strings::inherited( $candidate->source, $candidate->domain, (string) $candidate->context, $language );
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
        $stats = TML_Stats::strings();
        $s = isset( $stats[ $language ] ) ? $stats[ $language ] : array( 'total' => 0, 'own' => 0, 'inherited' => 0, 'missing' => 0 );
        $language_name = TML_Languages::name( $language );
        $tabs = array(
            ''          => array( __( 'Tất cả', 'tml-multilingual' ), $s['total'] ),
            'missing'   => array( __( 'Chưa dịch', 'tml-multilingual' ), $s['missing'] ),
            'own'       => array( __( 'Đã dịch', 'tml-multilingual' ), $s['own'] ),
            'inherited' => array( __( 'Giao diện có sẵn', 'tml-multilingual' ), $s['inherited'] ),
        );
        $base = array( 'language' => $language, 'domain' => $domain, 'keyword' => $keyword );
        ?>
        <section class="tml-card">
            <div class="tml-card__head">
                <h2 class="tml-card__title"><?php /* translators: %s: language */ echo esc_html( sprintf( __( 'Chữ trên giao diện · %s', 'tml-multilingual' ), $language_name ) ); ?></h2>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tml_scan_theme_strings' ), 'tml_scan_theme_strings' ) ); ?>"><span class="dashicons dashicons-search" aria-hidden="true"></span><?php esc_html_e( 'Quét giao diện', 'tml-multilingual' ); ?></a>
            </div>

            <ul class="tml-segments" aria-label="<?php esc_attr_e( 'Lọc theo tình trạng', 'tml-multilingual' ); ?>">
                <?php foreach ( $tabs as $key => $item ) : ?>
                    <li><a href="<?php echo esc_url( TML_Admin::url( 'strings', array_filter( array_merge( $base, array( 'status' => $key ) ) ) ) ); ?>" class="<?php echo $key === $status ? 'is-active' : ''; ?>"<?php echo $key === $status ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $item[0] ); ?> <span class="tml-segments__count"><?php echo esc_html( number_format_i18n( $item[1] ) ); ?></span></a></li>
                <?php endforeach; ?>
            </ul>

            <form method="get" class="tml-filters" role="search">
                <input type="hidden" name="page" value="<?php echo esc_attr( TML_Admin::PAGE ); ?>">
                <input type="hidden" name="tab" value="strings">
                <?php if ( $status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"><?php endif; ?>
                <?php if ( count( $others ) > 1 ) : ?>
                    <div class="tml-field">
                        <label for="tml-s-lang"><?php esc_html_e( 'Dịch sang', 'tml-multilingual' ); ?></label>
                        <select id="tml-s-lang" name="language">
                            <?php foreach ( $others as $item ) : ?>
                                <option value="<?php echo esc_attr( $item['code'] ); ?>" <?php selected( $language, $item['code'] ); ?>><?php echo esc_html( $item['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else : ?>
                    <input type="hidden" name="language" value="<?php echo esc_attr( $language ); ?>">
                <?php endif; ?>
                <?php if ( count( (array) $domains ) > 1 ) : ?>
                    <div class="tml-field">
                        <label for="tml-s-domain"><?php esc_html_e( 'Nguồn', 'tml-multilingual' ); ?></label>
                        <select id="tml-s-domain" name="domain">
                            <option value=""><?php esc_html_e( 'Tất cả', 'tml-multilingual' ); ?></option>
                            <?php foreach ( (array) $domains as $item ) : ?>
                                <option value="<?php echo esc_attr( $item ); ?>" <?php selected( $domain, $item ); ?>><?php echo esc_html( $item ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="tml-field tml-field--grow">
                    <label for="tml-s-keyword"><?php esc_html_e( 'Tìm', 'tml-multilingual' ); ?></label>
                    <input id="tml-s-keyword" type="search" name="keyword" value="<?php echo esc_attr( $keyword ); ?>" placeholder="<?php esc_attr_e( 'Chữ gốc hoặc bản dịch', 'tml-multilingual' ); ?>">
                </div>
                <div class="tml-field tml-field--actions">
                    <button type="submit" class="button"><?php esc_html_e( 'Lọc', 'tml-multilingual' ); ?></button>
                    <?php if ( '' !== $keyword || '' !== $domain ) : ?>
                        <a class="button-link" href="<?php echo esc_url( TML_Admin::url( 'strings', array_filter( array( 'language' => $language, 'status' => $status ) ) ) ); ?>"><?php esc_html_e( 'Xoá bộ lọc', 'tml-multilingual' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <p class="tml-kbd-hint"><?php echo wp_kses( __( 'Mẹo: gõ bản dịch rồi nhấn <kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>Enter</kbd> để lưu và chuyển sang chuỗi kế tiếp.', 'tml-multilingual' ), array( 'kbd' => array() ) ); ?></p>

            <div class="tml-table-wrap">
                <table class="tml-table tml-strings">
                    <thead>
                        <tr>
                            <th scope="col" class="tml-strings__source"><?php /* translators: %s: language */ echo esc_html( sprintf( __( 'Chữ gốc (%s)', 'tml-multilingual' ), TML_Languages::name( TML_Languages::source_code() ) ) ); ?></th>
                            <th scope="col"><?php /* translators: %s: language */ echo esc_html( sprintf( __( 'Bản dịch %s', 'tml-multilingual' ), $language_name ) ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( ! $rows ) : ?>
                        <tr><td colspan="2" class="tml-table__empty">
                            <?php
                            if ( 'missing' === $status ) {
                                echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Không còn chuỗi nào chưa dịch.', 'tml-multilingual' );
                            } elseif ( ! $s['total'] ) {
                                esc_html_e( 'Danh sách đang trống. Bấm “Quét giao diện” để lấy chữ từ theme, hoặc nhập file CSV bên dưới.', 'tml-multilingual' );
                            } else {
                                esc_html_e( 'Không có chuỗi nào khớp bộ lọc.', 'tml-multilingual' );
                            }
                            ?>
                        </td></tr>
                    <?php else : foreach ( (array) $rows as $row ) :
                        $id = (int) $row->id;
                        $inherit = array_key_exists( $id, $inherited ) ? $inherited[ $id ] : ( '' === $row->translation ? TML_Strings::inherited( $row->source, $row->domain, (string) $row->context, $language ) : '' );
                        $state = '' !== $row->translation ? 'own' : ( '' !== $inherit ? 'inherited' : 'missing' );
                        $chips = array( 'own' => array( __( 'Đã dịch', 'tml-multilingual' ), 'ok' ), 'inherited' => array( __( 'Giao diện có sẵn', 'tml-multilingual' ), 'info' ), 'missing' => array( __( 'Chưa dịch', 'tml-multilingual' ), 'warn' ) );
                        ?>
                        <tr data-tml-row="<?php echo esc_attr( $id ); ?>">
                            <td class="tml-strings__source">
                                <div class="tml-source" lang="<?php echo esc_attr( TML_Languages::source_code() ); ?>" id="tml-src-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $row->source ); ?></div>
                                <span class="tml-meta"><?php echo esc_html( $row->domain . ( '' !== (string) $row->context ? ' · ' . $row->context : '' ) ); ?></span>
                            </td>
                            <td>
                                <?php self::form( 'row', 'tml-string' ); ?>
                                    <input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
                                    <label class="screen-reader-text" for="tml-str-<?php echo esc_attr( $id ); ?>"><?php /* translators: %s: language */ echo esc_html( sprintf( __( 'Bản dịch %s', 'tml-multilingual' ), $language_name ) ); ?></label>
                                    <textarea id="tml-str-<?php echo esc_attr( $id ); ?>" name="translation" rows="<?php echo esc_attr( max( 1, min( 6, (int) ceil( mb_strlen( $row->source ) / 70 ) ) ) ); ?>" lang="<?php echo esc_attr( $language ); ?>" aria-describedby="tml-src-<?php echo esc_attr( $id ); ?>" placeholder="<?php echo esc_attr( '' !== $inherit ? $inherit : __( 'Nhập bản dịch…', 'tml-multilingual' ) ); ?>" data-inherited="<?php echo esc_attr( $inherit ); ?>"><?php echo esc_textarea( $row->translation ); ?></textarea>
                                    <div class="tml-string__bar">
                                        <span class="tml-chip tml-chip--<?php echo esc_attr( $chips[ $state ][1] ); ?>" data-tml-status="<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $chips[ $state ][0] ); ?></span>
                                        <span class="tml-string__msg" role="status" aria-live="polite"></span>
                                        <span class="tml-string__actions">
                                            <?php if ( '' !== $inherit ) : ?>
                                                <button type="button" class="button-link" data-tml-copy><?php esc_html_e( 'Sửa từ bản có sẵn', 'tml-multilingual' ); ?></button>
                                            <?php elseif ( 'missing' === $state ) : ?>
                                                <button type="button" class="button-link" data-tml-same title="<?php esc_attr_e( 'Dùng cho tên riêng, mã chương trình… không cần dịch', 'tml-multilingual' ); ?>"><?php esc_html_e( 'Giữ nguyên', 'tml-multilingual' ); ?></button>
                                            <?php endif; ?>
                                            <button type="submit" name="delete" value="1" class="button-link button-link-delete" data-tml-delete formnovalidate><?php esc_html_e( 'Xoá', 'tml-multilingual' ); ?></button>
                                            <button type="submit" class="button button-small button-primary" data-tml-save><?php esc_html_e( 'Lưu', 'tml-multilingual' ); ?></button>
                                        </span>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php TML_Admin::pagination( min( $page, $pages ), $pages, $total, self::PER_PAGE ); ?>
        </section>

        <section class="tml-card">
            <h2 class="tml-card__title"><?php esc_html_e( 'Dịch hàng loạt bằng CSV', 'tml-multilingual' ); ?></h2>
            <div class="tml-grid tml-grid--two">
                <div class="tml-subcard">
                    <h3><?php esc_html_e( '1. Xuất file', 'tml-multilingual' ); ?></h3>
                    <p><?php esc_html_e( 'Tải toàn bộ chuỗi và bản dịch hiện có, mở bằng Google Sheets hoặc Excel để dịch.', 'tml-multilingual' ); ?></p>
                    <?php self::form( 'export' ); ?>
                        <button type="submit" class="button"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Tải file CSV', 'tml-multilingual' ); ?></button>
                    </form>
                </div>
                <div class="tml-subcard">
                    <h3><?php esc_html_e( '2. Nhập lại', 'tml-multilingual' ); ?></h3>
                    <?php self::form( 'import', 'tml-import' ); ?>
                        <div class="tml-field">
                            <label for="tml-csv"><?php esc_html_e( 'File CSV (UTF-8, tối đa 2 MB)', 'tml-multilingual' ); ?></label>
                            <input id="tml-csv" type="file" name="csv" accept=".csv,text/csv" required>
                        </div>
                        <p class="tml-hint"><?php esc_html_e( 'Dùng file đã xuất ở bước 1 (cột domain, context, source, language, translation).', 'tml-multilingual' ); ?></p>
                        <button type="submit" class="button button-primary"><span class="dashicons dashicons-upload" aria-hidden="true"></span><?php esc_html_e( 'Nhập bản dịch', 'tml-multilingual' ); ?></button>
                        <p class="tml-hint"><?php esc_html_e( 'File được kiểm tra trước khi ghi; nếu một dòng sai, không dòng nào bị ghi.', 'tml-multilingual' ); ?></p>
                    </form>
                </div>
            </div>
        </section>
        <?php
    }
}
