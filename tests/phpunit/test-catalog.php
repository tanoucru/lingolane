<?php
/**
 * Language catalog and on-demand language packs: URL codes generated from WordPress locales, the offline
 * fallback, the picker data, translation update locales and the add-language flow. No network: the
 * WordPress.org list comes from a fixture and downloads are short-circuited.
 */
class Test_Lingolane_Catalog extends Lingolane_TestCase {

	/** @var string[] Locales passed to the installer. */
	private $installed = array();

	/** @var true|WP_Error What the short-circuited installer returns. */
	private $install_result = true;

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		foreach ( array( 'stats', 'admin' ) as $file ) {
			require_once LINGOLANE_DIR . 'admin/class-lingolane-' . $file . '.php';
		}
	}

	public function set_up() {
		parent::set_up();
		$this->installed      = array();
		$this->install_result = true;
		add_filter( 'pre_site_transient_available_translations', array( $this, 'fixture_translations' ) );
		add_filter( 'lingolane_pre_install_language_pack', array( $this, 'fake_install' ), 10, 2 );
		Lingolane_Catalog::reset();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		Lingolane_Catalog::reset();
		parent::tear_down();
	}

	/** Shape of wp_get_available_translations(): locale => [language, english_name, native_name, ...]. */
	public function fixture_translations() {
		$list = array();
		$rows = array(
			'fr_FR' => array( 'French (France)', 'Français' ),
			'fr_CA' => array( 'French (Canada)', 'Français du Canada' ),
			'pt_PT' => array( 'Portuguese (Portugal)', 'Português' ),
			'pt_BR' => array( 'Portuguese (Brazil)', 'Português do Brasil' ),
			'pt_AO' => array( 'Portuguese (Angola)', 'Português de Angola' ),
			'de_DE' => array( 'German', 'Deutsch' ),
			'de_DE_formal' => array( 'German (Formal)', 'Deutsch (Sie)' ),
			'de_CH' => array( 'German (Switzerland)', 'Deutsch (Schweiz)' ),
			'en_GB' => array( 'English (UK)', 'English (UK)' ),
			'zh_CN' => array( 'Chinese (China)', '简体中文' ),
			'zh_TW' => array( 'Chinese (Taiwan)', '繁體中文' ),
			'zh_HK' => array( 'Chinese (Hong Kong)', '香港中文' ),
			'sv_SE' => array( 'Swedish', 'Svenska' ),
			'es_ES' => array( 'Spanish (Spain)', 'Español' ),
			'es_MX' => array( 'Spanish (Mexico)', 'Español de México' ),
			'ja'    => array( 'Japanese', '日本語' ),
			'vi'    => array( 'Vietnamese', 'Tiếng Việt' ),
		);
		foreach ( $rows as $locale => $names ) {
			$list[ $locale ] = array( 'language' => $locale, 'english_name' => $names[0], 'native_name' => $names[1], 'iso' => array() );
		}
		return $list;
	}

	public function fake_install( $pre, $locale ) {
		$this->installed[] = $locale;
		return $this->install_result;
	}

	private function locale_of( $code ) {
		$item = Lingolane_Catalog::get( $code );
		return $item ? $item['locale'] : null;
	}

	public function test_codes_follow_the_language_and_add_a_region_only_when_needed() {
		$this->assertSame( 'fr_FR', $this->locale_of( 'fr' ), 'main locale gets the bare code' );
		$this->assertSame( 'fr_CA', $this->locale_of( 'fr-ca' ), 'second locale adds its region' );
		$this->assertSame( 'de_DE', $this->locale_of( 'de' ) );
		$this->assertSame( 'de_CH', $this->locale_of( 'de-ch' ) );
		$this->assertSame( 'es_ES', $this->locale_of( 'es' ) );
		$this->assertSame( 'es_MX', $this->locale_of( 'es-mx' ) );
		$this->assertSame( 'sv_SE', $this->locale_of( 'sv' ), 'a lone locale gets the bare code even when its region differs' );
		$this->assertSame( 'ja', $this->locale_of( 'ja' ) );
	}

	public function test_codes_used_before_the_catalog_stay_stable() {
		$this->assertSame( 'en_US', $this->locale_of( 'en' ) );
		$this->assertSame( 'en_GB', $this->locale_of( 'en-gb' ) );
		$this->assertSame( 'pt_PT', $this->locale_of( 'pt' ) );
		$this->assertSame( 'pt_BR', $this->locale_of( 'pt-br' ) );
		$this->assertSame( 'pt_AO', $this->locale_of( 'pt-ao' ) );
		$this->assertSame( 'zh_CN', $this->locale_of( 'zh' ) );
		$this->assertSame( 'zh_TW', $this->locale_of( 'zh-tw' ) );
		$this->assertSame( 'zh_HK', $this->locale_of( 'zh-hk' ) );
	}

	public function test_variants_are_left_out_and_every_code_is_a_valid_url_code() {
		$this->assertNull( Lingolane_Catalog::by_locale( 'de_DE_formal' ), 'formal variant skipped' );
		$locales = array();
		foreach ( Lingolane_Catalog::all() as $code => $item ) {
			$this->assertMatchesRegularExpression( '/^[a-z]{2,3}(?:-[a-z]{2,4})?$/D', $code, "code $code" );
			$locales[] = $item['locale'];
		}
		$this->assertSame( count( $locales ), count( array_unique( $locales ) ), 'one code per locale' );
	}

	public function test_popular_languages_come_first_in_their_order() {
		$codes = array_keys( Lingolane_Catalog::all() );
		$this->assertSame( array( 'en', 'zh', 'es' ), array_slice( $codes, 0, 3 ) );
		$this->assertTrue( Lingolane_Catalog::get( 'vi' )['popular'] );
		$this->assertFalse( Lingolane_Catalog::get( 'fr-ca' )['popular'] );
	}

	public function test_offline_fallback_still_lists_the_common_languages() {
		remove_filter( 'pre_site_transient_available_translations', array( $this, 'fixture_translations' ) );
		add_filter( 'pre_site_transient_available_translations', '__return_empty_array' );
		Lingolane_Catalog::reset();

		$this->assertGreaterThanOrEqual( 40, count( Lingolane_Catalog::all() ) );
		$this->assertSame( 'ko_KR', $this->locale_of( 'ko' ) );
		$this->assertSame( 'Tiếng Việt', Lingolane_Catalog::get( 'vi' )['name'] );
		$this->assertSame( 'he_IL', $this->locale_of( 'he' ) );
	}

	public function test_picker_presets_come_from_the_catalog() {
		$presets = Lingolane_Admin::presets();
		$this->assertSame( array( 'Français du Canada', 'fr_CA' ), $presets['fr-ca'] );
		$this->assertSame( count( Lingolane_Catalog::all() ), count( $presets ) );
	}

	public function test_translation_updates_cover_every_site_language() {
		$locales = Lingolane_Packs::update_locales( array( 'fr_FR' ) );
		$this->assertSame( array( 'fr_FR', 'vi' ), $locales, 'adds site locales, skips en_US, keeps admin locales' );
		$this->assertSame( array( 'vi' ), apply_filters( 'plugins_update_check_locales', array() ) );
		$this->assertSame( array( 'vi' ), apply_filters( 'themes_update_check_locales', array() ) );
	}

	public function test_english_needs_no_download() {
		remove_filter( 'lingolane_pre_install_language_pack', array( $this, 'fake_install' ), 10 );
		$this->assertTrue( Lingolane_Packs::install( 'en_US' ) );
		$this->assertTrue( Lingolane_Packs::installed( 'en_US' ) );
	}

	public function test_users_who_cannot_install_translations_get_an_error() {
		remove_filter( 'lingolane_pre_install_language_pack', array( $this, 'fake_install' ), 10 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = Lingolane_Packs::install( 'fr_FR' );
		$this->assertWPError( $result );
		$this->assertSame( 'lingolane_pack_cap', $result->get_error_code() );
	}

	/** Uses the real option so the new language can be read back. */
	private function use_stored_settings() {
		remove_filter( 'pre_option_lingolane_settings', array( $this, 'filter_settings' ) );
		update_option( 'lingolane_settings', $this->settings_array() );
	}

	public function test_adding_a_language_downloads_only_its_translations() {
		$this->use_stored_settings();
		$result = Lingolane_Admin::insert_language( 'fr', 'Français', 'fr_FR' );

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result[0] );
		$this->assertSame( array( 'fr_FR' ), $this->installed, 'one download, for the chosen locale' );
		$this->assertSame( 'fr_FR', Lingolane_Languages::locale( 'fr' ), 'language saved' );
	}

	public function test_a_failed_download_keeps_the_language_and_warns() {
		$this->use_stored_settings();
		$this->install_result = new WP_Error( 'lingolane_pack_download', 'offline' );
		$result = Lingolane_Admin::insert_language( 'ja', '日本語', 'ja' );

		$this->assertSame( 'warning', $result[0] );
		$this->assertStringContainsString( 'offline', $result[1] );
		$this->assertTrue( Lingolane_Languages::is_valid( 'ja' ), 'language added even without translations' );
	}

	public function test_invalid_or_duplicate_languages_download_nothing() {
		$this->use_stored_settings();
		$this->assertWPError( Lingolane_Admin::insert_language( 'vi', 'Tiếng Việt', 'vi' ) );
		$this->assertWPError( Lingolane_Admin::insert_language( 'Fr!', 'Français', 'fr_FR' ) );
		$this->assertWPError( Lingolane_Admin::insert_language( 'fr', 'Français', 'french' ) );
		$this->assertSame( array(), $this->installed );
	}
}
