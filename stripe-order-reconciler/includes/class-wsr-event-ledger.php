<?php
/**
 * Dedup ledger for the real-time hook listeners and Pass B — scoped to
 * Stripe event IDs, which PaymentIntents (Pass A's subject) don't have,
 * so Pass A's own idempotency lives entirely in wsr_drift_log's open_key
 * column instead (see class-wsr-reconciler.php).
 *
 * Genuine purpose, not just schema for its own sake: the gateway's own
 * webhook processing can retry, and Stripe's own delivery is at-least-once
 * with no ordering guarantee — so the same webhook error notification can
 * reach WSR_Hook_Listener::on_webhook_payment_error() more than once for
 * the same underlying Stripe event. Claiming the event ID here before
 * scheduling a verification prevents redundant scheduling for a webhook
 * retry we've already reacted to.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Event_Ledger {

	const RETENTION_DAYS = 30; // Matches Stripe's own Events API retention — nothing older is actionable anyway.

	/**
	 * Attempts to claim an event ID as "seen." Returns true the first
	 * time (caller should proceed), false on every subsequent call for
	 * the same ID (caller should skip — already handled).
	 *
	 * No-ops to true (always proceed) when no event ID is available at
	 * all — not every call site has one (e.g. a notification payload
	 * without an `id` field), and there's nothing to dedupe against in
	 * that case.
	 */
	public static function claim( $event_id ) {
		if ( ! $event_id ) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wsr_event_ledger';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no wpdb abstraction exists for this table.
		$inserted = $wpdb->insert(
			$table,
			array(
				'event_id'   => $event_id,
				'status'     => 'processed',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);

		return false !== $inserted; // false means the unique event_id key already exists — already claimed.
	}

	/**
	 * Called from the daily job (see WSR_Reconciler::run_all()) — keeps
	 * the table from growing unbounded and matches Stripe's own 30-day
	 * Events retention, past which an event ID could never legitimately
	 * reappear anyway.
	 */
	public static function prune() {
		global $wpdb;
		$table  = $wpdb->prefix . 'wsr_event_ledger';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is our own constant prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
