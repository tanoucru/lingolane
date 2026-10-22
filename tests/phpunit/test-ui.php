<?php
/**
 * Shared admin UI helpers: the inline SVG icon set.
 */
class Test_Lingolane_UI extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once LINGOLANE_DIR . 'admin/class-lingolane-ui.php';
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
		unset( $_GET['tab'] );
		parent::tear_down();
	}

	public function test_every_required_icon_exists() {
		$required = array( 'layout-dashboard', 'files', 'type', 'languages', 'settings', 'life-buoy', 'circle-check', 'eye', 'triangle-alert', 'upload', 'download', 'search', 'plus', 'info', 'users', 'external-link', 'chevron-down', 'chevron-right', 'x', 'sparkles', 'globe', 'loader' );
		$icons    = Lingolane_UI::icons();
		foreach ( $required as $name ) {
			$this->assertContains( $name, $icons, "icon $name exists" );
		}
	}

	public function test_decorative_icon_is_hidden_from_screen_readers() {
		$svg = Lingolane_UI::icon( 'globe' );
		$this->assertStringContainsString( 'aria-hidden="true"', $svg );
		$this->assertStringContainsString( 'width="20"', $svg );
		$this->assertStringContainsString( 'class="lingolane-icon lingolane-icon--globe"', $svg );
		$this->assertStringNotContainsString( '<title>', $svg );
	}

	public function test_labelled_icon_has_escaped_title() {
		$svg = Lingolane_UI::icon( 'info', 16, 'A <b>' );
		$this->assertStringContainsString( 'role="img"', $svg );
		$this->assertStringContainsString( '<title>A &lt;b&gt;</title>', $svg );
		$this->assertStringContainsString( 'width="16"', $svg );
	}

	public function test_unknown_icon_returns_empty_string() {
		$this->setExpectedIncorrectUsage( 'Lingolane_UI::icon' );
		$this->assertSame( '', Lingolane_UI::icon( 'nope' ) );
	}

	public function test_size_is_clamped() {
		$this->assertStringContainsString( 'width="48"', Lingolane_UI::icon( 'x', 500 ) );
		$this->assertStringContainsString( 'width="12"', Lingolane_UI::icon( 'x', 1 ) );
	}

	public function test_no_screen_uses_dashicons() {
		foreach ( array_keys( Lingolane_Admin::tabs() ) as $tab ) {
			$_GET['tab'] = $tab;
			set_current_screen( 'toplevel_page_lingolane' );
			ob_start();
			Lingolane_Admin::render();
			$html = ob_get_clean();
			$this->assertStringNotContainsString( 'dashicons', $html, $tab );
			$this->assertStringContainsString( 'lingolane-icon', $html, $tab );
		}
	}

	public function test_metabox_and_column_use_svg_icons() {
		$page = $this->new_post( array( 'post_type' => 'page' ) );
		$copy = Lingolane_Elements::duplicate_post( $page, 'en', 'draft' );
		$this->assertNotWPError( $copy );

		// Neither the metabox nor the column renders an icon; assert what they do render.
		ob_start();
		Lingolane_Metabox::render( get_post( $page ) );
		$box = ob_get_clean();
		$this->assertStringContainsString( 'lingolane-box__list', $box );
		$this->assertStringNotContainsString( 'dashicons', $box );

		ob_start();
		Lingolane_Columns::render( 'lingolane_language', $page );
		$col = ob_get_clean();
		$this->assertStringContainsString( 'lingolane-col', $col );
		$this->assertStringNotContainsString( 'dashicons', $col );
	}

	public function test_styles_no_longer_depend_on_dashicons() {
		Lingolane_Admin::enqueue( 'toplevel_page_lingolane' );
		$this->assertNotContains( 'dashicons', wp_styles()->registered['lingolane-admin']->deps );
	}

	private function render_tab( $tab ) {
		$_GET['tab'] = $tab;
		set_current_screen( 'toplevel_page_lingolane' );
		ob_start();
		Lingolane_Admin::render();
		return ob_get_clean();
	}

	public function test_header_has_logo_tile() {
		$html = $this->render_tab( 'overview' );
		$this->assertStringContainsString( 'class="lingolane-logo"', $html );
		$this->assertStringContainsString( 'lingolane-icon--globe', $html );
	}

	public function test_overview_uses_kpi_cards() {
		$this->assertCount( 2, Lingolane_Languages::all(), 'fixture has two languages' );
		$html = $this->render_tab( 'overview' );
		$this->assertMatchesRegularExpression( '/class="lingolane-kpi"/', $html );
		$this->assertStringContainsString( 'lingolane-progress', $html );
	}

	public function test_old_token_aliases_removed() {
		$css = (string) file_get_contents( LINGOLANE_DIR . 'assets/admin.css' );
		foreach ( array( '--lingolane-brand', '--lingolane-soft', '--lingolane-shadow:', 'var(--lingolane-shadow)' ) as $alias ) {
			$this->assertSame( 0, substr_count( $css, $alias ), "$alias still in admin.css" );
		}
	}

	public function test_delete_links_keep_danger_colour() {
		$css = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( LINGOLANE_DIR . 'assets/admin.css' ) );
		$this->assertMatchesRegularExpression( '/\.lingolane-wrap \.button-link-delete\s*[,{][^}]*color:\s*var\(--lingolane-danger\)/s', $css );
		$this->assertMatchesRegularExpression( '/\.button-link:not\(\.button-link-delete\)/', $css );
	}

	public function test_success_notice_renders_as_autohiding_toast() {
		Lingolane_Admin::flash( 'success', 'Saved <b>' );
		$html = $this->render_tab( 'overview' );
		$this->assertStringContainsString( 'lingolane-toast--success', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'data-autohide="5000"', $html );
		$this->assertStringContainsString( 'Saved &lt;b&gt;', $html );
		$this->assertStringContainsString( 'lingolane-toast__close', $html );
		$this->assertStringNotContainsString( 'notice notice-', $html );
	}

	public function test_error_notice_stays_until_dismissed() {
		Lingolane_Admin::flash( 'error', 'Broken' );
		$html = $this->render_tab( 'overview' );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'data-autohide="0"', $html );
		$this->assertStringContainsString( 'lingolane-icon--triangle-alert', $html );
	}

	public function test_warning_and_info_toast_roles() {
		Lingolane_Admin::flash( 'warning', 'Careful' );
		$html = $this->render_tab( 'overview' );
		$this->assertStringContainsString( 'lingolane-toast--warning', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'data-autohide="0"', $html );
		Lingolane_Admin::flash( 'info', 'FYI' );
		$html = $this->render_tab( 'overview' );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'data-autohide="5000"', $html );
	}

	public function test_toast_region_and_close_button_markup() {
		Lingolane_Admin::flash( 'success', 'Done' );
		$html = $this->render_tab( 'overview' );
		$this->assertMatchesRegularExpression( '/class="lingolane-toasts" role="region" aria-label="[^"]+"/', $html );
		$this->assertMatchesRegularExpression( '/<button type="button" class="lingolane-toast__close" hidden aria-label="[^"]+"/', $html );
	}

	public function test_toast_icons_are_localized_for_js() {
		Lingolane_Admin::enqueue( 'toplevel_page_lingolane' );
		$data = (string) wp_scripts()->get_data( 'lingolane-admin', 'data' );
		$this->assertStringContainsString( '"icons"', $data );
		foreach ( array( 'circle-check', 'triangle-alert', 'lingolane-icon--info', 'lingolane-icon--x' ) as $needle ) {
			$this->assertStringContainsString( $needle, $data );
		}
	}
}
