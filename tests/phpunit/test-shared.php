<?php
/**
 * Records shared across languages (Lingolane_Shared) and internal link rewriting (Lingolane_Links):
 * sharing must never expose a registered record of another language.
 */
class Test_Lingolane_Shared extends Lingolane_TestCase {

	public function set_up() {
		parent::set_up();
		$this->register_shared_fixtures();
	}

	public function test_registered_records_are_isolated_and_unregistered_ones_shared() {
		Lingolane_Languages::set_current( 'vi' );
		$shared     = $this->new_post( array( 'post_type' => 'science' ) );
		$registered = $this->new_post( array( 'post_type' => 'science' ) );
		$draft      = $this->new_post( array( 'post_type' => 'science', 'post_status' => 'draft' ) );
		Lingolane_Elements::set( 'post_science', $registered, 'vi' );

		$shared_term     = wp_insert_term( 'Shared subject', 'listing_categories' );
		$registered_term = wp_insert_term( 'Registered subject', 'listing_categories' );
		$this->assertNotWPError( $shared_term );
		$this->assertNotWPError( $registered_term );
		Lingolane_Elements::set( 'tax_listing_categories', $registered_term['term_id'], 'vi' );

		Lingolane_Languages::set_current( 'en' );
		$q = new WP_Query(
			array(
				'post_type'   => 'science',
				'post__in'    => array( $shared, $registered, $draft ),
				'post_status' => 'publish',
				'fields'      => 'ids',
			)
		);
		$this->assertContains( $shared, $q->posts, 'unregistered record visible in English' );
		$this->assertNotContains( $registered, $q->posts, 'registered Vietnamese record isolated' );
		$this->assertNotContains( $draft, $q->posts, 'draft record stays private' );

		$found = array_map(
			'intval',
			get_terms(
				array(
					'taxonomy'   => 'listing_categories',
					'hide_empty' => false,
					'include'    => array( $shared_term['term_id'], $registered_term['term_id'] ),
					'fields'     => 'ids',
				)
			)
		);
		$this->assertContains( (int) $shared_term['term_id'], $found, 'unregistered subject shared' );
		$this->assertNotContains( (int) $registered_term['term_id'], $found, 'registered subject isolated' );
	}

	public function test_internal_links_are_rewritten_only_when_needed() {
		Lingolane_Languages::set_current( 'en' );
		$home = get_option( 'home' );

		$this->assertSame( $home . '/en/create/?id=5#form', Lingolane_Links::url( $home . '/create/?id=5#form' ), 'shared page URL preserves query and fragment' );
		$this->assertSame( 'https://example.com/contact/', Lingolane_Links::url( 'https://example.com/contact/' ), 'external link unchanged' );
		$this->assertSame( $home . '/wp-content/test.png', Lingolane_Links::url( $home . '/wp-content/test.png' ), 'assets unchanged' );
	}

	public function test_saved_link_uses_the_actual_translation_permalink() {
		Lingolane_Languages::set_current( 'vi' );
		$contact = $this->new_post( array( 'post_type' => 'page', 'post_title' => 'Contact', 'post_name' => 'contact' ) );
		Lingolane_Elements::set( 'post_page', $contact, 'vi' );
		$copy = Lingolane_Elements::duplicate_post( $contact, 'en', 'publish' );
		$this->assertNotWPError( $copy );

		Lingolane_Languages::set_current( 'en' );
		$translation = Lingolane_Elements::get_translation( 'post_page', $contact, 'en' );
		$this->assertSame(
			Lingolane_Urls::for_language( get_permalink( $translation ), 'en' ),
			Lingolane_Links::url( get_option( 'home' ) . '/contact/' ),
			'saved contact link uses actual English translation'
		);
	}
}
