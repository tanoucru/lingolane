<?php
/**
 * Lingolane In-Context Frontend Translation (P4)
 *
 * Provides visual live translation directly on the frontend for administrators
 * and users with translation capabilities:
 * - Admin Bar toggle "Dịch trang này" / "Thoát chế độ dịch"
 * - In-Context toolbar with target language selector, progress counter & exit button
 * - Visual hover/focus highlights with edit pen icon
 * - Inline popover modal with original text, editable translation, AI suggestion hook, and instant DOM update
 * - Cache disabling via DONOTCACHEPAGE and nocache_headers()
 *
 * @package Lingolane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingolane_Incontext {

	const NONCE_ACTION = 'lingolane_incontext_action';

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_menu' ), 90 );
		add_action( 'init', array( __CLASS__, 'handle_request' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_lingolane_incontext_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_lingolane_incontext_manifest', array( __CLASS__, 'ajax_manifest' ) );
	}

	/**
	 * Determine if in-context translation mode is currently active.
	 *
	 * Requires query parameter lingolane_incontext=1 (or lingolane_editor=1)
	 * AND current user must have can_translate capability.
	 *
	 * @return bool
	 */
	public static function is_active() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$has_param = ( isset( $_GET['lingolane_incontext'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_GET['lingolane_incontext'] ) ) ) ||
					( isset( $_GET['lingolane_editor'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_GET['lingolane_editor'] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $has_param ) {
			return false;
		}

		if ( ! is_user_logged_in() || ! class_exists( 'Lingolane_Admin' ) || ! Lingolane_Admin::can_translate() ) {
			return false;
		}

		return true;
	}

	/**
	 * Handle request headers and cache prevention when in-context mode is active.
	 */
	public static function handle_request() {
		if ( ! self::is_active() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Add "Translate this page" / "Exit translation mode" toggle to WordPress Admin Bar.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin Bar instance.
	 */
	public static function admin_bar_menu( $admin_bar ) {
		if ( ! is_user_logged_in() || ! class_exists( 'Lingolane_Admin' ) || ! Lingolane_Admin::can_translate() ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		$is_active = self::is_active();
		$current_url = home_url( add_query_arg( array() ) );

		if ( $is_active ) {
			$href  = remove_query_arg( array( 'lingolane_incontext', 'lingolane_editor' ), $current_url );
			$title = '<span class="ab-icon dashicons-no-alt" aria-hidden="true" style="font-family:dashicons;margin-top:2px;"></span> ' . __( 'Thoát chế độ dịch', 'lingolane' );
		} else {
			$href  = add_query_arg( 'lingolane_incontext', '1', $current_url );
			$title = '<span class="ab-icon dashicons-translation" aria-hidden="true" style="font-family:dashicons;margin-top:2px;"></span> ' . __( 'Dịch trang này', 'lingolane' );
		}

		$admin_bar->add_node( array(
			'id'    => 'lingolane_incontext_toggle',
			'title' => $title,
			'href'  => esc_url( $href ),
			'meta'  => array(
				'title' => $is_active ? __( 'Thoát giao diện dịch trực quan Lingolane', 'lingolane' ) : __( 'Mở giao diện dịch trực quan Lingolane trên trang này', 'lingolane' ),
			),
		) );
	}

	/**
	 * Enqueue frontend CSS and JavaScript assets when in-context mode is active.
	 */
	public static function enqueue_scripts() {
		if ( ! self::is_active() ) {
			return;
		}

		wp_enqueue_style( 'lingolane-incontext', LINGOLANE_URL . 'assets/incontext.css', array(), LINGOLANE_VERSION );
		wp_enqueue_script( 'lingolane-ai-translate', LINGOLANE_URL . 'assets/ai-translate.js', array( 'jquery' ), LINGOLANE_VERSION, true );
		wp_localize_script( 'lingolane-ai-translate', 'lingolaneAIConfig', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => class_exists( 'Lingolane_AI' ) ? wp_create_nonce( Lingolane_AI::NONCE_ACTION ) : '',
		) );
		wp_enqueue_script( 'lingolane-incontext', LINGOLANE_URL . 'assets/incontext.js', array( 'jquery', 'lingolane-ai-translate' ), LINGOLANE_VERSION, true );

		$current_lang = class_exists( 'Lingolane_Languages' ) ? Lingolane_Languages::current() : 'en';
		$languages    = class_exists( 'Lingolane_Languages' ) ? Lingolane_Languages::all() : array();
		$page_path    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		if ( false !== strpos( $page_path, '?' ) ) {
			$parts     = explode( '?', $page_path );
			$page_path = $parts[0];
		}

		$post_data = null;
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$post_data = array(
					'id'       => $post->ID,
					'editUrl'  => get_edit_post_link( $post->ID, 'raw' ),
					'title'    => get_the_title( $post->ID ),
					'isShared' => class_exists( 'Lingolane_Shared' ) && Lingolane_Shared::post( $post ),
				);
			}
		}

		// Flags array with SVGs for language selector in toolbar.
		$lang_flags = array();
		if ( class_exists( 'Lingolane_Flags' ) ) {
			foreach ( $languages as $lang_item ) {
				$c = $lang_item['code'];
				$lang_flags[ $c ] = array(
					'name' => $lang_item['name'],
					'svg'  => Lingolane_Flags::get_flag_svg( $c, 18, 13, 'lingolane-flag--shadow' ),
				);
			}
		}

		wp_localize_script( 'lingolane-incontext', 'lingolaneInContextData', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
			'currentLang'  => $current_lang,
			'languages'    => $languages,
			'flags'        => $lang_flags,
			'homeUrl'      => home_url( '/' ),
			'pagePath'     => $page_path,
			'postData'     => $post_data,
			'exitUrl'      => esc_url( remove_query_arg( array( 'lingolane_incontext', 'lingolane_editor' ) ) ),
			'i18n'         => array(
				'toolbarTitle'   => __( 'Dịch trực quan', 'lingolane' ),
				'translatingTo'  => __( 'Đang dịch sang:', 'lingolane' ),
				'exit'           => __( 'Thoát', 'lingolane' ),
				'editPostMsg'    => __( 'Nội dung bài viết: Bấm để mở trong Trình biên tập', 'lingolane' ),
				'editPostBtn'    => __( 'Sửa bài viết', 'lingolane' ),
				'save'           => __( 'Lưu bản dịch', 'lingolane' ),
				'saving'         => __( 'Đang lưu...', 'lingolane' ),
				'cancel'         => __( 'Huỷ', 'lingolane' ),
				'originalText'   => __( 'Bản gốc', 'lingolane' ),
				'translation'    => __( 'Bản dịch', 'lingolane' ),
				'groupLabel'     => __( 'Vị trí:', 'lingolane' ),
				'savedSuccess'   => __( 'Đã lưu bản dịch thành công!', 'lingolane' ),
				'saveFailed'     => __( 'Lỗi khi lưu bản dịch.', 'lingolane' ),
				'aiSuggest'      => __( 'Gợi ý AI', 'lingolane' ),
				'filterAll'      => __( 'Tất cả', 'lingolane' ),
				'filterMissing'  => __( 'Chưa dịch', 'lingolane' ),
				/* translators: 1: translated string count, 2: total string count. */
				'progressFormat' => __( '%1$d/%2$d chuỗi đã dịch', 'lingolane' ),
			),
		) );
	}

	/**
	 * Programmatically save a translation and record its location.
	 *
	 * @param array $data Input payload.
	 * @return array|WP_Error Result or error.
	 */
	public static function save_translation( array $data ) {
		if ( ! is_user_logged_in() || ! class_exists( 'Lingolane_Admin' ) || ! Lingolane_Admin::can_translate() ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to save translations.', 'lingolane' ) );
		}

		$domain        = isset( $data['domain'] ) ? sanitize_key( $data['domain'] ) : 'lingolane';
		$source        = isset( $data['source'] ) ? wp_unslash( $data['source'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$context       = isset( $data['context'] ) ? sanitize_text_field( wp_unslash( $data['context'] ) ) : '';
		$language_code = isset( $data['language_code'] ) ? sanitize_key( $data['language_code'] ) : '';
		$translation   = isset( $data['translation'] ) ? wp_unslash( $data['translation'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$group_key     = isset( $data['group_key'] ) ? sanitize_key( $data['group_key'] ) : 'other';
		$page_path     = isset( $data['page_path'] ) ? sanitize_text_field( wp_unslash( $data['page_path'] ) ) : '/';
		$locator       = isset( $data['locator'] ) ? sanitize_text_field( wp_unslash( $data['locator'] ) ) : '';

		if ( '' === $source || '' === $language_code ) {
			return new WP_Error( 'invalid_data', __( 'Source text and language code are required.', 'lingolane' ) );
		}

		// 1. Save translation in Lingolane_Strings.
		$save_result = Lingolane_Strings::save( $domain, $source, $context, $language_code, $translation );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		// 2. Record or update location occurrence.
		if ( class_exists( 'Lingolane_String_Locations' ) ) {
			$identity = array(
				'domain'  => $domain,
				'source'  => $source,
				'context' => $context,
			);
			$occurrence = array(
				'group_key' => $group_key,
				'subgroup'  => '',
				'provider'  => 'site',
				'locator'   => $locator ? $locator : 'incontext',
				'page_path' => $page_path,
				'supported' => 1,
			);
			Lingolane_String_Locations::record( $identity, $occurrence );
		}

		return array(
			'success'     => true,
			'source'      => $source,
			'translation' => $translation,
			'language'    => $language_code,
			'updated_at'  => current_time( 'mysql' ),
		);
	}

	/**
	 * AJAX handler to save in-context string translation.
	 */
	public static function ajax_save() {
		if ( ! is_user_logged_in() || ! class_exists( 'Lingolane_Admin' ) || ! Lingolane_Admin::can_translate() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'lingolane' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'lingolane' ) ), 403 );
		}

		$payload = array(
			'domain'        => isset( $_POST['domain'] ) ? sanitize_key( wp_unslash( $_POST['domain'] ) ) : 'lingolane',
			'source'        => isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'context'       => isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '',
			'language_code' => isset( $_POST['language_code'] ) ? sanitize_key( wp_unslash( $_POST['language_code'] ) ) : '',
			'translation'   => isset( $_POST['translation'] ) ? wp_unslash( $_POST['translation'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'group_key'     => isset( $_POST['group_key'] ) ? sanitize_key( wp_unslash( $_POST['group_key'] ) ) : 'other',
			'page_path'     => isset( $_POST['page_path'] ) ? sanitize_text_field( wp_unslash( $_POST['page_path'] ) ) : '/',
			'locator'       => isset( $_POST['locator'] ) ? sanitize_text_field( wp_unslash( $_POST['locator'] ) ) : '',
		);

		$result = self::save_translation( $payload );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler to return manifest of known strings and their translation status for current page.
	 */
	public static function ajax_manifest() {
		if ( ! is_user_logged_in() || ! class_exists( 'Lingolane_Admin' ) || ! Lingolane_Admin::can_translate() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'lingolane' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'lingolane' ) ), 403 );
		}

		$target_lang = isset( $_POST['target_lang'] ) ? sanitize_key( wp_unslash( $_POST['target_lang'] ) ) : Lingolane_Languages::current();
		$page_path   = isset( $_POST['page_path'] ) ? sanitize_text_field( wp_unslash( $_POST['page_path'] ) ) : '/';

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$locations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.source_hash, l.group_key, l.provider, l.locator, s.domain, s.context, s.source, s.translation
				FROM {$wpdb->prefix}lingolane_string_locations l
				LEFT JOIN {$wpdb->prefix}lingolane_strings s
					ON l.source_hash = s.source_hash AND s.language_code = %s
				WHERE l.page_path = %s",
				$target_lang,
				$page_path
			),
			ARRAY_A
		);

		wp_send_json_success( array(
			'target_lang' => $target_lang,
			'strings'     => (array) $locations,
		) );
	}
}
