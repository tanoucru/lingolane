<?php
/**
 * Plugin Name: TML Multilingual
 * Plugin URI: https://github.com/tanoucru/wp-multilingual
 * Description: Lightweight multilingual for WordPress: translation groups for posts and pages, string translation, language URLs, hreflang and a language switcher.
 * Version: 1.2
 * Author: @tannguyen
 * Author URI: https://github.com/tanoucru
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: tml-multilingual
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TML_VERSION', '1.2' );
define( 'TML_DIR', plugin_dir_path( __FILE__ ) );
define( 'TML_URL', plugin_dir_url( __FILE__ ) );

require_once TML_DIR . 'includes/class-tml-languages.php';
require_once TML_DIR . 'includes/class-tml-install.php';
require_once TML_DIR . 'includes/class-tml-shared.php';
require_once TML_DIR . 'includes/class-tml-elements.php';
require_once TML_DIR . 'includes/class-tml-router.php';
require_once TML_DIR . 'includes/class-tml-query.php';
require_once TML_DIR . 'includes/class-tml-urls.php';
require_once TML_DIR . 'includes/class-tml-switcher.php';
require_once TML_DIR . 'includes/class-tml-seo.php';
require_once TML_DIR . 'includes/class-tml-links.php';
require_once TML_DIR . 'includes/class-tml-strings.php';

register_activation_hook( __FILE__, array( 'TML_Install', 'activate' ) );
add_action( 'plugins_loaded', array( 'TML_Install', 'maybe_upgrade' ) );

TML_Router::init();
TML_Elements::init();
TML_Shared::init();
TML_Query::init();
TML_Switcher::init();
TML_SEO::init();
TML_Links::init();
TML_Strings::init();

if ( is_admin() ) {
    require_once TML_DIR . 'admin/class-tml-stats.php';
    require_once TML_DIR . 'admin/class-tml-admin.php';
    require_once TML_DIR . 'admin/class-tml-strings-admin.php';
    require_once TML_DIR . 'admin/class-tml-metabox.php';
    require_once TML_DIR . 'admin/class-tml-columns.php';
    TML_Admin::init();
    TML_Strings_Admin::init();
    TML_Metabox::init();
    TML_Columns::init();
}
