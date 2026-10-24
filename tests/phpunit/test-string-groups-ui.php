<?php
/**
 * Tests for Lingolane String Groups UI and Multilingual Table:
 * - test_groups_distinct_counts
 * - test_source_and_all_target_columns
 * - test_shared_identity_updates_all_groups
 * - test_filters_pagination_and_no_js_save
 * - test_terms_use_term_endpoint
 * - test_theme_scan_keeps_page_sources
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Lingolane_String_Groups_UI extends Lingolane_TestCase {

	protected function settings_array() {
		return array(
			'default'   => 'en',
			'languages' => array(
				array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ),
				array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ),
				array( 'code' => 'fr', 'locale' => 'fr_FR', 'name' => 'Français' ),
			),
			'url_mode'  => 'directory',
		);
	}

	/** @var int */
	private $admin_id;

	public static function wpSetUpBeforeClass() {
		require_once LINGOLANE_DIR . 'admin/class-lingolane-admin.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-stats.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-strings-admin.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-string-terms.php';
	}

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();
		Lingolane_Languages::set_current( 'en' );
		Lingolane_String_Locations::invalidate();
		Lingolane_Stats::flush_strings();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_groups_distinct_counts() {
		// Record 2 occurrences for the SAME identity in 'site' (header and footer).
		$id1 = array( 'domain' => 'lingolane-site', 'source' => 'Site Title', 'context' => 'blogname' );
		Lingolane_String_Locations::record( $id1, array(
			'group_key' => 'site',
			'subgroup'  => 'header',
			'provider'  => 'site',
			'locator'   => 'h1',
			'page_path' => '/',
			'supported' => 1,
		) );
		Lingolane_String_Locations::record( $id1, array(
			'group_key' => 'site',
			'subgroup'  => 'footer',
			'provider'  => 'site',
			'locator'   => '.footer-title',
			'page_path' => '/',
			'supported' => 1,
		) );

		// Record another distinct identity in 'site'.
		$id2 = array( 'domain' => 'lingolane-site', 'source' => 'Just another site', 'context' => 'blogdescription' );
		Lingolane_String_Locations::record( $id2, array(
			'group_key' => 'site',
			'subgroup'  => 'header',
			'provider'  => 'site',
			'locator'   => 'p.desc',
			'page_path' => '/',
			'supported' => 1,
		) );

		// Record an unsupported occurrence in 'other'.
		$id3 = array( 'domain' => 'lingolane-dom', 'source' => 'Random Candidate', 'context' => '' );
		Lingolane_String_Locations::record( $id3, array(
			'group_key' => 'other',
			'subgroup'  => '',
			'provider'  => 'other',
			'locator'   => 'div.random',
			'page_path' => '/',
			'supported' => 0,
		) );

		$groups = Lingolane_Strings_Admin::get_groups_data( 'vi' );
		$this->assertIsArray( $groups );

		// Group 'site' has 2 distinct identities (not 3 occurrences).
		$this->assertArrayHasKey( 'site', $groups );
		$this->assertSame( 2, (int) $groups['site']['total'] );
		$this->assertSame( 0, (int) $groups['site']['own'] );
		$this->assertSame( 2, (int) $groups['site']['missing'] );

		// Group 'other' has 1 unsupported.
		$this->assertArrayHasKey( 'other', $groups );
		$this->assertSame( 1, (int) $groups['other']['total'] );
		$this->assertSame( 1, (int) $groups['other']['unsupported'] );

		// Empty group has 0.
		$this->assertArrayHasKey( 'widgets', $groups );
		$this->assertSame( 0, (int) $groups['widgets']['total'] );
		$this->assertSame( 0, (int) $groups['widgets']['percent'] );
	}

	public function test_source_and_all_target_columns() {
		// Record an identity and translate only into 'vi', leaving 'fr' empty.
		$identity = array( 'domain' => 'lingolane-site', 'source' => 'Contact Us', 'context' => '' );
		Lingolane_String_Locations::record( $identity, array(
			'group_key' => 'site',
			'subgroup'  => 'header',
			'provider'  => 'site',
			'locator'   => 'a.contact',
			'page_path' => '/',
			'supported' => 1,
		) );

		Lingolane_Strings::save( 'lingolane-site', 'Contact Us', '', 'vi', 'Liên hệ' );

		$page_data = Lingolane_Strings_Admin::page_data( 'site', '', 'vi', 'all', '', 1, 30 );
		$this->assertIsArray( $page_data );
		$this->assertArrayHasKey( 'items', $page_data );
		$this->assertNotEmpty( $page_data['items'] );

		$item = $page_data['items'][0];
		$this->assertSame( 'Contact Us', $item['source'] );
		$this->assertArrayHasKey( 'translations', $item );
		$this->assertSame( 'Liên hệ', $item['translations']['vi'] );
		$this->assertSame( '', $item['translations']['fr'] );
	}

	public function test_shared_identity_updates_all_groups() {
		// An identity occurring in both 'header' and 'footer'.
		$shared_id = array( 'domain' => 'lingolane-site', 'source' => 'Copyright 2026', 'context' => '' );
		Lingolane_String_Locations::record( $shared_id, array(
			'group_key' => 'header',
			'subgroup'  => '',
			'provider'  => 'site',
			'locator'   => 'meta[name=copyright]',
			'page_path' => '/',
			'supported' => 1,
		) );
		Lingolane_String_Locations::record( $shared_id, array(
			'group_key' => 'footer',
			'subgroup'  => '',
			'provider'  => 'site',
			'locator'   => '.footer-cr',
			'page_path' => '/',
			'supported' => 1,
		) );

		// Save translation via Lingolane_Strings_Admin::save_identity.
		$save_result = Lingolane_Strings_Admin::save_identity( 'lingolane-site', 'Copyright 2026', '', 'vi', 'Bản quyền 2026' );
		$this->assertNotWPError( $save_result );

		// Query header group.
		$header_data = Lingolane_Strings_Admin::page_data( 'header', '', 'vi', 'all', '', 1, 30 );
		$this->assertSame( 'Bản quyền 2026', $header_data['items'][0]['translations']['vi'] );

		// Query footer group.
		$footer_data = Lingolane_Strings_Admin::page_data( 'footer', '', 'vi', 'all', '', 1, 30 );
		$this->assertSame( 'Bản quyền 2026', $footer_data['items'][0]['translations']['vi'] );

		// Verify only 1 row exists in lingolane_strings for (lingolane-site, source_hash, 'vi').
		global $wpdb;
		$source_hash = Lingolane_Strings::hash( 'Copyright 2026', '' );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}lingolane_strings WHERE domain = 'lingolane-site' AND source_hash = %s AND language_code = 'vi'",
			$source_hash
		) );
		$this->assertSame( 1, $count, 'Shared identity must not duplicate rows in lingolane_strings.' );
	}

	public function test_filters_pagination_and_no_js_save() {
		// Fixture: 31 distinct identities in group 'site'.
		for ( $i = 1; $i <= 31; $i++ ) {
			$id = array( 'domain' => 'lingolane-site', 'source' => "String Number {$i}", 'context' => "ctx_{$i}" );
			Lingolane_String_Locations::record( $id, array(
				'group_key' => 'site',
				'subgroup'  => '',
				'provider'  => 'site',
				'locator'   => "#str-{$i}",
				'page_path' => '/',
				'supported' => 1,
			) );
		}

		// Page 1 with per_page 30 should return 30 items.
		$p1 = Lingolane_Strings_Admin::page_data( 'site', '', 'vi', 'all', '', 1, 30 );
		$this->assertSame( 31, $p1['total'] );
		$this->assertCount( 30, $p1['items'] );

		// Page 2 should return 1 item.
		$p2 = Lingolane_Strings_Admin::page_data( 'site', '', 'vi', 'all', '', 2, 30 );
		$this->assertSame( 31, $p2['total'] );
		$this->assertCount( 1, $p2['items'] );

		// Translate 5 items.
		for ( $i = 1; $i <= 5; $i++ ) {
			Lingolane_Strings::save( 'lingolane-site', "String Number {$i}", "ctx_{$i}", 'vi', "Dịch chuỗi {$i}" );
		}

		// Filter status 'missing': 31 - 5 = 26 items.
		$missing = Lingolane_Strings_Admin::page_data( 'site', '', 'vi', 'missing', '', 1, 30 );
		$this->assertSame( 26, $missing['total'] );

		// Filter status 'translated': 5 items.
		$translated = Lingolane_Strings_Admin::page_data( 'site', '', 'vi', 'translated', '', 1, 30 );
		$this->assertSame( 5, $translated['total'] );

		// Keyword search.
		$search = Lingolane_Strings_Admin::page_data( 'site', '', 'vi', 'all', 'Number 25', 1, 30 );
		$this->assertSame( 1, $search['total'] );
		$this->assertSame( 'String Number 25', $search['items'][0]['source'] );

		// Test No-JS POST Save.
		$_POST = array(
			'operation'     => 'save_identity',
			'domain'        => 'lingolane-site',
			'source'        => 'String Number 30',
			'context'       => 'ctx_30',
			'language_code' => 'vi',
			'translation'   => 'Dịch No-JS Thành Công',
			'group'         => 'site',
			'_wpnonce'      => wp_create_nonce( 'lingolane_strings_save_identity' ),
		);
		$_REQUEST = array_merge( $_REQUEST, $_POST );

		$save_post = Lingolane_Strings_Admin::handle_save_identity();
		$this->assertNotWPError( $save_post );

		$trans = Lingolane_Strings::get( 'lingolane-site', 'String Number 30', 'ctx_30', 'vi' );
		$this->assertSame( 'Dịch No-JS Thành Công', $trans );
	}

	public function test_terms_use_term_endpoint() {
		// Create a source category.
		$cat = wp_insert_term( 'Tech News', 'category', array( 'description' => 'Original Description' ) );
		$this->assertIsArray( $cat );
		$cat_id = $cat['term_id'];

		// Save translation via Lingolane_Strings_Admin::save_term_translation.
		$res = Lingolane_Strings_Admin::save_term_translation( $cat_id, 'category', 'vi', 'Tin Công Nghệ', 'Mô Tả Tiếng Việt' );
		$this->assertNotWPError( $res );
		$this->assertIsInt( $res );

		// Verify translation exists in taxonomy.
		$trans_term = get_term( $res, 'category' );
		$this->assertSame( 'Tin Công Nghệ', $trans_term->name );
		$this->assertSame( 'Mô Tả Tiếng Việt', $trans_term->description );

		// Verify terms progress.
		$term_progress = Lingolane_String_Terms::progress( 'vi' );
		$this->assertGreaterThan( 0, $term_progress['completed'] );
	}

	public function test_theme_scan_keeps_page_sources() {
		$theme_domain = Lingolane_Strings_Admin::theme_domain();

		// String A: in theme domain, but observed on page via block provider.
		$id_block = array( 'domain' => $theme_domain, 'source' => 'Block Header Title', 'context' => 'header' );
		Lingolane_String_Locations::record( $id_block, array(
			'group_key' => 'header',
			'subgroup'  => '',
			'provider'  => 'block',
			'locator'   => 'h2.header',
			'page_path' => '/',
			'supported' => 1,
		) );

		// String B: in theme domain, orphan gettext never observed on page.
		$hash_orphan = Lingolane_Strings::hash( 'Orphan Gettext String', '' );
		Lingolane_Strings::save( $theme_domain, 'Orphan Gettext String', '', 'en', '' );

		// Run theme scan.
		Lingolane_Strings_Admin::scan_theme_strings();

		global $wpdb;
		$table = $wpdb->prefix . 'lingolane_strings';

		// String A must remain in database!
		$hash_block = Lingolane_Strings::hash( 'Block Header Title', 'header' );
		$row_a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE domain = %s AND source_hash = %s", $theme_domain, $hash_block ) );
		$this->assertNotNull( $row_a, 'String with page occurrence must not be deleted by theme scanner.' );

		// String B (orphan) should be removed if not in theme code.
		$row_b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE domain = %s AND source_hash = %s", $theme_domain, $hash_orphan ) );
		$this->assertNull( $row_b, 'Orphan untranslated gettext string should be deleted by theme scanner.' );
	}
}
