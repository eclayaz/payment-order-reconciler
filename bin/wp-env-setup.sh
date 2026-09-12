#!/usr/bin/env bash
# Runs automatically after `wp-env start` (see .wp-env.json's lifecycleScripts.afterStart).
# Activates plugins in dependency order and does a quick smoke test — this is
# what actually answers "does the plugin activate cleanly against a real
# WordPress + WooCommerce + Stripe gateway install" instead of just eyeballing
# the PHP source.
set -euo pipefail

WP_CLI="npx wp-env run cli --"

echo "==> Activating plugins in dependency order"
# Order matters: our plugin's own activation-time dependency check requires
# WooCommerce and the Stripe gateway to already be active (see
# WSR_Activator::dependencies_met_at_activation()).
$WP_CLI wp plugin activate woocommerce
$WP_CLI wp plugin activate woocommerce-gateway-stripe
$WP_CLI wp plugin activate payment-order-reconciler

echo "==> Basic WooCommerce config (skip onboarding, disable tracking, pretty permalinks)"
$WP_CLI wp option update woocommerce_allow_tracking "no"
$WP_CLI wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
$WP_CLI wp rewrite structure '/%postname%/' --hard

echo "==> Plugin status"
$WP_CLI wp plugin list --status=active --field=name

echo "==> Checking debug.log for fatal errors or warnings from our plugin"
if $WP_CLI wp eval 'echo file_exists(WP_CONTENT_DIR . "/debug.log") ? "yes" : "no";' | grep -q yes; then
	$WP_CLI wp eval 'echo file_get_contents(WP_CONTENT_DIR . "/debug.log");' | grep -i "payment-order-reconciler\|wsr_\|WSR_" || echo "(no matching log lines — good sign, but check manually for anything unrelated too)"
else
	echo "(no debug.log yet — nothing has errored)"
fi

echo ""
echo "==> Done. Site: http://localhost:8888  Admin: http://localhost:8888/wp-admin (admin/password)"
echo "    Stripe test API keys still need to be pasted manually into WooCommerce > Settings > Payments > Stripe — never commit real or test secret keys to this repo."
