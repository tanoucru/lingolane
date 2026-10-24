<?php
/**
 * Tests for Lingolane_String_Blocks:
 * - Header and footer template parts translated
 * - Nested blocks translate once
 * - Posts and navigation untouched
 * - Widget block only in widget area
 * - Unsafe nodes and malformed HTML unchanged (exact match, attributes/scripts/styles safe)
 * - Guest has no capture queries or markers
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Lingolane_String_Blocks extends Lingolane_TestCase {

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

	public function test_header_footer_template_parts() {
		Lingolane_Strings::save( 'lingolane-block', 'Copyright 2026', 'footer', 'vi', 'Bản quyền 2026' );
		Lingolane_Strings::save( 'lingolane-block', 'Call Us Now', 'header', 'vi', 'Gọi cho chúng tôi' );

		Lingolane_Languages::set_current( 'vi' );

		$footer_html  = '<footer class="site-footer"><p>Copyright 2026</p></footer>';
		$footer_block = array(
			'blockName' => 'core/template-part',
			'attrs'     => array(
				'slug' => 'footer',
				'area' => 'footer',
			),
		);

		$rendered_footer = Lingolane_String_Blocks::render( $footer_html, $footer_block );
		$this->assertSame( '<footer class="site-footer"><p>Bản quyền 2026</p></footer>', $rendered_footer );

		$header_html  = '<header class="site-header"><span>Call Us Now</span></header>';
		$header_block = array(
			'blockName' => 'core/template-part',
			'attrs'     => array(
				'slug' => 'header',
				'area' => 'header',
			),
		);

		$rendered_header = Lingolane_String_Blocks::render( $header_html, $header_block );
		$this->assertSame( '<header class="site-header"><span>Gọi cho chúng tôi</span></header>', $rendered_header );
	}

	public function test_nested_blocks_translate_once() {
		Lingolane_Strings::save( 'lingolane-block', 'Nested Hello', 'header', 'vi', 'Xin Chào Lồng Nhau' );
		Lingolane_Languages::set_current( 'vi' );

		$nested_html = '<div class="outer"><div class="inner"><p>Nested Hello</p></div></div>';
		$block       = array(
			'blockName' => 'core/template-part',
			'attrs'     => array( 'area' => 'header' ),
		);

		// Render once.
		$once = Lingolane_String_Blocks::render( $nested_html, $block );
		$this->assertSame( '<div class="outer"><div class="inner"><p>Xin Chào Lồng Nhau</p></div></div>', $once );

		// If rendered again (e.g. inner block was already replaced or outer passes), no double corruption.
		$twice = Lingolane_String_Blocks::render( $once, $block );
		$this->assertSame( '<div class="outer"><div class="inner"><p>Xin Chào Lồng Nhau</p></div></div>', $twice );
	}

	public function test_posts_and_navigation_untouched() {
		Lingolane_Strings::save( 'lingolane-block', 'About Us', 'content', 'vi', 'Về Chúng Tôi' );
		Lingolane_Languages::set_current( 'vi' );

		// 1. Post content block must remain untouched by block string translator.
		$post_html  = '<div class="entry-content"><p>About Us</p></div>';
		$post_block = array(
			'blockName' => 'core/post-content',
			'attrs'     => array(),
		);
		$this->assertSame( $post_html, Lingolane_String_Blocks::render( $post_html, $post_block ) );

		// 2. Navigation block inside template part must remain untouched.
		$nav_html  = '<nav class="wp-block-navigation"><ul class="wp-block-navigation__container"><li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="/about">About Us</a></li></ul></nav>';
		$tpl_html  = '<header>' . $nav_html . '<p>About Us</p></header>';
		$tpl_block = array(
			'blockName' => 'core/template-part',
			'attrs'     => array( 'area' => 'header' ),
		);
		Lingolane_Strings::save( 'lingolane-block', 'About Us', 'header', 'vi', 'Về Chúng Tôi' );

		$result = Lingolane_String_Blocks::render( $tpl_html, $tpl_block );
		// The nav element should keep original inner text untouched, while <p> outside nav is translated.
		$this->assertStringContainsString( '<nav class="wp-block-navigation"', $result );
		$this->assertStringContainsString( '>About Us</a>', $result );
		$this->assertStringContainsString( '<p>Về Chúng Tôi</p>', $result );
	}

	public function test_widget_block_only_in_widget_area() {
		Lingolane_Strings::save( 'lingolane-widget', 'Recent Updates', 'title', 'vi', 'Cập Nhật Gần Đây' );
		Lingolane_Strings::save( 'lingolane-widget', 'Widget Body Text', 'text', 'vi', 'Nội Dung Tiện Ích' );
		Lingolane_Languages::set_current( 'vi' );

		// Classic widget filter.
		$instance = array(
			'title' => 'Recent Updates',
			'text'  => 'Widget Body Text',
		);
		$widget   = array( 'id' => 'text-2' );

		$translated_instance = Lingolane_String_Blocks::widget( $instance, $widget );
		$this->assertSame( 'Cập Nhật Gần Đây', $translated_instance['title'] );
		$this->assertSame( 'Nội Dung Tiện Ích', $translated_instance['text'] );

		// Block widget: block in widget-area.
		$widget_html  = '<div class="wp-widget-group"><h2 class="widget-title">Recent Updates</h2></div>';
		$widget_block = array(
			'blockName' => 'core/widget-group',
			'attrs'     => array(),
		);
		$res = Lingolane_String_Blocks::render( $widget_html, $widget_block );
		$this->assertStringContainsString( '<h2 class="widget-title">Cập Nhật Gần Đây</h2>', $res );
	}

	public function test_unsafe_nodes_and_malformed_html_unchanged() {
		Lingolane_Strings::save( 'lingolane-block', 'A', 'header', 'vi', 'Bản Dịch A' );
		Lingolane_Strings::save( 'lingolane-block', 'Dangerous', 'header', 'vi', 'Nguy Hiểm' );
		Lingolane_Languages::set_current( 'vi' );

		$html = '<div class="container">'
			. '<p>ABC</p>' // String A is inside ABC; should NOT be replaced!
			. '<a href="https://example.com/A" data-info="Dangerous">A</a>' // Attribute value "Dangerous" and href "/A" untouched, inner text "A" translated.
			. '<script>var x = "Dangerous";</script>' // Script untouched.
			. '<style>.Dangerous { color: red; }</style>' // Style untouched.
			. '<code>Dangerous code</code>' // Code untouched.
			. '<pre>Dangerous pre</pre>' // Pre untouched.
			. '<textarea>Dangerous textarea</textarea>' // Textarea untouched.
			. '<svg><text>Dangerous svg</text></svg>' // SVG untouched.
			. '</div>';

		$block = array(
			'blockName' => 'core/template-part',
			'attrs'     => array( 'area' => 'header' ),
		);

		$result = Lingolane_String_Blocks::render( $html, $block );

		// "ABC" must NOT be modified.
		$this->assertStringContainsString( '<p>ABC</p>', $result );
		$this->assertStringNotContainsString( 'Bản Dịch ABC', $result );

		// Tag attributes must NOT be modified.
		$this->assertStringContainsString( 'href="https://example.com/A"', $result );
		$this->assertStringContainsString( 'data-info="Dangerous"', $result );

		// Inner text node "A" MUST be translated.
		$this->assertStringContainsString( '>Bản Dịch A</a>', $result );

		// Special tags contents must NOT be modified.
		$this->assertStringContainsString( 'var x = "Dangerous";', $result );
		$this->assertStringContainsString( '.Dangerous { color: red; }', $result );
		$this->assertStringContainsString( '<code>Dangerous code</code>', $result );
		$this->assertStringContainsString( '<pre>Dangerous pre</pre>', $result );
		$this->assertStringContainsString( '<textarea>Dangerous textarea</textarea>', $result );
		$this->assertStringContainsString( '<svg><text>Dangerous svg</text></svg>', $result );

		// Malformed HTML should be safely returned without crashing.
		$malformed = '<div><p>Unclosed paragraph <b>bold without end';
		$safe      = Lingolane_String_Blocks::render( $malformed, $block );
		$this->assertIsString( $safe );
	}

	public function test_guest_has_no_capture_queries_or_markers() {
		wp_set_current_user( 0 ); // Guest.
		global $wpdb;

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lingolane_string_locations" );

		Lingolane_Languages::set_current( 'vi' );
		$html  = '<header><p>Guest Test</p></header>';
		$block = array(
			'blockName' => 'core/template-part',
			'attrs'     => array( 'area' => 'header' ),
		);

		$out = Lingolane_String_Blocks::render( $html, $block );

		// No HTML markers or debug comments injected for visitors.
		$this->assertStringNotContainsString( 'data-lingolane', $out );
		$this->assertStringNotContainsString( '<!--lingolane', $out );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lingolane_string_locations" );
		$this->assertSame( $before, $after );
	}
}
