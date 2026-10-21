<?php
/**
 * Interface string translation (Lingolane_Strings): gettext filters, context, plurals, domain isolation
 * and the one-SELECT-per-language loading rule.
 */
class Test_Lingolane_Strings extends Lingolane_TestCase {

	/** @var int Number of string-catalog SELECTs seen while the counter filter is active. */
	private $reads = 0;

	/** @var string */
	private $domain = 'lingolane-test';

	/** @var string */
	private $source = 'Lingolane greeting';

	public function count_reads( $sql ) {
		global $wpdb;
		if ( 0 === strpos( $sql, 'SELECT domain, source_hash, translation FROM ' . $wpdb->prefix . 'lingolane_strings' ) ) {
			++$this->reads;
		}
		return $sql;
	}

	private function write( $source, $context, $translation ) {
		$result = Lingolane_Strings::save( $this->domain, $source, $context, 'en', $translation );
		$this->assertNotWPError( $result );
	}

	public function test_translations_contexts_plurals_and_query_budget() {
		$this->assertNotWPError( lingolane_register_string( $this->domain, $this->source ) );
		$this->write( $this->source, '', 'Hello teacher' );
		$this->write( $this->source, 'button', 'Contact teacher' );
		$this->write( 'One lesson', '', 'A lesson' );
		$this->write( 'Many lessons', '', 'Several lessons' );
		lingolane_register_string( $this->domain, $this->source );

		Lingolane_Languages::set_current( 'en' );
		Lingolane_Strings::invalidate( 'en' );
		add_filter( 'query', array( $this, 'count_reads' ) );

		// phpcs:disable WordPress.WP.I18n -- the strings and domain are dynamic on purpose.
		$this->assertSame( 'Hello teacher', __( $this->source, $this->domain ), '__ translated and registration preserves translation' );
		$this->assertSame( 'Hello teacher', lingolane__( $this->source, $this->domain ), 'helper translated' );
		$this->assertSame( 'Contact teacher', _x( $this->source, 'button', $this->domain ), 'context translation' );
		$this->assertSame( $this->source, _x( $this->source, 'other', $this->domain ), 'missing context fallback' );
		$this->assertSame( 'A lesson', _n( 'One lesson', 'Many lessons', 1, $this->domain ), 'singular' );
		$this->assertSame( 'Several lessons', _n( 'One lesson', 'Many lessons', 2, $this->domain ), 'plural' );
		$this->assertSame( 'Missing', __( 'Missing', $this->domain ), 'missing source' );
		$this->assertSame( $this->source, __( $this->source, $this->domain . '-other' ), 'domain isolation' );
		$this->assertSame( 1, $this->reads, 'one language SELECT for repeated gettext' );

		Lingolane_Languages::set_current( 'vi' );
		$this->assertSame( $this->source, __( $this->source, $this->domain ), 'default unchanged' );
		$this->assertSame( 1, $this->reads, 'default issues no string SELECT' );
		// phpcs:enable
	}
}
