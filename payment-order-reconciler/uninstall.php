<?php
/**
 * Fires only on an explicit "Delete" from wp-admin's Plugins screen (or
 * `wp plugin delete`) — never on deactivation, which WSR_Activator::deactivate()
 * deliberately leaves alone so a merchant deactivating temporarily (e.g. to
 * troubleshoot) doesn't lose drift history. Deletion is the merchant's
 * explicit opt-in to full data removal; this is that removal.
 *
 * Hardcodes every table/option name directly rather than loading the
 * plugin's own classes for their constants — WooCommerce (or the plugin's
 * other dependencies) may already be gone by the time a merchant gets
 * around to deleting this plugin, and this file must not depend on them.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table names are our own constant prefix, no user input.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsr_drift_log" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table names are our own constant prefix, no user input.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsr_event_ledger" );

$options = array(
	'wsr_schema_version',              // WSR_Activator::SCHEMA_VERSION_OPTION
	'wsr_stripe_restricted_key',       // WSR_Settings::OPTION_API_KEY
	'wsr_stripe_scope_status',         // WSR_Settings::OPTION_SCOPE_STATUS
	'wsr_alert_email',                 // WSR_Email_Alerts::OPTION_ALERT_EMAIL
	'wsr_last_run_at',
	'wsr_last_run_failure',
	'wsr_last_pass_b_result',
	'wsr_last_webhook_health_result',
	'wsr_cancellation_prevented_total',
	'wsr_run_lock',                    // WSR_Scheduler::LOCK_OPTION
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// Cancel the daily job and any pending single-order verifications —
// passing an empty hook with our group cancels everything in the group
// regardless of which hook scheduled it (see ActionScheduler's own
// as_unschedule_all_actions()).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'payment-order-reconciler-for-stripe' );
}
