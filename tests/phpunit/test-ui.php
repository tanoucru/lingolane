<?php
/**
 * Shared admin UI helpers: the inline SVG icon set.
 */
class Test_Lingolane_UI extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once LINGOLANE_DIR . 'admin/class-lingolane-ui.php';
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
}
