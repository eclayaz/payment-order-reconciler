<?php

class AuditLogTest extends WSR_TestCase {

	public function test_hooks_are_registered() {
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_exporters', array( 'WSR_Audit_Log', 'register_exporter' ) ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers', array( 'WSR_Audit_Log', 'register_eraser' ) ) );
	}

	public function test_export_finds_drift_rows_for_the_matching_billing_email() {
		$order = $this->make_order( 'cancelled' );
		$order->set_billing_email( 'privacy-export@example.test' );
		$order->save();

		$this->insert_drift_row(
			array(
				'order_id'         => $order->get_id(),
				'stripe_object_id' => 'pi_export_test',
				'drift_type'       => 'wrongly_cancelled_paid',
			)
		);

		$export = WSR_Audit_Log::export_data( 'privacy-export@example.test', 1 );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'woo-stripe-reconcile-drift', $export['data'][0]['group_id'] );

		$values = wp_list_pluck( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'pi_export_test', $values['Stripe object'] );
	}

	public function test_export_finds_nothing_for_an_unrelated_email() {
		$export = WSR_Audit_Log::export_data( 'nobody@example.test', 1 );
		$this->assertTrue( $export['done'] );
		$this->assertSame( array(), $export['data'] );
	}

	public function test_erase_removes_drift_rows_for_the_matching_billing_email() {
		$order = $this->make_order( 'cancelled' );
		$order->set_billing_email( 'privacy-erase@example.test' );
		$order->save();

		$this->insert_drift_row(
			array(
				'order_id'         => $order->get_id(),
				'stripe_object_id' => 'pi_erase_test',
			)
		);

		$result = WSR_Audit_Log::erase_data( 'privacy-erase@example.test', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertNull( $this->drift_log_row( 'pi_erase_test' ) );
	}

	public function test_erase_reports_nothing_removed_for_unrelated_email() {
		$result = WSR_Audit_Log::erase_data( 'nobody@example.test', 1 );
		$this->assertFalse( $result['items_removed'] );
	}
}
