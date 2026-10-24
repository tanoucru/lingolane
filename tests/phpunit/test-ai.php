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
		require_once LINGOLANE_DIR . 'admin/class-lingolane-ui.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-admin.php';
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

	public function test_admin_tabs_includes_ai_tab_for_admin() {
		wp_set_current_user( $this->subscriber_id );
		$tabs = Lingolane_Admin::tabs();
		$this->assertArrayNotHasKey( 'ai', $tabs );

		wp_set_current_user( $this->admin_id );
		$tabs = Lingolane_Admin::tabs();
		$this->assertArrayHasKey( 'ai', $tabs );

		$_GET['tab'] = 'ai';
		ob_start();
		Lingolane_Admin::render();
		$output = ob_get_clean();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'gemini_api_key', $output );
		$this->assertStringContainsString( 'gemini_model', $output );
		$this->assertStringContainsString( 'Chrome', $output );
	}

	public function test_save_ai_settings_action() {
		wp_set_current_user( $this->admin_id );

		$_POST = array(
			'_wpnonce'       => wp_create_nonce( 'lingolane_save_ai_settings' ),
			'gemini_api_key' => 'AIzaSyNewSavedKey1234567890XYZ',
			'gemini_model'   => 'gemini-1.5-flash',
			'auto_approve'   => '1',
		);
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		// Catch redirect.
		add_filter(
			'wp_redirect',
			function( $location ) {
				throw new Exception( 'REDIRECT:' . $location );
			}
		);

		try {
			Lingolane_Admin::save_ai_settings();
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'REDIRECT:', $e->getMessage() );
		}

		remove_all_filters( 'wp_redirect' );
		$_POST = array();

		$saved = Lingolane_AI::get_settings();
		$this->assertSame( 'AIzaSyNewSavedKey1234567890XYZ', $saved['gemini_api_key'] );
		$this->assertSame( 'gemini-1.5-flash', $saved['gemini_model'] );
		$this->assertSame( 1, $saved['auto_approve'] );
	}
}
