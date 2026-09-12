<?php

class ReconcilerUpsertDriftTest extends WSR_TestCase {

	private function upsert( $order_id, $stripe_object_id, array $drift ) {
		return $this->call_private( WSR_Reconciler::class, 'upsert_drift', array( $order_id, $stripe_object_id, $drift ) );
	}

	private function drift_payload( array $overrides = array() ) {
		return array_merge(
			array(
				'drift_type'    => 'stuck_pending',
				'severity'      => 'high',
				'local_status'  => 'pending',
				'stripe_status' => 'succeeded',
				'details'       => array(),
			),
			$overrides
		);
	}

	public function test_first_insert_returns_inserted() {
		$outcome = $this->upsert( 123, 'pi_abc', $this->drift_payload() );
		$this->assertSame( 'inserted', $outcome );

		$row = $this->drift_log_row( 'pi_abc' );
		$this->assertSame( 'open', $row->status );
		$this->assertSame( 1, (int) $row->detection_count );
		$this->assertNotEmpty( $row->first_detected_at );
	}

	public function test_redetecting_an_open_row_updates_not_duplicates() {
		$this->upsert( 123, 'pi_abc', $this->drift_payload() );
		$outcome = $this->upsert( 123, 'pi_abc', $this->drift_payload() );

		$this->assertSame( 'updated', $outcome );

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wsr_drift_log WHERE stripe_object_id = 'pi_abc'" );
		$this->assertSame( 1, $count, 'Must never create a second row for an already-open drift.' );

		$row = $this->drift_log_row( 'pi_abc' );
		$this->assertSame( 2, (int) $row->detection_count );
	}

	public function test_first_detected_at_is_preserved_across_redetection() {
		$this->upsert( 123, 'pi_abc', $this->drift_payload() );
		$first_row = $this->drift_log_row( 'pi_abc' );

		sleep( 1 );
		$this->upsert( 123, 'pi_abc', $this->drift_payload() );
		$second_row = $this->drift_log_row( 'pi_abc' );

		$this->assertSame( $first_row->first_detected_at, $second_row->first_detected_at, 'first_detected_at must never be overwritten — only detected_at (last seen) updates.' );
	}

	public function test_dismissed_row_is_not_resurrected_on_redetection() {
		$this->upsert( 123, 'pi_abc', $this->drift_payload() );
		$row = $this->drift_log_row( 'pi_abc' );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wsr_drift_log', array( 'status' => 'dismissed' ), array( 'id' => $row->id ) );

		$outcome = $this->upsert( 123, 'pi_abc', $this->drift_payload() );
		$this->assertSame( 'dismissed_skip', $outcome );

		$after = $this->drift_log_row( 'pi_abc' );
		$this->assertSame( 'dismissed', $after->status, 'A dismissal must persist rather than silently reappearing.' );
	}

	public function test_fixed_rows_do_not_block_a_genuinely_new_future_occurrence() {
		$this->upsert( 123, 'pi_abc', $this->drift_payload() );
		$row = $this->drift_log_row( 'pi_abc' );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wsr_drift_log', array( 'status' => 'fixed' ), array( 'id' => $row->id ) );

		$outcome = $this->upsert( 456, 'pi_abc', $this->drift_payload() );
		$this->assertSame( 'inserted', $outcome, 'A fixed row\'s NULL open_key must allow a new insert for the same object/type pair.' );

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wsr_drift_log WHERE stripe_object_id = 'pi_abc'" );
		$this->assertSame( 2, $count );
	}

	// --- upsert_unresolved_drift(): needs_review -> orphaned_charge lineage ---
	//
	// A separate method from upsert_drift() above — see its docblock.
	// Round-2 independent review found the original design (escalation
	// handled inside upsert_drift() itself, keyed on (object, drift_type)
	// together) broke on exactly the third detection: escalating the row's
	// drift_type changed its own open_key, so the *next* insert attempt at
	// the original drift_type no longer collided with it and succeeded as
	// a duplicate — and as a direct consequence, dismissing the escalated
	// row never stuck, because the next run's "insert" was against a
	// different open_key than the dismissed row's. Both failure modes are
	// covered explicitly below, walking a realistic multi-day sequence
	// rather than stopping at two detections the way the original suite did.

	private function upsert_unresolved( $stripe_object_id, array $details = array() ) {
		return $this->call_private(
			WSR_Reconciler::class,
			'upsert_unresolved_drift',
			array( $stripe_object_id, array_merge( array( 'stripe_status' => 'succeeded' ), $details ) )
		);
	}

	public function test_unresolved_first_detection_inserts_needs_review() {
		$outcome = $this->upsert_unresolved( 'pi_orphan' );
		$this->assertSame( 'inserted', $outcome );

		$row = $this->drift_log_row( 'pi_orphan' );
		$this->assertSame( 'needs_review', $row->drift_type, 'Must not escalate on the very first detection — only at detection_count >= 2.' );
		$this->assertNull( $row->order_id );
	}

	public function test_unresolved_escalates_to_orphaned_charge_at_detection_count_two() {
		$this->upsert_unresolved( 'pi_orphan' );
		$outcome = $this->upsert_unresolved( 'pi_orphan' );

		$this->assertSame( 'escalated', $outcome );

		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE stripe_object_id = 'pi_orphan'" );
		$this->assertCount( 1, $rows, 'Escalation must mutate the existing row in place, never insert a second row for the same charge.' );
		$this->assertSame( 'orphaned_charge', $rows[0]->drift_type );
	}

	public function test_unresolved_third_and_later_detections_do_not_duplicate_the_escalated_row() {
		// This is the exact bug: day 1 inserts needs_review, day 2
		// escalates to orphaned_charge, day 3's re-detection must update
		// that SAME row (still keyed only on stripe_object_id, regardless
		// of its now-changed drift_type) — not silently succeed as a
		// fresh "inserted" duplicate under the original needs_review key.
		$this->upsert_unresolved( 'pi_orphan' );                    // day 1: inserted
		$this->upsert_unresolved( 'pi_orphan' );                    // day 2: escalated
		$day3 = $this->upsert_unresolved( 'pi_orphan' );             // day 3
		$day4 = $this->upsert_unresolved( 'pi_orphan' );             // day 4

		$this->assertSame( 'updated', $day3 );
		$this->assertSame( 'updated', $day4 );

		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE stripe_object_id = 'pi_orphan'" );
		$this->assertCount( 1, $rows, 'A single continuing charge must never produce a second row, no matter how many times it is re-detected after escalating.' );
		$this->assertSame( 'orphaned_charge', $rows[0]->drift_type );
		$this->assertSame( 4, (int) $rows[0]->detection_count );
	}

	public function test_dismissing_an_escalated_orphaned_charge_row_actually_persists() {
		$this->upsert_unresolved( 'pi_orphan' ); // day 1: inserted (needs_review)
		$this->upsert_unresolved( 'pi_orphan' ); // day 2: escalated (orphaned_charge)

		$row = $this->drift_log_row( 'pi_orphan' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wsr_drift_log', array( 'status' => 'dismissed' ), array( 'id' => $row->id ) );

		$day3 = $this->upsert_unresolved( 'pi_orphan' );

		$this->assertSame( 'dismissed_skip', $day3, 'Dismissing the escalated row must actually block re-detection — not get silently bypassed by a fresh needs_review insert.' );

		$after = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE stripe_object_id = 'pi_orphan'" );
		$this->assertCount( 1, $after, 'No duplicate row should have appeared alongside the dismissed one.' );
		$this->assertSame( 'dismissed', $after[0]->status );
	}

	public function test_different_drift_types_for_the_same_object_are_independent() {
		// A single PaymentIntent could in principle match different drift
		// types across runs (e.g. if local state changes) — open_key
		// includes drift_type, so these must not collide with each other.
		$this->upsert( 1, 'pi_shared', $this->drift_payload( array( 'drift_type' => 'stuck_pending' ) ) );
		$outcome = $this->upsert( 1, 'pi_shared', $this->drift_payload( array( 'drift_type' => 'wrongly_cancelled_paid' ) ) );
		$this->assertSame( 'inserted', $outcome );

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wsr_drift_log WHERE stripe_object_id = 'pi_shared'" );
		$this->assertSame( 2, $count );
	}
}
