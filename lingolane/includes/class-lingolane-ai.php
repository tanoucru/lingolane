<?php
/**
 * Lingolane AI Backend Engine (P5)
 *
 * Manages AI translation settings, API key encryption/masking, Google Gemini API
 * client integration via WordPress HTTP API, and AJAX endpoints for testing keys and translating text.
 *
 * @package Lingolane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingolane_AI {

	const OPTION_KEY   = 'lingolane_ai_settings';
	const NONCE_ACTION = 'lingolane_ai_action';

	/**
	 * Default AI configuration options.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'gemini_api_key' => '',
			'gemini_model'   => 'gemini-2.5-flash',
			'auto_approve'   => 0,
		);
	}

	public static function init() {
		add_action( 'wp_ajax_lingolane_ai_test_key', array( __CLASS__, 'ajax_test_key' ) );
		add_action( 'wp_ajax_lingolane_ai_translate', array( __CLASS__, 'ajax_translate' ) );
	}

	/**
	 * Retrieve saved AI settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Save AI settings.
	 *
	 * @param array $settings New settings array.
	 * @return bool
	 */
	public static function save_settings( array $settings ) {
		$current = self::get_settings();

		if ( isset( $settings['gemini_api_key'] ) ) {
			$key = trim( sanitize_text_field( $settings['gemini_api_key'] ) );
			// If key was submitted as masked (e.g. starts with AIzaSy... and contains ...), keep current key.
			if ( false !== strpos( $key, '...' ) ) {
				$settings['gemini_api_key'] = $current['gemini_api_key'];
			} else {
				$settings['gemini_api_key'] = $key;
			}
		}

		if ( isset( $settings['gemini_model'] ) ) {
			$allowed_models = array( 'gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro' );
			$model          = sanitize_text_field( $settings['gemini_model'] );
			$settings['gemini_model'] = in_array( $model, $allowed_models, true ) ? $model : 'gemini-2.5-flash';
		}

		if ( isset( $settings['auto_approve'] ) ) {
			$settings['auto_approve'] = ! empty( $settings['auto_approve'] ) ? 1 : 0;
		}

		$merged = array_merge( $current, $settings );
		return update_option( self::OPTION_KEY, $merged );
	}

	/**
	 * Mask an API key for safe UI display (e.g. AIzaSy...0XYZ).
	 *
	 * @param string $key Raw API key.
	 * @return string Masked key or empty string.
	 */
	public static function mask_key( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return '';
		}
		$len = strlen( $key );
		if ( $len <= 10 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 6 ) . '...' . substr( $key, -4 );
	}

	/**
	 * Test a Gemini API key by making a lightweight test request.
	 *
	 * @param string $api_key API key to test.
	 * @return array
	 */
	public static function test_api_key( $api_key = '' ) {
		if ( '' === $api_key ) {
			$settings = self::get_settings();
			$api_key  = $settings['gemini_api_key'];
		}

		if ( '' === $api_key ) {
			return array(
				'success' => false,
				'message' => __( 'Vui lòng nhập API Key để kiểm tra.', 'lingolane' ),
			);
		}

		$url = add_query_arg(
			array( 'key' => $api_key ),
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
		);

		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => 'Translate to Vietnamese: Hello' ),
					),
				),
			),
			'generationConfig' => array(
				'maxOutputTokens' => 10,
				'temperature'     => 0.1,
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Lỗi kết nối máy chủ Google AI: %s', 'lingolane' ),
					$response->get_error_message()
				),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Key không hợp lệ hoặc bị từ chối.', 'lingolane' );
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: HTTP code, 2: error detail */
					__( 'Google AI trả về lỗi (Mã %1$d): %2$s', 'lingolane' ),
					$code,
					$error_msg
				),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Kết nối Gemini API thành công! API Key hợp lệ và sẵn sàng sử dụng.', 'lingolane' ),
		);
	}

	/**
	 * Translate text using Gemini API.
	 *
	 * @param string $text Source text.
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @param string $context Optional context note.
	 * @return string|WP_Error Translated text or error.
	 */
	public static function translate_text( $text, $source_lang, $target_lang, $context = '' ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}

		$settings = self::get_settings();
		$api_key  = $settings['gemini_api_key'];
		$model    = ! empty( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-2.5-flash';

		if ( '' === $api_key ) {
			return new WP_Error( 'missing_api_key', __( 'Chưa cấu hình Gemini API Key.', 'lingolane' ) );
		}

		$url = add_query_arg(
			array( 'key' => $api_key ),
			"https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent"
		);

		$prompt = "You are a professional website translator for WordPress.\n" .
				"Task: Translate the following text into {$target_lang}.\n" .
				( $context ? "Context: {$context}\n" : '' ) .
				"Strict instructions:\n" .
				"1. Return ONLY the translated string, no commentary, no markdown quotes, no explanations.\n" .
				"2. Preserve all HTML tags, shortcodes, and placeholder tokens (e.g. %s, %1\$d, {name}) exactly as-is.\n" .
				"3. Keep whitespace and casing style where natural.\n\n" .
				"Original text:\n{$text}";

		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature' => 0.1,
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Lỗi từ Gemini API.', 'lingolane' );
			return new WP_Error( 'gemini_api_error', $error_msg, array( 'status' => $code ) );
		}

		if ( ! empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$translated = trim( $data['candidates'][0]['content']['parts'][0]['text'] );
			return $translated;
		}

		return new WP_Error( 'empty_response', __( 'Gemini không trả về kết quả dịch.', 'lingolane' ) );
	}

	/**
	 * AJAX endpoint to test Gemini API key.
	 */
	public static function ajax_test_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'lingolane' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'lingolane' ) ), 403 );
		}

		$key    = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$result = self::test_api_key( $key );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result, 400 );
		}
	}

	/**
	 * AJAX endpoint to translate text via Gemini backend.
	 */
	public static function ajax_translate() {
		if ( ! is_user_logged_in() || ! class_exists( 'Lingolane_Admin' ) || ! Lingolane_Admin::can_translate() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'lingolane' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'lingolane' ) ), 403 );
		}

		$text        = isset( $_POST['text'] ) ? wp_unslash( $_POST['text'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$source_lang = isset( $_POST['source_lang'] ) ? sanitize_key( wp_unslash( $_POST['source_lang'] ) ) : 'auto';
		$target_lang = isset( $_POST['target_lang'] ) ? sanitize_key( wp_unslash( $_POST['target_lang'] ) ) : 'vi';
		$context     = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '';

		$result = self::translate_text( $text, $source_lang, $target_lang, $context );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'translation' => $result,
				'engine'      => 'gemini',
			)
		);
	}
}
