<?php
/**
 * Pass A: bulk PaymentIntent list-and-diff against local WooCommerce orders.
 *
 * Build-order step 3 (TECHNICAL_SPEC.md) — detection only. No fix action
 * lives here; that's WSR_Fixer, added in a later step. This class only
 * detects drift and writes/updates rows in wsr_drift_log.
 *
 * The design here is the product of three rounds of independent technical
 * review — see TECHNICAL_SPEC.md's "The central mechanism" section for the
 * full reasoning. The short version: list PaymentIntents in bulk (not
 * per-order polling), resolve each to a local order via a specific
 * fallback chain, and run two state-comparison checks with exclusion lists
 * derived from real, evidenced gateway bugs — not assumptions.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Reconciler {

	/** How far back Pass A looks — matches Stripe's own Events API retention, kept as one round number for both. */
	const WINDOW_DAYS = 30;

	/**
	 * The gateway backfills order-identifying metadata onto Adaptive
	 * Pricing / Optimized Checkout PaymentIntents ~2 minutes after a
	 * successful session (see TECHNICAL_SPEC.md's Checkout Session
	 * section). A PaymentIntent younger than this is skipped entirely
	 * this run rather than risking a premature "needs_review" — it'll
	 * resolve normally via metadata on tomorrow's run once the backfill
	 * has had time to land.
	 */
	const FRESH_GRACE_SECONDS = 300; // 5 minutes — comfortably past the gateway's ~2 minute backfill delay.

	/** Safety cap on pagination so a manual "Run now" click can't runaway on a very large store. */
	const MAX_PAGES = 50; // 50 * 100 = 5,000 PaymentIntents per run.

	/**
	 * Event types Pass B checks for undelivered-webhook status. Kept well
	 * under Stripe's documented 20-type limit on the `types` list param.
	 * This is a diagnostic, not a drift-detection input (see class comment
	 * on run_pass_b()) — the set only needs to be broad enough to notice
	 * "this store's webhook endpoint is currently failing," not to cover
	 * every event type Pass A's own PaymentIntent-based checks care about.
	 */
	const PASS_B_EVENT_TYPES = array(
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'payment_intent.canceled',
		'charge.refunded',
		'charge.dispute.created',
		'checkout.session.completed',
	);

	/**
	 * Runs all three steps of the single daily job (TECHNICAL_SPEC.md
	 * "Scheduled reconciliation — one daily job, three steps") and records
	 * Pass B / webhook-health results as options for the settings screen
	 * (and, later, the real dashboard in build-order step 6) to display.
	 * Called by both the ActionScheduler callback and the manual "Run now"
	 * button — WSR_Scheduler is responsible for the run-in-progress guard
	 * around this, not this method itself.
	 *
	 * @return array{pass_a: array|WP_Error, pass_b: array|WP_Error, webhook_health: array|WP_Error}
	 */
	public static function run_all() {
		if ( class_exists( 'WSR_Event_Ledger' ) ) {
			WSR_Event_Ledger::prune();
		}

		$pass_a         = self::run_pass_a();
		$pass_b         = self::run_pass_b();
		$webhook_health = self::run_webhook_health_check();

		update_option( 'wsr_last_pass_b_result', is_wp_error( $pass_b ) ? array( 'error' => $pass_b->get_error_message() ) : $pass_b, false );
		update_option( 'wsr_last_webhook_health_result', is_wp_error( $webhook_health ) ? array( 'error' => $webhook_health->get_error_message() ) : $webhook_health, false );
		update_option( 'wsr_last_run_at', current_time( 'mysql', true ), false );

		if ( ! is_wp_error( $pass_a ) && ! empty( $pass_a['new_alerts'] ) && class_exists( 'WSR_Email_Alerts' ) ) {
			WSR_Email_Alerts::maybe_send( $pass_a['new_alerts'] );
		}

		return array(
			'pass_a'         => $pass_a,
			'pass_b'         => $pass_b,
			'webhook_health' => $webhook_health,
		);
	}

	/**
	 * Pass A. Runs Pass A once, synchronously. Called directly by the manual
	 * "Run now" button in the prior build-order step; now also wrapped by
	 * run_all() for the scheduled daily job.
	 *
	 * @return array|WP_Error Summary counts on success, WP_Error if the
	 *                         Stripe API key isn't usable at all.
	 */
	public static function run_pass_a() {
		$api_key = WSR_Settings::get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wsr_no_api_key', __( 'No Stripe API key is configured — save one on the settings screen first.', 'woo-stripe-reconcile' ) );
		}

		$client       = new WSR_Stripe_Client( $api_key );
		$window_start = time() - ( self::WINDOW_DAYS * DAY_IN_SECONDS );

		$summary = array(
			'payment_intents_scanned'   => 0,
			'resolved_to_order'         => 0,
			'skipped_other_site'        => 0,
			'skipped_too_fresh'         => 0,
			'needs_review'              => 0,
			'orphaned_charge_escalated' => 0,
			'stuck_pending_flagged'     => 0,
			'wrongly_cancelled_flagged' => 0,
			'no_drift'                  => 0,
			'pages_fetched'             => 0,
			'errors'                    => array(),
			'new_alerts'                => array(), // Genuinely new (not re-detected) high/critical drift this run — see WSR_Email_Alerts.
			'started_at'                => current_time( 'mysql', true ),
		);

		$order_maps = self::batch_load_order_maps( $window_start );

		$cursor = null;
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = array(
				'limit'   => 100,
				'created' => array( 'gte' => $window_start ),
				'expand'  => array( 'data.latest_charge', 'data.latest_charge.dispute', 'data.latest_charge.review' ),
			);
			if ( $cursor ) {
				$query['starting_after'] = $cursor;
			}

			$page_result = $client->get( 'payment_intents', $query );
			if ( is_wp_error( $page_result ) ) {
				$summary['errors'][] = $page_result->get_error_message();
				break;
			}

			$summary['pages_fetched']++;
			$intents = isset( $page_result['data'] ) && is_array( $page_result['data'] ) ? $page_result['data'] : array();

			foreach ( $intents as $pi ) {
				$summary['payment_intents_scanned']++;
				self::process_payment_intent( $pi, $order_maps, $client, $summary );
			}

			if ( empty( $page_result['has_more'] ) || empty( $intents ) ) {
				break;
			}
			$cursor = end( $intents )['id'];
		}

		$summary['finished_at'] = current_time( 'mysql', true );

		return $summary;
	}

	/**
	 * Loads every local order in the reconciliation window into memory
	 * once, keyed every way a PaymentIntent might resolve through. Never
	 * queried per-PaymentIntent — that per-order-lookup pattern is exactly
	 * what made the original design (see prior TECHNICAL_SPEC.md
	 * revisions) not scale.
	 *
	 * Deliberately loads ALL order statuses in the window, not just
	 * pending/on-hold/cancelled — a PaymentIntent resolving to a
	 * perfectly healthy 'processing' order must be recognized as "resolved,
	 * no drift," not misclassified as unresolved just because we didn't
	 * load that order into the map.
	 *
	 * @param int $window_start Unix timestamp.
	 * @return array{
	 *     by_id: array<int, WC_Order>,
	 *     by_order_key: array<string, int>,
	 *     by_session_id: array<string, int>,
	 *     by_intent_id: array<string, int>,
	 * }
	 */
	private static function batch_load_order_maps( $window_start ) {
		$orders = wc_get_orders(
			array(
				'date_created' => '>=' . $window_start,
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		$maps = array(
			'by_id'         => array(),
			'by_order_key'  => array(),
			'by_session_id' => array(),
			'by_intent_id'  => array(),
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$order_id = $order->get_id();
			$maps['by_id'][ $order_id ] = $order;

			$order_key = $order->get_order_key();
			if ( $order_key ) {
				$maps['by_order_key'][ $order_key ] = $order_id;
			}

			$session_id = $order->get_meta( '_stripe_checkout_session_id' );
			if ( $session_id ) {
				$maps['by_session_id'][ $session_id ] = $order_id;
			}

			$intent_id = $order->get_meta( '_stripe_intent_id' );
			if ( $intent_id ) {
				$maps['by_intent_id'][ $intent_id ] = $order_id;
			}
			$setup_intent_id = $order->get_meta( '_stripe_setup_intent' );
			if ( $setup_intent_id ) {
				$maps['by_intent_id'][ $setup_intent_id ] = $order_id;
			}
		}

		return $maps;
	}

	/**
	 * Resolves one PaymentIntent to a local order (or determines it can't
	 * be resolved) and runs the state-comparison checks if a match is
	 * found. All counting/side effects happen here; nothing is returned.
	 */
	private static function process_payment_intent( array $pi, array $order_maps, WSR_Stripe_Client $client, array &$summary ) {
		$pi_id       = isset( $pi['id'] ) ? $pi['id'] : '';
		$metadata    = isset( $pi['metadata'] ) && is_array( $pi['metadata'] ) ? $pi['metadata'] : array();
		$order_id    = self::resolve_via_metadata( $metadata, $order_maps );

		if ( null === $order_id ) {
			$order_id = self::resolve_via_intent_map( $pi_id, $order_maps );
		}

		if ( null === $order_id && '' !== $pi_id ) {
			$pi_age = time() - ( isset( $pi['created'] ) ? (int) $pi['created'] : time() );
			if ( $pi_age < self::FRESH_GRACE_SECONDS ) {
				// Too fresh to conclude anything — the gateway's own
				// metadata backfill (Checkout Session flows) may not have
				// run yet. Revisit next run, don't flag or spend an API
				// call on it now.
				$summary['skipped_too_fresh']++;
				return;
			}

			$order_id = self::resolve_via_checkout_session_lookup( $pi_id, $order_maps, $client );
		}

		if ( null === $order_id ) {
			self::handle_unresolved( $pi, $metadata, $summary );
			return;
		}

		$summary['resolved_to_order']++;
		$order = $order_maps['by_id'][ $order_id ];

		$stuck_pending_drift = self::check_stuck_pending( $order, $pi );
		if ( null !== $stuck_pending_drift ) {
			self::upsert_drift_and_maybe_alert( $order_id, $pi_id, $stuck_pending_drift, $summary );
			$summary['stuck_pending_flagged']++;
			return;
		}

		$wrongly_cancelled_drift = self::check_wrongly_cancelled( $order, $pi );
		if ( null !== $wrongly_cancelled_drift ) {
			self::upsert_drift_and_maybe_alert( $order_id, $pi_id, $wrongly_cancelled_drift, $summary );
			$summary['wrongly_cancelled_flagged']++;
			return;
		}

		$summary['no_drift']++;
	}

	/**
	 * Join order 1: metadata.signature (parse the leading order ID — see
	 * class comment on the format). Join order 2: metadata.order_key.
	 *
	 * Per TECHNICAL_SPEC.md/FEATURES.md's corrected join-key order: these
	 * two are tried before the in-memory intent-ID map, since a store's
	 * own metadata is authoritative when present, and cheaper to check
	 * than a map lookup keyed on a value (PHP associative array lookups
	 * are O(1) either way, but this ordering matches the documented
	 * resolution chain exactly rather than reordering for a difference
	 * that doesn't matter at this scale).
	 */
	private static function resolve_via_metadata( array $metadata, array $order_maps ) {
		if ( ! empty( $metadata['signature'] ) && is_string( $metadata['signature'] ) ) {
			$order_id = (int) strtok( $metadata['signature'], ':' );
			if ( $order_id > 0 && isset( $order_maps['by_id'][ $order_id ] ) ) {
				return $order_id;
			}
		}

		if ( ! empty( $metadata['order_key'] ) && isset( $order_maps['by_order_key'][ $metadata['order_key'] ] ) ) {
			return $order_maps['by_order_key'][ $metadata['order_key'] ];
		}

		return null;
	}

	private static function resolve_via_intent_map( $pi_id, array $order_maps ) {
		if ( '' === $pi_id ) {
			return null;
		}
		return isset( $order_maps['by_intent_id'][ $pi_id ] ) ? $order_maps['by_intent_id'][ $pi_id ] : null;
	}

	/**
	 * The targeted Checkout Session lookup — only ever called for a
	 * PaymentIntent that failed both metadata joins AND is past the
	 * gateway's own metadata-backfill grace window. Sized to the
	 * unresolved-drift tail, not a full session list scan (see
	 * TECHNICAL_SPEC.md for why the full-list version was cut).
	 */
	private static function resolve_via_checkout_session_lookup( $pi_id, array $order_maps, WSR_Stripe_Client $client ) {
		$result = $client->get( 'checkout/sessions', array( 'payment_intent' => $pi_id ) );
		if ( is_wp_error( $result ) || empty( $result['data'][0]['id'] ) ) {
			return null;
		}

		$session_id = $result['data'][0]['id'];
		return isset( $order_maps['by_session_id'][ $session_id ] ) ? $order_maps['by_session_id'][ $session_id ] : null;
	}

	/**
	 * A PaymentIntent that resolved to no local order at all: site-scope
	 * it (host-normalized, not strict string equality — see
	 * TECHNICAL_SPEC.md) and log as needs_review if it plausibly belongs
	 * to this store, escalating to orphaned_charge once upsert_drift's
	 * detection_count crosses the threshold.
	 */
	private static function handle_unresolved( array $pi, array $metadata, array &$summary ) {
		$pi_id    = isset( $pi['id'] ) ? $pi['id'] : '';
		$site_url = isset( $metadata['site_url'] ) ? $metadata['site_url'] : '';

		if ( '' !== $site_url && self::normalize_host( $site_url ) !== self::normalize_host( home_url() ) ) {
			// Belongs to a different store/app sharing this Stripe
			// account — not ours to flag at all.
			$summary['skipped_other_site']++;
			return;
		}

		$drift = array(
			'drift_type'    => 'needs_review',
			'severity'      => 'high',
			'local_status'  => null,
			'stripe_status' => isset( $pi['status'] ) ? $pi['status'] : '',
			'details'       => array(
				'amount'   => isset( $pi['amount'] ) ? $pi['amount'] : null,
				'currency' => isset( $pi['currency'] ) ? $pi['currency'] : null,
				'reason'   => '' === $site_url ? 'no_site_url_metadata' : 'unresolved_with_matching_site',
			),
		);

		$outcome = self::upsert_drift( null, $pi_id, $drift );
		if ( 'escalated' === $outcome ) {
			$summary['orphaned_charge_escalated']++;
		} else {
			$summary['needs_review']++;
		}
	}

	/**
	 * Host-normalized comparison: strips scheme and a leading "www.", so
	 * http vs. https and www vs. non-www don't produce a false mismatch —
	 * a strict string-equality check would silently drop legitimate
	 * payments across any past domain change (see TECHNICAL_SPEC.md).
	 */
	private static function normalize_host( $url_or_host ) {
		if ( ! $url_or_host ) {
			return '';
		}
		$host = ( false === strpos( $url_or_host, '://' ) ) ? $url_or_host : (string) wp_parse_url( $url_or_host, PHP_URL_HOST );
		$host = strtolower( trim( $host ) );
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Live dispute status from the expanded charge — never cached, always
	 * read fresh from Stripe's current data (see PROBLEM.md's corrected
	 * dispute-handling principle: a permanent exclusion based on a
	 * point-in-time flag was the exact bug this replaced).
	 *
	 * @return string|null 'open' | 'won' | 'lost' | null (no dispute).
	 */
	private static function live_dispute_status( array $pi ) {
		$dispute = self::expanded_charge_field( $pi, 'dispute' );
		if ( ! is_array( $dispute ) || empty( $dispute['status'] ) ) {
			return null;
		}

		$status = $dispute['status'];
		if ( in_array( $status, array( 'needs_response', 'warning_needs_response', 'under_review', 'warning_under_review' ), true ) ) {
			return 'open';
		}
		if ( 'lost' === $status ) {
			return 'lost';
		}
		if ( in_array( $status, array( 'won', 'warning_closed' ), true ) ) {
			return 'won';
		}
		return null;
	}

	/**
	 * Whether a Radar review is currently open. Stripe clears
	 * charge.review to null once a review closes (the review object
	 * itself still exists, retrievable by ID, just no longer linked from
	 * the charge) — so checking for presence here is self-clearing by
	 * construction, with no permanence bug to guard against.
	 */
	private static function live_review_open( array $pi ) {
		return is_array( self::expanded_charge_field( $pi, 'review' ) );
	}

	private static function expanded_charge_field( array $pi, $field ) {
		$charge = isset( $pi['latest_charge'] ) && is_array( $pi['latest_charge'] ) ? $pi['latest_charge'] : null;
		if ( null === $charge || ! isset( $charge[ $field ] ) || ! is_array( $charge[ $field ] ) ) {
			return null;
		}
		return $charge[ $field ];
	}

	/**
	 * Stuck-pending check (FEATURES.md #7): local pending/on-hold, PI
	 * succeeded, with the corrected exclusion list — a time-bounded
	 * awaiting-action window (not permanent) and live dispute/review
	 * status (downgraded to info, not excluded outright, so it's still
	 * visible on the dashboard).
	 *
	 * @return array|null Drift details to log, or null if no drift / excluded.
	 */
	private static function check_stuck_pending( WC_Order $order, array $pi ) {
		if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
			return null;
		}
		if ( ! isset( $pi['status'] ) || 'succeeded' !== $pi['status'] ) {
			return null;
		}

		$awaiting_action = $order->get_meta( '_stripe_payment_awaiting_action' );
		$date_modified   = $order->get_date_modified();
		if ( $awaiting_action && $date_modified && $date_modified->getTimestamp() > ( time() - DAY_IN_SECONDS ) ) {
			// Legitimately mid-3DS/SCA within the gateway's own 24h
			// window — not drift. Mirrors the gateway's own time-bound
			// treatment of this meta (see FEATURES.md #7).
			return null;
		}

		$severity = 'high';
		if ( 'open' === self::live_dispute_status( $pi ) || self::live_review_open( $pi ) ) {
			$severity = 'info';
		}

		return array(
			'drift_type'    => 'stuck_pending',
			'severity'      => $severity,
			'local_status'  => $order->get_status(),
			'stripe_status' => $pi['status'],
			'details'       => array(
				'amount'   => isset( $pi['amount'] ) ? $pi['amount'] : null,
				'currency' => isset( $pi['currency'] ) ? $pi['currency'] : null,
			),
		);
	}

	/**
	 * Wrongly-cancelled-paid check (FEATURES.md #6): local cancelled, PI
	 * succeeded, charge captured, not (yet) fully refunded — with a lost
	 * dispute excluded outright (a legitimate reason for cancelled/failed,
	 * per the gateway's own logic, not wrongful cancellation) and an open
	 * dispute downgraded to info rather than excluded.
	 */
	private static function check_wrongly_cancelled( WC_Order $order, array $pi ) {
		if ( 'cancelled' !== $order->get_status() ) {
			return null;
		}
		if ( ! isset( $pi['status'] ) || 'succeeded' !== $pi['status'] ) {
			return null;
		}

		$charge = isset( $pi['latest_charge'] ) && is_array( $pi['latest_charge'] ) ? $pi['latest_charge'] : null;
		if ( null === $charge || empty( $charge['captured'] ) ) {
			return null;
		}

		$amount          = isset( $charge['amount'] ) ? (int) $charge['amount'] : 0;
		$amount_refunded = isset( $charge['amount_refunded'] ) ? (int) $charge['amount_refunded'] : 0;
		if ( $amount > 0 && $amount_refunded >= $amount ) {
			return null; // Fully refunded — not "wrongly cancelled while paid."
		}

		$dispute_status = self::live_dispute_status( $pi );
		if ( 'lost' === $dispute_status ) {
			return null; // Legitimate: the gateway treats a lost dispute as a real reason for failed/cancelled.
		}

		$severity = ( 'open' === $dispute_status ) ? 'info' : 'critical';

		return array(
			'drift_type'    => 'wrongly_cancelled_paid',
			'severity'      => $severity,
			'local_status'  => $order->get_status(),
			'stripe_status' => $pi['status'],
			'details'       => array(
				'amount'          => $amount,
				'amount_refunded' => $amount_refunded,
				'currency'        => isset( $pi['currency'] ) ? $pi['currency'] : null,
			),
		);
	}

	/**
	 * Thin wrapper around upsert_drift() used by the two order-resolved
	 * checks specifically (stuck_pending / wrongly_cancelled_paid) — adds
	 * a genuinely-new, high/critical-severity drift to the run's
	 * new_alerts list for WSR_Email_Alerts, without changing upsert_drift()
	 * itself (which handle_unresolved()'s needs_review path also calls,
	 * and needs_review is never email-alert-worthy on its own — only once
	 * it escalates to orphaned_charge would it matter, and that's a
	 * separate, deliberately not-yet-built notification path since
	 * orphaned-charge alerts need different handling — no order to link to
	 * in the email).
	 */
	private static function upsert_drift_and_maybe_alert( $order_id, $stripe_object_id, array $drift, array &$summary ) {
		$outcome = self::upsert_drift( $order_id, $stripe_object_id, $drift );
		if ( 'inserted' === $outcome && in_array( $drift['severity'], array( 'high', 'critical' ), true ) ) {
			$summary['new_alerts'][] = array(
				'order_id'         => $order_id,
				'drift_type'       => $drift['drift_type'],
				'severity'         => $drift['severity'],
				'stripe_object_id' => $stripe_object_id,
			);
		}
	}

	/**
	 * Inserts a new drift row, or — on an open_key collision — updates the
	 * existing one: bumps detection_count, refreshes the detected_at/
	 * severity/status snapshot, and (for needs_review specifically)
	 * escalates to orphaned_charge once detection_count reaches 2 by
	 * mutating drift_type in place. Never inserts a second row for an
	 * already-open drift, and never resurrects a dismissed one — see
	 * TECHNICAL_SPEC.md's end-to-end trace for why both of those matter.
	 *
	 * @param int|null $order_id         Null for needs_review/orphaned_charge.
	 * @param string   $stripe_object_id PaymentIntent ID.
	 * @param array    $drift            drift_type, severity, local_status, stripe_status, details.
	 * @return string 'inserted' | 'updated' | 'escalated' | 'dismissed_skip'
	 */
	private static function upsert_drift( $order_id, $stripe_object_id, array $drift ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wsr_drift_log';

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no wpdb-based abstraction exists for this table; see class-wsr-activator.php.
		$inserted = $wpdb->insert(
			$table,
			array(
				'order_id'                   => $order_id,
				'stripe_object_id'           => $stripe_object_id,
				'drift_type'                 => $drift['drift_type'],
				'severity'                   => $drift['severity'],
				'local_status_at_detection'  => $drift['local_status'],
				'stripe_status_at_detection' => $drift['stripe_status'],
				'status'                     => 'open',
				'first_detected_at'          => $now,
				'detected_at'                => $now,
				'detection_count'            => 1,
				'details'                    => wp_json_encode( $drift['details'] ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false !== $inserted ) {
			return 'inserted';
		}

		// Insert failed — expected cause is the open_key unique-index
		// collision (this object/type pair is already open or dismissed).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is our own constant prefix.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, drift_type, detection_count FROM {$table} WHERE stripe_object_id = %s AND drift_type = %s AND status IN ('open','dismissed')",
				$stripe_object_id,
				$drift['drift_type']
			)
		);

		if ( ! $existing ) {
			// Not the collision we expected — a real DB error. Nothing
			// more to do here; the caller's summary just won't count this
			// one, which is acceptable for a manual test run.
			return 'error';
		}

		if ( 'dismissed' === $existing->status ) {
			// A dismissal persists — do not resurrect it, per
			// TECHNICAL_SPEC.md's corrected dismissal-persistence design.
			return 'dismissed_skip';
		}

		$new_count = (int) $existing->detection_count + 1;
		$new_type  = $existing->drift_type;
		$outcome   = 'updated';

		if ( 'needs_review' === $existing->drift_type && $new_count >= 2 ) {
			$new_type = 'orphaned_charge';
			$outcome  = 'escalated';
		}

		$wpdb->update(
			$table,
			array(
				'detected_at'                => $now,
				'detection_count'            => $new_count,
				'drift_type'                 => $new_type,
				'severity'                   => $drift['severity'],
				'local_status_at_detection'  => $drift['local_status'],
				'stripe_status_at_detection' => $drift['stripe_status'],
				'details'                    => wp_json_encode( $drift['details'] ),
			),
			array( 'id' => $existing->id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return $outcome;
	}

	/**
	 * Verifies one order directly against Stripe — the target of the
	 * hook-listener accelerators (WSR_Hook_Listener), which schedule this
	 * a couple of minutes after a webhook-processing error or a
	 * charge-processed signal, rather than waiting for tomorrow's full
	 * daily pass. Reuses the same state-comparison checks and upsert
	 * mechanism as Pass A — this is Pass A's logic scoped to a single
	 * PaymentIntent instead of the whole 30-day window.
	 *
	 * Deliberately does nothing (no drift written, no error surfaced) if
	 * the order has no known Stripe intent ID or the API call fails —
	 * this is an acceleration convenience on top of the daily job, which
	 * will pick up any real drift regardless.
	 */
	public static function verify_single_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$intent_id = $order->get_meta( '_stripe_intent_id' );
		if ( ! $intent_id ) {
			$intent_id = $order->get_meta( '_stripe_setup_intent' );
		}
		if ( ! $intent_id ) {
			return;
		}

		$api_key = WSR_Settings::get_api_key();
		if ( '' === $api_key ) {
			return;
		}

		$client = new WSR_Stripe_Client( $api_key );
		$pi     = $client->get(
			'payment_intents/' . $intent_id,
			array( 'expand' => array( 'latest_charge', 'latest_charge.dispute', 'latest_charge.review' ) )
		);

		if ( is_wp_error( $pi ) || ! is_array( $pi ) ) {
			return;
		}

		$drift = self::check_stuck_pending( $order, $pi );
		if ( null === $drift ) {
			$drift = self::check_wrongly_cancelled( $order, $pi );
		}
		if ( null !== $drift ) {
			self::upsert_drift( $order_id, $intent_id, $drift );
		}
	}

	/**
	 * Pass B: lists Stripe events that failed delivery to at least one
	 * webhook endpoint. Diagnostic only — never writes to wsr_drift_log.
	 *
	 * Per TECHNICAL_SPEC.md: this cannot be the orphaned-charge detection
	 * mechanism (that's Pass A's job) because a webhook the gateway
	 * couldn't map to an order still gets acked 200 — delivery succeeded,
	 * so `delivery_success=false` returns nothing for that case. What this
	 * *can* tell a merchant is narrower and still valuable: "your webhook
	 * endpoint is currently failing to receive events," independent of
	 * whether any specific order has drifted yet.
	 *
	 * @return array|WP_Error
	 */
	public static function run_pass_b() {
		$api_key = WSR_Settings::get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wsr_no_api_key', __( 'No Stripe API key is configured.', 'woo-stripe-reconcile' ) );
		}

		$client       = new WSR_Stripe_Client( $api_key );
		$window_start = time() - ( self::WINDOW_DAYS * DAY_IN_SECONDS );

		$result = $client->get(
			'events',
			array(
				'created'          => array( 'gte' => $window_start ),
				'delivery_success' => false,
				'types'            => self::PASS_B_EVENT_TYPES,
				'limit'            => 100,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$events = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();

		return array(
			'undelivered_count' => count( $events ),
			'sample_event_ids'  => array_slice( wp_list_pluck( $events, 'id' ), 0, 10 ),
			'checked_at'        => current_time( 'mysql', true ),
		);
	}

	/**
	 * Webhook health-check (FEATURES.md #11): compares each enabled
	 * webhook endpoint's URL against this site's own host. Directly
	 * targets the stale-staging-domain root cause documented in
	 * PROBLEM.md's best piece of evidence — a merchant whose endpoint
	 * points at an old domain would never be told so otherwise.
	 *
	 * @return array|WP_Error
	 */
	public static function run_webhook_health_check() {
		$api_key = WSR_Settings::get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wsr_no_api_key', __( 'No Stripe API key is configured.', 'woo-stripe-reconcile' ) );
		}

		$client = new WSR_Stripe_Client( $api_key );
		$result = $client->get( 'webhook_endpoints', array( 'limit' => 100 ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$endpoints = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$site_host = self::normalize_host( home_url() );

		$matching   = array();
		$mismatched = array();

		foreach ( $endpoints as $endpoint ) {
			if ( empty( $endpoint['url'] ) || 'enabled' !== ( $endpoint['status'] ?? '' ) ) {
				continue; // A disabled endpoint isn't a live misconfiguration to flag.
			}
			if ( self::normalize_host( $endpoint['url'] ) === $site_host ) {
				$matching[] = $endpoint['url'];
			} else {
				$mismatched[] = $endpoint['url'];
			}
		}

		return array(
			'endpoints_checked'        => count( $endpoints ),
			'matching_endpoint_found'  => ! empty( $matching ),
			'mismatched_endpoint_urls' => $mismatched,
			'no_endpoints_configured'  => empty( $endpoints ),
			'checked_at'               => current_time( 'mysql', true ),
		);
	}
}
