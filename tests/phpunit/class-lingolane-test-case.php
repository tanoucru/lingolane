<?php
/**
 * Base test case: fixed language settings, pretty permalinks and clean plugin state around every test.
 *
 * WP_UnitTestCase wraps each test in a database transaction and restores hooks, so tests only need to
 * restore what lives outside the database (request URI, current language, string config).
 */
abstract class Lingolane_TestCase extends WP_UnitTestCase {

	/** @var string|null */
	protected $saved_uri;

	/** Language settings used by a test. Default language vi, no separate source language. */
	protected function settings_array() {
		return array(
			'default'   => 'vi',
			'languages' => array(
				array( 'code' => 'vi', 'locale' => 'vi', 'name' => 'Tiếng Việt' ),
				array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ),
			),
			'url_mode'  => 'directory',
		);
	}

	public function filter_settings() {
		return $this->settings_array();
	}

	public function set_up() {
		parent::set_up();
		$this->saved_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		add_filter( 'pre_option_lingolane_settings', array( $this, 'filter_settings' ) );
		$this->set_permalink_structure( '/%postname%/' );
		Lingolane_Strings::reset_config();
		Lingolane_Languages::set_current( Lingolane_Languages::default_code() );
	}

	public function tear_down() {
		if ( null === $this->saved_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->saved_uri;
		}
		Lingolane_Router::route();
		Lingolane_Strings::reset_config();
		Lingolane_Strings::flush_cache();
		Lingolane_Languages::set_current( Lingolane_Languages::default_code() );
		parent::tear_down();
	}

	/**
	 * Register the post types and taxonomies a typical directory site shares across languages,
	 * and declare them through the public filters (the plugin ships with empty lists).
	 */
	protected function register_shared_fixtures() {
		foreach ( array( 'listing', 'science', 'language' ) as $type ) {
			if ( ! post_type_exists( $type ) ) {
				register_post_type( $type, array( 'public' => true, 'label' => $type ) );
			}
		}
		foreach ( array( 'listing_categories', 'locations' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, array( 'listing', 'science', 'language' ), array( 'public' => true, 'label' => $taxonomy ) );
			}
		}
		add_filter( 'lingolane_shared_post_types', array( $this, 'fixture_types' ) );
		add_filter( 'lingolane_gated_post_types', array( $this, 'fixture_types' ) );
		add_filter( 'lingolane_shared_taxonomies', array( $this, 'fixture_taxonomies' ) );
		add_filter( 'lingolane_translatable_post_types', array( $this, 'merge_fixture_types' ) );
		add_filter( 'lingolane_translatable_taxonomies', array( $this, 'merge_fixture_taxonomies' ) );
	}

	public function fixture_types() {
		return array( 'listing', 'science', 'language' );
	}

	public function fixture_taxonomies() {
		return array( 'listing_categories', 'locations' );
	}

	public function merge_fixture_types( $types ) {
		return array_values( array_unique( array_merge( $types, $this->fixture_types() ) ) );
	}

	public function merge_fixture_taxonomies( $taxonomies ) {
		return array_values( array_unique( array_merge( $taxonomies, $this->fixture_taxonomies() ) ) );
	}

	/** Create a published post and return its ID. */
	protected function new_post( array $args = array() ) {
		return self::factory()->post->create( array_merge( array( 'post_status' => 'publish' ), $args ) );
	}

	/** Make WP_Query behave like a singular front-end request for $post_id. */
	protected function set_singular_context( $post_id ) {
		$query = new WP_Query();
		$query->is_singular       = true;
		$query->is_single         = true;
		$query->queried_object    = get_post( $post_id );
		$query->queried_object_id = $post_id;
		$GLOBALS['wp_query']      = $query;
	}
}
