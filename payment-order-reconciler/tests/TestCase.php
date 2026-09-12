<?php
/**
 * Shared helpers for the payment-order-reconciler test suite: calling private
 * static methods via reflection (most of the interesting logic in this
 * plugin is deliberately private, since it's internal mechanism, not
 * public API), building test orders, and a base synthetic PaymentIntent
 * shape matching what Stripe actually returns.
 */

abstract class WSR_TestCase extends WP_UnitTestCase {

	protected function tearDown(): void {
		// DELETE, not TRUNCATE: TRUNCATE TABLE is DDL in MySQL/MariaDB and
		// causes an IMPLICIT COMMIT — which would silently end
		// WP_UnitTestCase's per-test rollback transaction early, permanently
		// committing every change made during the test (including
		// update_option() calls) instead of rolling them back. Found the
		// hard way: an option set in one test was leaking into the next
		// despite explicit delete_option() calls here, because the
		// transaction those deletes lived in had already been force-closed
		// by an earlier TRUNCATE in this same method.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wsr_drift_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wsr_event_ledger" );
		parent::tearDown();
	}

	/**
	 * @param string $class  Fully-qualified class name.
	 * @param string $method Method name (private or protected, static or instance).
	 * @param array  $args   Positional arguments.
	 * @param object|null $instance Object to invoke on, for non-static methods.
	 */
	protected function call_private( $class, $method, array $args = array(), $instance = null ) {
		$ref = new ReflectionMethod( $class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $instance, $args );
	}

	protected function get_private_property( $object, $property ) {
		$ref = new ReflectionProperty( get_class( $object ), $property );
		$ref->setAccessible( true );
		return $ref->getValue( $object );
	}

	protected function set_private_static_property( $class, $property, $value ) {
		$ref = new ReflectionProperty( $class, $property );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	protected function make_order( $status, array $meta = array() ) {
		$order = wc_create_order();
		$order->set_status( $status );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return $order;
	}

	/**
	 * A synthetic PaymentIntent matching Stripe's real shape closely
	 * enough for the reconciler's checks — a succeeded, fully-captured,
	 * unrefunded, undisputed $10.00 USD charge by default.
	 */
	protected function base_pi( array $overrides = array() ) {
		return array_replace_recursive(
			array(
				'id'            => 'pi_test_' . wp_generate_password( 12, false ),
				'object'        => 'payment_intent',
				'status'        => 'succeeded',
				'amount'        => 1000,
				'currency'      => 'usd',
				'created'       => time() - HOUR_IN_SECONDS, // Past FRESH_GRACE_SECONDS by default.
				'metadata'      => array(),
				'latest_charge' => array(
					'id'              => 'ch_test_' . wp_generate_password( 12, false ),
					'object'          => 'charge',
					'captured'        => true,
					'amount'          => 1000,
					'amount_refunded' => 0,
				),
			),
			$overrides
		);
	}

	protected function drift_log_row( $stripe_object_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wsr_drift_log WHERE stripe_object_id = %s",
				$stripe_object_id
			)
		);
	}

	protected function insert_drift_row( array $fields ) {
		global $wpdb;
		$defaults = array(
			'order_id'                   => null,
			'stripe_object_id'           => 'pi_test_' . wp_generate_password( 8, false ),
			'drift_type'                 => 'stuck_pending',
			'severity'                   => 'high',
			'local_status_at_detection'  => 'pending',
			'stripe_status_at_detection' => 'succeeded',
			'status'                     => 'open',
			'first_detected_at'          => current_time( 'mysql', true ),
			'detected_at'                => current_time( 'mysql', true ),
			'detection_count'            => 1,
			'details'                    => '{}',
		);
		$row = array_merge( $defaults, $fields );
		$wpdb->insert( $wpdb->prefix . 'wsr_drift_log', $row );
		return $wpdb->insert_id;
	}
}
