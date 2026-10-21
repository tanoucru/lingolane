<?php
/**
 * Translation URLs (Lingolane_Urls), the language switcher and hreflang output (Lingolane_SEO).
 */
class Test_Lingolane_Urls extends Lingolane_TestCase {

	public function tear_down() {
		unset( $GLOBALS['wp_query'] );
		parent::tear_down();
	}

	public function test_translation_url_for_linked_and_missing_translations() {
		$vi      = $this->new_post();
		$en      = $this->new_post();
		$missing = $this->new_post();
		$trid    = Lingolane_Elements::set( 'post_post', $vi, 'vi' );
		$this->assertNotWPError( $trid );
		$this->assertNotWPError( Lingolane_Elements::set( 'post_post', $en, 'en', $trid ) );

		$this->set_singular_context( $vi );
		$this->assertSame( Lingolane_Urls::for_language( get_permalink( $en ), 'en' ), lingolane_get_translation_url( 'en' ), 'translated post' );
		$this->assertSame( 'vi', Lingolane_Languages::current(), 'language restored' );

		Lingolane_Languages::set_current( 'en' );
		$this->set_singular_context( $en );
		$this->assertSame( Lingolane_Urls::for_language( get_permalink( $vi ), 'vi' ), lingolane_get_translation_url( 'vi' ), 'reverse translation removes en prefix' );

		Lingolane_Languages::set_current( 'vi' );
		$this->set_singular_context( $missing );
		$missing_url = add_query_arg( 'lingolane_untranslated', 'en', get_permalink( $missing ) );
		$this->assertSame( $missing_url, lingolane_get_translation_url( 'en' ), 'missing translation source fallback' );

		add_filter(
			'lingolane_missing_translation_url',
			function ( $url, $lang ) {
				return $url . '?missing=' . $lang;
			},
			10,
			2
		);
		$this->assertSame( $missing_url . '?missing=en', lingolane_get_translation_url( 'en' ), 'fallback filter' );
	}

	public function test_draft_translations_are_not_public() {
		$vi   = $this->new_post();
		$en   = $this->new_post();
		$trid = Lingolane_Elements::set( 'post_post', $vi, 'vi' );
		Lingolane_Elements::set( 'post_post', $en, 'en', $trid );

		$this->set_singular_context( $vi );
		wp_update_post( array( 'ID' => $en, 'post_status' => 'draft' ) );

		$this->assertFalse( Lingolane_Urls::published_url( 'en' ), 'draft not public' );
		$this->assertFalse( strpos( Lingolane_Switcher::shortcode(), 'hreflang="en"' ), 'draft absent from switcher' );

		ob_start();
		Lingolane_SEO::alternates();
		$head = ob_get_clean();
		$this->assertFalse( strpos( $head, 'hreflang="en"' ), 'draft absent from hreflang' );
		$this->assertNotFalse( strpos( Lingolane_Switcher::shortcode(), 'aria-current="true"' ), 'current switcher accessible' );
	}

	public function test_home_urls_and_canonical() {
		$GLOBALS['wp_query']          = new WP_Query();
		$GLOBALS['wp_query']->is_home = true;
		add_filter(
			'pre_option_show_on_front',
			function () {
				return 'posts';
			}
		);

		$this->assertSame( Lingolane_Urls::home( 'en' ), lingolane_get_translation_url( 'en' ), 'home en' );
		Lingolane_Languages::set_current( 'en' );
		$this->assertSame( Lingolane_Urls::home( 'vi' ), lingolane_get_translation_url( 'vi' ), 'home vi' );
		$this->assertSame( Lingolane_Urls::home( 'en' ), Lingolane_SEO::canonical( Lingolane_Urls::home( 'vi' ) ), 'canonical prefix retained' );
	}
}
