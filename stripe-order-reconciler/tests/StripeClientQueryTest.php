<?php
/**
 * Covers WSR_Stripe_Client::flatten_params() — the fix for a real bug
 * found while building Pass A: WordPress's own add_query_arg() serializes
 * array values as indexed brackets (expand[0]=, expand[1]=), not Stripe's
 * documented expand[]= repeated-parameter form. These tests assert the
 * literal decoded query string shape, not just "a request went out."
 */
class StripeClientQueryTest extends WSR_TestCase {

	private function flatten( array $params ) {
		$pairs = array();
		$ref   = new ReflectionMethod( WSR_Stripe_Client::class, 'flatten_params' );
		$ref->setAccessible( true );
		$ref->invokeArgs( null, array( $params, '', &$pairs ) );
		return $pairs;
	}

	private function decoded_pairs( array $params ) {
		$pairs = $this->flatten( $params );
		return array_map( 'urldecode', $pairs );
	}

	public function test_scalar_param() {
		$pairs = $this->decoded_pairs( array( 'limit' => 100 ) );
		$this->assertSame( array( 'limit=100' ), $pairs );
	}

	public function test_indexed_array_uses_repeated_bracket_form_not_indexed() {
		$pairs = $this->decoded_pairs( array( 'expand' => array( 'data.latest_charge', 'data.latest_charge.dispute' ) ) );
		$this->assertSame(
			array(
				'expand[]=data.latest_charge',
				'expand[]=data.latest_charge.dispute',
			),
			$pairs,
			'Must be the literal expand[]= form Stripe documents, not add_query_arg()\'s indexed expand[0]=/expand[1]= form.'
		);
	}

	public function test_nested_associative_param() {
		$pairs = $this->decoded_pairs( array( 'created' => array( 'gte' => 1700000000 ) ) );
		$this->assertSame( array( 'created[gte]=1700000000' ), $pairs );
	}

	public function test_boolean_false_serializes_as_literal_string() {
		$pairs = $this->decoded_pairs( array( 'delivery_success' => false ) );
		$this->assertSame( array( 'delivery_success=false' ), $pairs, 'PHP casts false to "" by default — Stripe needs the literal string "false".' );
	}

	public function test_boolean_true_serializes_as_literal_string() {
		$pairs = $this->decoded_pairs( array( 'active' => true ) );
		$this->assertSame( array( 'active=true' ), $pairs );
	}

	public function test_combined_realistic_pass_a_query() {
		$pairs = $this->decoded_pairs(
			array(
				'limit'   => 100,
				'created' => array( 'gte' => 123 ),
				'expand'  => array( 'data.latest_charge' ),
			)
		);
		$this->assertSame(
			array( 'limit=100', 'created[gte]=123', 'expand[]=data.latest_charge' ),
			$pairs
		);
	}
}
