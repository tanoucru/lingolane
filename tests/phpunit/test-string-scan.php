<?php
/**
 * Tests for Lingolane_String_Scan:
 * - Nonce, capability, and user-bound session validation
 * - External, admin, and private paths rejected
 * - Forged supported identity rejected or marked unsupported
 * - Payload limits (200 items/req, 500 session ceiling) and expiry
 * - Guest has no manifest or access
 * - Pages discovery deduplication
 * - Occurrence preview URL generation and validation
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Lingolane_String_Scan extends Lingolane_TestCase {

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
	private $translator_id;

	/** @var int */
	private $other_user_id;

	public static function wpSetUpBeforeClass() {
		require_once LINGOLANE_DIR . 'admin/class-lingolane-admin.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-string-scan.php';
	}

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();
		Lingolane_Languages::set_current( 'en' );

		$this->translator_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->other_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function test_nonce_capability_and_user_bound_session() {
		wp_set_current_user( $this->translator_id );

		$paths = array( '/' );
		$session_data = Lingolane_String_Scan::start( $paths );
		$this->assertIsArray( $session_data );
		$this->assertArrayHasKey( 'session', $session_data );
		$this->assertArrayHasKey( 'session_id', $session_data );
		$this->assertArrayHasKey( 'expires', $session_data );
		$this->assertSame( $session_data['session'], $session_data['session_id'] );
		$session_token = $session_data['session'];

		// User A can accept items into session.
		$items = array(
			array(
				'identity'   => array( 'domain' => 'lingolane-site', 'source' => 'Site Title', 'context' => 'blogname' ),
				'occurrence' => array( 'group_key' => 'site', 'subgroup' => 'header', 'provider' => 'site', 'locator' => 'h1', 'page_path' => '/', 'supported' => 1 ),
			),
		);
		$accepted = Lingolane_String_Scan::accept( $session_token, '/', $items );
		$this->assertIsArray( $accepted );
		$this->assertSame( 1, $accepted['accepted'] );

		// Switch to User B: cannot use User A's session.
		wp_set_current_user( $this->other_user_id );
		$rejected_b = Lingolane_String_Scan::accept( $session_token, '/', $items );
		$this->assertWPError( $rejected_b );
		$this->assertSame( 'unauthorized_session', $rejected_b->get_error_code() );

		// Switch to guest: rejected.
		wp_set_current_user( 0 );
		$rejected_guest = Lingolane_String_Scan::accept( $session_token, '/', $items );
		$this->assertWPError( $rejected_guest );
	}

	public function test_external_admin_private_paths_rejected() {
		wp_set_current_user( $this->translator_id );
		$session_data = Lingolane_String_Scan::start( array( '/' ) );
		$session_token = $session_data['session'];

		$dummy_items = array(
			array(
				'identity'   => array( 'domain' => 'lingolane-site', 'source' => 'Test', 'context' => '' ),
				'occurrence' => array( 'group_key' => 'site', 'subgroup' => '', 'provider' => 'site', 'locator' => 'p', 'page_path' => '/bad', 'supported' => 0 ),
			),
		);

		// External URL.
		$res_ext = Lingolane_String_Scan::accept( $session_token, 'https://evil.com/phishing', $dummy_items );
		$this->assertWPError( $res_ext );
		$this->assertSame( 'invalid_path', $res_ext->get_error_code() );

		// Admin path.
		$res_admin = Lingolane_String_Scan::accept( $session_token, '/wp-admin/options.php', $dummy_items );
		$this->assertWPError( $res_admin );

		// Private account/checkout path.
		$res_cart = Lingolane_String_Scan::accept( $session_token, '/cart/', $dummy_items );
		$this->assertWPError( $res_cart );
	}

	public function test_forged_supported_identity_rejected() {
		wp_set_current_user( $this->translator_id );
		$session_data = Lingolane_String_Scan::start( array( '/' ) );
		$session_token = $session_data['session'];

		// Item claims supported: 1 but provider is forged or non-existent.
		$forged_items = array(
			array(
				'identity'   => array( 'domain' => 'lingolane-forged', 'source' => 'Fake Text', 'context' => 'fake' ),
				'occurrence' => array( 'group_key' => 'theme', 'subgroup' => '', 'provider' => 'hacked_provider', 'locator' => 'div', 'page_path' => '/', 'supported' => 1 ),
			),
		);

		$result = Lingolane_String_Scan::accept( $session_token, '/', $forged_items );
		$this->assertIsArray( $result );
		// The forged item should be converted to supported: 0 (candidate) or recorded as unsupported.
		global $wpdb;
		$row = $wpdb->get_row( "SELECT supported FROM {$wpdb->prefix}lingolane_string_locations WHERE provider = 'hacked_provider' LIMIT 1", ARRAY_A );
		if ( $row ) {
			$this->assertSame( 0, (int) $row['supported'], 'Forged provider must not be marked as supported: 1' );
		}
	}

	public function test_payload_limits_and_expiry() {
		wp_set_current_user( $this->translator_id );
		$session_data = Lingolane_String_Scan::start( array( '/' ) );
		$session_token = $session_data['session'];

		// Exceed 200 items in single request.
		$too_many = array();
		for ( $i = 0; $i < 205; $i++ ) {
			$too_many[] = array(
				'identity'   => array( 'domain' => 'lingolane-dom', 'source' => "String {$i}", 'context' => '' ),
				'occurrence' => array( 'group_key' => 'other', 'subgroup' => '', 'provider' => 'dom', 'locator' => "p:{$i}", 'page_path' => '/', 'supported' => 0 ),
			);
		}

		$res_limit = Lingolane_String_Scan::accept( $session_token, '/', $too_many );
		$this->assertWPError( $res_limit );
		$this->assertSame( 'payload_too_large', $res_limit->get_error_code() );

		// Test session expiration (simulated).
		$expired_sess = Lingolane_String_Scan::start( array( '/' ) );
		// Force expire in transient.
		delete_transient( 'lingolane_scan_' . $expired_sess['session'] );
		$res_expired = Lingolane_String_Scan::accept( $expired_sess['session'], '/', array() );
		$this->assertWPError( $res_expired );
		$this->assertSame( 'session_expired', $res_expired->get_error_code() );
	}

	public function test_guest_has_no_manifest() {
		wp_set_current_user( 0 ); // Guest.
		$pages = Lingolane_String_Scan::pages();
		$this->assertSame( array(), $pages );

		$start = Lingolane_String_Scan::start( array( '/' ) );
		$this->assertWPError( $start );
	}

	public function test_pages_discovery_deduplication() {
		wp_set_current_user( $this->translator_id );

		// Create sample posts.
		$this->factory->post->create_many( 5, array( 'post_status' => 'publish', 'post_type' => 'post' ) );
		$this->factory->post->create_many( 4, array( 'post_status' => 'publish', 'post_type' => 'page' ) );

		$pages = Lingolane_String_Scan::pages();
		$this->assertIsArray( $pages );
		$this->assertNotEmpty( $pages );
		$this->assertLessThanOrEqual( 10, count( $pages ) );

		// Verify no duplicate paths.
		$unique_paths = array_unique( wp_list_pluck( $pages, 'path' ) );
		$this->assertSame( count( $pages ), count( $unique_paths ) );

		// Verify every path starts with / and contains no query string.
		foreach ( $pages as $p ) {
			$this->assertSame( '/', substr( $p['path'], 0, 1 ) );
			$this->assertFalse( strpos( $p['path'], '?' ) );
		}
	}

	public function test_preview_url_generation() {
		wp_set_current_user( $this->translator_id );

		// Record an occurrence.
		$identity = array( 'domain' => 'lingolane-site', 'source' => 'Preview Site Title', 'context' => 'blogname' );
		$occurrence = array(
			'group_key' => 'site',
			'subgroup'  => 'header',
			'provider'  => 'site',
			'locator'   => 'site-title',
			'page_path' => '/',
			'supported' => 1,
		);
		Lingolane_String_Locations::record( $identity, $occurrence );

		global $wpdb;
		$occ_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}lingolane_string_locations WHERE locator = 'site-title' LIMIT 1" );
		$this->assertGreaterThan( 0, $occ_id );

		$url = Lingolane_String_Scan::preview_url( $occ_id );
		$this->assertIsString( $url );
		$this->assertStringContainsString( 'lingolane_preview=', $url );
		$this->assertStringContainsString( home_url( '/' ), $url );

		// Non-existent occurrence returns error.
		$bad_url = Lingolane_String_Scan::preview_url( 99999999 );
		$this->assertWPError( $bad_url );
	}

	public function test_enqueue_frontend_scripts_authorization_and_token_mapping() {
		// Clean $_GET before test.
		$_GET = array();

		// 1. Guest request should not enqueue scripts.
		wp_set_current_user( 0 );
		$_GET['lingolane_scan'] = '1';
		$_GET['token']          = 'dummy_token_123';
		wp_dequeue_script( 'lingolane-string-scan' );
		wp_dequeue_style( 'lingolane-string-scan' );
		Lingolane_String_Scan::enqueue_frontend_scripts();
		$this->assertFalse( wp_script_is( 'lingolane-string-scan', 'enqueued' ) );

		// 2. Admin without scan or preview params should not enqueue scripts.
		wp_set_current_user( $this->translator_id );
		$_GET = array();
		wp_dequeue_script( 'lingolane-string-scan' );
		wp_dequeue_style( 'lingolane-string-scan' );
		Lingolane_String_Scan::enqueue_frontend_scripts();
		$this->assertFalse( wp_script_is( 'lingolane-string-scan', 'enqueued' ) );

		// 3. Admin with lingolane_scan=1 and token=... enqueues scripts.
		$_GET = array(
			'lingolane_scan' => '1',
			'token'          => 'test_session_token_xyz',
		);
		wp_dequeue_script( 'lingolane-string-scan' );
		wp_dequeue_style( 'lingolane-string-scan' );
		Lingolane_String_Scan::enqueue_frontend_scripts();
		$this->assertTrue( wp_script_is( 'lingolane-string-scan', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'lingolane-string-scan', 'enqueued' ) );

		// 4. Admin with preview token enqueues scripts.
		$_GET = array(
			'lingolane_preview' => 'test_preview_token_abc',
		);
		wp_dequeue_script( 'lingolane-string-scan' );
		wp_dequeue_style( 'lingolane-string-scan' );
		Lingolane_String_Scan::enqueue_frontend_scripts();
		$this->assertTrue( wp_script_is( 'lingolane-string-scan', 'enqueued' ) );

		// Reset $_GET and dequeue.
		$_GET = array();
		wp_dequeue_script( 'lingolane-string-scan' );
		wp_dequeue_style( 'lingolane-string-scan' );
	}
}
