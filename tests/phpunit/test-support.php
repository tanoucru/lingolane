<?php
/**
 * Support tab: the system report, conflict detection, the ticket composer and the plugin row link.
 */
class Test_Lingolane_Support extends Lingolane_TestCase {

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		foreach ( array( 'ui', 'stats', 'admin', 'support' ) as $file ) {
			require_once LINGOLANE_DIR . 'admin/class-lingolane-' . $file . '.php';
		}
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		unset( $_GET['tab'] );
		parent::tear_down();
	}

	public function test_report_lists_versions_and_languages() {
		$report = Lingolane_Support::report();
		$this->assertStringContainsString( 'Lingolane: ' . LINGOLANE_VERSION, $report );
		$this->assertStringContainsString( 'WordPress: ' . get_bloginfo( 'version' ), $report );
		$this->assertStringContainsString( 'PHP: ' . PHP_VERSION, $report );
		$this->assertStringContainsString( 'vi (vi)', $report );
		$this->assertStringContainsString( 'en (en_US)', $report );
		$this->assertStringContainsString( 'URL mode: directory', $report );
	}

	public function test_report_leaves_out_site_address_and_emails() {
		update_option( 'admin_email', 'owner@private-site.test' );
		$report = Lingolane_Support::report();
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertStringNotContainsString( $host, $report );
		$this->assertStringNotContainsString( 'owner@private-site.test', $report );
	}

	/** Fakes an active third-party plugin whose header carries the given name. */
	private function fake_plugin( $name ) {
		wp_cache_set( 'plugins', array( '' => array( 'acme/acme.php' => array( 'Name' => $name, 'Version' => '1.0' ) ) ), 'plugins' );
		update_option( 'active_plugins', array( 'acme/acme.php' ) );
	}

	public function test_report_masks_emails_links_and_host_in_third_party_metadata() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->fake_plugin( 'Acme by owner@private-site.test https://private.example/x ' . $host );
		$report = Lingolane_Support::report();
		$this->assertStringContainsString( 'Acme by [email] [url] [site] 1.0', $report );
		$this->assertStringNotContainsString( 'private-site.test', $report );
		$this->assertStringNotContainsString( 'private.example', $report );
	}

	public function test_report_is_escaped_in_the_textarea() {
		$this->fake_plugin( '</textarea><script>alert(1)</script>' );
		ob_start();
		Lingolane_Support::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;/textarea&gt;&lt;script&gt;', $html );
	}

	public function test_support_tab_needs_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_GET['tab'] = 'support';
		ob_start();
		Lingolane_Admin::render();
		$this->assertStringNotContainsString( 'lingolane-report', ob_get_clean() );
	}

	public function test_network_active_plugins_count_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		update_option( 'active_plugins', array() );
		update_site_option( 'active_sitewide_plugins', array( 'sitepress-multilingual-cms/sitepress.php' => time() ) );
		$this->assertSame( array( array( 'WPML', 'multilingual' ) ), Lingolane_Support::conflicts() );
	}

	public function test_report_is_filterable() {
		add_filter( 'lingolane_support_report', function ( $text ) {
			return $text . "\nExtra: 1";
		} );
		$this->assertStringEndsWith( 'Extra: 1', Lingolane_Support::report() );
	}

	public function test_conflicts_detect_known_plugins_by_folder() {
		update_option( 'active_plugins', array() );
		$this->assertSame( array(), Lingolane_Support::conflicts() );

		update_option( 'active_plugins', array( 'polylang/polylang.php', 'wp-rocket/wp-rocket.php', 'akismet/akismet.php' ) );
		$this->assertSame(
			array( array( 'Polylang', 'multilingual' ), array( 'WP Rocket', 'cache' ) ),
			Lingolane_Support::conflicts()
		);
	}

	public function test_support_tab_is_registered_and_renders() {
		$this->assertArrayHasKey( 'support', Lingolane_Admin::tabs() );

		$_GET['tab'] = 'support';
		set_current_screen( 'toplevel_page_lingolane' );
		ob_start();
		Lingolane_Admin::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-lingolane-ticket', $html );
		$this->assertStringContainsString( 'id="lingolane-report"', $html );
		$this->assertStringContainsString( esc_url( Lingolane_Support::FORUM ), $html );
		$this->assertStringNotContainsString( '<form method="post"', $html, 'nothing is submitted to the server' );
	}

	public function test_render_warns_about_another_multilingual_plugin() {
		update_option( 'active_plugins', array( 'sitepress-multilingual-cms/sitepress.php' ) );
		ob_start();
		Lingolane_Support::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'lingolane-banner--warn', $html );
		$this->assertStringContainsString( 'WPML', $html );
	}

	public function test_support_urls_are_filterable() {
		add_filter( 'lingolane_support_url', function () {
			return 'https://example.test/help/';
		} );
		$this->assertSame( 'https://example.test/help/', Lingolane_Support::forum_url() );
		ob_start();
		Lingolane_Support::render();
		$this->assertStringContainsString( 'https://example.test/help/', ob_get_clean() );
	}

	public function test_security_link_defaults_to_author_email_and_is_filterable() {
		$this->assertStringStartsWith( 'mailto:' . Lingolane_Support::SECURITY_EMAIL, Lingolane_Support::security_url() );
		add_filter( 'lingolane_security_url', function () {
			return 'https://example.test/security/';
		} );
		ob_start();
		Lingolane_Support::render();
		$this->assertStringContainsString( 'https://example.test/security/', ob_get_clean() );
	}

	public function test_row_meta_adds_link_only_for_this_plugin() {
		$links = Lingolane_Support::row_meta( array(), plugin_basename( LINGOLANE_FILE ) );
		$this->assertCount( 1, $links );
		$this->assertStringContainsString( 'tab=support', $links[0] );

		$this->assertSame( array( 'x' ), Lingolane_Support::row_meta( array( 'x' ), 'akismet/akismet.php' ) );
	}
}
