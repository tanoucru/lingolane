<?php
/**
 * Tests for Lingolane_Switcher Gutenberg Block (M3 C7):
 * - Register block lingolane/language-switcher
 * - Render block output
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Switcher_Block extends Lingolane_TestCase {

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

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();
		Lingolane_Languages::set_current( 'en' );
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( 'lingolane/language-switcher' ) ) {
			unregister_block_type( 'lingolane/language-switcher' );
		}
		Lingolane_Switcher::register_block();
	}

	public function test_block_is_registered() {
		$registry = WP_Block_Type_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( 'lingolane/language-switcher' ) );
		$block = $registry->get_registered( 'lingolane/language-switcher' );
		$this->assertContains( 'lingolane-switcher-block', $block->editor_script_handles );
		$this->assertTrue( wp_script_is( 'lingolane-switcher-block', 'registered' ) );
	}

	public function test_render_block_maps_attributes_to_switcher_options() {
		$html = Lingolane_Switcher::render_block( array(
			'format' => 'flags', 'flagShape' => 'round', 'showCurrent' => false,
		) );
		$this->assertStringContainsString( '<svg class="lingolane-flag ', $html );
		$this->assertStringContainsString( 'lingolane-flag--round"', $html );
		$this->assertStringNotContainsString( '<span class="lingolane-name">', $html );
		$this->assertDoesNotMatchRegularExpression( '/<a\b[^>]*aria-current="true"/', $html );
	}

	public function test_render_block_returns_html() {
		$html = Lingolane_Switcher::render_block( array( 'format' => 'flags' ) );
		$this->assertIsString( $html );
		$this->assertStringContainsString( 'lingolane-switcher', $html );
	}
}
