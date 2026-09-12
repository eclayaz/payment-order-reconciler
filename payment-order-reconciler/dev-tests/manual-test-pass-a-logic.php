<?php
/**
 * Throwaway manual test script for WSR_Reconciler's core logic — run via
 * `wp eval-file bin/manual-test-pass-a-logic.php` inside the wp-env
 * container. Not part of the plugin; not autoloaded; not committed as a
 * permanent test suite (that comes later with a proper PHPUnit setup).
 *
 * Exercises the parts of Pass A that don't require a real Stripe API call:
 * the two state-comparison checks with their exclusion lists, and the
 * upsert/escalation/dismissal-persistence mechanics in wsr_drift_log —
 * exactly the logic three rounds of independent review scrutinized most.
 *
 * Creates and cleans up its own WC_Order test fixtures and drift_log rows.
 */

if ( ! class_exists( 'WSR_Reconciler' ) ) {
	echo "FAIL: WSR_Reconciler not loaded — is the plugin active?\n";
	exit( 1 );
}

$pass    = 0;
$fail    = 0;
$cleanup = array(); // order IDs to delete at the end.

function wsr_test_assert( $label, $condition ) {
	global $pass, $fail;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		$pass++;
	} else {
		echo "FAIL: {$label}\n";
		$fail++;
	}
}

function wsr_test_call_private( $method, array $args ) {
	$ref = new ReflectionMethod( 'WSR_Reconciler', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

function wsr_test_make_order( $status, array $meta = array() ) {
	$order = wc_create_order();
	$order->set_status( $status );
	foreach ( $meta as $key => $value ) {
		$order->update_meta_data( $key, $value );
	}
	$order->save();
	return $order;
}

function wsr_test_base_pi( $overrides = array() ) {
	return array_replace_recursive(
		array(
			'id'            => 'pi_test_' . wp_generate_password( 10, false ),
			'status'        => 'succeeded',
			'amount'        => 1000,
			'currency'      => 'usd',
			'metadata'      => array(),
			'latest_charge' => array(
				'captured'        => true,
				'amount'          => 1000,
				'amount_refunded' => 0,
			),
		),
		$overrides
	);
}

// --- 1. Stuck-pending: basic positive case ---------------------------------
$order = wsr_test_make_order( 'pending' );
$cleanup[] = $order->get_id();
$pi    = wsr_test_base_pi();
$drift = wsr_test_call_private( 'check_stuck_pending', array( $order, $pi ) );
wsr_test_assert( 'stuck_pending: pending order + succeeded PI flags drift', is_array( $drift ) && 'stuck_pending' === $drift['drift_type'] );
wsr_test_assert( 'stuck_pending: severity is high with no dispute/review', is_array( $drift ) && 'high' === $drift['severity'] );

// --- 2. Stuck-pending: excluded by fresh awaiting-action meta --------------
$order = wsr_test_make_order( 'pending', array( '_stripe_payment_awaiting_action' => 'yes' ) );
$cleanup[] = $order->get_id();
$drift = wsr_test_call_private( 'check_stuck_pending', array( $order, wsr_test_base_pi() ) );
wsr_test_assert( 'stuck_pending: fresh awaiting-action meta excludes (mid-3DS, not drift)', null === $drift );

// --- 3. Stuck-pending: NOT excluded once awaiting-action meta is stale (>24h) ---
$order = wsr_test_make_order( 'pending', array( '_stripe_payment_awaiting_action' => 'yes' ) );
$cleanup[] = $order->get_id();
// Force date_modified back to 2 days ago to simulate a stale, abandoned 3DS
// flag — via WC_Order's own CRUD API (storage-agnostic, HPOS-or-legacy-safe),
// not a raw $wpdb query, matching the plugin's own no-raw-SQL design rule
// even in test setup code.
$order->set_date_modified( time() - 2 * DAY_IN_SECONDS );
$order->save();
$order = wc_get_order( $order->get_id() ); // reload with the forced date
$drift = wsr_test_call_private( 'check_stuck_pending', array( $order, wsr_test_base_pi() ) );
wsr_test_assert( 'stuck_pending: stale (>24h) awaiting-action meta does NOT exclude — matches gateway\'s own time bound', is_array( $drift ) );

// --- 4. Stuck-pending: open dispute downgrades to info, doesn't exclude ---
$order = wsr_test_make_order( 'pending' );
$cleanup[] = $order->get_id();
$pi_disputed = wsr_test_base_pi( array( 'latest_charge' => array( 'dispute' => array( 'status' => 'needs_response' ) ) ) );
$drift = wsr_test_call_private( 'check_stuck_pending', array( $order, $pi_disputed ) );
wsr_test_assert( 'stuck_pending: open dispute still detected (not excluded)', is_array( $drift ) );
wsr_test_assert( 'stuck_pending: open dispute forces severity=info', is_array( $drift ) && 'info' === $drift['severity'] );

// --- 5. Wrongly-cancelled: basic positive case -----------------------------
$order = wsr_test_make_order( 'cancelled' );
$cleanup[] = $order->get_id();
$drift = wsr_test_call_private( 'check_wrongly_cancelled', array( $order, wsr_test_base_pi() ) );
wsr_test_assert( 'wrongly_cancelled_paid: cancelled order + succeeded+captured PI flags drift', is_array( $drift ) && 'wrongly_cancelled_paid' === $drift['drift_type'] );
wsr_test_assert( 'wrongly_cancelled_paid: severity is critical with no dispute', is_array( $drift ) && 'critical' === $drift['severity'] );

// --- 6. Wrongly-cancelled: NOT flagged when uncaptured (requires_capture-shaped charge) ---
$order = wsr_test_make_order( 'cancelled' );
$cleanup[] = $order->get_id();
$pi_uncaptured = wsr_test_base_pi( array( 'latest_charge' => array( 'captured' => false ) ) );
$drift = wsr_test_call_private( 'check_wrongly_cancelled', array( $order, $pi_uncaptured ) );
wsr_test_assert( 'wrongly_cancelled_paid: uncaptured charge excluded (normal authorize-then-cancel flow)', null === $drift );

// --- 7. Wrongly-cancelled: NOT flagged when fully refunded -----------------
$order = wsr_test_make_order( 'cancelled' );
$cleanup[] = $order->get_id();
$pi_refunded = wsr_test_base_pi( array( 'latest_charge' => array( 'amount_refunded' => 1000 ) ) );
$drift = wsr_test_call_private( 'check_wrongly_cancelled', array( $order, $pi_refunded ) );
wsr_test_assert( 'wrongly_cancelled_paid: fully refunded charge excluded', null === $drift );

// --- 8. Wrongly-cancelled: lost dispute excluded outright (legitimate failed reason) ---
$order = wsr_test_make_order( 'cancelled' );
$cleanup[] = $order->get_id();
$pi_lost_dispute = wsr_test_base_pi( array( 'latest_charge' => array( 'dispute' => array( 'status' => 'lost' ) ) ) );
$drift = wsr_test_call_private( 'check_wrongly_cancelled', array( $order, $pi_lost_dispute ) );
wsr_test_assert( 'wrongly_cancelled_paid: lost dispute excluded (legitimate reason for cancelled/failed)', null === $drift );

// --- 9. Wrongly-cancelled: OPEN dispute downgrades to info, doesn't exclude ---
$order = wsr_test_make_order( 'cancelled' );
$cleanup[] = $order->get_id();
$pi_open_dispute = wsr_test_base_pi( array( 'latest_charge' => array( 'dispute' => array( 'status' => 'under_review' ) ) ) );
$drift = wsr_test_call_private( 'check_wrongly_cancelled', array( $order, $pi_open_dispute ) );
wsr_test_assert( 'wrongly_cancelled_paid: open dispute still detected (not excluded)', is_array( $drift ) );
wsr_test_assert( 'wrongly_cancelled_paid: open dispute forces severity=info', is_array( $drift ) && 'info' === $drift['severity'] );

// --- 10. upsert_drift: idempotency — re-detecting an open row updates, doesn't duplicate ---
global $wpdb;
$table = $wpdb->prefix . 'wsr_drift_log';
$test_object_id = 'pi_test_upsert_' . wp_generate_password( 8, false );
$drift_payload  = array(
	'drift_type'    => 'stuck_pending',
	'severity'      => 'high',
	'local_status'  => 'pending',
	'stripe_status' => 'succeeded',
	'details'       => array( 'test' => true ),
);
$r1 = wsr_test_call_private( 'upsert_drift', array( 999999, $test_object_id, $drift_payload ) );
$r2 = wsr_test_call_private( 'upsert_drift', array( 999999, $test_object_id, $drift_payload ) );
$row_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE stripe_object_id = %s", $test_object_id ) );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE stripe_object_id = %s", $test_object_id ) );
wsr_test_assert( 'upsert_drift: first call inserts', 'inserted' === $r1 );
wsr_test_assert( 'upsert_drift: second call updates, does not duplicate', 'updated' === $r2 && 1 === $row_count );
wsr_test_assert( 'upsert_drift: detection_count incremented to 2', $row && 2 === (int) $row->detection_count );

// --- 11. upsert_drift: dismissal persists across re-detection --------------
$wpdb->update( $table, array( 'status' => 'dismissed' ), array( 'id' => $row->id ) );
$r3 = wsr_test_call_private( 'upsert_drift', array( 999999, $test_object_id, $drift_payload ) );
$row_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE stripe_object_id = %s", $test_object_id ) );
wsr_test_assert( 'upsert_drift: re-detecting a dismissed row is a no-op (dismissal persists)', 'dismissed_skip' === $r3 && 'dismissed' === $row_after->status );
$wpdb->delete( $table, array( 'id' => $row->id ) );

// --- 12. upsert_unresolved_drift: needs_review escalates to orphaned_charge at detection_count 2, mutated in place ---
//
// Bug fix (independent review, round 2): escalation used to live inside
// upsert_drift() itself, keyed on (object, drift_type) together — which
// broke on the third detection, since escalating the row's drift_type
// changed its own open_key and let the next insert attempt at the
// original drift_type succeed as a duplicate. It's now its own method,
// upsert_unresolved_drift(), keyed only on stripe_object_id. See
// ReconcilerUpsertDriftTest::test_unresolved_third_and_later_detections_do_not_duplicate_the_escalated_row
// for the full multi-day regression this script only spot-checks.
$needs_review_object_id = 'pi_test_escalate_' . wp_generate_password( 8, false );
$needs_review_details   = array( 'stripe_status' => 'succeeded' );
wsr_test_call_private( 'upsert_unresolved_drift', array( $needs_review_object_id, $needs_review_details ) );
$escalate_result = wsr_test_call_private( 'upsert_unresolved_drift', array( $needs_review_object_id, $needs_review_details ) );
$escalated_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE stripe_object_id = %s", $needs_review_object_id ) );
wsr_test_assert( 'upsert_unresolved_drift: escalates needs_review to orphaned_charge at detection_count 2', 'escalated' === $escalate_result );
wsr_test_assert( 'upsert_unresolved_drift: escalation mutates in place — exactly one row, now orphaned_charge', 1 === count( $escalated_rows ) && 'orphaned_charge' === $escalated_rows[0]->drift_type );
$wpdb->delete( $table, array( 'stripe_object_id' => $needs_review_object_id ) );

// --- Cleanup -----------------------------------------------------------
// wp_delete_post() does NOT delete orders on an HPOS store (orders live in
// a custom table, not wp_posts) — confirmed the hard way on first run of
// this script, which left 9 leftover test orders per run. WC_Order::delete()
// is the HPOS-and-legacy-safe way, matching the plugin's own no-raw-access
// design rule.
foreach ( $cleanup as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->delete( true );
	}
}

echo "\n---\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	exit( 1 );
}
