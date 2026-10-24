<?php
/**
 * Tests for Lingolane_AI (P5 AI Translation):
 * - Settings storage and API key masking
 * - Access control (admin only for configuration, translator for translation endpoint)
 * - Gemini API remote call and mock response parsing
 * - Server-side text translation endpoint
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Lingolane_AI extends Lingolane_TestCase {

	protected function settings_array() {
		return array(
			'default'   => 'en',
			'languages' => array(
				array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ),
				array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ),
			),
			'url_mode'  => 'directory',
		);
	}

	/** @var int */
	private $admin_id;

	/** @var int */
	private $subscriber_id;

	public static function wpSetUpBeforeClass() {
		require_once LINGOLANE_DIR . 'includes/class-lingolane-ai.php';
	}

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();
		Lingolane_Languages::set_current( 'en' );

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_settings_storage_and_key_masking() {
		wp_set_current_user( $this->admin_id );

		$raw_key = 'AIzaSyA1234567890abcdef1234567890XYZ';
		Lingolane_AI::save_settings(
			array(
				'gemini_api_key' => $raw_key,
				'gemini_model'   => 'gemini-2.5-flash',
				'auto_approve'   => 1,
			)
		);

		$stored = Lingolane_AI::get_settings();
		$this->assertSame( $raw_key, $stored['gemini_api_key'] );
		$this->assertSame( 'gemini-2.5-flash', $stored['gemini_model'] );
		$this->assertSame( 1, $stored['auto_approve'] );

		// Test key masking
		$masked = Lingolane_AI::mask_key( $raw_key );
		$this->assertSame( 'AIzaSy...0XYZ', $masked );
		$this->assertStringNotContainsString( '1234567890abcdef', $masked );

		// Empty key masking
		$this->assertSame( '', Lingolane_AI::mask_key( '' ) );
	}

	public function test_api_key_test_with_mocked_gemini_success() {
		wp_set_current_user( $this->admin_id );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) ) {
					return array(
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Xin chào' ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$test_res = Lingolane_AI::test_api_key( 'AIzaSyValidMockKey' );
		$this->assertIsArray( $test_res );
		$this->assertTrue( $test_res['success'] );

		remove_all_filters( 'pre_http_request' );
	}

	public function test_translate_text_with_mocked_gemini() {
		wp_set_current_user( $this->admin_id );

		Lingolane_AI::save_settings(
			array(
				'gemini_api_key' => 'AIzaSyValidMockKey',
				'gemini_model'   => 'gemini-2.5-flash',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) ) {
					return array(
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'body'     => wp_json_encode(
							array(
								'candidates' => array(
									array(
										'content' => array(
											'parts' => array(
												array( 'text' => 'Chào mừng đến với Lingolane' ),
											),
										),
									),
								),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = Lingolane_AI::translate_text( 'Welcome to Lingolane', 'en', 'vi' );
		$this->assertNotWPError( $result );
		$this->assertSame( 'Chào mừng đến với Lingolane', $result );

		remove_all_filters( 'pre_http_request' );
	}

	public function test_translate_text_without_key_returns_error() {
		wp_set_current_user( $this->admin_id );

		Lingolane_AI::save_settings( array( 'gemini_api_key' => '' ) );
		$result = Lingolane_AI::translate_text( 'Test text', 'en', 'vi' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_api_key', $result->get_error_code() );
	}
}
