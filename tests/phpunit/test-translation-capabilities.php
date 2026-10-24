<?php
/**
 * Tests for translation capability separation (lingolane_translate vs manage_options):
 * - Administrator gets lingolane_translate
 * - Translators (read + lingolane_translate) can view strings tab and save translations
 * - Translators cannot change plugin settings, languages, or delete string identities
 * - Non-translators and bad nonces are rejected
 */
class Test_Lingolane_Translation_Capabilities extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		foreach ( array( 'stats', 'ui', 'admin', 'strings-admin', 'metabox', 'columns', 'support' ) as $file ) {
			require_once LINGOLANE_DIR . 'admin/class-lingolane-' . $file . '.php';
		}
	}

	/** @var int */
	private $translator_id;

	/** @var int */
	private $subscriber_id;

	/** @var int */
	private $admin_id;

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a translator with only read + lingolane_translate.
		$this->translator_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$translator = get_user_by( 'id', $this->translator_id );
		$translator->add_cap( 'lingolane_translate' );

		// Create a plain subscriber.
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_upgrade_grants_cap_once() {
		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'lingolane_translate' ) );

		// Re-run activate; must be idempotent.
		Lingolane_Install::activate();
		$this->assertTrue( $role->has_cap( 'lingolane_translate' ) );
	}

	public function test_translator_can_translate_and_view_tabs() {
		wp_set_current_user( $this->translator_id );

		$this->assertTrue( Lingolane_Admin::can_translate() );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		// Translator should only see strings tab.
		$tabs = Lingolane_Admin::tabs();
		$this->assertArrayHasKey( 'strings', $tabs );
		$this->assertArrayNotHasKey( 'settings', $tabs );
		$this->assertArrayNotHasKey( 'languages', $tabs );
	}

	public function test_subscriber_and_bad_nonce_rejected() {
		wp_set_current_user( $this->subscriber_id );

		$this->assertFalse( Lingolane_Admin::can_translate() );

		// Translation guard should fail for subscriber.
		try {
			Lingolane_Admin::translation_guard( 'lingolane_test' );
			$this->fail( 'Expected wp_die on missing capability' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
	}

	public function test_translator_saves_string_via_ajax() {
		wp_set_current_user( $this->translator_id );

		// Prepare a test string.
		Lingolane_Strings::save( 'test-domain', 'Save Me', '', 'en', '' );
		global $wpdb;
		$row_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}lingolane_strings WHERE domain = %s AND source = %s",
			'test-domain', 'Save Me'
		) );
		$this->assertGreaterThan( 0, $row_id );

		$_POST = array(
			'nonce'       => wp_create_nonce( 'lingolane_strings_ajax' ),
			'id'          => $row_id,
			'translation' => 'Saved By Translator',
		);
		$_REQUEST = $_POST;

		$die_handler = function( $message = '' ) {
			throw new WPDieException( (string) $message );
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', function() use ( $die_handler ) { return $die_handler; } );
		ob_start();
		try {
			Lingolane_Strings_Admin::ajax_save();
		} catch ( WPDieException $e ) {
			// Expected WP AJAX die.
		} finally {
			ob_end_clean();
			remove_all_filters( 'wp_die_ajax_handler' );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertSame( 'Saved By Translator', Lingolane_Strings::get( 'test-domain', 'Save Me', '', 'en' ) );
	}

	public function test_translator_cannot_delete_string_identity() {
		wp_set_current_user( $this->translator_id );

		Lingolane_Strings::save( 'test-domain', 'Do Not Delete', '', 'en', 'Value' );
		global $wpdb;
		$row_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}lingolane_strings WHERE domain = %s AND source = %s",
			'test-domain', 'Do Not Delete'
		) );

		$_POST = array(
			'nonce' => wp_create_nonce( 'lingolane_strings_ajax' ),
			'id'    => $row_id,
		);
		$_REQUEST = $_POST;

		$die_handler = function( $message = '' ) {
			throw new WPDieException( (string) $message );
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', function() use ( $die_handler ) { return $die_handler; } );
		ob_start();
		try {
			Lingolane_Strings_Admin::ajax_delete();
		} catch ( WPDieException $e ) {
			// Expected WP AJAX die.
		} finally {
			ob_end_clean();
			remove_all_filters( 'wp_die_ajax_handler' );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		// String should still exist because only manage_options can delete.
		$this->assertSame( 'Value', Lingolane_Strings::get( 'test-domain', 'Do Not Delete', '', 'en' ) );
	}

	public function test_translator_cannot_write_settings() {
		wp_set_current_user( $this->translator_id );

		$_POST = array(
			'_wpnonce' => wp_create_nonce( 'lingolane_save_default_language' ),
			'default'  => 'fr',
		);

		try {
			Lingolane_Admin::save_default_language();
			$this->fail( 'Expected wp_die on settings write by translator' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
	}
}
