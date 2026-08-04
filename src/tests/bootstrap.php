<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Packlink_Pro_Shipping
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL;

	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

// Load the core test base class matching the running PHPUnit version (mirrors the core test
// bootstrap). The plugin's repository tests extend core abstracts that extend this CompatTestCase,
// which composer PSR-4 cannot resolve on its own because the file lives under compat/{legacy,modern}.
$_core_tests_dir = dirname( __DIR__ ) . '/vendor/packlink/integration-core/tests';
$_is_modern_phpunit = class_exists( 'PHPUnit\\Runner\\Version' )
	&& version_compare( \PHPUnit\Runner\Version::series(), '9.0', '>=' );
require $_is_modern_phpunit
	? $_core_tests_dir . '/Infrastructure/Common/compat/modern/CompatTestCase.php'
	: $_core_tests_dir . '/Infrastructure/Common/compat/legacy/CompatTestCase.php';

/**
 * Manually load the plugin being tested (and WooCommerce, which it depends on).
 */
function _manually_load_plugin() {
	$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if ( file_exists( $woocommerce ) ) {
		require $woocommerce;
	}

	require dirname( __DIR__ ) . '/packlink-pro-shipping.php';
}

/** @noinspection PhpUndefinedFunctionInspection */
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Install WooCommerce (tables + roles) into the test database so order/product tests can run.
 */
function _install_woocommerce() {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}

	WC_Install::install();

	// Reload capabilities so WC roles are available.
	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}

/** @noinspection PhpUndefinedFunctionInspection */
tests_add_filter( 'setup_theme', '_install_woocommerce' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
