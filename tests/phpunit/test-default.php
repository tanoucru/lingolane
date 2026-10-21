<?php
/**
 * The default (unprefixed) language differs from the source language of the content:
 * English is the default and has no prefix, while existing content and interface strings are Vietnamese.
 */
class Test_Lingolane_Default_Language extends Lingolane_TestCase {

	protected function settings_array() {
		$settings            = parent::settings_array();
		$settings['default'] = 'en';
		$settings['source']  = 'vi';
		return $settings;
	}

	private function base() {
		return rtrim( (string) wp_parse_url( get_option( 'home' ), PHP_URL_PATH ), '/' );
	}

	public function test_default_and_source_languages_differ() {
		$this->assertSame( 'en', Lingolane_Languages::default_code() );
		$this->assertSame( 'vi', Lingolane_Languages::source_code() );
	}

	public function provide_routes() {
		return array(
			array( '/vi/about/', 'vi', '' ),
			array( '/about/', 'en', '' ),
			array( '/en/about/', 'en', '/about/' ),
		);
	}

	/**
	 * @dataProvider provide_routes
	 */
	public function test_routing_with_an_unprefixed_default_language( $uri, $language, $redirect ) {
		$_SERVER['REQUEST_URI'] = $this->base() . $uri;
		Lingolane_Router::route();
		$this->assertSame( $language, Lingolane_Languages::current(), $uri . ' language' );
		$this->assertSame( '' === $redirect ? '' : $this->base() . $redirect, Lingolane_Router::prefix_redirect(), $uri . ' prefix redirect' );
	}

	public function test_home_urls_follow_the_default_language() {
		$home = rtrim( get_option( 'home' ), '/' );
		Lingolane_Languages::set_current( 'vi' );
		$this->assertSame( $home . '/vi/x/', home_url( '/x/' ), 'Vietnamese URLs get /vi/' );
		Lingolane_Languages::set_current( 'en' );
		$this->assertSame( $home . '/x/', home_url( '/x/' ), 'English URLs unprefixed' );
	}

	public function test_unassigned_content_belongs_to_the_source_language() {
		$home  = rtrim( get_option( 'home' ), '/' );
		$plain = $this->new_post();

		$this->assertSame( 'vi', Lingolane_Elements::get_language( 'post_post', $plain ), 'unassigned content is source language' );

		Lingolane_Languages::set_current( 'vi' );
		$vi = new WP_Query( array( 'post_type' => 'post', 'p' => $plain, 'fields' => 'ids' ) );
		$this->assertSame( array( $plain ), array_map( 'intval', $vi->posts ), 'unassigned content listed in Vietnamese' );

		Lingolane_Languages::set_current( 'en' );
		$en = new WP_Query( array( 'post_type' => 'post', 'p' => $plain, 'fields' => 'ids' ) );
		$this->assertSame( array(), $en->posts, 'unassigned content not listed in English' );
		$this->assertSame( 0, strpos( get_permalink( $plain ), $home . '/vi/' ), 'Vietnamese permalink prefixed' );
	}

	public function test_strings_are_translated_on_the_default_language_only() {
		$domain = 'lingolane-default-test';
		Lingolane_Strings::save( $domain, 'Xin chào', '', 'en', 'Hello' );
		Lingolane_Strings::invalidate( 'en' );

		Lingolane_Languages::set_current( 'en' );
		$this->assertSame( 'Hello', __( 'Xin chào', $domain ), 'strings translated on default English' ); // phpcs:ignore WordPress.WP.I18n
		Lingolane_Languages::set_current( 'vi' );
		$this->assertSame( 'Xin chào', __( 'Xin chào', $domain ), 'source strings untouched in Vietnamese' ); // phpcs:ignore WordPress.WP.I18n
	}
}
