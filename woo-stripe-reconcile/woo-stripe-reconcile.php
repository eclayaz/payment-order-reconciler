<?php
/**
 * Plugin Name:       WooCommerce Stripe Reconcile
 * Plugin URI:        https://github.com/eclayaz/woo-stripe-reconcile
 * Description:       Reconciles Stripe payment state against WooCommerce order state for stores on the standard Stripe gateway plugin. Self-hosted, read-only Stripe API key only — no data ever leaves your site.
 * Version:           0.1.0-dev
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            eclayaz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-stripe-reconcile
 *
 * WC requires at least: 8.2
 * WC tested up to:      11.0
 *
 * See PROBLEM.md / FEATURES.md / TECHNICAL_SPEC.md in the repo root for the
 * research and design decisions behind this plugin, including two rounds of
 * independent technical review corrections.
 */

defined( 'ABSPATH' ) || exit;

define( 'WSR_VERSION', '0.1.0-dev' );
define( 'WSR_PLUGIN_FILE', __FILE__ );
define( 'WSR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare HPOS (custom order tables) compatibility.
 *
 * Per TECHNICAL_SPEC.md: this plugin never touches order data via raw
 * postmeta/$wpdb queries, only via wc_get_orders()/WC_Order_Query and core
 * CRUD methods — so it's storage-agnostic by construction and safe to
 * declare compatible unconditionally.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WSR_PLUGIN_FILE,
				true
			);
		}
	}
);

/**
 * Dependency check: WooCommerce and the standard Stripe gateway plugin must
 * both be active. This plugin has nothing to reconcile against otherwise.
 *
 * Checked on plugins_loaded (after all plugins have had a chance to load)
 * rather than at activation time alone, since a dependency being deactivated
 * later must also disable this plugin's own behavior, not just block the
 * initial activation.
 */
function wsr_dependencies_met() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return false;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// The standard third-party Stripe gateway plugin — not WooPayments,
	// which is explicitly out of scope (see PROBLEM.md).
	return is_plugin_active( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' );
}

function wsr_missing_dependencies_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'WooCommerce Stripe Reconcile requires both WooCommerce and the "WooCommerce Stripe Payment Gateway" plugin to be active. It has been deactivated.',
				'woo-stripe-reconcile'
			);
			?>
		</p>
	</div>
	<?php
}

function wsr_deactivate_self() {
	deactivate_plugins( WSR_PLUGIN_BASENAME );
	// deactivate_plugins() during plugins_loaded doesn't unset the current
	// request's already-loaded classes, but it does prevent the plugin from
	// running on the next request and updates the active_plugins option.
	unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- purely cosmetic, prevents WP's default activation notice.
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! wsr_dependencies_met() ) {
			add_action( 'admin_notices', 'wsr_missing_dependencies_notice' );
			if ( is_plugin_active( WSR_PLUGIN_BASENAME ) ) {
				wsr_deactivate_self();
			}
			return;
		}

		wsr_init();
	},
	20 // After WooCommerce (10) and the Stripe gateway have registered.
);

/**
 * Load plugin classes and boot. Kept deliberately minimal at this stage of
 * the build (see TECHNICAL_SPEC.md's suggested build order) — later steps
 * add the reconciler, scheduler, fixer, hook listener, and admin dashboard.
 */
function wsr_init() {
	// class-wsr-activator.php is already loaded unconditionally below (it
	// must be available at activation time regardless of whether this
	// function runs this request).
	require_once WSR_PLUGIN_DIR . 'includes/class-wsr-stripe-client.php';
	require_once WSR_PLUGIN_DIR . 'includes/class-wsr-settings.php';
	WSR_Settings::init();

	// Later build-order steps will require_once their classes here:
	// class-wsr-event-ledger.php, class-wsr-hook-listener.php,
	// class-wsr-reconciler.php, class-wsr-scheduler.php, class-wsr-fixer.php,
	// class-wsr-audit-log.php, class-wsr-admin-dashboard.php.
}

/**
 * Shown once, immediately after an activation attempt was rejected because
 * WooCommerce or the Stripe gateway plugin wasn't active yet (see
 * WSR_Activator::activate()). Distinct from wsr_missing_dependencies_notice()
 * above, which covers a dependency being deactivated later, after this
 * plugin was already running.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! get_transient( 'wsr_activation_error' ) ) {
			return;
		}
		delete_transient( 'wsr_activation_error' );
		?>
		<div class="notice notice-error">
			<p>
				<?php
				esc_html_e(
					'WooCommerce Stripe Reconcile could not be activated: both WooCommerce and the "WooCommerce Stripe Payment Gateway" plugin must be active first.',
					'woo-stripe-reconcile'
				);
				?>
			</p>
		</div>
		<?php
	}
);

// The activator class must be loadable at activation time even if
// wsr_init() hasn't run yet this request (e.g. fresh activation before
// plugins_loaded's dependency check has cached a result).
require_once WSR_PLUGIN_DIR . 'includes/class-wsr-activator.php';

register_activation_hook( WSR_PLUGIN_FILE, array( 'WSR_Activator', 'activate' ) );
register_deactivation_hook( WSR_PLUGIN_FILE, array( 'WSR_Activator', 'deactivate' ) );
