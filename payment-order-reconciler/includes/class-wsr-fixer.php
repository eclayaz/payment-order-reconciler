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

	/**
	 * Bug fix (independent review, round 2): a per-row-scoped nonce action
	 * name, instead of one shared NONCE_FIX/NONCE_DISMISS constant for
	 * every row — a nonce lifted from one row's link used to also verify
	 * against any other row (same capability either way, so not a
	 * privilege escalation, but weaker per-object CSRF protection than it
	 * needs to be).
	 */
	public static function fix_nonce_action( $drift_id ) {
		return self::NONCE_FIX . '_' . (int) $drift_id;
	}

	public static function dismiss_nonce_action( $drift_id ) {
		return self::NONCE_DISMISS . '_' . (int) $drift_id;
	}

	public static function handle_fix() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'driftwatch-order-reconciler-for-stripe' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only to build the nonce action name below; check_admin_referer() immediately after is the actual verification, before any effectful action.
		$drift_id = isset( $_REQUEST['drift_id'] ) ? absint( $_REQUEST['drift_id'] ) : 0;
		check_admin_referer( self::fix_nonce_action( $drift_id ) );

		$result = self::apply_fix( $drift_id, get_current_user_id() );

		$notice = is_wp_error( $result ) ? 'fix_error' : 'fix_applied';
		if ( is_wp_error( $result ) ) {
			set_transient( 'wsr_fix_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'wsr_notice', $notice, self::redirect_target() ) );
		exit;
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'driftwatch-order-reconciler-for-stripe' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only to build the nonce action name below; check_admin_referer() immediately after is the actual verification, before any effectful action.
		$drift_id = isset( $_REQUEST['drift_id'] ) ? absint( $_REQUEST['drift_id'] ) : 0;
		check_admin_referer( self::dismiss_nonce_action( $drift_id ) );

		self::dismiss( $drift_id );

		wp_safe_redirect( add_query_arg( 'wsr_notice', 'dismissed', self::redirect_target() ) );
		exit;
	}

	/**
	 * Dismisses one open drift row. Public + static, same reasoning as
	 * apply_fix() above — both the single admin_post handler and
	 * WSR_Admin_Dashboard's bulk-action processing use this directly.
	 *
	 * @return int 1 if a row was dismissed, 0 if it wasn't found or wasn't open.
	 */
	public static function dismiss( $drift_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wsr_drift_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no wpdb abstraction exists for this table.
		return (int) $wpdb->update(
			$table,
			array( 'status' => 'dismissed' ),
			array(
				'id'     => $drift_id,
				'status' => 'open',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * wp_get_referer() can return false (e.g. no Referer header sent) —
	 * add_query_arg( ..., false ) degrades in a way that produces a
	 * broken redirect URL. Falls back to the dashboard itself, which is
	 * where every row action originates from anyway.
	 */
	private static function redirect_target() {
		return wp_get_referer() ?: admin_url( 'admin.php?page=wsr-dashboard' );
	}

	/**
	 * Applies the fix for one drift row. Public + static so both the
	 * admin_post handler and future callers (e.g. a bulk-action in the
	 * real dashboard) can use it directly.
	 *
	 * @param int                  $drift_id wsr_drift_log.id
	 * @param int                  $user_id  WP user applying the fix, for audit attribution.
	 * @param WSR_Stripe_Client|null $client Optional injected client, for tests only —
	 *                                        production callers never pass this; it's
	 *                                        constructed from the configured API key below.
	 * @return true|WP_Error
	 */
	public static function apply_fix( $drift_id, $user_id, WSR_Stripe_Client $client = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wsr_drift_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- %i escapes the table identifier.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $drift_id ) );
		if ( ! $row ) {
			return new WP_Error( 'wsr_not_found', __( 'Drift record not found.', 'driftwatch-order-reconciler-for-stripe' ) );
		}
		if ( 'open' !== $row->status ) {
			return new WP_Error( 'wsr_not_open', __( 'This drift is no longer open (already fixed or dismissed).', 'driftwatch-order-reconciler-for-stripe' ) );
		}

		// No Fix action exists for orphaned_charge/needs_review — no order
		// to act on. See FEATURES.md / PROBLEM.md non-goals.
		if ( ! in_array( $row->drift_type, array( 'stuck_pending', 'wrongly_cancelled_paid' ), true ) ) {
			return new WP_Error( 'wsr_no_fix_for_type', __( 'No automated fix exists for this drift type — it can only be dismissed.', 'driftwatch-order-reconciler-for-stripe' ) );
		}

		if ( ! $row->order_id ) {
			return new WP_Error( 'wsr_no_order', __( 'This drift record has no associated order.', 'driftwatch-order-reconciler-for-stripe' ) );
		}

		$order = wc_get_order( $row->order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wsr_order_missing', __( 'The associated order no longer exists.', 'driftwatch-order-reconciler-for-stripe' ) );
		}

		// Bug fix (independent review, round 2): this used to be
		// `class_exists(...) && self::is_locked(...)` — if the gateway
		// ever renamed or removed WC_Stripe_Order_Helper, the whole
		// condition would short-circuit false and this destructive path
		// would proceed with NO lock check at all, silently failing open
		// on safety-critical logic. Missing the helper class now refuses
		// the fix outright instead.
		if ( ! class_exists( 'WC_Stripe_Order_Helper' ) ) {
			return new WP_Error( 'wsr_lock_check_unavailable', __( 'Cannot verify the gateway\'s own lock state before fixing — the WooCommerce Stripe Payment Gateway plugin may be missing or an incompatible version.', 'driftwatch-order-reconciler-for-stripe' ) );
		}
		if ( self::is_locked( $order ) ) {
			return new WP_Error( 'wsr_locked', __( 'This order is currently locked by the Stripe gateway\'s own webhook processing — try again in a few minutes.', 'driftwatch-order-reconciler-for-stripe' ) );
		}

		// Bug fix (independent review, round 2): the only prior guard here
		// was `severity === 'info'` — a snapshot written whenever the row
		// was originally detected, possibly hours or days ago. PROBLEM.md's
		// own stated highest-severity principle is "evaluate dispute/review
		// state fresh from Stripe on every detection run — never as a
		// cached or permanent flag," and this destructive path was doing
		// exactly the opposite: a dispute or Radar review opened *after*
		// detection left the row at high/critical severity with a live Fix
		// button, and there was no re-check against Stripe at all before
		// applying it. Re-fetch the PaymentIntent live and re-run the same
		// check Pass A itself uses, right before acting — not the cached row.
		if ( null === $client ) {
			$api_key = WSR_Settings::get_api_key();
			if ( '' === $api_key ) {
				return new WP_Error( 'wsr_no_api_key', __( 'No Stripe API key is configured — cannot verify current state before fixing.', 'driftwatch-order-reconciler-for-stripe' ) );
			}
			$client = new WSR_Stripe_Client( $api_key );
		}

		$pi = $client->get(
			'payment_intents/' . rawurlencode( $row->stripe_object_id ),
			array( 'expand' => array( 'latest_charge', 'latest_charge.dispute', 'latest_charge.review' ) )
		);

		if ( is_wp_error( $pi ) ) {
			return new WP_Error(
				'wsr_fix_verify_failed',
				sprintf(
					/* translators: %s: the underlying Stripe API error message */
					__( 'Could not verify current Stripe state before fixing — refusing to act on a stale snapshot: %s', 'driftwatch-order-reconciler-for-stripe' ),
					$pi->get_error_message()
				)
			);
		}

		$live_drift = WSR_Reconciler::check_drift_still_applies( $order, $pi, $row->drift_type );

		if ( null === $live_drift ) {
			// No longer drifting — it self-healed since detection (e.g.
			// another process's webhook or a prior fix already resolved
			// it). Mark it resolved rather than either fixing something
			// that isn't broken or leaving a stale open row behind.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no wpdb abstraction exists for this table.
			$wpdb->update(
				$table,
				array(
					'status'      => 'fixed',
					'resolved_at' => current_time( 'mysql', true ),
					'resolved_by' => 'system',
				),
				array( 'id' => $drift_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return new WP_Error( 'wsr_already_resolved', __( 'This order no longer shows as drifting against Stripe\'s current state — marked resolved automatically, no fix was needed.', 'driftwatch-order-reconciler-for-stripe' ) );
		}

		if ( 'info' === $live_drift['severity'] ) {
			// Currently disputed/under review right now — refuse, and
			// refresh the row's own severity so the dashboard reflects
			// this immediately rather than continuing to show a
			// misleadingly actionable high/critical row until the next
			// scheduled run.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no wpdb abstraction exists for this table.
			$wpdb->update( $table, array( 'severity' => 'info' ), array( 'id' => $drift_id ), array( '%s' ), array( '%d' ) );
			return new WP_Error( 'wsr_disputed_no_fix', __( 'This order currently has an active dispute or Radar review — no automated fix is available while that\'s open.', 'driftwatch-order-reconciler-for-stripe' ) );
		}

		// Bug fix (independent review, round 2): the prior version used
		// $order->get_transaction_id(), which is empty by definition for a
		// stuck-pending order (it's only ever set by the webhook that
		// already failed to process) — the order would be marked paid and
		// captured with no Stripe charge reference at all, making it
		// un-refundable from wp-admin. The just-fetched live PaymentIntent
		// carries the real charge ID.
		$charge_id = isset( $pi['latest_charge']['id'] ) ? $pi['latest_charge']['id'] : $order->get_transaction_id();

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
			return new WP_Error( 'wsr_fix_did_not_take', __( 'The fix did not result in the order being marked paid — no changes were recorded as applied.', 'driftwatch-order-reconciler-for-stripe' ) );
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
	 * Order-level fix history — one meta key holding a JSON list of every
	 * stripe_object_id ever fixed on this order, not a per-fix dynamic
	 * meta key (which would grow unbounded). wsr_drift_log.status is the
	 * actual, authoritative guard against re-applying a fix (apply_fix()
	 * refuses any row that isn't 'open' before this is ever reached) —
	 * this meta is never read back and must not become one either: a
	 * fixed row's NULL open_key deliberately allows the *same*
	 * stripe_object_id to be flagged and fixed again for a genuinely new
	 * future occurrence (see ReconcilerUpsertDriftTest's
	 * test_fixed_rows_do_not_block_a_genuinely_new_future_occurrence), so
	 * "already appears in this list" is not a valid reason to block a
	 * fix. This is a human-readable audit trail on the order itself,
	 * nothing more — a prior version of this comment called it an
	 * idempotency guard, which it was never actually used as (independent
	 * review, round 2).
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
