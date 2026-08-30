<?php

class ReconcilerResolutionTest extends WSR_TestCase {

	private function batch_load_order_maps( $window_start ) {
		return $this->call_private( WSR_Reconciler::class, 'batch_load_order_maps', array( $window_start ) );
	}

	private function resolve_via_metadata( array $metadata, array $order_maps ) {
		return $this->call_private( WSR_Reconciler::class, 'resolve_via_metadata', array( $metadata, $order_maps ) );
	}

	private function resolve_via_intent_map( $pi_id, array $order_maps ) {
		return $this->call_private( WSR_Reconciler::class, 'resolve_via_intent_map', array( $pi_id, $order_maps ) );
	}

	private function normalize_host( $url_or_host ) {
		return $this->call_private( WSR_Reconciler::class, 'normalize_host', array( $url_or_host ) );
	}

	private function handle_unresolved( array $pi, array $metadata, array &$summary ) {
		return $this->call_private( WSR_Reconciler::class, 'handle_unresolved', array( $pi, $metadata, &$summary ) );
	}

	// --- batch_load_order_maps -------------------------------------------

	public function test_batch_load_indexes_orders_by_all_four_identifiers() {
		$order = $this->make_order(
			'pending',
			array(
				'_stripe_intent_id'               => 'pi_batchtest',
				'_stripe_checkout_session_id'      => 'cs_batchtest',
			)
		);

		$maps = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );

		$this->assertArrayHasKey( $order->get_id(), $maps['by_id'] );
		$this->assertSame( $order->get_id(), $maps['by_order_key'][ $order->get_order_key() ] );
		$this->assertSame( $order->get_id(), $maps['by_session_id']['cs_batchtest'] );
		$this->assertSame( $order->get_id(), $maps['by_intent_id']['pi_batchtest'] );
	}

	public function test_batch_load_indexes_setup_intent_into_the_same_intent_map() {
		$order = $this->make_order( 'pending', array( '_stripe_setup_intent' => 'seti_batchtest' ) );
		$maps  = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		$this->assertSame( $order->get_id(), $maps['by_intent_id']['seti_batchtest'] );
	}

	public function test_batch_load_excludes_orders_outside_the_window() {
		$order = $this->make_order( 'pending' );
		$order->set_date_created( time() - 60 * DAY_IN_SECONDS );
		$order->save();

		$maps = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		$this->assertArrayNotHasKey( $order->get_id(), $maps['by_id'] );
	}

	public function test_batch_load_includes_healthy_orders_of_every_status() {
		// Not just pending/on-hold/cancelled — a 'processing' or
		// 'completed' order must also be loaded, so a PaymentIntent that
		// resolves to it is correctly recognized as "no drift," not
		// misclassified as unresolved.
		$order = $this->make_order( 'completed' );
		$maps  = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		$this->assertArrayHasKey( $order->get_id(), $maps['by_id'] );
	}

	// --- resolve_via_metadata --------------------------------------------

	/**
	 * Replicates the gateway's own get_order_signature() exactly (verified
	 * against the installed gateway's source), so tests can produce a
	 * signature that will actually pass hash verification.
	 */
	private function real_signature_for( WC_Order $order ) {
		$parts = array(
			absint( $order->get_id() ),
			$order->get_order_key(),
			$order->get_customer_id(),
			WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $order->get_currency() ),
		);
		return sprintf( '%d:%s', $order->get_id(), md5( implode( '-', $parts ) ) );
	}

	public function test_resolve_via_signature_with_a_valid_hash() {
		$order = $this->make_order( 'pending' );
		$maps  = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );

		$metadata = array( 'signature' => $this->real_signature_for( $order ) );
		$this->assertSame( $order->get_id(), $this->resolve_via_metadata( $metadata, $maps ) );
	}

	public function test_resolve_via_signature_rejects_an_order_id_outside_the_loaded_map() {
		$maps     = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		$metadata = array( 'signature' => '999999:deadbeef' );
		$this->assertNull( $this->resolve_via_metadata( $metadata, $maps ) );
	}

	public function test_resolve_via_signature_rejects_a_mismatched_hash() {
		// The actual bug (independent review, round 2): the leading order
		// ID alone was trusted blindly, with no verification that the
		// hash half of the signature actually belongs to *this* order.
		// Two stores (or staging + production) sharing one Stripe account
		// can have overlapping order IDs — a foreign PaymentIntent whose
		// metadata carries someone else's signature, but an order ID that
		// happens to match a real local order, must not resolve to it.
		$order = $this->make_order( 'pending' );
		$maps  = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );

		// Same leading order ID as a real local order, but a hash that
		// doesn't match it (as if it belonged to a different order/store
		// entirely, e.g. this order's ID happened to collide with one
		// from another Stripe-account-sharing site).
		$metadata = array( 'signature' => $order->get_id() . ':' . md5( 'not-this-order' ) );

		$this->assertNull(
			$this->resolve_via_metadata( $metadata, $maps ),
			'A hash that does not match the local order\'s own key/customer/amount must never resolve to it, even when the leading order ID matches.'
		);
	}

	public function test_resolve_via_signature_falls_back_to_order_key_on_hash_mismatch() {
		// A rejected signature match should not short-circuit the whole
		// resolution chain — order_key is a separate, independently
		// trustworthy fallback and must still be tried.
		$order = $this->make_order( 'pending' );
		$maps  = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );

		$metadata = array(
			'signature' => $order->get_id() . ':' . md5( 'wrong' ),
			'order_key' => $order->get_order_key(),
		);

		$this->assertSame( $order->get_id(), $this->resolve_via_metadata( $metadata, $maps ) );
	}

	public function test_resolve_via_order_key_fallback() {
		$order = $this->make_order( 'pending' );
		$maps  = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );

		$metadata = array( 'order_key' => $order->get_order_key() );
		$this->assertSame( $order->get_id(), $this->resolve_via_metadata( $metadata, $maps ) );
	}

	public function test_resolve_via_metadata_returns_null_when_nothing_matches() {
		$maps = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		$this->assertNull( $this->resolve_via_metadata( array(), $maps ) );
	}

	// --- resolve_via_intent_map -------------------------------------------

	public function test_resolve_via_intent_map() {
		$order = $this->make_order( 'pending', array( '_stripe_intent_id' => 'pi_maptest' ) );
		$maps  = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		$this->assertSame( $order->get_id(), $this->resolve_via_intent_map( 'pi_maptest', $maps ) );
	}

	// --- normalize_host ----------------------------------------------------

	public function test_normalize_host_strips_scheme_and_www() {
		$this->assertSame( 'example.com', $this->normalize_host( 'https://www.example.com' ) );
		$this->assertSame( 'example.com', $this->normalize_host( 'http://example.com' ) );
		$this->assertSame( 'example.com', $this->normalize_host( 'example.com' ) );
	}

	public function test_normalize_host_is_case_insensitive() {
		$this->assertSame( 'example.com', $this->normalize_host( 'HTTPS://WWW.EXAMPLE.COM' ) );
	}

	public function test_normalize_host_empty_input() {
		$this->assertSame( '', $this->normalize_host( '' ) );
	}

	// --- handle_unresolved -------------------------------------------------

	private function base_summary() {
		return array(
			'skipped_other_site' => 0,
			'needs_review'       => 0,
			'orphaned_charge_escalated' => 0,
		);
	}

	public function test_handle_unresolved_skips_charges_from_another_site() {
		$summary = $this->base_summary();
		$pi      = $this->base_pi();
		$this->handle_unresolved( $pi, array( 'site_url' => 'https://some-other-store.example' ), $summary );

		$this->assertSame( 1, $summary['skipped_other_site'] );
		$this->assertNull( $this->drift_log_row( $pi['id'] ) );
	}

	public function test_handle_unresolved_flags_needs_review_when_site_matches() {
		$summary = $this->base_summary();
		$pi      = $this->base_pi();
		$this->handle_unresolved( $pi, array( 'site_url' => home_url() ), $summary );

		$this->assertSame( 1, $summary['needs_review'] );
		$row = $this->drift_log_row( $pi['id'] );
		$this->assertSame( 'needs_review', $row->drift_type );
		$this->assertNull( $row->order_id );
	}

	public function test_handle_unresolved_flags_needs_review_when_site_url_absent() {
		// The Checkout Session / Adaptive Pricing gap: metadata can be
		// entirely absent — must not be silently dropped as "other site."
		$summary = $this->base_summary();
		$pi      = $this->base_pi();
		$this->handle_unresolved( $pi, array(), $summary );

		$this->assertSame( 1, $summary['needs_review'] );
	}

	public function test_handle_unresolved_site_match_is_host_normalized() {
		$summary = $this->base_summary();
		$pi      = $this->base_pi();
		// A www/scheme variant of the same host must still count as a match.
		$this->handle_unresolved( $pi, array( 'site_url' => 'http://www.' . wp_parse_url( home_url(), PHP_URL_HOST ) ), $summary );

		$this->assertSame( 1, $summary['needs_review'] );
		$this->assertSame( 0, $summary['skipped_other_site'] );
	}

	// --- process_payment_intent: the PI-status gate before flagging an orphan ---
	//
	// Bug (independent review, round 2): handle_unresolved() itself never
	// checked PaymentIntent status at all — an abandoned checkout
	// (requires_payment_method, requires_confirmation, canceled, etc.)
	// resolves to no local order for exactly the same reason a genuinely
	// lost payment does, but no charge was ever actually made. The guard
	// belongs one level up, in process_payment_intent(), which decides
	// whether to call handle_unresolved() at all.

	private function full_summary() {
		return array(
			'resolved_to_order'         => 0,
			'skipped_other_site'        => 0,
			'skipped_too_fresh'         => 0,
			'skipped_not_succeeded'     => 0,
			'needs_review'              => 0,
			'orphaned_charge_escalated' => 0,
			'stuck_pending_flagged'     => 0,
			'wrongly_cancelled_flagged' => 0,
			'no_drift'                  => 0,
			'new_alerts'                => array(),
		);
	}

	/**
	 * A real WSR_Stripe_Client instance (harmless — its constructor only
	 * stores the key, never makes a network call). Safe to pass here
	 * because every test below deliberately avoids the one code path
	 * (resolve_via_checkout_session_lookup) that would actually invoke it:
	 * either the PaymentIntent has no `id` at all, so the whole
	 * intent-map/session-lookup block is skipped by process_payment_intent
	 * itself, or resolution succeeds earlier via metadata.
	 */
	private function harmless_client() {
		return new WSR_Stripe_Client( 'rk_test_unused' );
	}

	private function process_payment_intent( array $pi, array $order_maps, array &$summary ) {
		return $this->call_private(
			WSR_Reconciler::class,
			'process_payment_intent',
			array( $pi, $order_maps, $this->harmless_client(), &$summary )
		);
	}

	public function test_unresolved_non_succeeded_pi_is_not_flagged_as_orphan() {
		$summary    = $this->full_summary();
		$order_maps = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		// No 'id' — deliberately, so the checkout-session-lookup branch
		// (which needs a real API call) is skipped entirely by
		// process_payment_intent's own `'' !== $pi_id` guard, isolating
		// just the status-gate logic under test.
		$pi = $this->base_pi(
			array(
				'id'       => '',
				'status'   => 'requires_payment_method',
				'metadata' => array( 'site_url' => home_url() ),
			)
		);

		$this->process_payment_intent( $pi, $order_maps, $summary );

		$this->assertSame( 1, $summary['skipped_not_succeeded'] );
		$this->assertSame( 0, $summary['needs_review'] );
		$this->assertSame( 0, $summary['orphaned_charge_escalated'] );

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wsr_drift_log" );
		$this->assertSame( 0, $count, 'An abandoned checkout must never produce a drift row at all.' );
	}

	public function test_unresolved_canceled_pi_is_not_flagged_as_orphan() {
		$summary    = $this->full_summary();
		$order_maps = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		$pi         = $this->base_pi(
			array(
				'id'       => '',
				'status'   => 'canceled',
				'metadata' => array( 'site_url' => home_url() ),
			)
		);

		$this->process_payment_intent( $pi, $order_maps, $summary );

		$this->assertSame( 1, $summary['skipped_not_succeeded'] );
	}

	public function test_unresolved_succeeded_pi_still_flags_needs_review() {
		// Regression guard: confirm the new status gate doesn't
		// accidentally block the genuinely-succeeded, genuinely-unresolved
		// case it's meant to still catch.
		$summary    = $this->full_summary();
		$order_maps = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		$pi         = $this->base_pi(
			array(
				'id'       => '',
				'status'   => 'succeeded',
				'metadata' => array( 'site_url' => home_url() ),
			)
		);

		$this->process_payment_intent( $pi, $order_maps, $summary );

		$this->assertSame( 0, $summary['skipped_not_succeeded'] );
		$this->assertSame( 1, $summary['needs_review'] );
	}
}
