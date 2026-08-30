<?php

class ReconcilerChecksTest extends WSR_TestCase {

	private function check_stuck_pending( $order, $pi ) {
		return $this->call_private( WSR_Reconciler::class, 'check_stuck_pending', array( $order, $pi ) );
	}

	private function check_wrongly_cancelled( $order, $pi ) {
		return $this->call_private( WSR_Reconciler::class, 'check_wrongly_cancelled', array( $order, $pi ) );
	}

	// --- stuck_pending -------------------------------------------------

	public function test_stuck_pending_basic_positive() {
		$order = $this->make_order( 'pending' );
		$drift = $this->check_stuck_pending( $order, $this->base_pi() );
		$this->assertIsArray( $drift );
		$this->assertSame( 'stuck_pending', $drift['drift_type'] );
		$this->assertSame( 'high', $drift['severity'] );
	}

	public function test_stuck_pending_on_hold_also_matches() {
		$order = $this->make_order( 'on-hold' );
		$drift = $this->check_stuck_pending( $order, $this->base_pi() );
		$this->assertIsArray( $drift );
	}

	public function test_stuck_pending_ignores_non_succeeded_intent() {
		$order = $this->make_order( 'pending' );
		$drift = $this->check_stuck_pending( $order, $this->base_pi( array( 'status' => 'requires_payment_method' ) ) );
		$this->assertNull( $drift );
	}

	public function test_stuck_pending_ignores_healthy_processing_order() {
		$order = $this->make_order( 'processing' );
		$drift = $this->check_stuck_pending( $order, $this->base_pi() );
		$this->assertNull( $drift, 'A processing order matching a succeeded PI is not drift at all.' );
	}

	public function test_stuck_pending_excluded_by_fresh_awaiting_action() {
		$order = $this->make_order( 'pending', array( '_stripe_payment_awaiting_action' => 'yes' ) );
		$drift = $this->check_stuck_pending( $order, $this->base_pi() );
		$this->assertNull( $drift, 'Fresh (<24h) awaiting-action meta means mid-3DS, not drift.' );
	}

	/**
	 * Forcing a genuinely stale date_modified via real DB writes proved
	 * unreliable in this test environment — WC_Order::save() re-stamps it
	 * to "now" regardless, and a raw SQL write is itself invisible to
	 * wc_get_order() behind HPOS's own order cache. A partial mock is the
	 * right tool here: it tests our logic's handling of the date value
	 * directly, without depending on WC/HPOS persistence quirks this
	 * plugin has no control over.
	 */
	private function order_with_mocked_dates( $status, array $meta, $date_modified, $date_created ) {
		$order = $this->getMockBuilder( WC_Order::class )
			->onlyMethods( array( 'get_status', 'get_meta', 'get_date_modified', 'get_date_created' ) )
			->getMock();

		$order->method( 'get_status' )->willReturn( $status );
		$order->method( 'get_meta' )->willReturnCallback(
			function ( $key ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);
		$order->method( 'get_date_modified' )->willReturn( $date_modified );
		$order->method( 'get_date_created' )->willReturn( $date_created );

		return $order;
	}

	public function test_stuck_pending_not_excluded_when_awaiting_action_is_stale() {
		$order = $this->order_with_mocked_dates(
			'pending',
			array( '_stripe_payment_awaiting_action' => 'yes' ),
			new WC_DateTime( '-2 days' ),
			new WC_DateTime( '-2 days' )
		);

		$drift = $this->check_stuck_pending( $order, $this->base_pi() );
		$this->assertIsArray( $drift, 'A >24h-stale awaiting-action flag must not permanently hide real drift — matches the gateway\'s own time bound.' );
	}

	public function test_stuck_pending_falls_back_to_date_created_when_date_modified_is_absent() {
		// The defensive fallback path: if date_modified is ever genuinely
		// null, the check must fall back to date_created rather than
		// treating a falsy date as "must be recent" (which would silently
		// disable the exclusion for exactly the freshest, most legitimate
		// mid-3DS orders — the opposite of the intended safety behavior).
		$order = $this->order_with_mocked_dates(
			'pending',
			array( '_stripe_payment_awaiting_action' => 'yes' ),
			null,
			new WC_DateTime( '-2 days' )
		);

		$drift = $this->check_stuck_pending( $order, $this->base_pi() );
		$this->assertIsArray( $drift, 'With date_modified absent and date_created 2 days old, must fall back to date_created and conclude "stale" — not silently exclude forever.' );
	}

	public function test_stuck_pending_excluded_when_date_modified_absent_but_date_created_is_fresh() {
		$order = $this->order_with_mocked_dates(
			'pending',
			array( '_stripe_payment_awaiting_action' => 'yes' ),
			null,
			new WC_DateTime( 'now' )
		);

		$drift = $this->check_stuck_pending( $order, $this->base_pi() );
		$this->assertNull( $drift, 'A brand-new order with no date_modified yet must use its (fresh) date_created — this is the real-world shape of a genuinely fresh, legitimately mid-3DS order.' );
	}

	public function test_stuck_pending_open_dispute_downgrades_to_info_not_excluded() {
		$order = $this->make_order( 'pending' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'dispute' => array( 'status' => 'needs_response' ) ) ) );
		$drift = $this->check_stuck_pending( $order, $pi );
		$this->assertIsArray( $drift, 'Open dispute must still be detected, not silently excluded.' );
		$this->assertSame( 'info', $drift['severity'] );
	}

	public function test_stuck_pending_closed_won_dispute_is_normal_severity() {
		$order = $this->make_order( 'pending' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'dispute' => array( 'status' => 'won' ) ) ) );
		$drift = $this->check_stuck_pending( $order, $pi );
		$this->assertIsArray( $drift );
		$this->assertSame( 'high', $drift['severity'], 'A resolved (won) dispute is not "currently disputed" — must not stay downgraded forever.' );
	}

	public function test_stuck_pending_open_radar_review_downgrades_to_info() {
		$order = $this->make_order( 'pending' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'review' => array( 'id' => 'prv_test', 'open' => true ) ) ) );
		$drift = $this->check_stuck_pending( $order, $pi );
		$this->assertIsArray( $drift, 'Open Radar review must still be detected, not excluded.' );
		$this->assertSame( 'info', $drift['severity'] );
	}

	public function test_stuck_pending_no_review_field_is_normal_severity() {
		// Stripe clears charge.review to null once a review closes — this
		// is the case where it's simply absent (resolved or never held).
		$order = $this->make_order( 'pending' );
		$drift = $this->check_stuck_pending( $order, $this->base_pi() );
		$this->assertIsArray( $drift );
		$this->assertSame( 'high', $drift['severity'], 'No review field present must not be treated as "currently under review."' );
	}

	// --- wrongly_cancelled_paid ------------------------------------------

	public function test_wrongly_cancelled_basic_positive() {
		$order = $this->make_order( 'cancelled' );
		$drift = $this->check_wrongly_cancelled( $order, $this->base_pi() );
		$this->assertIsArray( $drift );
		$this->assertSame( 'wrongly_cancelled_paid', $drift['drift_type'] );
		$this->assertSame( 'critical', $drift['severity'] );
	}

	public function test_wrongly_cancelled_ignores_non_cancelled_order() {
		$order = $this->make_order( 'processing' );
		$drift = $this->check_wrongly_cancelled( $order, $this->base_pi() );
		$this->assertNull( $drift );
	}

	public function test_wrongly_cancelled_excludes_uncaptured_charge() {
		$order = $this->make_order( 'cancelled' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'captured' => false ) ) );
		$drift = $this->check_wrongly_cancelled( $order, $pi );
		$this->assertNull( $drift, 'An uncaptured authorization on a cancelled order is a normal authorize-then-cancel flow, not wrongful cancellation.' );
	}

	public function test_wrongly_cancelled_excludes_fully_refunded() {
		$order = $this->make_order( 'cancelled' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'amount_refunded' => 1000 ) ) );
		$drift = $this->check_wrongly_cancelled( $order, $pi );
		$this->assertNull( $drift );
	}

	public function test_wrongly_cancelled_flags_partial_refund() {
		$order = $this->make_order( 'cancelled' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'amount_refunded' => 500 ) ) );
		$drift = $this->check_wrongly_cancelled( $order, $pi );
		$this->assertIsArray( $drift, 'Partially refunded is still drift — only a *full* refund explains a cancelled-but-paid state.' );
	}

	public function test_wrongly_cancelled_excludes_lost_dispute() {
		$order = $this->make_order( 'cancelled' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'dispute' => array( 'status' => 'lost' ) ) ) );
		$drift = $this->check_wrongly_cancelled( $order, $pi );
		$this->assertNull( $drift, 'A lost dispute is a legitimate reason for cancelled/failed, not wrongful cancellation.' );
	}

	public function test_wrongly_cancelled_open_dispute_downgrades_to_info() {
		$order = $this->make_order( 'cancelled' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'dispute' => array( 'status' => 'under_review' ) ) ) );
		$drift = $this->check_wrongly_cancelled( $order, $pi );
		$this->assertIsArray( $drift );
		$this->assertSame( 'info', $drift['severity'] );
	}

	public function test_wrongly_cancelled_won_dispute_is_critical_not_excluded() {
		$order = $this->make_order( 'cancelled' );
		$pi    = $this->base_pi( array( 'latest_charge' => array( 'dispute' => array( 'status' => 'won' ) ) ) );
		$drift = $this->check_wrongly_cancelled( $order, $pi );
		$this->assertIsArray( $drift, 'A won dispute does not excuse a wrongly-cancelled-while-paid order — only a lost one does.' );
		$this->assertSame( 'critical', $drift['severity'] );
	}
}
