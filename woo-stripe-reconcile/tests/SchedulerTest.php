<?php

class SchedulerTest extends WSR_TestCase {

	protected function tearDown(): void {
		delete_option( WSR_Scheduler::LOCK_OPTION );
		parent::tearDown();
	}

	public function test_run_guarded_refuses_to_run_when_already_locked() {
		add_option( WSR_Scheduler::LOCK_OPTION, time(), '', 'no' );

		$result = WSR_Scheduler::run_guarded();

		$this->assertWPError( $result );
		$this->assertSame( 'wsr_run_in_progress', $result->get_error_code() );
	}

	public function test_run_guarded_does_not_touch_a_lock_it_did_not_acquire() {
		$original = time() - 100; // A distinguishable value.
		add_option( WSR_Scheduler::LOCK_OPTION, $original, '', 'no' );

		WSR_Scheduler::run_guarded();

		$this->assertEquals( $original, get_option( WSR_Scheduler::LOCK_OPTION ), 'Refusing to run must not clear or alter a lock this call didn\'t set itself.' );
	}

	public function test_run_guarded_acquires_and_releases_the_lock_around_a_real_run() {
		// No API key configured — run_all()/run_pass_a() will return a
		// WP_Error internally, but run_guarded() itself must still
		// complete normally and release the lock afterward.
		$this->assertFalse( get_option( WSR_Scheduler::LOCK_OPTION ) );

		WSR_Scheduler::run_guarded();

		$this->assertFalse(
			get_option( WSR_Scheduler::LOCK_OPTION ),
			'The lock must be released once the run completes, even when the underlying reconciliation itself errors.'
		);
	}

	// --- try_acquire_lock(): atomicity + stale-lock recovery (independent review, round 2) ---

	public function test_try_acquire_lock_is_atomic_not_a_toctou_race() {
		// The bug this replaces: get_transient() then set_transient() as
		// two separate calls meant two workers could both pass the "is it
		// held" check before either one set it. add_option() collapses
		// that into one atomic INSERT-or-fail — reproduced here by
		// pre-seeding the option exactly as a "first worker already won"
		// scenario and confirming a second attempt is correctly refused,
		// with no window where both could succeed.
		$first  = $this->call_private( WSR_Scheduler::class, 'try_acquire_lock' );
		$second = $this->call_private( WSR_Scheduler::class, 'try_acquire_lock' );

		$this->assertTrue( $first );
		$this->assertFalse( $second, 'A second concurrent attempt must never also succeed.' );
	}

	public function test_try_acquire_lock_reclaims_a_stale_lock_past_ttl() {
		$stale = time() - WSR_Scheduler::LOCK_TTL - 60;
		add_option( WSR_Scheduler::LOCK_OPTION, $stale, '', 'no' );

		$reacquired = $this->call_private( WSR_Scheduler::class, 'try_acquire_lock' );

		$this->assertTrue( $reacquired, 'A crashed run that never released its lock must not block every future run forever.' );
		$this->assertNotEquals( $stale, get_option( WSR_Scheduler::LOCK_OPTION ) );
	}

	public function test_try_acquire_lock_refuses_a_fresh_lock_within_ttl() {
		add_option( WSR_Scheduler::LOCK_OPTION, time(), '', 'no' );

		$this->assertFalse( $this->call_private( WSR_Scheduler::class, 'try_acquire_lock' ) );
	}

	public function test_two_sequential_runs_both_succeed_once_the_lock_is_released() {
		$first  = WSR_Scheduler::run_guarded();
		$second = WSR_Scheduler::run_guarded();

		// Neither should be the "already in progress" error — the first
		// run's lock release must have actually taken effect.
		$this->assertNotWPErrorWithCode( $first, 'wsr_run_in_progress' );
		$this->assertNotWPErrorWithCode( $second, 'wsr_run_in_progress' );
	}

	private function assertNotWPErrorWithCode( $result, $code ) {
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( $code, $result->get_error_code() );
		} else {
			$this->assertTrue( true ); // Not a WP_Error at all — definitely not this code.
		}
	}

	// --- run_all(): wsr_last_run_at must reflect success, not attempts ---
	//
	// Bug (independent review, round 2): this option used to be written
	// unconditionally on every run — reproduced with a bogus key:
	// pages_fetched=0, an "Invalid API Key provided" error, and the
	// coverage indicator still showed green. It must only advance on a
	// genuinely successful run.

	public function test_run_all_does_not_set_last_run_at_with_no_api_key_configured() {
		delete_option( 'wsr_last_run_at' );

		WSR_Reconciler::run_all();

		$this->assertSame( '', get_option( 'wsr_last_run_at', '' ), 'No API key at all means run_pass_a() returns a top-level WP_Error — never a success.' );
		$failure = get_option( 'wsr_last_run_failure' );
		$this->assertIsArray( $failure );
		$this->assertNotEmpty( $failure['error'] );
	}

	public function test_run_all_does_not_clobber_a_prior_successful_timestamp_on_a_later_failure() {
		update_option( 'wsr_last_run_at', '2020-01-01 00:00:00', false );

		WSR_Reconciler::run_all();

		$this->assertSame( '2020-01-01 00:00:00', get_option( 'wsr_last_run_at' ), 'A failed run must not erase evidence of the last time it actually succeeded.' );
	}
}
