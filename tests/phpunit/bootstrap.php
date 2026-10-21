<?php
/**
 * PHPUnit bootstrap for Lingolane.
 *
 * Works in two places:
 *  - wp-env / CI: WP_TESTS_DIR points at the WordPress test library that matches the installed core.
 *  - locally: falls back to the wp-phpunit package from Composer (set WP_PHPUNIT__TESTS_CONFIG to your wp-tests-config.php).
 *
 * The WordPress test library DROPS EVERY TABLE of the database it is configured with. Never point it at a real site.
 */

$lingolane_root = dirname( __DIR__, 2 );
$lingolane_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $lingolane_tests_dir ) {
	$lingolane_tests_dir = $lingolane_root . '/vendor/wp-phpunit/wp-phpunit';
}
if ( ! file_exists( $lingolane_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found at {$lingolane_tests_dir}. Run `composer install` or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $lingolane_root . '/vendor/yoast/phpunit-polyfills' );
}

require_once $lingolane_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $lingolane_root ) {
		require $lingolane_root . '/lingolane/lingolane.php';
	}
);

require $lingolane_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-lingolane-test-case.php';
