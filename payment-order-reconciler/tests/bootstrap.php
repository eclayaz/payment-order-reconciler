<?php
/**
 * PHPUnit bootstrap. Standard WordPress-plugin pattern: load the WP core
 * test library (via WP_TESTS_DIR — already set by wp-env's tests-cli
 * container), load WooCommerce and this plugin on muplugins_loaded (before
 * WP's own bootstrap runs its install routine), then hand off.
 *
 * Run via: wp-env run tests-cli -- bash -c "cd wp-content/plugins/payment-order-reconciler && vendor/bin/phpunit"
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads WooCommerce, the Stripe gateway, and this plugin — in that
 * dependency order, matching the real activation-time requirement chain
 * this plugin's own dependency check enforces.
 */
function _wsr_manually_load_plugins() {
	$plugins_dir = WP_CONTENT_DIR . '/plugins';

	// WP core's PHPUnit install routine runs a fresh wp_install() against
	// the test database, which doesn't carry over the real dev site's
	// active_plugins state — but our plugin's own runtime dependency
	// check (WSR_Activator/the main file's plugins_loaded handler) calls
	// is_plugin_active(), which only reads that option. Direct-requiring
	// the files below makes the *code* load regardless, but our own
	// dependency check would still (incorrectly, for test purposes) think
	// the gateway isn't active unless this is set explicitly.
	update_option(
		'active_plugins',
		array(
			'woocommerce/woocommerce.php',
			'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php',
		)
	);

	require $plugins_dir . '/woocommerce/woocommerce.php';
	require $plugins_dir . '/woocommerce-gateway-stripe/woocommerce-gateway-stripe.php';
	require dirname( __DIR__ ) . '/payment-order-reconciler.php';
}
tests_add_filter( 'muplugins_loaded', '_wsr_manually_load_plugins' );

require $_tests_dir . '/includes/bootstrap.php';

// Not itself a *Test.php file (deliberately, so PHPUnit's suffix-based
// discovery doesn't try to run it as a test suite) — load it explicitly.
require __DIR__ . '/TestCase.php';
