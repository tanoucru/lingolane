<?php
/**
 * Tests for Lingolane_Elements Batch Cache Preloading (M3):
 * - Prime elements cache in bulk
 * - Verify the_posts filter warms the cache
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_Batch_Cache extends Lingolane_TestCase {

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

	public function test_prime_cache_loads_multiple_elements() {
		$id1 = $this->factory->post->create();
		$id2 = $this->factory->post->create();

		Lingolane_Elements::set( 'post_post', $id1, 'vi' );
		Lingolane_Elements::set( 'post_post', $id2, 'en' );

		// Flush object cache for testing prime_cache
		wp_cache_flush();

		Lingolane_Elements::prime_cache( 'post_post', array( $id1, $id2 ) );

		// Next calls to get_language should hit cache
		$this->assertSame( 'vi', Lingolane_Elements::get_language( 'post_post', $id1 ) );
		$this->assertSame( 'en', Lingolane_Elements::get_language( 'post_post', $id2 ) );
	}

	public function test_the_posts_filter_primes_cache() {
		$id = $this->factory->post->create( array( 'post_title' => 'Sample' ) );
		Lingolane_Elements::set( 'post_post', $id, 'vi' );

		wp_cache_flush();

		$posts = array( get_post( $id ) );
		$filtered = Lingolane_Elements::on_the_posts( $posts );

		$this->assertSame( $posts, $filtered );
		$this->assertSame( 'vi', Lingolane_Elements::get_language( 'post_post', $id ) );
	}
}
