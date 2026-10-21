<?php
/**
 * URL routing (Lingolane_Router): language prefix detection, URI rewriting, prefix redirects,
 * home_url()/site_url() filtering, locale and canonical handling.
 */
class Test_Lingolane_Router extends Lingolane_TestCase {

	private function base() {
		return rtrim( (string) wp_parse_url( get_option( 'home' ), PHP_URL_PATH ), '/' );
	}

	public function provide_routes() {
		return array(
			array( '/en/about-us/', 'en', '/about-us/' ),
			array( '/about-us/', 'vi', '/about-us/' ),
			array( '/en', 'en', '/' ),
			array( '/wp-json/', 'vi', '/wp-json/' ),
			array( '/en/about-us/?a=1&b=2', 'en', '/about-us/?a=1&b=2' ),
			array( '/en/wp-admin/', 'vi', '/en/wp-admin/' ),
			array( '/en/wp-json/', 'vi', '/en/wp-json/' ),
			array( '/english/about/', 'vi', '/english/about/' ),
			array( '/wp-login.php', 'vi', '/wp-login.php' ),
		);
	}

	/**
	 * @dataProvider provide_routes
	 */
	public function test_route_sets_language_and_rewrites_the_uri( $uri, $language, $expected_uri ) {
		$_SERVER['REQUEST_URI'] = $this->base() . $uri;
		Lingolane_Router::route();
		$this->assertSame( $language, Lingolane_Languages::current(), $uri . ' language' );
		$this->assertSame( $this->base() . $expected_uri, $_SERVER['REQUEST_URI'], $uri . ' URI' );
	}

	public function provide_prefix_redirects() {
		return array(
			array( '/vi/about-us/?a=1', '/about-us/?a=1' ),
			array( '/vi', '/' ),
			array( '/en/en/about-us/', '/en/about-us/' ),
			array( '/en/vi/', '/en/' ),
			array( '/en/about-us/', '' ),
			array( '/about-us/', '' ),
			array( '/vi/wp-json/', '' ),
		);
	}

	/**
	 * @dataProvider provide_prefix_redirects
	 */
	public function test_prefix_redirect( $uri, $expected ) {
		$_SERVER['REQUEST_URI'] = $this->base() . $uri;
		Lingolane_Router::route();
		$this->assertSame( '' === $expected ? '' : $this->base() . $expected, Lingolane_Router::prefix_redirect(), $uri . ' prefix redirect' );
	}

	public function test_urls_are_prefixed_but_core_assets_are_not() {
		Lingolane_Languages::set_current( 'en' );
		$home = rtrim( get_option( 'home' ), '/' );
		$site = rtrim( get_option( 'siteurl' ), '/' );

		$this->assertSame( $home . '/en/x/', home_url( '/x/' ), 'home_url /x/' );
		$this->assertSame( $home . '/en/x/', home_url( '/en/x/' ), 'no double prefix' );
		$this->assertSame( $home . '/wp-json/', home_url( '/wp-json/' ), 'REST URL' );
		$this->assertSame( $site, site_url(), 'site_url root unprefixed' );
		$this->assertSame( $site . '/wp-includes/js/jquery/jquery.min.js', includes_url( 'js/jquery/jquery.min.js' ), 'core asset URL unprefixed' );
		$this->assertSame( $site, wp_styles()->base_url, 'core styles base unprefixed' );
		$this->assertSame( 'en_US', Lingolane_Router::filter_locale( 'vi' ), 'locale' );
	}

	public function test_canonical_adds_the_trailing_slash_without_looping() {
		Lingolane_Languages::set_current( 'en' );
		$home = rtrim( get_option( 'home' ), '/' );

		$_SERVER['REQUEST_URI'] = $this->base() . '/en?x=1';
		Lingolane_Router::route();
		$this->assertSame( $home . '/en/?x=1', Lingolane_Router::canonical( false, $home . '/en?x=1' ), '/en canonical' );

		$_SERVER['REQUEST_URI'] = $this->base() . '/en/';
		Lingolane_Router::route();
		$this->assertFalse( Lingolane_Router::canonical( false, $home . '/en/' ), 'no canonical loop' );
	}
}
