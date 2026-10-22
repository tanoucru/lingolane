<?php
/**
 * Tree list screens: language column order, body class and scoped design tokens.
 */
class Test_Lingolane_Tree extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-columns.php';
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
}
