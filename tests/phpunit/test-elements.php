<?php
/**
 * Translation groups (Lingolane_Elements) and the language filter applied to post and term queries (Lingolane_Query).
 */
class Test_Lingolane_Elements extends Lingolane_TestCase {

	private function posts_for( array $ids, $lang, $suppressed = false ) {
		Lingolane_Languages::set_current( $lang );
		return new WP_Query(
			array(
				'post_type'        => 'post',
				'post__in'         => $ids,
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => $suppressed,
			)
		);
	}

	public function test_post_groups_and_language_filtering() {
		$vi  = $this->new_post();
		$en  = $this->new_post();
		$ids = array( $vi, $en );

		$this->assertSame( 'vi', Lingolane_Elements::get_language( 'post_post', $vi ), 'unregistered defaults to vi' );

		$trid = Lingolane_Elements::set( 'post_post', $en, 'en' );
		$this->assertNotWPError( $trid );

		$q = $this->posts_for( $ids, 'vi' );
		$this->assertSame( array( $vi ), array_map( 'intval', $q->posts ), 'vi includes unregistered only' );
		$q = $this->posts_for( $ids, 'en' );
		$this->assertSame( array( $en ), array_map( 'intval', $q->posts ), 'en excludes unregistered' );
		$this->assertSame( 1, (int) $q->found_posts, 'en found_posts' );

		$result = Lingolane_Elements::set( 'post_post', $vi, 'vi', $trid );
		$this->assertNotWPError( $result );

		$group = Lingolane_Elements::get_group( $trid );
		ksort( $group );
		$this->assertSame( array( 'en' => $en, 'vi' => $vi ), $group, 'get_group' );
		$this->assertSame( $en, Lingolane_Elements::get_translation( 'post_post', $vi, 'en' ), 'get_translation' );
		$this->assertWPError( Lingolane_Elements::set( 'post_post', $vi, 'en', $trid ), 'duplicate language rejected' );

		$q = $this->posts_for( $ids, 'en' );
		$this->assertSame( array( $en ), array_map( 'intval', $q->posts ), 'en registered query' );
		$q = $this->posts_for( $ids, 'en', true );
		$this->assertSame( 2, (int) $q->found_posts, 'suppress_filters bypass and pagination' );
	}

	public function test_term_filtering_and_cleanup() {
		$terms = array(
			self::factory()->term->create( array( 'taxonomy' => 'category' ) ),
			self::factory()->term->create( array( 'taxonomy' => 'category' ) ),
		);
		$trid = Lingolane_Elements::set( 'tax_category', $terms[1], 'en' );
		$this->assertNotWPError( $trid );

		foreach ( array( 'en' => $terms[1], 'vi' => $terms[0] ) as $code => $expected ) {
			Lingolane_Languages::set_current( $code );
			$found = get_terms(
				array(
					'taxonomy'   => 'category',
					'include'    => $terms,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			$this->assertNotWPError( $found );
			$this->assertSame( array( $expected ), array_map( 'intval', $found ), 'term filter ' . $code );
		}

		wp_delete_term( $terms[1], 'category' );
		$this->assertNull( Lingolane_Elements::get_trid( 'tax_category', $terms[1] ), 'term deletion cleanup' );
	}

	public function test_post_deletion_removes_it_from_its_group() {
		$vi  = $this->new_post();
		$en  = $this->new_post();
		$trid = Lingolane_Elements::set( 'post_post', $en, 'en' );
		$this->assertNotWPError( Lingolane_Elements::set( 'post_post', $vi, 'vi', $trid ) );

		wp_delete_post( $en, true );
		$this->assertSame( array( 'vi' => $vi ), Lingolane_Elements::get_group( $trid ), 'post deletion cleanup' );
	}
}
