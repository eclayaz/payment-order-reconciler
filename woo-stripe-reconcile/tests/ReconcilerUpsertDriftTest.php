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

	public function test_needs_review_escalates_to_orphaned_charge_at_detection_count_two() {
		$payload = $this->drift_payload(
			array(
				'drift_type'    => 'needs_review',
				'local_status'  => null,
				'stripe_status' => 'succeeded',
			)
		);

		$this->upsert( null, 'pi_orphan', $payload );
		$outcome = $this->upsert( null, 'pi_orphan', $payload );

		$this->assertSame( 'escalated', $outcome );

		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE stripe_object_id = 'pi_orphan'" );
		$this->assertCount( 1, $rows, 'Escalation must mutate the existing row in place, never insert a second row for the same charge.' );
		$this->assertSame( 'orphaned_charge', $rows[0]->drift_type );
	}

	public function test_does_not_escalate_on_first_detection() {
		$payload = $this->drift_payload( array( 'drift_type' => 'needs_review', 'local_status' => null ) );
		$outcome = $this->upsert( null, 'pi_orphan', $payload );
		$this->assertSame( 'inserted', $outcome );

		$row = $this->drift_log_row( 'pi_orphan' );
		$this->assertSame( 'needs_review', $row->drift_type, 'Must not escalate on the very first detection — only at detection_count >= 2.' );
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
