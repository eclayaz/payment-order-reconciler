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
	 * @param array  $query Query args, Stripe-formatted (e.g. 'limit', 'created' => array('gte' => ...), 'expand' => array(...)).
	 * @return array|WP_Error Decoded JSON body on 2xx, WP_Error otherwise.
	 */
	public function get( $path, array $query = array() ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'wsr_no_api_key', __( 'No Stripe API key is configured.', 'woo-stripe-reconcile' ) );
		}

		$url = self::API_BASE . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$pairs = array();
			self::flatten_params( $query, '', $pairs );
			if ( ! empty( $pairs ) ) {
				$url .= '?' . implode( '&', $pairs );
			}
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
	 * Serializes nested query params into Stripe's exact bracket notation
	 * (`created[gte]=...`, `expand[]=...&expand[]=...`).
	 *
	 * WordPress's own add_query_arg()/build_query() produce PHP's default
	 * *indexed* bracket style for array values (`expand[0]=...&expand[1]=...`)
	 * — Stripe's server-side param parser distinguishes an indexed-key hash
	 * from an actual array, and a schema expecting an array parameter (as
	 * `expand` is) may not accept the indexed form. This builds the literal
	 * `key[]=` repeated-parameter form Stripe's own docs show instead of
	 * relying on WordPress's helper to happen to produce the same thing.
	 *
	 * @param mixed  $value      Current value (array to recurse into, or a scalar to emit).
	 * @param string $key_prefix Accumulated key so far, e.g. 'created' or 'created[gte]'.
	 * @param array  $pairs      Accumulator, by reference: each entry is one already-encoded "key=value" pair.
	 */
	private static function flatten_params( $value, $key_prefix, array &$pairs ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			foreach ( $value as $k => $v ) {
				$new_prefix = $is_list
					? $key_prefix . '[]'
					: ( '' === $key_prefix ? (string) $k : $key_prefix . '[' . $k . ']' );
				self::flatten_params( $v, $new_prefix, $pairs );
			}
			return;
		}

		if ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		}

		$pairs[] = rawurlencode( $key_prefix ) . '=' . rawurlencode( (string) $value );
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
			// Bug found via a real Pass A run against a live sandbox key
			// scoped to exactly the other six (2026-08-30): Pass A's own
			// PaymentIntents list call requests `expand:
			// data.latest_charge.review` for the live Radar-review
			// downgrade check, and that expand requires a *separate*
			// `review_read` permission Stripe calls "Reviews Read" — not
			// covered by Disputes:Read, and never checked here, so this
			// screen previously showed all-green while every real run
			// failed outright on its very first page fetch. Listing
			// reviews directly is the cheapest real call that needs
			// exactly this permission.
			'Reviews:Read'           => array( 'reviews', array( 'limit' => 1 ) ),
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
