<?php

class HookListenerTest extends WSR_TestCase {

	protected function tearDown(): void {
		$this->set_private_static_property( WSR_Fixer::class, 'applying_fix', false );
		parent::tearDown();
	}

	private function is_dispute_notification( $notification ) {
		return $this->call_private( WSR_Hook_Listener::class, 'is_dispute_notification', array( $notification ) );
	}

	private function notification_event_id( $notification ) {
		return $this->call_private( WSR_Hook_Listener::class, 'notification_event_id', array( $notification ) );
	}

	private function auto_resolve_open_drift( $order_id ) {
		return $this->call_private( WSR_Hook_Listener::class, 'auto_resolve_open_drift', array( $order_id ) );
	}

	// --- is_dispute_notification -------------------------------------------

	public function test_dispute_notification_detected_from_object() {
		$notification = (object) array( 'type' => 'charge.dispute.created' );
		$this->assertTrue( $this->is_dispute_notification( $notification ) );
	}

	public function test_dispute_notification_detected_from_array() {
		$this->assertTrue( $this->is_dispute_notification( array( 'type' => 'charge.dispute.closed' ) ) );
	}

	public function test_non_dispute_notification_is_not_flagged() {
		$notification = (object) array( 'type' => 'payment_intent.payment_failed' );
		$this->assertFalse( $this->is_dispute_notification( $notification ) );
	}

	public function test_null_notification_is_not_a_dispute() {
		$this->assertFalse( $this->is_dispute_notification( null ) );
	}

	// --- notification_event_id ----------------------------------------------

	public function test_extracts_event_id_from_object() {
		$notification = (object) array(
			'id'   => 'evt_abc123',
			'type' => 'payment_intent.payment_failed',
		);
		$this->assertSame( 'evt_abc123', $this->notification_event_id( $notification ) );
	}

	public function test_extracts_event_id_from_array() {
		$this->assertSame( 'evt_xyz', $this->notification_event_id( array( 'id' => 'evt_xyz' ) ) );
	}

	public function test_missing_event_id_returns_empty_string() {
		$this->assertSame( '', $this->notification_event_id( (object) array( 'type' => 'x' ) ) );
		$this->assertSame( '', $this->notification_event_id( null ) );
	}

	// --- on_webhook_payment_error: dispute filtering + ledger dedup --------

	public function test_dispute_notification_does_not_schedule_verification() {
		$order        = $this->make_order( 'on-hold' );
		$notification = (object) array( 'id' => 'evt_dispute1', 'type' => 'charge.dispute.created' );

		WSR_Hook_Listener::on_webhook_payment_error( $order, $notification );

		$this->assertFalse(
			as_has_scheduled_action( WSR_Hook_Listener::VERIFY_HOOK, array( 'order_id' => $order->get_id() ), WSR_Scheduler::GROUP ),
			'A dispute notification must never trigger a verification schedule.'
		);
	}

	public function test_webhook_error_schedules_verification_once() {
		$order        = $this->make_order( 'pending' );
		$notification = (object) array( 'id' => 'evt_realfailure1', 'type' => 'payment_intent.payment_failed' );

		WSR_Hook_Listener::on_webhook_payment_error( $order, $notification );

		$this->assertTrue(
			(bool) as_has_scheduled_action( WSR_Hook_Listener::VERIFY_HOOK, array( 'order_id' => $order->get_id() ), WSR_Scheduler::GROUP )
		);
	}

	public function test_webhook_error_with_same_event_id_is_deduped_by_the_ledger() {
		$order        = $this->make_order( 'pending' );
		$notification = (object) array( 'id' => 'evt_retry1', 'type' => 'payment_intent.payment_failed' );

		// First call claims the event and schedules.
		WSR_Hook_Listener::on_webhook_payment_error( $order, $notification );
		// A retried webhook for the exact same event must not stack a
		// second scheduled action.
		WSR_Hook_Listener::on_webhook_payment_error( $order, $notification );

		global $wpdb;
		$claims = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wsr_event_ledger WHERE event_id = %s", 'evt_retry1' ) );
		$this->assertSame( 1, $claims, 'The event ledger must only hold one claim row for a retried event.' );
	}

	// --- on_payment_complete: auto-resolve + re-entrancy guard --------------

	public function test_payment_complete_auto_resolves_open_drift_for_that_order() {
		$order = $this->make_order( 'pending' );
		$id    = $this->insert_drift_row( array( 'order_id' => $order->get_id(), 'status' => 'open' ) );

		WSR_Hook_Listener::on_payment_complete( $order->get_id() );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE id = %d", $id ) );
		$this->assertSame( 'fixed', $row->status );
		$this->assertSame( 'system', $row->resolved_by );
	}

	public function test_payment_complete_does_not_touch_dismissed_or_already_fixed_rows() {
		$order       = $this->make_order( 'pending' );
		$dismissed   = $this->insert_drift_row( array( 'order_id' => $order->get_id(), 'status' => 'dismissed' ) );
		$fixed       = $this->insert_drift_row( array( 'order_id' => $order->get_id(), 'status' => 'fixed', 'resolved_by' => '5' ) );

		WSR_Hook_Listener::on_payment_complete( $order->get_id() );

		global $wpdb;
		$dismissed_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE id = %d", $dismissed ) );
		$fixed_row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE id = %d", $fixed ) );

		$this->assertSame( 'dismissed', $dismissed_row->status );
		$this->assertSame( '5', $fixed_row->resolved_by, 'Must not overwrite an existing fix\'s attribution.' );
	}

	public function test_payment_complete_skips_when_fixer_is_applying_its_own_fix() {
		$order = $this->make_order( 'pending' );
		$id    = $this->insert_drift_row( array( 'order_id' => $order->get_id(), 'status' => 'open' ) );

		$this->set_private_static_property( WSR_Fixer::class, 'applying_fix', true );
		WSR_Hook_Listener::on_payment_complete( $order->get_id() );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE id = %d", $id ) );
		$this->assertSame( 'open', $row->status, 'Must defer to the fixer\'s own authoritative write, not resolve as "system" out from under it.' );
	}

	// --- auto_resolve_open_drift only touches order-linked rows -------------

	public function test_auto_resolve_never_touches_orphaned_charge_rows() {
		// order_id is NULL for orphaned_charge/needs_review by design —
		// this hook fires with a real order_id, so those rows are
		// structurally excluded by the WHERE clause, not by extra logic.
		$id = $this->insert_drift_row( array( 'order_id' => null, 'drift_type' => 'orphaned_charge', 'status' => 'open' ) );

		$this->auto_resolve_open_drift( 12345 );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE id = %d", $id ) );
		$this->assertSame( 'open', $row->status );
	}
}
