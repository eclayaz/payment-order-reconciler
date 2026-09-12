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

	const HOOK        = 'wsr_daily_reconciliation';
	const GROUP       = 'payment-order-reconciler';
	const LOCK_OPTION = 'wsr_run_lock';

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
				__( 'A reconciliation run is already in progress — try again in a few minutes.', 'payment-order-reconciler' )
			);
		}

		try {
			return WSR_Reconciler::run_all();
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Bug fix (independent review, round 2): the previous get_transient()
	 * then set_transient() pair was a real TOCTOU race, not just a
	 * theoretical one — reproduced with the literal two-worker
	 * interleaving (get/get/set/set), both proceed. add_option() is a
	 * genuine INSERT guarded by wp_options' unique option_name index, so
	 * it's atomic across concurrent requests/processes the way two
	 * separate get()-then-set() calls never can be.
	 */
	private static function try_acquire_lock() {
		$now = time();

		if ( add_option( self::LOCK_OPTION, $now, '', 'no' ) ) {
			return true;
		}

		// Someone already holds the lock — unless it's stale (a crashed
		// run that never released it), in which case reclaim it.
		// update_option() here isn't itself perfectly atomic against
		// another process reclaiming the same stale lock at the same
		// instant, but that's a far narrower window than the original
		// bug: worst case is two runs overlapping during crash recovery,
		// not on every normal concurrent trigger.
		$existing = get_option( self::LOCK_OPTION );
		if ( is_numeric( $existing ) && ( $now - (int) $existing ) > self::LOCK_TTL ) {
			update_option( self::LOCK_OPTION, $now, 'no' );
			return true;
		}

		return false;
	}

	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}
}
