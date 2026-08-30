<?php

class SchedulerTest extends WSR_TestCase {

	protected function tearDown(): void {
		delete_transient( WSR_Scheduler::LOCK_TRANSIENT );
		parent::tearDown();
	}

	public function test_run_guarded_refuses_to_run_when_already_locked() {
		set_transient( WSR_Scheduler::LOCK_TRANSIENT, time(), 900 );

		$result = WSR_Scheduler::run_guarded();

		$this->assertWPError( $result );
		$this->assertSame( 'wsr_run_in_progress', $result->get_error_code() );
	}

	public function test_run_guarded_does_not_touch_a_lock_it_did_not_acquire() {
		$original = time() - 100; // A distinguishable value.
		set_transient( WSR_Scheduler::LOCK_TRANSIENT, $original, 900 );

		WSR_Scheduler::run_guarded();

		$this->assertSame( $original, get_transient( WSR_Scheduler::LOCK_TRANSIENT ), 'Refusing to run must not clear or alter a lock this call didn\'t set itself.' );
	}

	public function test_run_guarded_acquires_and_releases_the_lock_around_a_real_run() {
		// No API key configured — run_all()/run_pass_a() will return a
		// WP_Error internally, but run_guarded() itself must still
		// complete normally and release the lock afterward.
		$this->assertFalse( (bool) get_transient( WSR_Scheduler::LOCK_TRANSIENT ) );

		WSR_Scheduler::run_guarded();

		$this->assertFalse(
			(bool) get_transient( WSR_Scheduler::LOCK_TRANSIENT ),
			'The lock must be released once the run completes, even when the underlying reconciliation itself errors.'
		);
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
}
