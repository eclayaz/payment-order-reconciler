<?php

class BootstrapSmokeTest extends WP_UnitTestCase {

	public function test_woocommerce_loaded() {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
	}

	public function test_gateway_loaded() {
		$this->assertTrue( class_exists( 'WC_Stripe_Order_Helper' ) );
	}

	public function test_plugin_classes_loaded() {
		$classes = array(
			'WSR_Activator',
			'WSR_Stripe_Client',
			'WSR_Reconciler',
			'WSR_Scheduler',
			'WSR_Fixer',
			'WSR_Hook_Listener',
			'WSR_Settings',
			'WSR_Email_Alerts',
			'WSR_Event_Ledger',
			'WSR_Audit_Log',
		);
		foreach ( $classes as $class ) {
			$this->assertTrue( class_exists( $class ), "{$class} should be loaded" );
		}
	}

	public function test_can_create_a_real_order() {
		$order = wc_create_order();
		$order->set_status( 'pending' );
		$order->save();
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
	}
}
