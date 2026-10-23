<?php
/**
 * Plugin Name: Lingolane
 * Plugin URI: https://github.com/tanoucru/lingolane
 * Description: Lightweight multilingual for WordPress: translation groups for posts and pages, string translation, language URLs, hreflang and a language switcher.
 * Version: 1.0.0-dev
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author: @tannguyen
 * Author URI: https://github.com/tanoucru
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: lingolane
 * Domain Path: /languages
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'LINGOLANE_VERSION', '1.0.0-dev' );
define( 'LINGOLANE_FILE', __FILE__ );
define( 'LINGOLANE_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINGOLANE_URL', plugin_dir_url( __FILE__ ) );

add_action( 'init', function () {
    load_plugin_textdomain( 'lingolane', false, dirname( plugin_basename( LINGOLANE_FILE ) ) . '/languages' );
}, 0 );

require_once LINGOLANE_DIR . 'includes/class-lingolane-languages.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-catalog.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-packs.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-install.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-shared.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-elements.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-router.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-query.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-urls.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-switcher.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-seo.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-links.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-strings.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-string-locations.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-string-sources.php';
require_once LINGOLANE_DIR . 'includes/class-lingolane-string-blocks.php';
require_once LINGOLANE_DIR . 'admin/class-lingolane-string-scan.php';

register_activation_hook( __FILE__, array( 'Lingolane_Install', 'activate' ) );
add_action( 'plugins_loaded', array( 'Lingolane_Install', 'maybe_upgrade' ) );

Lingolane_Router::init();
Lingolane_Elements::init();
Lingolane_Shared::init();
Lingolane_Query::init();
Lingolane_Switcher::init();
Lingolane_SEO::init();
Lingolane_Links::init();
Lingolane_Strings::init();
Lingolane_String_Sources::init();
Lingolane_String_Blocks::init();
Lingolane_String_Scan::init();
Lingolane_Packs::init();

if ( ! defined( 'LINGOLANE_NO_LEGACY' ) ) { require_once LINGOLANE_DIR . 'includes/compat-legacy.php'; }

if ( is_admin() ) {
    require_once LINGOLANE_DIR . 'admin/class-lingolane-stats.php';
    require_once LINGOLANE_DIR . 'admin/class-lingolane-ui.php';
    require_once LINGOLANE_DIR . 'admin/class-lingolane-admin.php';
    require_once LINGOLANE_DIR . 'admin/class-lingolane-strings-admin.php';
    require_once LINGOLANE_DIR . 'admin/class-lingolane-metabox.php';
    require_once LINGOLANE_DIR . 'admin/class-lingolane-columns.php';
    require_once LINGOLANE_DIR . 'admin/class-lingolane-tree.php';
    require_once LINGOLANE_DIR . 'admin/class-lingolane-support.php';
    require_once LINGOLANE_DIR . 'admin/class-lingolane-string-terms.php';
    Lingolane_Admin::init();
    Lingolane_Strings_Admin::init();
    Lingolane_String_Terms::init();
    Lingolane_Metabox::init();
    Lingolane_Columns::init();
    Lingolane_Tree::init();
    Lingolane_Support::init();
}
