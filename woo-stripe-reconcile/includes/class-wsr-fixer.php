<?php
/**
 * The fix action: `payment_complete()` plus the corrected companion steps
 * from TECHNICAL_SPEC.md. The plugin's only destructive operation — never
 * calls a Stripe write endpoint, only ever writes to the local order.
 *
 * Method names below were confirmed against the actually-installed
 * gateway version's source (class-wc-stripe-order-helper.php), not
 * assumed from prior documentation — this caught a real error: an earlier
 * design assumed a public `is_order_payment_locked()` method exists on
 * `WC_Stripe_Order_Helper`. It doesn't; that method is `protected`. The
 * only public accessor is `get_order_existing_payment_lock()`, which
 * returns the raw lock meta value (an expiry timestamp string) — the
 * "is it still active" comparison has to be replicated here, matching the
 * gateway's own internal `is_order_payment_locked()` logic exactly (see
 * is_locked() below).
 */

defined( 'ABSPATH' ) || exit;

class WSR_Fixer {

	const CAPABILITY    = 'manage_woocommerce';
	const NONCE_FIX     = 'wsr_fix_drift';
	const NONCE_DISMISS = 'wsr_dismiss_drift';

	/**
	 * Set for the duration of a fix's own call to $order->payment_complete(),
	 * so the re-entrancy-guard-aware auto-resolve listener
	 * (WSR_Hook_Listener::on_payment_complete()) can tell the difference
	 * between "this order just completed on its own" and "the fixer just
	 * did this" — the fixer writes its own authoritative resolved_by
	 * afterward either way, but without this flag the listener would write
	 * resolved_by: system moments before the fixer overwrites it with the
	 * admin's user ID, and in the harder case (listener runs after) could
	 * clobber the fixer's correct attribution back to 'system'.
	 */
	private static $applying_fix = false;

	public static function is_applying_fix() {
		return self::$applying_fix;
	}

	public static function init() {
		add_action( 'admin_post_wsr_fix_drift', array( __CLASS__, 'handle_fix' ) );
		add_action( 'admin_post_wsr_dismiss_drift', array( __CLASS__, 'handle_dismiss' ) );
	}

	public static function handle_fix() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-stripe-reconcile' ) );
		}
		check_admin_referer( self::NONCE_FIX );

		$drift_id = isset( $_REQUEST['drift_id'] ) ? absint( $_REQUEST['drift_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() just above; dashboard row actions are plain nonce-protected GET links, not forms.
		$result   = self::apply_fix( $drift_id, get_current_user_id() );

		$notice = is_wp_error( $result ) ? 'fix_error' : 'fix_applied';
		if ( is_wp_error( $result ) ) {
			set_transient( 'wsr_fix_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'wsr_notice', $notice, wp_get_referer() ) );
		exit;
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-stripe-reconcile' ) );
		}
		check_admin_referer( self::NONCE_DISMISS );

		$drift_id = isset( $_REQUEST['drift_id'] ) ? absint( $_REQUEST['drift_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() just above; dashboard row actions are plain nonce-protected GET links, not forms.
		global $wpdb;
		$table = $wpdb->prefix . 'wsr_drift_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no wpdb abstraction exists for this table.
		$wpdb->update(
			$table,
			array( 'status' => 'dismissed' ),
			array(
				'id'     => $drift_id,
				'status' => 'open',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		wp_safe_redirect( add_query_arg( 'wsr_notice', 'dismissed', wp_get_referer() ) );
		exit;
	}

	/**
	 * Applies the fix for one drift row. Public + static so both the
	 * admin_post handler and future callers (e.g. a bulk-action in the
	 * real dashboard) can use it directly.
	 *
	 * @param int $drift_id wsr_drift_log.id
	 * @param int $user_id  WP user applying the fix, for audit attribution.
	 * @return true|WP_Error
	 */
	public static function apply_fix( $drift_id, $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wsr_drift_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is our own constant prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $drift_id ) );
		if ( ! $row ) {
			return new WP_Error( 'wsr_not_found', __( 'Drift record not found.', 'woo-stripe-reconcile' ) );
		}
		if ( 'open' !== $row->status ) {
			return new WP_Error( 'wsr_not_open', __( 'This drift is no longer open (already fixed or dismissed).', 'woo-stripe-reconcile' ) );
		}

		// No Fix action exists for orphaned_charge/needs_review — no order
		// to act on. See FEATURES.md / PROBLEM.md non-goals.
		if ( ! in_array( $row->drift_type, array( 'stuck_pending', 'wrongly_cancelled_paid' ), true ) ) {
			return new WP_Error( 'wsr_no_fix_for_type', __( 'No automated fix exists for this drift type — it can only be dismissed.', 'woo-stripe-reconcile' ) );
		}

		// A currently-disputed/under-review match is detected at
		// severity=info specifically so no automated status change
		// happens while that's active — see PROBLEM.md's corrected
		// dispute-handling principle. Enforced again here, not just at
		// detection time, since detection and fix are separate requests.
		if ( 'info' === $row->severity ) {
			return new WP_Error( 'wsr_disputed_no_fix', __( 'This order has an active dispute or Radar review — no automated fix is available while that\'s open.', 'woo-stripe-reconcile' ) );
		}

		if ( ! $row->order_id ) {
			return new WP_Error( 'wsr_no_order', __( 'This drift record has no associated order.', 'woo-stripe-reconcile' ) );
		}

		$order = wc_get_order( $row->order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wsr_order_missing', __( 'The associated order no longer exists.', 'woo-stripe-reconcile' ) );
		}

		if ( class_exists( 'WC_Stripe_Order_Helper' ) && self::is_locked( $order ) ) {
			return new WP_Error( 'wsr_locked', __( 'This order is currently locked by the Stripe gateway\'s own webhook processing — try again in a few minutes.', 'woo-stripe-reconcile' ) );
		}

		$charge_id = $order->get_transaction_id();

		self::$applying_fix = true;
		try {
			$order->payment_complete( $charge_id );
		} finally {
			self::$applying_fix = false;
		}

		// payment_complete()'s return value can't be trusted on its own —
		// it returns true on a no-op path too. Re-read the order fresh and
		// verify date_paid actually got set before recording success.
		$order = wc_get_order( $row->order_id );
		if ( ! $order instanceof WC_Order || ! $order->get_date_paid() ) {
			return new WP_Error( 'wsr_fix_did_not_take', __( 'The fix did not result in the order being marked paid — no changes were recorded as applied.', 'woo-stripe-reconcile' ) );
		}

		self::complete_stripe_meta( $order );
		self::track_fix_applied( $order, $row->stripe_object_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no wpdb abstraction exists for this table.
		$wpdb->update(
			$table,
			array(
				'status'      => 'fixed',
				'resolved_at' => current_time( 'mysql', true ),
				'resolved_by' => (string) $user_id,
			),
			array( 'id' => $drift_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Replicates WC_Stripe_Order_Helper's own (protected, so not directly
	 * callable) is_order_payment_locked() logic against its public
	 * get_order_existing_payment_lock() accessor. The stored value is an
	 * expiry timestamp (optionally "{timestamp}|..." — only the leading
	 * segment matters); the lock is active only while time() is still
	 * before that expiry.
	 */
	private static function is_locked( WC_Order $order ) {
		$helper        = WC_Stripe_Order_Helper::get_instance();
		$existing_lock = $helper->get_order_existing_payment_lock( $order );
		if ( ! $existing_lock ) {
			return false;
		}
		$parts      = explode( '|', $existing_lock );
		$expiration = (int) $parts[0];
		return time() <= $expiration;
	}

	/**
	 * payment_complete() doesn't set _stripe_charge_captured or clear
	 * _stripe_payment_awaiting_action — both of which other gateway logic
	 * (refunds, cancellation checks) reads. Leaving them unset would
	 * reproduce the exact failure mode described in this project's own
	 * cited evidence (#5699: missing capture flag breaks wp-admin refunds
	 * for async-confirmed payments). Routes through the order-helper's own
	 * confirmed-real setter methods, not hardcoded meta key writes.
	 */
	private static function complete_stripe_meta( WC_Order $order ) {
		if ( ! class_exists( 'WC_Stripe_Order_Helper' ) ) {
			return;
		}
		$helper = WC_Stripe_Order_Helper::get_instance();
		$helper->set_stripe_charge_captured( $order, true );
		$helper->remove_payment_awaiting_action( $order );
	}

	/**
	 * Idempotency guard for the fix itself — one meta key holding a JSON
	 * list of already-fixed stripe_object_ids, not a per-fix dynamic meta
	 * key (which would grow unbounded). wsr_drift_log remains the
	 * authoritative, queryable fix history regardless; this is just a
	 * fast guard.
	 */
	private static function track_fix_applied( WC_Order $order, $stripe_object_id ) {
		$applied = $order->get_meta( '_wsr_fix_applied' );
		$applied = is_array( $applied ) ? $applied : array();
		if ( ! in_array( $stripe_object_id, $applied, true ) ) {
			$applied[] = $stripe_object_id;
		}
		$order->update_meta_data( '_wsr_fix_applied', $applied );
		$order->save();
	}
}
