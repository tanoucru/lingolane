<?php
/**
 * Lingolane AI Post Translation Engine (P5)
 *
 * Handles post and page translation while preserving HTML tags,
 * Gutenberg block comments (<!-- wp:... -->), shortcodes, and media.
 * Sets the machine-translated flag and creates posts in 'draft' status.
 *
 * @package Lingolane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingolane_AI_Post {

	const META_FLAG = '_lingolane_machine_translated';

	public static function init() {
		add_action( 'admin_post_lingolane_ai_translate_post', array( __CLASS__, 'handle_action_translate_post' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Translate HTML and Gutenberg block content while preserving tags, block comments, and shortcodes.
	 *
	 * @param string $html Source HTML or block markup.
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @return string Translated HTML markup.
	 */
	public static function translate_html( $html, $source_lang, $target_lang ) {
		if ( '' === trim( (string) $html ) ) {
			return '';
		}

		// Tokenize markup: Gutenberg comments, HTML tags, and shortcodes
		$pattern = '/(<!--[\s\S]*?-->|<[^>]+>|\[\/?[\w-]+(?:\s+[^\]]*)?\])/u';
		$parts   = preg_split( $pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) ) {
			return $html;
		}

		$output = '';
		foreach ( $parts as $part ) {
			// Check if part is a comment, tag, or shortcode
			$first = isset( $part[0] ) ? $part[0] : '';
			if ( '<' === $first || '[' === $first ) {
				$output .= $part;
				continue;
			}

			// Plain text node: check for letters
			if ( preg_match( '/\p{L}/u', $part ) ) {
				preg_match( '/^(\s*)([\s\S]*?)(\s*)$/u', $part, $ws );
				$leading  = isset( $ws[1] ) ? $ws[1] : '';
				$inner    = isset( $ws[2] ) ? $ws[2] : '';
				$trailing = isset( $ws[3] ) ? $ws[3] : '';

				if ( '' !== $inner && preg_match( '/\p{L}/u', $inner ) ) {
					$trans = Lingolane_AI::translate_text( $inner, $source_lang, $target_lang );
					if ( ! is_wp_error( $trans ) && '' !== $trans ) {
						$inner = $trans;
					}
				}
				$output .= $leading . $inner . $trailing;
			} else {
				$output .= $part;
			}
		}

		return $output;
	}

	/**
	 * Duplicate and translate an entire post/page using AI.
	 *
	 * @param int    $post_id Original post ID.
	 * @param string $target_lang Target language code.
	 * @return int|WP_Error New post ID on success, WP_Error on failure.
	 */
	public static function translate_post( $post_id, $target_lang ) {
		return new WP_Error(
			'ai_post_translation_disabled',
			__( 'Tính năng nhân bản và dịch toàn bài bằng AI tạm thời bị vô hiệu hóa để bảo đảm an toàn dữ liệu, chống ghi đè bản dịch và chống rủi ro chi phí/race condition theo tiêu chuẩn phát hành.', 'lingolane' )
		);
	}

	/**
	 * Generate URL for AI post translation action with nonce.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang Language code.
	 * @return string Action URL.
	 */
	public static function action_url( $post_id, $lang ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'lingolane_ai_translate_post',
					'post'   => (int) $post_id,
					'lang'   => sanitize_key( $lang ),
				),
				admin_url( 'admin-post.php' )
			),
			'lingolane_ai_post_' . (int) $post_id . '_' . sanitize_key( $lang )
		);
	}

	/**
	 * Handle admin_post action to duplicate and translate a post.
	 */
	public static function handle_action_translate_post() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$lang    = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Bạn không có quyền thực hiện thao tác này.', 'lingolane' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'lingolane_ai_post_' . $post_id . '_' . $lang );

		$new_id = self::translate_post( $post_id, $lang );

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ), '', array( 'response' => 400 ) );
		}

		$edit_url = add_query_arg( 'lingolane_ai_created', '1', get_edit_post_link( $new_id, 'raw' ) );
		wp_safe_redirect( $edit_url );
		exit;
	}

	/**
	 * Admin notice when a post was created via AI translation.
	 */
	public static function admin_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['lingolane_ai_created'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_GET['lingolane_ai_created'] ) ) ) {
			echo '<div class="notice notice-info is-dismissible"><p><strong>' .
				 esc_html__( 'Bản dịch máy bằng AI đã được tạo thành công ở dạng Bản nháp.', 'lingolane' ) .
				 '</strong> ' .
				 esc_html__( 'Cấu trúc bài viết, thẻ HTML và các khối Gutenberg đã được giữ nguyên vẹn. Vui lòng rà soát và nhấn Đăng khi hoàn tất.', 'lingolane' ) .
				 '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
