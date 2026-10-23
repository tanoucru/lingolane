<?php
/**
 * Tests for Lingolane_String_Terms:
 * - Create and reuse translation term
 * - Taxonomy enabled and public only
 * - Source language immutable
 * - Failed insert has no mapping
 * - Duplicate save creates one term
 * - Parent hierarchy auto links when parent is translated
 * - Progress calculation per field
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Lingolane_String_Terms extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once LINGOLANE_DIR . 'admin/class-lingolane-string-terms.php';
	}

	protected function settings_array() {
		return array(
			'default'    => 'en',
			'languages'  => array(
				array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ),
				array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ),
			),
			'url_mode'   => 'directory',
			'taxonomies' => array( 'category', 'post_tag' ),
		);
	}

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();
		Lingolane_Languages::set_current( 'en' );
	}

	public function test_create_and_reuse_translation_term() {
		global $wpdb;
		$source = wp_insert_term( 'News', 'category', array( 'description' => 'Latest News' ) );
		$this->assertIsArray( $source );
		$source_id = (int) $source['term_id'];

		$trans_id = Lingolane_String_Terms::save( $source_id, 'category', 'vi', 'Tin Tức', 'Tin tức mới nhất' );
		$this->assertIsInt( $trans_id );
		$this->assertGreaterThan( 0, $trans_id );
		$this->assertNotSame( $source_id, $trans_id );

		// Verify translation mapping.
		$mapped = Lingolane_Elements::get_translation( 'tax_category', $source_id, 'vi' );
		$this->assertSame( $trans_id, $mapped );

		$term = get_term( $trans_id, 'category' );
		$this->assertSame( 'Tin Tức', $term->name );
		$this->assertSame( 'Tin tức mới nhất', $term->description );

		// Saving again updates existing term without creating new ID.
		$reused_id = Lingolane_String_Terms::save( $source_id, 'category', 'vi', 'Tin Tức Cập Nhật', 'Mô tả mới' );
		$this->assertSame( $trans_id, $reused_id );

		$updated_term = get_term( $trans_id, 'category' );
		$this->assertSame( 'Tin Tức Cập Nhật', $updated_term->name );
		$this->assertSame( 'Mô tả mới', $updated_term->description );

		// Verify original term is unchanged.
		$orig_term = get_term( $source_id, 'category' );
		$this->assertSame( 'News', $orig_term->name );
		$this->assertSame( 'Latest News', $orig_term->description );

		// Verify strings table contains no fake rows for terms.
		$fake_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lingolane_strings WHERE domain LIKE '%category%' OR domain LIKE '%term%'" );
		$this->assertSame( 0, $fake_rows );
	}

	public function test_taxonomy_enabled_and_public_only() {
		$bad_tax = Lingolane_String_Terms::page( 'non_existent_tax', 'vi', '', 1 );
		$this->assertSame( array( 'items' => array(), 'total' => 0 ), $bad_tax );

		$res = Lingolane_String_Terms::save( 1, 'non_existent_tax', 'vi', 'Name', 'Desc' );
		$this->assertWPError( $res );
	}

	public function test_source_language_immutable() {
		$source = wp_insert_term( 'Immutable Source', 'category' );
		$source_id = (int) $source['term_id'];

		$res = Lingolane_String_Terms::save( $source_id, 'category', 'en', 'Attempted Overwrite', 'Desc' );
		$this->assertWPError( $res );
		$this->assertSame( 'lingolane_immutable_source', $res->get_error_code() );

		$term = get_term( $source_id, 'category' );
		$this->assertSame( 'Immutable Source', $term->name );
	}

	public function test_failed_insert_has_no_mapping() {
		$source = wp_insert_term( 'Valid Source', 'category' );
		$source_id = (int) $source['term_id'];

		// Empty name fails term creation.
		$res = Lingolane_String_Terms::save( $source_id, 'category', 'vi', '', 'Desc' );
		$this->assertWPError( $res );

		$mapped = Lingolane_Elements::get_translation( 'tax_category', $source_id, 'vi' );
		$this->assertNull( $mapped );
	}

	public function test_duplicate_save_creates_one_term() {
		$source = wp_insert_term( 'Single Term', 'category' );
		$source_id = (int) $source['term_id'];

		$id1 = Lingolane_String_Terms::save( $source_id, 'category', 'vi', 'Duy Nhất', 'Desc' );
		$id2 = Lingolane_String_Terms::save( $source_id, 'category', 'vi', 'Duy Nhất', 'Desc' );

		$this->assertSame( $id1, $id2 );

		$trid = Lingolane_Elements::get_trid( 'tax_category', $source_id );
		$group = Lingolane_Elements::get_group( $trid );
		$this->assertSame( array( 'en' => $source_id, 'vi' => $id1 ), $group );
	}

	public function test_parent_hierarchy_auto_links() {
		$parent = wp_insert_term( 'Tech Parent', 'category' );
		$parent_id = (int) $parent['term_id'];

		$child = wp_insert_term( 'AI Child', 'category', array( 'parent' => $parent_id ) );
		$child_id = (int) $child['term_id'];

		// Step 1: Translate child first (parent translation does not exist yet).
		$trans_child_id = Lingolane_String_Terms::save( $child_id, 'category', 'vi', 'AI Con', '' );
		$this->assertGreaterThan( 0, $trans_child_id );

		$child_term = get_term( $trans_child_id, 'category' );
		$this->assertSame( 0, (int) $child_term->parent, 'Child parent should initially be 0' );

		// Step 2: Now translate parent.
		$trans_parent_id = Lingolane_String_Terms::save( $parent_id, 'category', 'vi', 'Công Nghệ Cha', '' );
		$this->assertGreaterThan( 0, $trans_parent_id );

		// Step 3: Check child translation again; parent should now be auto-updated to $trans_parent_id!
		clean_term_cache( $trans_child_id, 'category' );
		$updated_child = get_term( $trans_child_id, 'category' );
		$this->assertSame( $trans_parent_id, (int) $updated_child->parent, 'Child parent must be linked to translated parent' );
	}

	public function test_progress_calculation() {
		// Clean categories first by creating specific testing terms.
		$term1 = wp_insert_term( 'Prog Term 1', 'post_tag', array( 'description' => 'Has Desc' ) );
		$id1 = (int) $term1['term_id'];

		$term2 = wp_insert_term( 'Prog Term 2', 'post_tag', array( 'description' => '' ) );
		$id2 = (int) $term2['term_id'];

		Lingolane_String_Terms::save( $id1, 'post_tag', 'vi', 'Thẻ 1', 'Có mô tả' );

		$progress = Lingolane_String_Terms::progress( 'vi' );
		$this->assertIsArray( $progress );
		$this->assertArrayHasKey( 'total', $progress );
		$this->assertArrayHasKey( 'completed', $progress );
		$this->assertArrayHasKey( 'percent', $progress );
		$this->assertGreaterThanOrEqual( 3, $progress['total'] );
		$this->assertGreaterThanOrEqual( 2, $progress['completed'] );
	}

	public function test_page_lists_terms_and_keyword_search() {
		$term1 = wp_insert_term( 'Alpha Unique Term', 'category', array( 'description' => 'Alpha Description' ) );
		$id1 = (int) $term1['term_id'];

		$term2 = wp_insert_term( 'Beta Term', 'category', array( 'description' => 'Beta Description' ) );
		$id2 = (int) $term2['term_id'];

		Lingolane_String_Terms::save( $id1, 'category', 'vi', 'Alpha Tiếng Việt', 'Mô tả Alpha' );

		$page = Lingolane_String_Terms::page( 'category', 'vi', 'Alpha', 1, 10 );
		$this->assertNotEmpty( $page['items'] );
		$this->assertSame( 1, $page['total'] );
		$this->assertSame( $id1, $page['items'][0]['source_id'] );
		$this->assertSame( 'Alpha Tiếng Việt', $page['items'][0]['translated_name'] );
		$this->assertSame( 'Mô tả Alpha', $page['items'][0]['translated_description'] );
		$this->assertSame( 'translated', $page['items'][0]['status'] );
	}
}
