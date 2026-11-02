<?php
/**
 * Output escaping (Lingolane_Kses): the allow-lists must keep the plugin's own markup intact
 * while filtered or injected markup is stripped.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Lingolane_Kses extends Lingolane_TestCase {

	protected function settings_array() {
		return array(
			'default'   => 'en',
			'languages' => array(
				array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ),
				array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ),
				array( 'code' => 'hi', 'locale' => 'hi_IN', 'name' => 'हिन्दी' ),
			),
			'url_mode'  => 'directory',
		);
	}

	public function set_up() {
		parent::set_up();
		Lingolane_Install::activate();
		Lingolane_Languages::set_current( 'en' );
	}

	public function test_switcher_markup_survives_its_allow_list() {
		foreach ( array( 'list', 'dropdown', 'pills', 'select' ) as $style ) {
			foreach ( array( 'flag_name', 'flag_code', 'flag_only', 'name', 'code' ) as $show ) {
				$html = Lingolane_Switcher::render( array( 'style' => $style, 'show' => $show ) );
				$this->assertSame( $this->normalize( $html ), $this->normalize( wp_kses( $html, Lingolane_Kses::switcher() ) ), "$style / $show" );
			}
		}
	}

	public function test_switcher_has_no_inline_script() {
		$html = Lingolane_Switcher::render( array( 'style' => 'select' ) );
		$this->assertStringNotContainsString( 'onchange', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<style', $html );
	}

	public function test_flag_keeps_stroke_width() {
		// The Indian flag's wheel is a stroked circle; a too narrow allow-list used to drop stroke-width.
		$this->assertStringContainsString( 'stroke-width="6"', Lingolane_Flags::get_flag_svg( 'hi' ) );
	}

	public function test_filtered_flag_cannot_inject_markup() {
		$inject = function ( $svg ) {
			return str_replace( '<svg ', '<svg onload="alert(1)" ', $svg ) . '<script>alert(2)</script><img src=x onerror="alert(3)">';
		};
		add_filter( 'lingolane_flag_svg', $inject );
		$svg = Lingolane_Flags::get_flag_svg( 'vi' );
		remove_filter( 'lingolane_flag_svg', $inject );
		$this->assertStringStartsWith( '<svg ', $svg );
		$this->assertStringNotContainsString( 'onload', $svg );
		$this->assertStringNotContainsString( '<script', $svg );
		$this->assertStringNotContainsString( '<img', $svg );
	}

	public function test_illustrations_keep_their_colours() {
		foreach ( array( 'welcome', 'empty-languages', 'all-done' ) as $name ) {
			$raw = Lingolane_UI::illustration( $name );
			$this->assertSame( $this->normalize( $raw ), $this->normalize( wp_kses( $raw, Lingolane_Kses::illustration() ) ), $name );
		}
	}

	public function test_lingolane_translate_helper_does_not_fatal_on_a_plain_string_language() {
		Lingolane_Languages::set_current( 'vi' );
		$this->assertSame( 'Unregistered text', lingolane__( 'Unregistered text', 'lingolane-test' ) );
	}

	/** wp_kses() lower-cases attribute names and writes "/>" as " />"; compare the content only. */
	private function normalize( $html ) {
		return strtolower( preg_replace( '/\s*\/>/', '>', preg_replace( '/\s+/', ' ', trim( $html ) ) ) );
	}
}
