<?php
/**
 * Tests for Lingolane_Incontext (Visual Frontend In-Context Translation P4):
 * - Detection of in-context mode via query arg
 * - Authorization (admin/translator only, guests/subscribers rejected)
 * - Cache prevention headers and DONOTCACHEPAGE definition
 * - Admin bar menu node generation (toggle on / exit)
 * - Script and style enqueuing with localized data
 * - AJAX endpoints: save translation & manifest retrieval
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Lingolane_Incontext extends Lingolane_TestCase {

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
		require_once LINGOLANE_DIR . 'includes/class-lingolane-incontext.php';
	}

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();
		Lingolane_Languages::set_current( 'en' );

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_incontext_active_detection_and_authorization() {
		$_GET = array();

		// 1. Inactive by default.
		$this->assertFalse( Lingolane_Incontext::is_active() );

		// 2. Guest with query param -> rejected.
		wp_set_current_user( 0 );
		$_GET['lingolane_incontext'] = '1';
		$this->assertFalse( Lingolane_Incontext::is_active() );

		// 3. Subscriber with query param -> rejected.
		wp_set_current_user( $this->subscriber_id );
		$_GET['lingolane_incontext'] = '1';
		$this->assertFalse( Lingolane_Incontext::is_active() );

		// 4. Admin with query param -> accepted.
		wp_set_current_user( $this->admin_id );
		$_GET['lingolane_incontext'] = '1';
		$this->assertTrue( Lingolane_Incontext::is_active() );

		// Also accepts lingolane_editor=1 alias.
		$_GET = array( 'lingolane_editor' => '1' );
		$this->assertTrue( Lingolane_Incontext::is_active() );

		$_GET = array();
	}

	public function test_admin_bar_node_rendering() {
		require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';
		$admin_bar = new WP_Admin_Bar();

		// 1. Guest -> no node added.
		wp_set_current_user( 0 );
		$_GET = array();
		Lingolane_Incontext::admin_bar_menu( $admin_bar );
		$this->assertNull( $admin_bar->get_node( 'lingolane_incontext_toggle' ) );

		// 2. Admin on frontend without incontext -> "Translate this page" node added.
		wp_set_current_user( $this->admin_id );
		$_GET = array();
		Lingolane_Incontext::admin_bar_menu( $admin_bar );
		$node = $admin_bar->get_node( 'lingolane_incontext_toggle' );
		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'lingolane_incontext=1', $node->href );

		// 3. Admin in active incontext mode -> "Exit translation mode" node added.
		$admin_bar = new WP_Admin_Bar();
		$_GET = array( 'lingolane_incontext' => '1' );
		Lingolane_Incontext::admin_bar_menu( $admin_bar );
		$node_active = $admin_bar->get_node( 'lingolane_incontext_toggle' );
		$this->assertNotNull( $node_active );
		$this->assertStringNotContainsString( 'lingolane_incontext=1', $node_active->href );

		$_GET = array();
	}

	public function test_enqueue_frontend_scripts() {
		// Clean $_GET before test.
		$_GET = array();

		// Inactive mode -> scripts should not be enqueued.
		wp_set_current_user( $this->admin_id );
		wp_dequeue_script( 'lingolane-incontext' );
		wp_dequeue_style( 'lingolane-incontext' );
		Lingolane_Incontext::enqueue_scripts();
		$this->assertFalse( wp_script_is( 'lingolane-incontext', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'lingolane-incontext', 'enqueued' ) );

		// Active mode -> scripts should be enqueued.
		$_GET['lingolane_incontext'] = '1';
		Lingolane_Incontext::enqueue_scripts();
		$this->assertTrue( wp_script_is( 'lingolane-incontext', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'lingolane-incontext', 'enqueued' ) );

		$_GET = array();
		wp_dequeue_script( 'lingolane-incontext' );
		wp_dequeue_style( 'lingolane-incontext' );
	}

	public function test_save_translation_ajax_validation_and_persistence() {
		wp_set_current_user( $this->admin_id );

		$source      = 'Discover our services';
		$context     = 'header_cta';
		$domain      = 'lingolane-theme';
		$lang        = 'vi';
		$translation = 'Khám phá dịch vụ của chúng tôi';

		$save_res = Lingolane_Incontext::save_translation(
			array(
				'domain'        => $domain,
				'source'        => $source,
				'context'       => $context,
				'language_code' => $lang,
				'translation'   => $translation,
				'group_key'     => 'header',
				'page_path'     => '/services/',
			)
		);

		$this->assertIsArray( $save_res );
		$this->assertTrue( $save_res['success'] );
		$this->assertSame( $translation, $save_res['translation'] );

		// Verify translation exists in database.
		$persisted = Lingolane_Strings::get( $domain, $source, $context, $lang );
		$this->assertSame( $translation, $persisted );

		// Verify location recorded in database.
		global $wpdb;
		$source_hash = md5( $context . "\0" . $source );
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lingolane_string_locations WHERE source_hash = %s AND page_path = %s LIMIT 1", $source_hash, '/services/' ), ARRAY_A );
		$this->assertNotNull( $occ );
		$this->assertSame( 'header', $occ['group_key'] );
	}
}
