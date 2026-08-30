<?php

class EventLedgerTest extends WSR_TestCase {

	public function test_first_claim_succeeds() {
		$this->assertTrue( WSR_Event_Ledger::claim( 'evt_first' ) );
	}

	public function test_duplicate_claim_is_blocked() {
		WSR_Event_Ledger::claim( 'evt_dup' );
		$this->assertFalse( WSR_Event_Ledger::claim( 'evt_dup' ) );
	}

	public function test_empty_event_id_always_proceeds() {
		$this->assertTrue( WSR_Event_Ledger::claim( '' ) );
		$this->assertTrue( WSR_Event_Ledger::claim( '' ), 'No event ID means nothing to dedupe against — must never block.' );
	}

	public function test_prune_removes_entries_older_than_retention() {
		global $wpdb;
		$table = $wpdb->prefix . 'wsr_event_ledger';

		$wpdb->insert(
			$table,
			array(
				'event_id'   => 'evt_old',
				'status'     => 'processed',
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( WSR_Event_Ledger::RETENTION_DAYS + 1 ) * DAY_IN_SECONDS ),
			)
		);
		$wpdb->insert(
			$table,
			array(
				'event_id'   => 'evt_recent',
				'status'     => 'processed',
				'created_at' => current_time( 'mysql', true ),
			)
		);

		WSR_Event_Ledger::prune();

		$remaining = $wpdb->get_col( "SELECT event_id FROM {$table}" );
		$this->assertNotContains( 'evt_old', $remaining );
		$this->assertContains( 'evt_recent', $remaining );
	}
}
