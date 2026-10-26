<?php
/**
 * Tests for Lingolane_Router Compatibility (M3):
 * - Detection of conflicting plugins (Polylang/WPML)
 * - Plain permalink detection and fallback query handling
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Router_Compat extends Lingolane_TestCase {

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
	}

	public function test_conflicted_status_returns_false_when_no_competitor() {
		$this->assertFalse( Lingolane_Router::is_conflicted() );
	}

	public function test_plain_permalink_notice_detected() {
		update_option( 'permalink_structure', '' );
		$this->assertTrue( Lingolane_Router::is_plain_permalink() );

		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertFalse( Lingolane_Router::is_plain_permalink() );
	}
}
