<?php

class FixerTest extends WSR_TestCase {

	private function is_locked( WC_Order $order ) {
		return $this->call_private( WSR_Fixer::class, 'is_locked', array( $order ) );
	}

	/**
	 * Bug fix (independent review, round 2): apply_fix() now re-fetches the
	 * PaymentIntent live from Stripe before acting (see class-wsr-fixer.php).
	 * A partial mock of WSR_Stripe_Client — overriding only get() — lets
	 * these tests control that live response without any real network call,
	 * exactly the way ReconcilerChecksTest mocks WC_Order for date fields.
	 */
	private function client_returning( array $pi ) {
		$client = $this->getMockBuilder( WSR_Stripe_Client::class )
			->setConstructorArgs( array( 'rk_test_unused' ) )
			->onlyMethods( array( 'get' ) )
			->getMock();
		$client->method( 'get' )->willReturn( $pi );
		return $client;
	}

	// --- is_locked() — the lock-expiry parsing the third review got wrong -----

	public function test_is_locked_false_when_no_lock_meta() {
		$order = $this->make_order( 'pending' );
		$this->assertFalse( $this->is_locked( $order ) );
	}

	public function test_is_locked_true_for_a_future_expiry() {
		$order = $this->make_order( 'pending' );
		$order->update_meta_data( '_stripe_lock_payment', (string) ( time() + 5 * MINUTE_IN_SECONDS ) );
		$order->save();
		$this->assertTrue( $this->is_locked( $order ) );
	}

	public function test_is_locked_false_for_an_expired_lock() {
		$order = $this->make_order( 'pending' );
		$order->update_meta_data( '_stripe_lock_payment', (string) ( time() - MINUTE_IN_SECONDS ) );
		$order->save();
		$this->assertFalse( $this->is_locked( $order ), 'An expired lock timestamp must not block a fix forever.' );
	}

	public function test_is_locked_handles_pipe_delimited_format() {
		// The gateway's own comment: "Format is: {expiry_timestamp}" but
		// the parsing code explodes on '|' defensively — confirm we match
		// that exactly, including when only the leading segment is used.
		$order = $this->make_order( 'pending' );
		$order->update_meta_data( '_stripe_lock_payment', ( time() + 300 ) . '|extra-context' );
		$order->save();
		$this->assertTrue( $this->is_locked( $order ) );
	}

	// --- apply_fix() validation paths (no Stripe call needed) -----------------

	public function test_apply_fix_rejects_unknown_drift_id() {
		$result = WSR_Fixer::apply_fix( 999999, 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'wsr_not_found', $result->get_error_code() );
	}

	public function test_apply_fix_rejects_already_fixed_row() {
		$id = $this->insert_drift_row( array( 'status' => 'fixed' ) );
		$result = WSR_Fixer::apply_fix( $id, 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'wsr_not_open', $result->get_error_code() );
	}

	public function test_apply_fix_rejects_needs_review_type() {
		$id = $this->insert_drift_row( array( 'drift_type' => 'needs_review', 'order_id' => null ) );
		$result = WSR_Fixer::apply_fix( $id, 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'wsr_no_fix_for_type', $result->get_error_code() );
	}

	public function test_apply_fix_rejects_orphaned_charge_type() {
		$id = $this->insert_drift_row( array( 'drift_type' => 'orphaned_charge', 'order_id' => null ) );
		$result = WSR_Fixer::apply_fix( $id, 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'wsr_no_fix_for_type', $result->get_error_code() );
	}

	public function test_apply_fix_rejects_info_severity_disputed_row() {
		// Bug fix (independent review, round 2): this must reflect the
		// *live* re-fetched dispute state, not the stored row's own
		// severity snapshot — see class-wsr-fixer.php's apply_fix(). The
		// row itself is stored at 'high' (as detection would leave it
		// before a dispute opened); the live PI carries the open dispute.
		$order = $this->make_order( 'pending' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'dispute' => array( 'status' => 'needs_response' ) ) ) );
		$id    = $this->insert_drift_row(
			array(
				'order_id'         => $order->get_id(),
				'stripe_object_id' => $pi['id'],
				'drift_type'       => 'stuck_pending',
				'severity'         => 'high',
			)
		);
		$result = WSR_Fixer::apply_fix( $id, 1, $this->client_returning( $pi ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wsr_disputed_no_fix', $result->get_error_code(), 'A currently-disputed/under-review match must never be auto-fixed, enforced again at fix time, not just at detection time.' );

		$row = $this->drift_log_row( $pi['id'] );
		$this->assertSame( 'info', $row->severity, 'The row\'s stored severity must be refreshed to info immediately, not left stale until the next scheduled run.' );
	}

	public function test_apply_fix_self_heals_when_no_longer_drifting_live() {
		// Another process (a webhook, or a prior fix) already resolved this
		// between detection and now — the live re-check must find no drift
		// and mark the row fixed automatically rather than acting on stale
		// data or leaving the row open forever.
		$order = $this->make_order( 'processing' );
		$pi    = $this->base_pi();
		$id    = $this->insert_drift_row(
			array(
				'order_id'         => $order->get_id(),
				'stripe_object_id' => $pi['id'],
				'drift_type'       => 'stuck_pending',
				'severity'         => 'high',
			)
		);
		$result = WSR_Fixer::apply_fix( $id, 1, $this->client_returning( $pi ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wsr_already_resolved', $result->get_error_code() );

		$row = $this->drift_log_row( $pi['id'] );
		$this->assertSame( 'fixed', $row->status );
		$this->assertSame( 'system', $row->resolved_by, 'Self-heal is attributed to the system, not the admin who merely clicked Fix on stale data.' );
	}

	public function test_apply_fix_refuses_when_live_fetch_fails() {
		$order = $this->make_order( 'pending' );
		$id    = $this->insert_drift_row(
			array(
				'order_id'   => $order->get_id(),
				'drift_type' => 'stuck_pending',
				'severity'   => 'high',
			)
		);
		$client = $this->getMockBuilder( WSR_Stripe_Client::class )
			->setConstructorArgs( array( 'rk_test_unused' ) )
			->onlyMethods( array( 'get' ) )
			->getMock();
		$client->method( 'get' )->willReturn( new WP_Error( 'wsr_http_error', 'Stripe unreachable' ) );

		$result = WSR_Fixer::apply_fix( $id, 1, $client );
		$this->assertWPError( $result );
		$this->assertSame( 'wsr_fix_verify_failed', $result->get_error_code(), 'Must refuse to act on a stale snapshot when live verification itself fails, not fall back to the cached row.' );
	}

	public function test_apply_fix_rejects_missing_order() {
		$id = $this->insert_drift_row( array( 'order_id' => 999999, 'drift_type' => 'stuck_pending', 'severity' => 'high' ) );
		$result = WSR_Fixer::apply_fix( $id, 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'wsr_order_missing', $result->get_error_code() );
	}

	public function test_apply_fix_rejects_a_locked_order() {
		$order = $this->make_order( 'pending' );
		$order->update_meta_data( '_stripe_lock_payment', (string) ( time() + 300 ) );
		$order->save();
		$id = $this->insert_drift_row( array( 'order_id' => $order->get_id(), 'drift_type' => 'stuck_pending', 'severity' => 'high' ) );

		$result = WSR_Fixer::apply_fix( $id, 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'wsr_locked', $result->get_error_code() );
	}

	// --- apply_fix() happy path -------------------------------------------

	public function test_apply_fix_happy_path_stuck_pending() {
		$order = $this->make_order( 'pending' );
		$pi    = $this->base_pi();
		$id    = $this->insert_drift_row(
			array(
				'order_id'         => $order->get_id(),
				'stripe_object_id' => $pi['id'],
				'drift_type'       => 'stuck_pending',
				'severity'         => 'high',
			)
		);

		$result = WSR_Fixer::apply_fix( $id, 42, $this->client_returning( $pi ) );
		$this->assertTrue( $result );

		$order = wc_get_order( $order->get_id() );
		$this->assertNotFalse( $order->get_date_paid(), 'payment_complete() must have set date_paid.' );
		$this->assertSame( 'yes', $order->get_meta( '_stripe_charge_captured' ) );
		$this->assertSame( '', $order->get_meta( '_stripe_payment_awaiting_action' ), 'Must be cleared, not left stale.' );
		// Bug fix (independent review, round 2): must record the live
		// PaymentIntent's real charge ID, not the empty get_transaction_id().
		$this->assertSame( $pi['latest_charge']['id'], $order->get_transaction_id() );

		$applied = $order->get_meta( '_wsr_fix_applied' );
		$this->assertIsArray( $applied );

		$row = $this->drift_log_row( $applied[0] );
		$this->assertSame( 'fixed', $row->status );
		$this->assertSame( '42', $row->resolved_by, 'Must record the actual admin user ID, not "system".' );
		$this->assertNotEmpty( $row->resolved_at );
	}

	public function test_apply_fix_happy_path_wrongly_cancelled() {
		$order = $this->make_order( 'cancelled' );
		$pi    = $this->base_pi();
		$id    = $this->insert_drift_row(
			array(
				'order_id'         => $order->get_id(),
				'stripe_object_id' => $pi['id'],
				'drift_type'       => 'wrongly_cancelled_paid',
				'severity'         => 'critical',
			)
		);

		$result = WSR_Fixer::apply_fix( $id, 7, $this->client_returning( $pi ) );
		$this->assertTrue( $result );

		$order = wc_get_order( $order->get_id() );
		$this->assertNotSame( 'cancelled', $order->get_status(), 'The cancelled-while-paid order must move out of cancelled.' );
	}

	public function test_apply_fix_tracks_idempotency_guard_without_duplicating() {
		$order = $this->make_order( 'pending' );
		$order->update_meta_data( '_wsr_fix_applied', array( 'pi_already_fixed_once' ) );
		$order->save();

		$pi = $this->base_pi( array( 'id' => 'pi_already_fixed_once' ) );
		$id = $this->insert_drift_row(
			array(
				'order_id'         => $order->get_id(),
				'stripe_object_id' => 'pi_already_fixed_once',
				'drift_type'       => 'stuck_pending',
				'severity'         => 'high',
			)
		);

		WSR_Fixer::apply_fix( $id, 1, $this->client_returning( $pi ) );

		$order   = wc_get_order( $order->get_id() );
		$applied = $order->get_meta( '_wsr_fix_applied' );
		$this->assertCount( 1, $applied, 'Re-fixing the same stripe_object_id must not duplicate the guard entry.' );
	}
}
