<?php
/**
 * Thin, read-only wrapper around Stripe's REST API using wp_remote_get()
 * directly — no bundled SDK.
 *
 * Per TECHNICAL_SPEC.md's "Stripe integration specifics" section: the
 * reference gateway plugin (woocommerce-gateway-stripe) itself doesn't
 * bundle stripe-php, using raw wp_remote_get()/wp_remote_post() instead —
 * bundling our own copy of the SDK would risk a global-PHP-namespace
 * collision if another active plugin bundles a different SDK version.
 *
 * There is no write/POST method anywhere in this class, by design. See
 * PROBLEM.md's trust-model constraint: this plugin only ever reads from
 * Stripe and never has write capability, regardless of what the configured
 * key would technically allow.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Stripe_Client {

	const API_BASE = 'https://api.stripe.com/v1/';

	/** @var string Restricted API key (rk_live_... / rk_test_...). */
	private $api_key;

	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * GET request against the Stripe API.
	 *
	 * @param string $path  Path relative to API_BASE, e.g. 'payment_intents' or 'checkout/sessions'.
	 * @param array  $query Query args, Stripe-formatted (e.g. 'limit', 'type').
	 * @return array|WP_Error Decoded JSON body on 2xx, WP_Error otherwise.
	 */
	public function get( $path, array $query = array() ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'wsr_no_api_key', __( 'No Stripe API key is configured.', 'woo-stripe-reconcile' ) );
		}

		$url = self::API_BASE . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message     = isset( $body['error']['message'] ) ? $body['error']['message'] : sprintf(
				/* translators: %d: HTTP status code */
				__( 'Stripe API returned HTTP %d.', 'woo-stripe-reconcile' ),
				$code
			);
			$stripe_code = isset( $body['error']['code'] ) ? $body['error']['code'] : '';

			return new WP_Error(
				'wsr_stripe_api_error',
				$message,
				array(
					'status'      => $code,
					'stripe_code' => $stripe_code,
					'body'        => $body,
				)
			);
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'wsr_stripe_invalid_response', __( 'Stripe API returned an unexpected response.', 'woo-stripe-reconcile' ) );
		}

		return $body;
	}

	/**
	 * Probes each of the six restricted-key scopes v1 needs (see
	 * TECHNICAL_SPEC.md) and reports which are missing, so the settings
	 * screen can tell a merchant exactly which Stripe dashboard toggle to
	 * flip instead of a generic "invalid key" message. Each check is the
	 * cheapest possible call on that resource (limit=1).
	 *
	 * @return array Map of scope label => true (ok) | string (error message).
	 */
	public function check_scopes() {
		$checks = array(
			'PaymentIntents:Read'    => array( 'payment_intents', array( 'limit' => 1 ) ),
			'Charges:Read'           => array( 'charges', array( 'limit' => 1 ) ),
			'Checkout Sessions:Read' => array( 'checkout/sessions', array( 'limit' => 1 ) ),
			'Events:Read'            => array( 'events', array( 'limit' => 1 ) ),
			'Webhook Endpoints:Read' => array( 'webhook_endpoints', array( 'limit' => 1 ) ),
			'Disputes:Read'          => array( 'disputes', array( 'limit' => 1 ) ),
		);

		$results = array();
		foreach ( $checks as $label => $call ) {
			list( $path, $query ) = $call;
			$response              = $this->get( $path, $query );
			$results[ $label ]     = is_wp_error( $response ) ? $response->get_error_message() : true;
		}

		return $results;
	}
}
