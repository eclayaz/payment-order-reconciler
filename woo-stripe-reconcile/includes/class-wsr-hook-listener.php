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

		// Claim the underlying Stripe event ID (when the notification
		// payload carries one) before scheduling — Stripe delivery is
		// at-least-once, and the gateway's own webhook processing can
		// retry, so this hook can fire more than once for the same event.
		// Without this, a retried webhook would stack a redundant
		// verification on top of one already scheduled or completed.
		$event_id = self::notification_event_id( $notification );
		if ( ! WSR_Event_Ledger::claim( $event_id ) ) {
			return; // Already handled this exact event.
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

		// A legitimately-completing order is direct evidence any previously
		// flagged drift for it has self-healed. Shared with Pass A's own
		// no-drift re-check (WSR_Reconciler::auto_resolve_open_drift_for_order())
		// — same signal, different trigger (a webhook here, the daily scan
		// there).
		WSR_Reconciler::auto_resolve_open_drift_for_order( $order_id );
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

	/**
	 * The notification payload is the deserialized webhook JSON body,
	 * which — when it's an actual Stripe Event object — carries the
	 * event's own `id` at the top level. Not every call site is
	 * guaranteed to pass one; WSR_Event_Ledger::claim() no-ops safely
	 * when this returns empty.
	 */
	private static function notification_event_id( $notification ) {
		if ( is_object( $notification ) && isset( $notification->id ) ) {
			return (string) $notification->id;
		}
		if ( is_array( $notification ) && isset( $notification['id'] ) ) {
			return (string) $notification['id'];
		}
		return '';
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
