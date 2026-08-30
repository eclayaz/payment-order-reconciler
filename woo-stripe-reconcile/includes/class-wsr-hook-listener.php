<?php
/**
 * Real-time signal from the gateway plugin's own hooks — accelerators and
 * telemetry only, never resolvers in their own right (except the one hook
 * that genuinely fires after a real state commit). See TECHNICAL_SPEC.md's
 * "Real-time signal" section for the corrected role of each hook.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Hook_Listener {

	const VERIFY_HOOK  = 'wsr_verify_single_order';
	const VERIFY_DELAY = 2 * MINUTE_IN_SECONDS;

	public static function init() {
		// Accelerator: schedules a targeted single-order verification a
		// couple of minutes out, rather than waiting for tomorrow's daily
		// pass. Filtered to exclude dispute-created invocations — this
		// hook also fires there, and a dispute is not a failure.
		add_action( 'wc_gateway_stripe_process_webhook_payment_error', array( __CLASS__, 'on_webhook_payment_error' ), 10, 3 );

		// Accelerator only — fires too early in process_response() to be
		// trusted as confirmation of anything (see class comment on the
		// non-deprecated replacement hook in TECHNICAL_SPEC.md).
		add_action( 'wc_gateway_stripe_process_payment_charge', array( __CLASS__, 'on_payment_charge_processed' ), 10, 2 );

		// Telemetry only — the gateway's own 10.8.0+ guard already
		// resolved the situation; this is a coverage signal, not
		// something to react to.
		add_action( 'wc_stripe_paid_order_cancellation_prevented', array( __CLASS__, 'on_cancellation_prevented' ) );

		// The genuine auto-resolve trigger — fires only after
		// WooCommerce core has actually committed the payment-complete
		// transition. Re-entrancy-guard-aware: skips when WSR_Fixer's own
		// call to payment_complete() is what triggered this.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ) );

		add_action( self::VERIFY_HOOK, array( 'WSR_Reconciler', 'verify_single_order' ) );
	}

	public static function on_webhook_payment_error( $order, $notification = null, $exception = null ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( self::is_dispute_notification( $notification ) ) {
			return; // Not a failure — see class comment.
		}

		self::schedule_verification( $order->get_id() );
	}

	public static function on_payment_charge_processed( $response = null, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		self::schedule_verification( $order->get_id() );
	}

	public static function on_cancellation_prevented( $order = null ) {
		// Simple running counter for now — surfaced properly once the
		// real dashboard (a later build-order step) exists. Every firing
		// is direct evidence the gateway's own 10.8.0+ guard is active
		// and working on this store.
		$count = (int) get_option( 'wsr_cancellation_prevented_total', 0 );
		update_option( 'wsr_cancellation_prevented_total', $count + 1, false );
	}

	public static function on_payment_complete( $order_id ) {
		if ( WSR_Fixer::is_applying_fix() ) {
			// The fixer's own call to payment_complete() triggered this —
			// it writes its own authoritative resolved_by afterward
			// regardless of what happens here, so there's nothing for
			// this listener to do. See WSR_Fixer's class comment for why
			// this guard exists.
			return;
		}

		self::auto_resolve_open_drift( $order_id );
	}

	/**
	 * A legitimately-completing order is direct evidence any previously
	 * flagged drift for it has self-healed. Only ever touches rows that
	 * have order_id set (stuck_pending / wrongly_cancelled_paid) —
	 * orphaned_charge / needs_review rows have no order_id and are
	 * structurally excluded by this WHERE clause, which is correct: this
	 * hook fires on an order, not a charge with no order at all.
	 */
	private static function auto_resolve_open_drift( $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wsr_drift_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no wpdb abstraction exists for this table.
		$wpdb->update(
			$table,
			array(
				'status'      => 'fixed',
				'resolved_at' => current_time( 'mysql', true ),
				'resolved_by' => 'system',
			),
			array(
				'order_id' => $order_id,
				'status'   => 'open',
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	private static function is_dispute_notification( $notification ) {
		$type = '';
		if ( is_object( $notification ) && isset( $notification->type ) ) {
			$type = (string) $notification->type;
		} elseif ( is_array( $notification ) && isset( $notification['type'] ) ) {
			$type = (string) $notification['type'];
		}
		return false !== strpos( $type, 'dispute' );
	}

	private static function schedule_verification( $order_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return; // ActionScheduler not available this request.
		}

		$args = array( 'order_id' => $order_id );
		if ( as_has_scheduled_action( self::VERIFY_HOOK, $args, WSR_Scheduler::GROUP ) ) {
			return; // Already queued — don't stack duplicates.
		}

		as_schedule_single_action( time() + self::VERIFY_DELAY, self::VERIFY_HOOK, $args, WSR_Scheduler::GROUP );
	}
}
