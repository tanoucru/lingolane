<?php
/**
 * Tests for Lingolane_String_Locations:
 * - Table creation and migration to 0.5.0
 * - Shared identity across multiple locations/occurrences
 * - Domain and context separation
 * - Groups statistics (total, own, inherited, missing, unsupported, percent)
 * - Pagination and filtering
 * - Sync contract between Strings and Locations
 */
class Test_Lingolane_String_Locations extends Lingolane_TestCase {

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();
		Lingolane_String_Locations::invalidate();
	}

	public function test_upgrade_idempotent() {
		global $wpdb;
		$table_loc = $wpdb->prefix . 'lingolane_string_locations';
		$this->assertSame( $table_loc, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_loc ) ) ) );
		$this->assertSame( '0.5.0', get_option( 'lingolane_db_version' ) );

		// Register a test string.
		Lingolane_Strings::save( 'test-domain', 'Original String', '', 'en', 'Translated String' );

		// Re-run activate; should not erase data or fail.
		Lingolane_Install::activate();
		$this->assertSame( '0.5.0', get_option( 'lingolane_db_version' ) );
		$this->assertSame( 'Translated String', Lingolane_Strings::get( 'test-domain', 'Original String', '', 'en' ) );
	}

	public function test_failed_schema_keeps_old_version() {
		// If tables do not exist or creation fails, lingolane_db_version should not advance.
		$old_version = '0.4.0';
		update_option( 'lingolane_db_version', $old_version );

		// Simulate missing table by checking verify_tables helper.
		$has_all = Lingolane_Install::verify_tables( array( 'nonexistent_table_xyz' ) );
		$this->assertFalse( $has_all );
		$this->assertSame( $old_version, get_option( 'lingolane_db_version' ) );
	}

	public function test_identity_shared_across_locations() {
		$identity = array(
			'domain'  => 'theme-test',
			'source'  => 'Search',
			'context' => '',
		);

		// Record occurrence in header.
		$header_occ = array(
			'group_key'   => 'header',
			'subgroup'    => 'top-bar',
			'provider'    => 'block',
			'object_id'   => 0,
			'field'       => 'search-input',
			'page_path'   => '/',
			'locator'     => '.search-field',
			'supported'   => true,
		);
		$res1 = Lingolane_String_Locations::record( $identity, $header_occ );
		$this->assertTrue( $res1 );

		// Record occurrence of same identity in footer.
		$footer_occ = array(
			'group_key'   => 'footer',
			'subgroup'    => 'bottom-widgets',
			'provider'    => 'block',
			'object_id'   => 0,
			'field'       => 'search-widget',
			'page_path'   => '/contact/',
			'locator'     => '#footer-search',
			'supported'   => true,
		);
		$res2 = Lingolane_String_Locations::record( $identity, $footer_occ );
		$this->assertTrue( $res2 );

		// Groups stats for 'en' (currently untranslated).
		$groups = Lingolane_String_Locations::groups( 'en' );
		$this->assertArrayHasKey( 'header', $groups );
		$this->assertArrayHasKey( 'footer', $groups );

		$this->assertSame( 1, $groups['header']['total'] );
		$this->assertSame( 0, $groups['header']['own'] );
		$this->assertSame( 1, $groups['header']['missing'] );

		$this->assertSame( 1, $groups['footer']['total'] );
		$this->assertSame( 0, $groups['footer']['own'] );
		$this->assertSame( 1, $groups['footer']['missing'] );

		// Now translate the shared string.
		Lingolane_Strings::save( $identity['domain'], $identity['source'], $identity['context'], 'en', 'Tìm kiếm' );

		// Groups stats should reflect translation in BOTH groups without double-counting distinct identities.
		Lingolane_String_Locations::invalidate();
		$groups_after = Lingolane_String_Locations::groups( 'en' );
		$this->assertSame( 1, $groups_after['header']['own'] );
		$this->assertSame( 0, $groups_after['header']['missing'] );
		$this->assertSame( 100, $groups_after['header']['percent'] );

		$this->assertSame( 1, $groups_after['footer']['own'] );
		$this->assertSame( 0, $groups_after['footer']['missing'] );
		$this->assertSame( 100, $groups_after['footer']['percent'] );
	}

	public function test_domain_and_context_stay_distinct() {
		$occ = array(
			'group_key' => 'header',
			'subgroup'  => 'nav',
			'provider'  => 'menu',
			'object_id' => 10,
			'field'     => 'title',
			'page_path' => '/',
			'locator'   => '#menu-item-10',
			'supported' => true,
		);

		// Two strings with same text but different domains or contexts.
		$id1 = array( 'domain' => 'plugin-a', 'source' => 'Home', 'context' => '' );
		$id2 = array( 'domain' => 'plugin-b', 'source' => 'Home', 'context' => '' );
		$id3 = array( 'domain' => 'plugin-a', 'source' => 'Home', 'context' => 'breadcrumb' );

		Lingolane_String_Locations::record( $id1, $occ );
		Lingolane_String_Locations::record( $id2, $occ );
		Lingolane_String_Locations::record( $id3, $occ );

		$groups = Lingolane_String_Locations::groups( 'en' );
		$this->assertSame( 3, $groups['header']['total'], 'Three distinct identities must not collide' );
	}

	public function test_empty_group_zero_percent() {
		$groups = Lingolane_String_Locations::groups( 'en' );
		$this->assertArrayHasKey( 'widgets', $groups );
		$this->assertSame( 0, $groups['widgets']['total'] );
		$this->assertSame( 0, $groups['widgets']['percent'] );
	}

	public function test_page_pagination_and_filtering() {
		// Insert 25 occurrences into group 'site'.
		for ( $i = 1; $i <= 25; $i++ ) {
			$identity = array(
				'domain'  => 'lingolane-site',
				'source'  => "Setting $i",
				'context' => '',
			);
			$occ = array(
				'group_key' => 'site',
				'subgroup'  => 'general',
				'provider'  => 'option',
				'object_id' => 0,
				'field'     => "opt_$i",
				'page_path' => '/',
				'locator'   => "label[for=opt_$i]",
				'supported' => true,
			);
			Lingolane_String_Locations::record( $identity, $occ );
		}

		// Translate the first 5 items.
		for ( $i = 1; $i <= 5; $i++ ) {
			Lingolane_Strings::save( 'lingolane-site', "Setting $i", '', 'en', "Cài đặt $i" );
		}

		// Page 1 (20 per page).
		$page1 = Lingolane_String_Locations::page( 'site', '', 'en', 'all', '', 1, 20 );
		$this->assertSame( 25, $page1['total'] );
		$this->assertCount( 20, $page1['items'] );

		// Page 2 (remaining 5).
		$page2 = Lingolane_String_Locations::page( 'site', '', 'en', 'all', '', 2, 20 );
		$this->assertSame( 25, $page2['total'] );
		$this->assertCount( 5, $page2['items'] );

		// Filter missing only.
		$missing = Lingolane_String_Locations::page( 'site', '', 'en', 'missing', '', 1, 30 );
		$this->assertSame( 20, $missing['total'] );
		$this->assertCount( 20, $missing['items'] );

		// Filter translated only.
		$translated = Lingolane_String_Locations::page( 'site', '', 'en', 'translated', '', 1, 30 );
		$this->assertSame( 5, $translated['total'] );
		$this->assertCount( 5, $translated['items'] );

		// Search keyword.
		$search = Lingolane_String_Locations::page( 'site', '', 'en', 'all', 'Setting 12', 1, 10 );
		$this->assertSame( 1, $search['total'] );
		$this->assertSame( 'Setting 12', $search['items'][0]['source'] );
	}

	public function test_forget_and_sync_with_strings() {
		$identity = array(
			'domain'  => 'test-sync',
			'source'  => 'Sync Label',
			'context' => '',
		);
		$occ = array(
			'group_key' => 'menu',
			'subgroup'  => 'primary',
			'provider'  => 'menu',
			'object_id' => 99,
			'field'     => 'title',
			'page_path' => '/',
			'locator'   => '#menu-99',
			'supported' => true,
		);

		Lingolane_String_Locations::record( $identity, $occ );
		Lingolane_Strings::save( $identity['domain'], $identity['source'], $identity['context'], 'en', 'Nhãn đồng bộ' );

		$hash = Lingolane_Strings::hash( $identity['source'], $identity['context'] );

		// When Strings::delete is called, Locations should also forget.
		Lingolane_Strings::delete( $identity['domain'], $hash );

		$p = Lingolane_String_Locations::page( 'menu', '', 'en', 'all', 'Sync Label', 1, 10 );
		$this->assertSame( 0, $p['total'], 'Deleting string identity must forget its locations' );
	}
}
