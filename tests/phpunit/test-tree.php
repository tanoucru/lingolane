<?php
/**
 * Tree list screens: language column order, body class and scoped design tokens.
 */
class Test_Lingolane_Tree extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-columns.php';
	}

	protected function settings_array() {
		$settings = parent::settings_array();
		$settings['languages'][] = array( 'code' => 'fr', 'locale' => 'fr_FR', 'name' => 'French' );
		return $settings;
	}

	public function tear_down() {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_language_column_follows_title() {
		$columns = Lingolane_Columns::columns( array( 'cb' => '', 'title' => 'T', 'author' => 'A', 'date' => 'D' ) );
		$this->assertSame( array( 'cb', 'title', 'lingolane_language', 'author', 'date' ), array_keys( $columns ) );
		unset( $columns['lingolane_language'] );
		$this->assertSame( array( 'cb' => '', 'title' => 'T', 'author' => 'A', 'date' => 'D' ), $columns );
	}

	public function test_language_column_appends_without_title() {
		$this->assertSame(
			array( 'cb' => '', 'author' => 'A', 'date' => 'D', 'lingolane_language' => __( 'Languages', 'lingolane' ) ),
			Lingolane_Columns::columns( array( 'cb' => '', 'author' => 'A', 'date' => 'D' ) )
		);
	}

	public function test_body_class_only_on_translatable_lists() {
		$this->assertTrue( is_callable( array( 'Lingolane_Columns', 'body_class' ) ) );
		$this->register_shared_fixtures();
		foreach ( array( 'edit-page', 'edit-post' ) as $screen ) {
			set_current_screen( $screen );
			$this->assertSame( 'existing lingolane-tree', Lingolane_Columns::body_class( 'existing' ) );
		}
		foreach ( array( 'edit-science', 'toplevel_page_lingolane', 'page', 'edit-attachment' ) as $screen ) {
			set_current_screen( $screen );
			$this->assertSame( 'existing', Lingolane_Columns::body_class( 'existing' ) );
		}
	}

	public function test_tree_screen_requires_a_translatable_non_shared_list() {
		$this->assertTrue( is_callable( array( 'Lingolane_Columns', 'tree_screen' ) ) );
		$this->register_shared_fixtures();
		set_current_screen( 'edit-page' );
		$this->assertTrue( Lingolane_Columns::tree_screen() );
		foreach ( array( 'edit-science', 'toplevel_page_lingolane', 'page', 'edit-attachment' ) as $screen ) {
			set_current_screen( $screen );
			$this->assertFalse( Lingolane_Columns::tree_screen() );
		}
	}

	public function test_tree_screen_handles_no_current_screen() {
		$this->assertTrue( is_callable( array( 'Lingolane_Columns', 'tree_screen' ) ) );
		$GLOBALS['current_screen'] = null;
		$this->assertFalse( Lingolane_Columns::tree_screen() );
		$this->assertSame( 'existing', Lingolane_Columns::body_class( 'existing' ) );
	}

	public function test_init_registers_tree_body_class_filter() {
		Lingolane_Columns::init();
		$this->assertSame( 10, has_filter( 'admin_body_class', array( 'Lingolane_Columns', 'body_class' ) ) );
		set_current_screen( 'edit-page' );
		$this->assertStringContainsString( ' lingolane-tree', apply_filters( 'admin_body_class', 'existing' ) );
	}

	public function test_tree_list_shares_the_existing_token_declaration() {
		$css = file_get_contents( LINGOLANE_DIR . 'assets/admin.css' );
		$this->assertMatchesRegularExpression(
			'/\.lingolane-wrap,\s*\.lingolane-box,\s*\.lingolane-col,\s*\.lingolane-toasts,\s*body\.lingolane-tree \.wp-list-table\s*\{\s*--lingolane-bg:/',
			$css
		);
	}

	private function require_tree_model() {
		$file = LINGOLANE_DIR . 'admin/class-lingolane-tree.php';
		$this->assertFileExists( $file );
		require_once $file;
	}

	private function new_translation_group() {
		$id = $this->new_post( array( 'post_type' => 'page' ) );
		$trid = Lingolane_Elements::set( 'post_page', $id, 'vi' );
		$this->assertNotWPError( $trid );
		$en = Lingolane_Elements::duplicate_post( $id, 'en', 'draft' );
		$this->assertNotWPError( $en );
		return array( $id, $en, $trid );
	}

	public function test_group_lists_members_missing_and_progress() {
		$this->require_tree_model();
		list( $id, $en, $trid ) = $this->new_translation_group();
		$this->assertSame( 'draft', get_post_status( $en ) );
		$this->assertSame(
			array( $id => array( 'trid' => $trid, 'lang' => 'vi', 'members' => array( 'en' => $en ), 'missing' => array( 'fr' ), 'done' => 2, 'total' => 3 ) ),
			Lingolane_Tree::groups( 'page', array( $id ) )
		);
	}

	public function test_trashed_translation_is_not_a_member() {
		$this->require_tree_model();
		list( $id, $en ) = $this->new_translation_group();
		wp_trash_post( $en );
		$group = Lingolane_Tree::groups( 'page', array( $id ) )[ $id ];
		$this->assertSame( array(), $group['members'] );
		$this->assertSame( array( 'en', 'fr' ), $group['missing'] );
		$this->assertSame( 1, $group['done'] );
	}

	public function test_ungrouped_and_shared_posts_are_left_out() {
		$this->require_tree_model();
		$this->register_shared_fixtures();
		$unassigned = $this->new_post( array( 'post_type' => 'page' ) );
		$shared = $this->new_post( array( 'post_type' => 'science' ) );
		$this->assertNotWPError( Lingolane_Elements::set( 'post_science', $shared, 'vi' ) );
		$this->assertSame( array(), Lingolane_Tree::groups( 'page', array( $unassigned, $shared ) ) );
		$this->assertSame( array(), Lingolane_Tree::groups( 'science', array( $shared ) ) );
	}

	public function test_shared_page_is_left_out_but_editorial_child_is_grouped() {
		$this->require_tree_model();
		add_filter( 'lingolane_shared_page_slugs', function () { return array( 'application' ); } );
		$shared = $this->new_post( array( 'post_type' => 'page', 'post_name' => 'application' ) );
		$child = $this->new_post( array( 'post_type' => 'page', 'post_name' => 'application', 'post_parent' => $shared ) );
		$this->assertNotWPError( Lingolane_Elements::set( 'post_page', $shared, 'vi' ) );
		$this->assertNotWPError( Lingolane_Elements::set( 'post_page', $child, 'en' ) );
		wp_cache_flush();
		global $wpdb;
		$before = $wpdb->num_queries;
		$groups = Lingolane_Tree::groups( 'page', array( $shared, $child ) );
		$this->assertLessThanOrEqual( 3, $wpdb->num_queries - $before );
		$this->assertSame( array( $child ), array_keys( $groups ) );
		$this->assertSame( array( 'vi', 'fr' ), $groups[ $child ]['missing'] );
		$this->assertSame( $shared, wp_get_post_parent_id( $child ) );
	}

	public function test_groups_use_constant_queries() {
		$this->require_tree_model();
		$ids = array();
		$translations = array();
		for ( $i = 0; $i < 20; $i++ ) {
			list( $id, $en ) = $this->new_translation_group();
			$ids[] = $id;
			$translations[ $id ] = $en;
		}
		wp_cache_flush();
		global $wpdb;
		$before = $wpdb->num_queries;
		$groups = Lingolane_Tree::groups( 'page', $ids );
		$this->assertLessThanOrEqual( 3, $wpdb->num_queries - $before );
		$this->assertCount( 20, $groups );
		$before = $wpdb->num_queries;
		foreach ( $translations as $id => $en ) {
			$this->assertSame( array( 'en' => $en ), $groups[ $id ]['members'] );
			$this->assertSame( 'draft', get_post_status( $en ) );
		}
		$this->assertSame( $before, $wpdb->num_queries, 'Member post caches should be primed.' );
	}

	public function test_empty_leaders_use_no_queries() {
		$this->require_tree_model();
		Lingolane_Tree::init();
		wp_cache_flush();
		global $wpdb;
		$before = $wpdb->num_queries;
		$this->assertSame( array(), Lingolane_Tree::groups( 'page', array() ) );
		$this->assertSame( $before, $wpdb->num_queries );
	}
}
