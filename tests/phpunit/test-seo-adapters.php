<?php
/**
 * Tests for Lingolane_SEO Adapters (M3):
 * - Yoast SEO, Rank Math, AIOSEO, SEOPress canonical filters
 * - WordPress Core XML Sitemap URL language formatting
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Seo_Adapters extends Lingolane_TestCase {

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

	public function test_canonical_returns_correct_language_url() {
		Lingolane_Languages::set_current( 'vi' );
		$url = home_url( '/hello-world/' );
		$canonical = Lingolane_SEO::canonical( $url );
		$this->assertStringContainsString( '/vi/hello-world/', $canonical );
	}

	public function test_core_sitemaps_posts_entry_formats_url_by_post_language() {
		$post_id = $this->factory->post->create(
			array(
				'post_title' => 'Vietnamese Post',
				'post_status' => 'publish',
			)
		);
		Lingolane_Elements::set( 'post_post', $post_id, 'vi' );

		$post = get_post( $post_id );
		$entry = array(
			'loc' => get_permalink( $post ),
		);

		$result = Lingolane_SEO::filter_core_sitemap_post_entry( $entry, $post, 'post' );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( '/vi/', $result['loc'] );
	}

	public function test_rank_math_sitemap_entry_formats_url() {
		$post_id = $this->factory->post->create(
			array(
				'post_title' => 'English Post',
				'post_status' => 'publish',
			)
		);
		Lingolane_Elements::set( 'post_post', $post_id, 'en' );

		$url_entry = array(
			'loc' => 'https://example.com/english-post/',
		);

		$result = Lingolane_SEO::rank_math_sitemap_entry( $url_entry, 'post', get_post( $post_id ) );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['loc'] );
	}
}
