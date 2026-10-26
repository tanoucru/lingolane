<?php
/**
 * Tests for Lingolane_WPML_Config and Meta Copy filters (M3 C1, C5):
 * - Parse XML config for custom fields and post types
 * - Filter lingolane_copy_post_metas
 * - Thumbnail copy
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Test_WPML_Config extends Lingolane_TestCase {

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
		require_once LINGOLANE_DIR . 'includes/class-lingolane-wpml-config.php';
	}

	public function test_parse_xml_string() {
		$xml = '<wpml-config>
			<custom-fields>
				<custom-field action="copy">custom_copy_field</custom-field>
				<custom-field action="ignore">custom_ignore_field</custom-field>
			</custom-fields>
			<custom-types>
				<custom-type translate="1">portfolio</custom-type>
			</custom-types>
		</wpml-config>';

		$config = Lingolane_WPML_Config::parse_xml( $xml );
		$this->assertContains( 'custom_copy_field', $config['copy_fields'] );
		$this->assertContains( 'custom_ignore_field', $config['ignore_fields'] );
		$this->assertContains( 'portfolio', $config['custom_types'] );
	}

	public function test_duplicate_post_copies_meta_and_thumbnail() {
		$id = $this->factory->post->create( array( 'post_title' => 'Original' ) );
		update_post_meta( $id, 'test_meta_key', 'test_meta_value' );
		update_post_meta( $id, '_thumbnail_id', 999 );

		Lingolane_Elements::set( 'post_post', $id, 'en' );

		$new_id = Lingolane_Elements::duplicate_post( $id, 'vi' );
		$this->assertIsInt( $new_id );

		$copied_val = get_post_meta( $new_id, 'test_meta_key', true );
		$this->assertSame( 'test_meta_value', $copied_val );

		$copied_thumb = get_post_meta( $new_id, '_thumbnail_id', true );
		$this->assertEquals( 999, $copied_thumb );
	}
}
