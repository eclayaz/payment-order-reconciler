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

	public function test_resolve_via_signature_parses_leading_order_id() {
		$order = $this->make_order( 'pending' );
		$maps  = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );

		$metadata = array( 'signature' => $order->get_id() . ':deadbeef' );
		$this->assertSame( $order->get_id(), $this->resolve_via_metadata( $metadata, $maps ) );
	}

	public function test_resolve_via_signature_rejects_an_order_id_outside_the_loaded_map() {
		$maps     = $this->batch_load_order_maps( time() - DAY_IN_SECONDS );
		$metadata = array( 'signature' => '999999:deadbeef' );
		$this->assertNull( $this->resolve_via_metadata( $metadata, $maps ) );
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
}
