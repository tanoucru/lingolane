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
		$post_id     = absint( $post_id );
		$target_lang = sanitize_key( $target_lang );
		$post        = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Không tìm thấy bài viết gốc.', 'lingolane' ) );
		}

		$source_lang = Lingolane_Elements::get_language( 'post_' . $post->post_type, $post_id );
		if ( ! $source_lang ) {
			$source_lang = Lingolane_Languages::source_code();
		}

		// 1. Duplicate post as draft in target language
		$new_id = Lingolane_Elements::duplicate_post( $post_id, $target_lang, 'draft' );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// 2. Translate post_title
		$translated_title   = $post->post_title;
		$translated_excerpt = $post->post_excerpt;
		$translated_content = $post->post_content;

		if ( '' !== trim( $post->post_title ) ) {
			$res_title = Lingolane_AI::translate_text( $post->post_title, $source_lang, $target_lang );
			if ( ! is_wp_error( $res_title ) && '' !== $res_title ) {
				$translated_title = $res_title;
			}
		}

		// 3. Translate post_excerpt
		if ( '' !== trim( $post->post_excerpt ) ) {
			$res_excerpt = Lingolane_AI::translate_text( $post->post_excerpt, $source_lang, $target_lang );
			if ( ! is_wp_error( $res_excerpt ) && '' !== $res_excerpt ) {
				$translated_excerpt = $res_excerpt;
			}
		}

		// 4. Translate post_content preserving blocks and HTML
		if ( '' !== trim( $post->post_content ) ) {
			$translated_content = self::translate_html( $post->post_content, $source_lang, $target_lang );
		}

		// 5. Update duplicated post with translated content
		wp_update_post(
			array(
				'ID'           => $new_id,
				'post_title'   => $translated_title,
				'post_excerpt' => $translated_excerpt,
				'post_content' => $translated_content,
				'post_name'    => sanitize_title( $translated_title ),
			)
		);

		// 6. Set machine translated flag
		update_post_meta( $new_id, self::META_FLAG, 1 );

		return $new_id;
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
