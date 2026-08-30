<?php
/**
 * ActionScheduler wiring for the single daily reconciliation job, plus the
 * "run in progress" guard shared with the manual "Run now" button — so a
 * scheduled trigger and a manual click can't overlap and double-process
 * the same PaymentIntents (see TECHNICAL_SPEC.md's cadence section for why
 * the earlier two-cadence design was cut to one).
 *
 * Registration is hooked to `action_scheduler_init`, not a generic `init`
 * guess about load order — this is the same defensive idiom the gateway
 * plugin itself uses internally for its own deferred webhook handling
 * (`if ( ! did_action( 'action_scheduler_init' ) || ! function_exists(...) )`),
 * confirmed against its source during an earlier build-order step's review.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Scheduler {

	const HOOK           = 'wsr_daily_reconciliation';
	const GROUP          = 'woo-stripe-reconcile';
	const LOCK_TRANSIENT = 'wsr_run_lock';

	/**
	 * Generous upper bound for one full run (Pass A + Pass B + webhook
	 * health-check) on a large store — a stuck/crashed run self-expires
	 * out of this lock rather than blocking every future run forever.
	 */
	const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'action_scheduler_init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
	}

	/**
	 * Registers the recurring daily action once, if it isn't already
	 * scheduled. Safe to call on every request — as_next_scheduled_action()
	 * is the guard against creating duplicates.
	 */
	public static function maybe_schedule() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return; // ActionScheduler not actually available this request — nothing to do.
		}

		if ( as_next_scheduled_action( self::HOOK ) ) {
			return;
		}

		// First run an hour out, then every 24 hours — avoids a reconciliation
		// run firing immediately on activation, before a merchant has had a
		// chance to configure and validate an API key.
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
	}

	/**
	 * The ActionScheduler callback. Thin wrapper around run_guarded() —
	 * kept separate so the hook signature (no return value expected) is
	 * distinct from run_guarded()'s (used directly by the manual button,
	 * which does care about the return value).
	 */
	public static function run_scheduled() {
		self::run_guarded();
	}

	/**
	 * Runs WSR_Reconciler::run_all() under the shared lock. Returns a
	 * WP_Error immediately, without doing any work, if a run is already in
	 * progress — used by both the scheduled callback and the manual
	 * "Run now" button so the two can never overlap.
	 *
	 * @return array|WP_Error
	 */
	public static function run_guarded() {
		if ( ! self::try_acquire_lock() ) {
			return new WP_Error(
				'wsr_run_in_progress',
				__( 'A reconciliation run is already in progress — try again in a few minutes.', 'woo-stripe-reconcile' )
			);
		}

		try {
			return WSR_Reconciler::run_all();
		} finally {
			self::release_lock();
		}
	}

	private static function try_acquire_lock() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );
		return true;
	}

	private static function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}
}
