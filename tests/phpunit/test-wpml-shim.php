<?php
/**
 * Tests for Lingolane_WPML_Shim (M3 C2):
 * - wpml_object_id filter
 * - wpml_current_language filter
 * - wpml_default_language filter
 * - icl_object_id global function
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_WPML_Shim extends Lingolane_TestCase {

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
		require_once LINGOLANE_DIR . 'includes/class-lingolane-wpml-shim.php';
		Lingolane_WPML_Shim::init();
	}

	public function test_wpml_current_language_filter() {
		Lingolane_Languages::set_current( 'vi' );
		$lang = apply_filters( 'wpml_current_language', null );
		$this->assertSame( 'vi', $lang );
	}

	public function test_wpml_default_language_filter() {
		$default = apply_filters( 'wpml_default_language', null );
		$this->assertSame( 'en', $default );
	}

	public function test_wpml_object_id_filter() {
		$en_id = $this->factory->post->create();
		$vi_id = $this->factory->post->create();

		$trid = Lingolane_Elements::set( 'post_post', $en_id, 'en' );
		Lingolane_Elements::set( 'post_post', $vi_id, 'vi', $trid );

		// Translate en_id to 'vi'
		$translated = apply_filters( 'wpml_object_id', $en_id, 'post', true, 'vi' );
		$this->assertSame( $vi_id, $translated );

		// When requesting missing translation with return_original = true
		$missing = apply_filters( 'wpml_object_id', $en_id, 'post', true, 'fr' );
		$this->assertSame( $en_id, $missing );

		// Global helper icl_object_id
		if ( function_exists( 'icl_object_id' ) ) {
			$this->assertSame( $vi_id, icl_object_id( $en_id, 'post', true, 'vi' ) );
		}
	}
}
