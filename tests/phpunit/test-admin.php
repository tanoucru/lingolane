<?php
/**
 * Admin behaviour: editor metabox saving, translation duplication and statistics, the listing fallback
 * setting and the theme string scanner's admin-only detection.
 */
class Test_Lingolane_Admin extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		foreach ( array( 'stats', 'admin', 'strings-admin', 'metabox', 'columns' ) as $file ) {
			require_once LINGOLANE_DIR . 'admin/class-lingolane-' . $file . '.php';
		}
	}

	public function set_up() {
		parent::set_up();
		$this->register_shared_fixtures();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	private function save_metabox( $post_id, $language ) {
		$_POST = array(
			'lingolane_nonce'    => wp_create_nonce( 'lingolane_save_' . $post_id ),
			'lingolane_language' => $language,
		);
		Lingolane_Metabox::save( $post_id, get_post( $post_id ) );
	}

	public function test_saving_a_shared_record_keeps_it_shared() {
		$record = $this->new_post( array( 'post_type' => 'science' ) );
		$this->save_metabox( $record, 'vi' );

		$this->assertNull( Lingolane_Elements::get_trid( 'post_science', $record ), 'editor save keeps shared record unregistered' );
		$this->assertTrue( Lingolane_Shared::post( get_post( $record ) ), 'shared record still shared' );
	}

	public function test_choosing_another_language_registers_the_post() {
		$page = $this->new_post( array( 'post_type' => 'page' ) );
		$this->save_metabox( $page, 'en' );

		$this->assertSame( 'en', Lingolane_Elements::get_language( 'post_page', $page ), 'editor save assigns chosen language' );
	}

	public function test_duplicate_creates_a_linked_draft_counted_by_the_stats() {
		Lingolane_Languages::set_current( 'vi' );
		$before = Lingolane_Stats::content( 'en' );
		$source = $this->new_post( array( 'post_type' => 'page' ) );
		$copy   = Lingolane_Elements::duplicate_post( $source, 'en', 'draft' );
		$this->assertNotWPError( $copy );
		$after = Lingolane_Stats::content( 'en' );

		$this->assertSame( (int) $copy, (int) Lingolane_Elements::get_translation( 'post_page', $source, 'en' ), 'duplicate is linked' );
		$this->assertSame( $before['total'] + 1, $after['total'], 'stats count new source' );
		$this->assertSame( $before['drafts'] + 1, $after['drafts'], 'stats count draft translation' );

		wp_update_post( array( 'ID' => $copy, 'post_status' => 'publish' ) );
		$published = Lingolane_Stats::content( 'en' );
		$this->assertSame( $before['published'] + 1, $published['published'], 'stats count published translation' );
		$this->assertSame( $before['drafts'], $published['drafts'], 'published translation leaves the drafts count' );
		$this->assertNotContains( 'science', Lingolane_Stats::editorial_types(), 'shared types excluded from editorial types' );
	}

	public function test_listing_fallback_setting_is_honoured() {
		$base = $this->settings_array();
		$with = function () use ( $base ) {
			return array_merge( $base, array( 'fallback_to_untranslated' => true ) );
		};
		$without = function () use ( $base ) {
			return array_merge( $base, array( 'fallback_to_untranslated' => false ) );
		};

		add_filter( 'pre_option_lingolane_settings', $with );
		$this->assertTrue( apply_filters( 'lingolane_fallback_to_untranslated', false, 'en' ), 'fallback setting enables listing fallback' );
		remove_filter( 'pre_option_lingolane_settings', $with );

		add_filter( 'pre_option_lingolane_settings', $without );
		$this->assertFalse( apply_filters( 'lingolane_fallback_to_untranslated', false, 'en' ), 'fallback off by default' );
	}

	public function test_scanner_skips_admin_only_labels_but_keeps_visitor_text() {
		$method = new ReflectionMethod( 'Lingolane_Strings_Admin', 'admin_only' );
		$method->setAccessible( true );

		$code = "<?php \$wp_customize->add_control( 'x', array( 'label' => __( 'Admin label', 'my-theme' ) ) );\necho esc_html__( 'Visitor text', 'my-theme' );";
		$this->assertTrue( $method->invoke( null, $code, strpos( $code, "__( 'Admin" ) ), 'customizer label is admin-only' );
		$this->assertFalse( $method->invoke( null, $code, strpos( $code, "esc_html__( 'Visitor" ) ), 'visitor text is kept' );

		$menus = "<?php register_nav_menus( array( 'main' => __( 'Main menu', 'my-theme' ) ) );";
		$this->assertTrue( $method->invoke( null, $menus, strpos( $menus, "__( 'Main" ) ), 'menu location label is admin-only' );
	}
}
