<?php
/**
 * Tests for Lingolane_String_Sources:
 * - Site title and tagline keep original options in database, translated on frontend
 * - Menu labels and custom URLs rewritten with language prefix stripped first
 * - Language-specific menu takes precedence
 * - Media alt text, caption, and logo alt translation
 * - Unknown options / providers rejected
 * - Guests never register occurrences or modify DB
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Lingolane_String_Sources extends Lingolane_TestCase {

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
		update_option( 'blogname', 'Original Site Title' );
		update_option( 'blogdescription', 'Original Tagline' );
	}

	public function tear_down() {
		Lingolane_Languages::set_current( 'en' );
		parent::tear_down();
	}

	public function test_site_title_tagline_keep_original_options() {
		// Register Vietnamese translation for site title and tagline.
		Lingolane_Strings::save( 'lingolane-site', 'Original Site Title', 'blogname', 'vi', 'Tiêu Đề Trang Gốc' );
		Lingolane_Strings::save( 'lingolane-site', 'Original Tagline', 'blogdescription', 'vi', 'Khẩu Hiệu Gốc' );

		// In default language (en): original options returned.
		Lingolane_Languages::set_current( 'en' );
		$this->assertSame( 'Original Site Title', get_option( 'blogname' ) );
		$this->assertSame( 'Original Tagline', get_option( 'blogdescription' ) );

		// Switch to vi on frontend.
		Lingolane_Languages::set_current( 'vi' );
		$this->assertSame( 'Tiêu Đề Trang Gốc', get_option( 'blogname' ) );
		$this->assertSame( 'Khẩu Hiệu Gốc', get_option( 'blogdescription' ) );

		// In admin: always returns original option.
		set_current_screen( 'dashboard' );
		$this->assertSame( 'Original Site Title', get_option( 'blogname' ) );
		set_current_screen( 'front' );
	}

	public function test_menu_labels_and_custom_urls() {
		$menu_id = wp_create_nav_menu( 'Main Navigation' );

		$item_id = wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-title'  => 'About Us',
			'menu-item-url'    => home_url( '/about/' ),
			'menu-item-type'   => 'custom',
			'menu-item-status' => 'publish',
		) );

		// External and fragment items.
		$ext_id = wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-title'  => 'External',
			'menu-item-url'    => 'https://example.com/other',
			'menu-item-type'   => 'custom',
			'menu-item-status' => 'publish',
		) );

		$frag_id = wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-title'  => 'Section',
			'menu-item-url'    => '#section',
			'menu-item-type'   => 'custom',
			'menu-item-status' => 'publish',
		) );

		Lingolane_Strings::save( 'lingolane-menu', 'About Us', 'label', 'vi', 'Về Chúng Tôi' );

		Lingolane_Languages::set_current( 'vi' );
		$items = wp_get_nav_menu_items( $menu_id );
		$by_id = array();
		foreach ( $items as $it ) {
			$by_id[ (int) $it->ID ] = $it;
		}

		$this->assertSame( 'Về Chúng Tôi', $by_id[ $item_id ]->title );
		// Internal custom URL should have /vi/ prefix.
		$this->assertSame( home_url( '/vi/about/' ), $by_id[ $item_id ]->url );

		// External and hash URLs must not be modified.
		$this->assertSame( 'https://example.com/other', $by_id[ $ext_id ]->url );
		$this->assertSame( '#section', $by_id[ $frag_id ]->url );
	}

	public function test_language_specific_menu_wins() {
		$default_menu = wp_create_nav_menu( 'Header Menu' );
		$vi_menu      = wp_create_nav_menu( 'Header Menu vi' );

		$this->assertTrue( Lingolane_String_Sources::menu_is_language_specific( $vi_menu, 'vi' ) );
		$this->assertFalse( Lingolane_String_Sources::menu_is_language_specific( $default_menu, 'vi' ) );
	}

	public function test_logo_alt_and_caption() {
		$attach_id = $this->factory->attachment->create_upload_object(
			DIR_TESTDATA . '/images/test-image.jpg',
			0,
			array(
				'post_excerpt' => 'English Caption',
			)
		);
		wp_update_post( array(
			'ID'           => $attach_id,
			'post_excerpt' => 'English Caption',
		) );
		update_post_meta( $attach_id, '_wp_attachment_image_alt', 'English Logo Alt' );

		Lingolane_Strings::save( 'lingolane-media', 'English Logo Alt', 'alt', 'vi', 'Logo Tiếng Việt' );
		Lingolane_Strings::save( 'lingolane-media', 'English Caption', 'caption', 'vi', 'Chú Thích Tiếng Việt' );

		Lingolane_Languages::set_current( 'vi' );
		$alt = get_post_meta( $attach_id, '_wp_attachment_image_alt', true );
		$this->assertSame( 'Logo Tiếng Việt', $alt );

		$caption = wp_get_attachment_caption( $attach_id );
		$this->assertSame( 'Chú Thích Tiếng Việt', $caption );
	}

	public function test_unknown_option_rejected() {
		$res = Lingolane_String_Sources::resolve( 'site', 0, 'unknown_option_key' );
		$this->assertWPError( $res );

		$res_bad_provider = Lingolane_String_Sources::resolve( 'forbidden_provider', 0, 'field' );
		$this->assertWPError( $res_bad_provider );
	}

	public function test_enumerate_sources() {
		$site_enum = Lingolane_String_Sources::enumerate( 'site' );
		$this->assertIsArray( $site_enum['items'] );
		$this->assertNotEmpty( $site_enum['items'] );
		$this->assertSame( 'lingolane-site', $site_enum['items'][0]['identity']['domain'] );

		$bad_enum = Lingolane_String_Sources::enumerate( 'bad_provider' );
		$this->assertWPError( $bad_enum );
	}

	public function test_guest_never_registers_sources() {
		wp_set_current_user( 0 ); // Guest user.

		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lingolane_string_locations" );

		// Simulate frontend options access and menu query.
		Lingolane_Languages::set_current( 'vi' );
		get_option( 'blogname' );
		get_option( 'blogdescription' );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lingolane_string_locations" );
		$this->assertSame( $before, $after );
	}
}
