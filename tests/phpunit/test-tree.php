<?php
/**
 * Tree list screens: language column order, body class and scoped design tokens.
 */
class Test_Lingolane_Tree extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-columns.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-ui.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-admin.php';
	}

	protected function settings_array() {
		$settings = parent::settings_array();
		$settings['languages'][] = array( 'code' => 'fr', 'locale' => 'fr_FR', 'name' => 'French' );
		return $settings;
	}

	public function tear_down() {
		wp_dequeue_script( 'lingolane-tree' );
		wp_deregister_script( 'lingolane-tree' );
		unset( $_GET['lingolane_lang'], $_REQUEST['paged'] );
		if ( class_exists( 'Lingolane_Tree' ) ) {
			Lingolane_Tree::reset();
		}
		unset( $GLOBALS['wp_list_table'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/** A+A_en, B+B_en, C alone (no language), D_en whose source D is in the trash. */
	private function tree_fixture() {
		$ids = array();
		foreach ( array( 'A', 'B', 'D' ) as $title ) {
			$ids[ $title ] = $this->new_post( array( 'post_type' => 'page', 'post_title' => $title ) );
			$this->assertNotWPError( Lingolane_Elements::set( 'post_page', $ids[ $title ], 'vi' ) );
			$ids[ $title . '_en' ] = Lingolane_Elements::duplicate_post( $ids[ $title ], 'en', 'publish' );
			$this->assertNotWPError( $ids[ $title . '_en' ] );
		}
		$ids['C'] = $this->new_post( array( 'post_type' => 'page', 'post_title' => 'C' ) );
		wp_trash_post( $ids['D'] );
		return $ids;
	}

	/** Runs a page query the way the admin list runs its main query. */
	private function tree_query( array $args = array(), $screen = 'edit-page' ) {
		Lingolane_Tree::init();
		set_current_screen( $screen );
		$GLOBALS['wp_the_query'] = new WP_Query();
		$query = $GLOBALS['wp_the_query'];
		$query->query( array_merge( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 2, 'orderby' => 'title', 'order' => 'ASC' ), $args ) );
		return $query;
	}

	private function ids( $query ) {
		$ids = array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );
		sort( $ids );
		return $ids;
	}

	public function test_tree_query_lists_leaders_and_paginates_by_group() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$first = $this->tree_query();
		$this->assertSame( 4, (int) $first->found_posts );
		$this->assertSame( 2, (int) $first->max_num_pages );
		$seen = array_merge( $this->ids( $first ), $this->ids( $this->tree_query( array( 'paged' => 2 ) ) ) );
		sort( $seen );
		$leaders = array( $ids['A'], $ids['B'], $ids['C'], $ids['D_en'] );
		sort( $leaders );
		$this->assertSame( $leaders, $seen );
	}

	public function test_orphan_translation_stays_visible() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$seen = array_merge( $this->ids( $this->tree_query() ), $this->ids( $this->tree_query( array( 'paged' => 2 ) ) ) );
		$this->assertContains( $ids['D_en'], $seen );
		$this->assertNotContains( $ids['D'], $seen );
		// The All view sends an empty status, not 'any'.
		$all = $this->ids( $this->tree_query( array( 'post_status' => '', 'posts_per_page' => -1 ) ) );
		$this->assertContains( $ids['D_en'], $all );
		$this->assertNotContains( $ids['A_en'], $all );
	}

	public function test_mine_and_month_views_stay_flat() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$author = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_update_post( array( 'ID' => $ids['A_en'], 'post_author' => $author ) );
		$this->assertSame( array( $ids['A_en'] ), $this->ids( $this->tree_query( array( 'author' => $author, 'posts_per_page' => -1 ) ) ) );
		$month = (int) get_the_date( 'Ym', $ids['A_en'] );
		$this->assertContains( $ids['A_en'], $this->ids( $this->tree_query( array( 'm' => $month, 'posts_per_page' => -1 ) ) ) );
	}

	public function test_translation_of_unreadable_private_source_stays_visible() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post( array( 'ID' => $ids['A'], 'post_status' => 'private' ) );
		wp_update_post( array( 'ID' => $ids['A_en'], 'post_status' => 'private', 'post_author' => $author ) );
		wp_set_current_user( $author );
		$found = $this->ids( $this->tree_query( array( 'post_status' => '', 'posts_per_page' => -1 ) ) );
		$this->assertContains( $ids['A_en'], $found );
		$this->assertNotContains( $ids['A'], $found );
		$this->assertNotContains( $ids['B_en'], $found );
		// A status view runs with perm=readable, which hides the same private posts.
		$private = $this->ids( $this->tree_query( array( 'post_status' => 'private', 'perm' => 'readable', 'posts_per_page' => -1 ) ) );
		$this->assertSame( array( $ids['A_en'] ), $private );
		$any = $this->ids( $this->tree_query( array( 'posts_per_page' => -1 ) ) );
		$this->assertContains( $ids['A'], $any );
		$this->assertNotContains( $ids['A_en'], $any );
	}

	public function test_language_filter_trash_and_shared_types_are_flat() {
		$this->require_tree_model();
		$this->register_shared_fixtures();
		$ids = $this->tree_fixture();
		add_action( 'pre_get_posts', array( 'Lingolane_Columns', 'filter' ), 20 );
		$_GET['lingolane_lang'] = 'en';
		$this->assertContains( $ids['A_en'], $this->ids( $this->tree_query( array( 'posts_per_page' => -1 ) ) ) );
		unset( $_GET['lingolane_lang'] );
		$this->assertContains( $ids['D'], $this->ids( $this->tree_query( array( 'post_status' => 'trash' ) ) ) );
		$query = $this->tree_query( array(), 'edit-science' );
		$this->assertSame( ' AND 1=1', Lingolane_Tree::posts_where( ' AND 1=1', $query ) );
		add_filter( 'lingolane_tree_mode', '__return_false' );
		$this->assertContains( $ids['A_en'], $this->ids( $this->tree_query( array( 'posts_per_page' => -1 ) ) ) );
		remove_filter( 'lingolane_tree_mode', '__return_false' );
		$secondary = new WP_Query( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1 ) );
		$this->assertContains( $ids['A_en'], $this->ids( $secondary ) );
	}

	public function test_search_matching_a_translation_lists_its_group() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		wp_update_post( array( 'ID' => $ids['B_en'], 'post_title' => 'Unique Zebra' ) );
		$query = $this->tree_query( array( 's' => 'Zebra' ) );
		$this->assertSame( array( $ids['B'] ), $this->ids( $query ) );
		$this->assertArrayHasKey( $ids['B_en'], Lingolane_Tree::$matches );
		$this->assertContains( 'lingolane-tree-match', get_post_class( '', $ids['B_en'] ) );
		$this->assertNotContains( 'lingolane-tree-match', get_post_class( '', $ids['B'] ) );
		$this->assertSame( array(), $this->ids( $this->tree_query( array( 's' => 'Nothing-matches-this' ) ) ) );
		$this->assertSame( array(), Lingolane_Tree::$matches );
	}

	public function test_hierarchical_id_parent_query_is_filtered() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$found = $this->ids( $this->tree_query( array( 'fields' => 'id=>parent', 'posts_per_page' => -1 ) ) );
		$this->assertContains( $ids['A'], $found );
		$this->assertNotContains( $ids['A_en'], $found );
		$this->assertNotContains( $ids['B_en'], $found );
	}

	public function test_status_view_keeps_translations_of_hidden_sources() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		wp_update_post( array( 'ID' => $ids['B'], 'post_status' => 'draft' ) );
		$found = $this->ids( $this->tree_query( array( 'post_status' => 'publish', 'posts_per_page' => -1 ) ) );
		$this->assertContains( $ids['B_en'], $found );
		$this->assertNotContains( $ids['B'], $found );
		$this->assertNotContains( $ids['A_en'], $found );
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
		$this->assertSame( array( 'fr' ), $group['missing'] );
		$this->assertNotContains( 'en', $group['missing'] );
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

	/** Signs in as an administrator and builds the pages list table the admin screen would use. */
	private function tree_list_table() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$GLOBALS['wp_list_table'] = _get_list_table( 'WP_Posts_List_Table', array( 'screen' => 'edit-page' ) );
		return $GLOBALS['wp_list_table'];
	}

	public function test_tree_script_only_selects_top_level_rows() {
		$js = file_get_contents( LINGOLANE_DIR . 'assets/tree.js' );
		$this->assertStringContainsString( 'Array.prototype.filter.call( fragment.children, function ( el )', $js );
		$this->assertStringContainsString( "return el.tagName === 'TR';", $js );
	}

	public function test_status_view_templates_only_hold_that_status() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		wp_update_post( array( 'ID' => $ids['A_en'], 'post_status' => 'draft' ) );
		$this->tree_query( array( 'post_status' => 'publish' ) );
		Lingolane_Tree::remember( $ids['A'] );
		$this->assertStringNotContainsString( 'id="post-' . $ids['A_en'] . '"', $this->printed_templates() );
		Lingolane_Tree::reset();
		$this->tree_query( array( 'post_status' => array( 'draft' ) ) );
		Lingolane_Tree::remember( $ids['A'] );
		$this->assertStringContainsString( 'id="post-' . $ids['A_en'] . '"', $this->printed_templates() );
	}

	public function test_custom_private_status_member_needs_read_post() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		$admin = get_current_user_id();
		register_post_status( 'lingo_secret', array( 'private' => true ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $editor )->add_cap( 'read_private_pages', false );
		try {
			wp_update_post( array( 'ID' => $ids['A_en'], 'post_status' => 'lingo_secret', 'post_author' => $admin ) );
			wp_set_current_user( $editor );
			$this->assertFalse( current_user_can( 'read_post', $ids['A_en'] ) );
			$this->tree_query();
			Lingolane_Tree::remember( $ids['A'] );
			$this->assertStringNotContainsString( 'id="post-' . $ids['A_en'] . '"', $this->printed_templates() );
			wp_set_current_user( $admin );
			$this->assertStringContainsString( 'id="post-' . $ids['A_en'] . '"', $this->printed_templates() );
		} finally {
			unset( $GLOBALS['wp_post_statuses']['lingo_secret'] );
		}
	}

	public function test_chip_skips_unreadable_private_translations() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		$admin = get_current_user_id();
		wp_update_post( array( 'ID' => $ids['A_en'], 'post_status' => 'private', 'post_author' => $admin ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( current_user_can( 'read_post', $ids['A_en'] ) );
		$this->tree_query();
		$states = Lingolane_Tree::post_states( array(), get_post( $ids['A'] ) );
		$this->assertStringContainsString( '1/3', $states['lingolane_progress'] );
		wp_set_current_user( $admin );
		$states = Lingolane_Tree::post_states( array(), get_post( $ids['A'] ) );
		$this->assertStringContainsString( '2/3', $states['lingolane_progress'] );
	}

	public function test_templates_query_count_constant() {
		$this->require_tree_model();
		$leaders = array();
		$members = array();
		$authors = array();
		for ( $i = 0; $i < 4; $i++ ) {
			list( $id, $en ) = $this->new_translation_group();
			$author = self::factory()->user->create( array( 'role' => 'author' ) );
			wp_update_post( array( 'ID' => $en, 'post_author' => $author ) );
			$leaders[] = $id;
			$members[] = $en;
			$authors[] = $author;
		}
		$this->tree_list_table();
		$this->tree_query( array( 'posts_per_page' => -1 ) );
		global $wpdb;
		$counts = array();
		foreach ( array( 2, 4 ) as $size ) {
			Lingolane_Tree::reset();
			foreach ( array_slice( $leaders, 0, $size ) as $id ) {
				Lingolane_Tree::remember( $id );
			}
			$this->printed_templates(); // Warm unrelated list-table caches before measuring cold member caches.
			foreach ( array_slice( $members, 0, $size ) as $id ) {
				wp_cache_delete( $id, 'post_meta' );
				foreach ( get_object_taxonomies( 'page' ) as $taxonomy ) {
					wp_cache_delete( $id, $taxonomy . '_relationships' );
				}
			}
			foreach ( array_slice( $authors, 0, $size ) as $id ) {
				clean_user_cache( $id );
			}
			$before = $wpdb->num_queries;
			$html = $this->printed_templates();
			$counts[] = $wpdb->num_queries - $before;
			foreach ( array_slice( $members, 0, $size ) as $id ) {
				$this->assertStringContainsString( 'id="post-' . $id . '"', $html );
			}
		}
		$this->assertSame( $counts[0], $counts[1], 'Printing 2 and 4 groups must use the same number of queries.' );
	}

	public function test_narrowed_queries_stay_flat() {
		$this->require_tree_model();
		foreach ( array(
			array( 'tax_query' => array( array( 'taxonomy' => 'category', 'terms' => array( 1 ) ) ) ),
			array( 'post__in' => array( 1 ) ),
			array( 'meta_query' => array( array( 'key' => 'tree_test', 'value' => 'yes' ) ) ),
			array( 'post_parent' => 1 ),
		) as $args ) {
			$this->assertFalse( Lingolane_Tree::is_tree_query( $this->tree_query( $args ) ), wp_json_encode( $args ) );
		}
	}

	public function test_inline_save_row_keeps_chip() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		$GLOBALS['wp_the_query'] = new WP_Query();
		$GLOBALS['wp_the_query']->set( 'lingolane_tree_off', true );
		$previous = $_POST;
		$_POST['action'] = 'inline-save';
		$_POST['screen'] = 'edit-page';
		add_filter( 'wp_doing_ajax', '__return_true' );
		try {
			// Real AJAX case: get_current_screen() is null, core sends $_POST['screen'].
			$GLOBALS['current_screen'] = null;
			$states = Lingolane_Tree::post_states( array(), get_post( $ids['A'] ) );
			$this->assertArrayHasKey( 'lingolane_progress', $states );
			$this->assertStringContainsString( '2/3', $states['lingolane_progress'] );

			// Mocked screen case.
			set_current_screen( 'edit-page' );
			$states = Lingolane_Tree::post_states( array(), get_post( $ids['A'] ) );
			$this->assertArrayHasKey( 'lingolane_progress', $states );
			$this->assertStringContainsString( '2/3', $states['lingolane_progress'] );

			$_POST['action'] = 'other-action';
			$this->assertSame( array(), Lingolane_Tree::post_states( array(), get_post( $ids['A'] ) ) );
			$_POST['action'] = 'inline-save';
			set_current_screen( 'front' );
			unset( $_POST['screen'] );
			$this->assertSame( array(), Lingolane_Tree::post_states( array(), get_post( $ids['A'] ) ) );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$_POST = $previous;
		}
	}

	private function printed_templates() {
		ob_start();
		Lingolane_Tree::print_templates( 'bottom' );
		return ob_get_clean();
	}

	public function test_templates_hold_real_list_rows() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		$this->tree_query();
		Lingolane_Tree::remember( $ids['A'] );
		$html = $this->printed_templates();
		$this->assertStringContainsString( '<template class="lingolane-tree-tpl" data-lingolane-leader="' . $ids['A'] . '"', $html );
		$this->assertStringContainsString( 'id="post-' . $ids['A_en'] . '"', $html );
		$this->assertStringContainsString( 'name="post[]"', $html );
		$this->assertStringContainsString( 'value="' . $ids['A_en'] . '"', $html );
		$this->assertStringContainsString( 'id="inline_' . $ids['A_en'] . '"', $html );
		$this->assertStringContainsString( 'lingolane-tree-missing', $html );
		$this->assertStringContainsString( 'class="colspanchange column-title" colspan="', $html );
		$this->assertStringContainsString( 'data-lingolane-lang="fr"', $html );
		$this->assertStringContainsString( esc_url( Lingolane_Admin::translate_url( $ids['A'], 'fr' ) ), $html );
	}

	public function test_templates_only_at_bottom_and_in_tree_mode() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		$this->tree_query();
		Lingolane_Tree::remember( $ids['A'] );
		ob_start();
		Lingolane_Tree::print_templates( 'top' );
		$this->assertSame( '', ob_get_clean() );
		$_GET['lingolane_lang'] = 'en';
		$this->tree_query();
		$this->assertSame( '', $this->printed_templates() );
	}

	public function test_progress_state_on_leader() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		$this->tree_query();
		$states = Lingolane_Tree::post_states( array(), get_post( $ids['A'] ) );
		$this->assertArrayHasKey( 'lingolane_progress', $states );
		$this->assertStringContainsString( '2/3', $states['lingolane_progress'] );
		$this->assertArrayNotHasKey( 'lingolane_progress', Lingolane_Tree::post_states( array(), get_post( $ids['C'] ) ) );
	}

	public function test_without_js_every_translation_is_reachable() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		$this->tree_query();
		ob_start();
		Lingolane_Columns::render( 'lingolane_language', $ids['A'] );
		$html = ob_get_clean();
		$this->assertStringContainsString( esc_url( get_edit_post_link( $ids['A_en'], 'raw' ) ), $html );
		$this->assertStringContainsString( esc_url( Lingolane_Admin::translate_url( $ids['A'], 'fr' ) ), $html );
	}

	public function test_no_missing_row_for_trashed_leader_or_without_cap() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		$this->tree_query();
		wp_trash_post( $ids['A'] );
		Lingolane_Tree::remember( $ids['A'] );
		$this->assertStringNotContainsString( 'lingolane-tree-missing', $this->printed_templates() );

		Lingolane_Tree::reset();
		wp_untrash_post( $ids['A'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->tree_query();
		Lingolane_Tree::remember( $ids['A'] );
		$this->assertStringNotContainsString( 'lingolane-tree-missing', $this->printed_templates() );
	}

	public function test_progress_states_use_constant_queries() {
		$this->require_tree_model();
		$leaders = array();
		for ( $i = 0; $i < 6; $i++ ) {
			list( $id ) = $this->new_translation_group();
			$leaders[] = $id;
		}
		$this->tree_list_table();
		$query = $this->tree_query( array( 'posts_per_page' => -1 ) );
		wp_cache_flush();
		global $wpdb;
		$before = $wpdb->num_queries;
		foreach ( $leaders as $id ) {
			$states = Lingolane_Tree::post_states( array(), get_post( $id ) );
			$this->assertArrayHasKey( 'lingolane_progress', $states );
			$this->assertStringContainsString( '2/3', $states['lingolane_progress'] );
		}
		$this->assertLessThanOrEqual( 3, $wpdb->num_queries - $before );
	}

	public function test_translations_leading_one_group_do_not_repeat_rows() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$ids['A_fr'] = Lingolane_Elements::duplicate_post( $ids['A'], 'fr', 'publish' );
		$this->assertNotWPError( $ids['A_fr'] );
		wp_update_post( array( 'ID' => $ids['A'], 'post_status' => 'draft' ) );
		$this->tree_list_table();
		$query = $this->tree_query( array( 'post_status' => 'publish', 'posts_per_page' => -1 ) );
		$this->assertContains( $ids['A_en'], $this->ids( $query ) );
		$this->assertContains( $ids['A_fr'], $this->ids( $query ) );
		Lingolane_Tree::remember( $ids['A_en'] );
		Lingolane_Tree::remember( $ids['A_fr'] );
		$html = $this->printed_templates();
		$this->assertSame( 0, substr_count( $html, 'id="post-' . $ids['A'] . '"' ) );
		$this->assertSame( 0, substr_count( $html, 'id="post-' . $ids['A_en'] . '"' ) );
		$this->assertSame( 0, substr_count( $html, 'id="post-' . $ids['A_fr'] . '"' ) );
		$this->assertStringNotContainsString( 'data-lingolane-leader="' . $ids['A_fr'] . '"', $html );
	}

	/** Pages a, a1 (child of b), b, c, d sort a, a1, b… by title but print a, b, a1, c, d, so page 2 is b, a1, c. */
	public function test_progress_states_batch_the_page_a_hierarchical_list_prints() {
		$this->require_tree_model();
		$ids = array();
		foreach ( array( 'a', 'b', 'c', 'd' ) as $title ) {
			$ids[ $title ] = $this->new_post( array( 'post_type' => 'page', 'post_title' => $title ) );
		}
		$ids['a1'] = $this->new_post( array( 'post_type' => 'page', 'post_title' => 'a1', 'post_parent' => $ids['b'] ) );
		foreach ( $ids as $id ) {
			$this->assertNotWPError( Lingolane_Elements::set( 'post_page', $id, 'vi' ) );
			$this->assertNotWPError( Lingolane_Elements::duplicate_post( $id, 'en', 'publish' ) );
		}
		$table = $this->tree_list_table();
		$query = $this->tree_query( array( 'fields' => 'id=>parent', 'orderby' => 'menu_order title', 'order' => 'asc', 'posts_per_page' => -1 ) );
		$this->assertSame( array( $ids['a'], $ids['a1'], $ids['b'], $ids['c'], $ids['d'] ), array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) ) );
		$pagination = new ReflectionMethod( $table, 'set_pagination_args' );
		$pagination->setAccessible( true );
		$pagination->invoke( $table, array( 'total_items' => 5, 'per_page' => 2 ) );
		$_REQUEST['paged'] = 2;
		$posts = array_map( 'get_post', array( $ids['b'], $ids['a1'], $ids['c'] ) );
		wp_cache_flush();
		global $wpdb;
		$before = $wpdb->num_queries;
		foreach ( $posts as $post ) {
			$states = Lingolane_Tree::post_states( array(), $post );
			$this->assertStringContainsString( '2/3', $states['lingolane_progress'] );
		}
		$this->assertLessThanOrEqual( 3, $wpdb->num_queries - $before );
	}

	public function test_tree_script_enqueued_only_on_tree_screens() {
		wp_dequeue_script( 'lingolane-tree' );
		wp_deregister_script( 'lingolane-tree' );
		set_current_screen( 'edit-page' );
		Lingolane_Columns::enqueue( 'edit.php' );
		$this->assertTrue( wp_script_is( 'lingolane-tree', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'lingolane-tree', 'data' );
		$this->assertStringContainsString( '"postType":"page"', $data );
		$this->assertStringContainsString( 'lingolane-icon--chevron-down', $data );
		$this->assertStringContainsString( 'lingolane-icon--chevron-right', $data );
		$this->assertSame( array(), wp_scripts()->registered['lingolane-tree']->deps );
		$this->assertSame( 1, wp_scripts()->get_data( 'lingolane-tree', 'group' ) );

		wp_dequeue_script( 'lingolane-tree' );
		wp_deregister_script( 'lingolane-tree' );
		$this->register_shared_fixtures();
		set_current_screen( 'edit-science' );
		Lingolane_Columns::enqueue( 'edit.php' );
		$this->assertFalse( wp_script_is( 'lingolane-tree', 'enqueued' ) );

		set_current_screen( 'page' );
		Lingolane_Columns::enqueue( 'post.php' );
		$this->assertFalse( wp_script_is( 'lingolane-tree', 'enqueued' ) );
	}

	public function test_private_member_row_hidden_from_users_who_cannot_read_it() {
		$this->require_tree_model();
		$ids = $this->tree_fixture();
		$this->tree_list_table();
		wp_update_post( array( 'ID' => $ids['A_en'], 'post_status' => 'private', 'post_author' => get_current_user_id() ) );
		$this->tree_query();
		Lingolane_Tree::remember( $ids['A'] );
		$this->assertStringContainsString( 'id="post-' . $ids['A_en'] . '"', $this->printed_templates() );

		Lingolane_Tree::reset();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->tree_query();
		Lingolane_Tree::remember( $ids['A'] );
		$this->assertStringNotContainsString( 'id="post-' . $ids['A_en'] . '"', $this->printed_templates() );
	}
}
