<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Lingolane_String_Scan {

    const MAX_ITEMS_PER_REQUEST = 200;
    const MAX_ITEMS_PER_SESSION = 500;
    const MAX_REQUESTS_PER_SESSION = 10;
    const SESSION_TTL = 900; // 15 minutes.
    const PREVIEW_TTL = 600; // 10 minutes.

    private static $allowed_providers = array( 'site', 'menu', 'media', 'block', 'gettext' );
    private static $disallowed_paths  = array( 'wp-admin', 'cart', 'checkout', 'my-account', 'account', 'login' );

    public static function init() {
        add_action( 'wp_ajax_lingolane_scan_pages', array( __CLASS__, 'ajax_pages' ) );
        add_action( 'wp_ajax_lingolane_scan_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_lingolane_scan_accept', array( __CLASS__, 'ajax_accept' ) );
        add_action( 'wp_ajax_lingolane_scan_preview', array( __CLASS__, 'ajax_preview' ) );

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_scripts' ) );
    }

    public static function pages() {
        if ( ! Lingolane_Admin::can_translate() ) {
            return array();
        }

        $discovered = array();

        // 1. Home.
        $discovered[] = array(
            'path'  => '/',
            'title' => __( 'Home', 'lingolane' ),
            'type'  => 'home',
        );

        // 2. Posts page (if distinct).
        $page_for_posts = (int) get_option( 'page_for_posts' );
        if ( $page_for_posts > 0 ) {
            $p_url = get_permalink( $page_for_posts );
            if ( $p_url ) {
                $path = self::clean_path( $p_url );
                if ( self::is_allowed_path( $path ) ) {
                    $discovered[] = array(
                        'path'  => $path,
                        'title' => get_the_title( $page_for_posts ),
                        'type'  => 'blog',
                    );
                }
            }
        }

        // 3. Up to 3 recent published posts.
        $recent_posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 3,
        ) );
        foreach ( (array) $recent_posts as $post ) {
            $url  = get_permalink( $post );
            $path = self::clean_path( $url );
            if ( self::is_allowed_path( $path ) ) {
                $discovered[] = array(
                    'path'  => $path,
                    'title' => get_the_title( $post ),
                    'type'  => 'post',
                );
            }
        }

        // 4. Up to 3 recent published pages.
        $recent_pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 3,
        ) );
        foreach ( (array) $recent_pages as $page ) {
            $url  = get_permalink( $page );
            $path = self::clean_path( $url );
            if ( self::is_allowed_path( $path ) ) {
                $discovered[] = array(
                    'path'  => $path,
                    'title' => get_the_title( $page ),
                    'type'  => 'page',
                );
            }
        }

        // 5. Up to 2 category term archives.
        $categories = get_terms( array(
            'taxonomy'   => 'category',
            'number'     => 2,
            'hide_empty' => true,
        ) );
        if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
            foreach ( (array) $categories as $cat ) {
                $link = get_term_link( $cat );
                if ( ! is_wp_error( $link ) ) {
                    $path = self::clean_path( $link );
                    if ( self::is_allowed_path( $path ) ) {
                        $discovered[] = array(
                            'path'  => $path,
                            'title' => $cat->name,
                            'type'  => 'archive',
                        );
                    }
                }
            }
        }

        // Deduplicate by path and cap at 10 items.
        $deduped = array();
        $seen    = array();
        foreach ( $discovered as $item ) {
            if ( ! isset( $seen[ $item['path'] ] ) ) {
                $seen[ $item['path'] ] = true;
                $deduped[]            = $item;
            }
            if ( count( $deduped ) >= 10 ) {
                break;
            }
        }

        return $deduped;
    }

    public static function clean_path( $url ) {
        if ( ! is_string( $url ) ) {
            return '/';
        }
        $parts = wp_parse_url( $url );
        $path  = isset( $parts['path'] ) ? $parts['path'] : '/';
        if ( '' === $path || '/' !== $path[0] ) {
            $path = '/' . $path;
        }
        return $path;
    }

    public static function is_allowed_path( $path ) {
        if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] || 0 === strpos( $path, '//' ) ) {
            return false;
        }
        if ( false !== strpos( $path, '?' ) || false !== strpos( $path, '#' ) ) {
            return false;
        }
        foreach ( self::$disallowed_paths as $disallowed ) {
            if ( false !== strpos( strtolower( $path ), $disallowed ) ) {
                return false;
            }
        }
        return true;
    }

    public static function start( array $paths ) {
        if ( ! Lingolane_Admin::can_translate() ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to start a scan session.', 'lingolane' ) );
        }

        $session_token = wp_generate_password( 32, false );
        $now           = time();
        $expires       = $now + self::SESSION_TTL;

        $cleaned_paths = array();
        foreach ( $paths as $p ) {
            $cp = self::clean_path( (string) $p );
            if ( self::is_allowed_path( $cp ) ) {
                $cleaned_paths[] = $cp;
            }
        }

        $session_data = array(
            'user_id'       => get_current_user_id(),
            'created'       => $now,
            'expires'       => $expires,
            'paths'         => array_unique( $cleaned_paths ),
            'request_count' => 0,
            'total_items'   => 0,
            'last_req'      => 0,
        );

        set_transient( 'lingolane_scan_' . $session_token, $session_data, self::SESSION_TTL );

        return array(
            'session'    => $session_token,
            'session_id' => $session_token,
            'pages'      => $session_data['paths'],
            'expires'    => $expires,
        );
    }

    public static function accept( $session, $path, array $items ) {
        if ( ! is_string( $session ) || '' === $session ) {
            return new WP_Error( 'invalid_session', __( 'Invalid scan session.', 'lingolane' ) );
        }

        $transient_key = 'lingolane_scan_' . $session;
        $session_data  = get_transient( $transient_key );
        if ( ! is_array( $session_data ) || ! isset( $session_data['user_id'] ) ) {
            return new WP_Error( 'session_expired', __( 'Scan session has expired or does not exist.', 'lingolane' ) );
        }

        if ( (int) $session_data['user_id'] !== get_current_user_id() || 0 === get_current_user_id() ) {
            return new WP_Error( 'unauthorized_session', __( 'Session does not belong to the current user.', 'lingolane' ) );
        }

        $clean_path = self::clean_path( $path );
        if ( ! self::is_allowed_path( $clean_path ) || ( false !== strpos( (string) $path, '://' ) ) ) {
            return new WP_Error( 'invalid_path', __( 'Path is disallowed or invalid.', 'lingolane' ) );
        }

        $item_count = count( $items );
        if ( $item_count > self::MAX_ITEMS_PER_REQUEST ) {
            return new WP_Error( 'payload_too_large', __( 'Item payload exceeds maximum per request limit.', 'lingolane' ) );
        }

        if ( ( $session_data['total_items'] + $item_count ) > self::MAX_ITEMS_PER_SESSION ) {
            return new WP_Error( 'payload_too_large', __( 'Session ceiling of maximum occurrences exceeded.', 'lingolane' ) );
        }

        if ( $session_data['request_count'] >= self::MAX_REQUESTS_PER_SESSION ) {
            return new WP_Error( 'rate_limited', __( 'Maximum request count reached for this session.', 'lingolane' ) );
        }

        $now = time();
        if ( $session_data['request_count'] > 0 && ( $now - $session_data['last_req'] ) < 1 ) {
            // Rate limit minimum 1 second between requests.
            return new WP_Error( 'rate_limited', __( 'Please wait at least 1 second between requests.', 'lingolane' ) );
        }

        $accepted = 0;
        $rejected = 0;

        foreach ( $items as $it ) {
            if ( ! isset( $it['identity'], $it['occurrence'] ) || ! is_array( $it['identity'] ) || ! is_array( $it['occurrence'] ) ) {
                $rejected++;
                continue;
            }

            $identity   = $it['identity'];
            $occurrence = $it['occurrence'];

            // Validate provider and supported flag.
            $provider = isset( $occurrence['provider'] ) ? sanitize_key( $occurrence['provider'] ) : '';
            if ( ! in_array( $provider, self::$allowed_providers, true ) ) {
                $occurrence['supported'] = 0;
            }

            $occurrence['page_path'] = $clean_path;

            $record_res = Lingolane_String_Locations::record( $identity, $occurrence );
            if ( is_wp_error( $record_res ) || false === $record_res ) {
                $rejected++;
            } else {
                $accepted++;
            }
        }

        // Update session tracking.
        $session_data['request_count']++;
        $session_data['total_items'] += $accepted;
        $session_data['last_req']     = $now;
        set_transient( $transient_key, $session_data, self::SESSION_TTL );

        return array(
            'accepted' => $accepted,
            'rejected' => $rejected,
        );
    }

    public static function preview_url( $occurrence_id ) {
        if ( ! Lingolane_Admin::can_translate() ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to preview strings.', 'lingolane' ) );
        }

        global $wpdb;
        $occ = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lingolane_string_locations WHERE id = %d LIMIT 1", absint( $occurrence_id ) ), ARRAY_A );
        if ( ! $occ ) {
            return new WP_Error( 'occurrence_not_found', __( 'Occurrence not found.', 'lingolane' ) );
        }

        $token = wp_generate_password( 24, false );
        $preview_data = array(
            'user_id'       => get_current_user_id(),
            'occurrence_id' => (int) $occ['id'],
            'locator'       => (string) $occ['locator'],
            'page_path'     => (string) $occ['page_path'],
        );
        set_transient( 'lingolane_prev_' . $token, $preview_data, self::PREVIEW_TTL );

        $target = home_url( $occ['page_path'] );
        return add_query_arg( 'lingolane_preview', $token, $target );
    }

    public static function enqueue_frontend_scripts() {
        if ( ! is_user_logged_in() || ! Lingolane_Admin::can_translate() ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $preview_token = isset( $_GET['lingolane_preview'] ) ? sanitize_key( wp_unslash( $_GET['lingolane_preview'] ) ) : '';
        $scan_token    = '';
        if ( ! empty( $_GET['token'] ) ) {
            $scan_token = sanitize_key( wp_unslash( $_GET['token'] ) );
        } elseif ( ! empty( $_GET['lingolane_scan'] ) && '1' !== $_GET['lingolane_scan'] ) {
            $scan_token = sanitize_key( wp_unslash( $_GET['lingolane_scan'] ) );
        }

        $is_scan_active = ( '' !== $scan_token || ( isset( $_GET['lingolane_scan'] ) && '1' === $_GET['lingolane_scan'] ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( '' === $preview_token && ! $is_scan_active ) {
            return;
        }

        $locator = '';
        if ( '' !== $preview_token ) {
            $data = get_transient( 'lingolane_prev_' . $preview_token );
            if ( is_array( $data ) && isset( $data['locator'] ) ) {
                $locator = $data['locator'];
            }
        }

        wp_enqueue_style( 'lingolane-string-scan', LINGOLANE_URL . 'assets/string-scan.css', array(), LINGOLANE_VERSION );
        wp_enqueue_script( 'lingolane-string-scan', LINGOLANE_URL . 'assets/string-scan.js', array(), LINGOLANE_VERSION, true );

        wp_localize_script( 'lingolane-string-scan', 'lingolaneScanData', array(
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'lingolane_scan_ajax' ),
            'preview_token' => $preview_token,
            'scan_token'    => $scan_token,
            'locator'       => $locator,
            'i18n'          => array(
                'notFound'   => __( 'Text no longer found. Scan this page again.', 'lingolane' ),
                'highlight'  => __( 'Highlighted element', 'lingolane' ),
                'scanActive' => __( 'Scan mode active...', 'lingolane' ),
            ),
        ) );
    }

    public static function ajax_pages() {
        if ( ! Lingolane_Admin::can_translate() || ! check_ajax_referer( 'lingolane_scan_ajax', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'lingolane' ) ), 403 );
        }
        wp_send_json_success( self::pages() );
    }

    public static function ajax_start() {
        if ( ! Lingolane_Admin::can_translate() || ! check_ajax_referer( 'lingolane_scan_ajax', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'lingolane' ) ), 403 );
        }
        $paths  = isset( $_POST['paths'] ) && is_array( $_POST['paths'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['paths'] ) ) : array( '/' );
        $result = self::start( $paths );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_accept() {
        if ( ! Lingolane_Admin::can_translate() || ! check_ajax_referer( 'lingolane_scan_ajax', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'lingolane' ) ), 403 );
        }
        $session = isset( $_POST['session'] ) ? sanitize_key( wp_unslash( $_POST['session'] ) ) : '';
        $path    = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '/';
        $items   = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        $result = self::accept( $session, $path, $items );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_preview() {
        if ( ! Lingolane_Admin::can_translate() || ! check_ajax_referer( 'lingolane_scan_ajax', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'lingolane' ) ), 403 );
        }
        $occ_id = isset( $_POST['occurrence_id'] ) ? absint( $_POST['occurrence_id'] ) : 0;
        $url    = self::preview_url( $occ_id );
        if ( is_wp_error( $url ) ) {
            wp_send_json_error( array( 'message' => $url->get_error_message() ), 400 );
        }
        wp_send_json_success( array( 'url' => $url ) );
    }
}
