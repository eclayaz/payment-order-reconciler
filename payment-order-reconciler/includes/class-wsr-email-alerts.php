<?php
/**
 * Email alert on newly-detected high/critical drift (TECHNICAL_SPEC.md
 * build-order step 8). Intentionally minimal for v1 — one plain-text
 * email per run that found something new, to a single configured address
 * (falling back to the site admin email). Slack/richer notification is
 * out of scope per FEATURES.md's explicit deferral list.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Email_Alerts {

	const OPTION_ALERT_EMAIL = 'wsr_alert_email';

	public static function get_alert_email() {
		$email = get_option( self::OPTION_ALERT_EMAIL, '' );
		return $email ? $email : get_option( 'admin_email' );
	}

	/**
	 * @param array $new_alerts List of {order_id, drift_type, severity,
	 *                           stripe_object_id} for rows genuinely
	 *                           inserted (not merely re-detected) this
	 *                           run, at high/critical severity — see
	 *                           WSR_Reconciler::run_pass_a()'s new_alerts
	 *                           collection.
	 */
	public static function maybe_send( array $new_alerts ) {
		if ( empty( $new_alerts ) ) {
			return;
		}

		$to = self::get_alert_email();
		if ( ! is_email( $to ) ) {
			return;
		}

		$count   = count( $new_alerts );
		$subject = sprintf(
			/* translators: %d: number of newly detected payment/order mismatches */
			_n( 'Payment Order Reconciler: %d new payment issue found', 'Payment Order Reconciler: %d new payment issues found', $count, 'driftwatch-order-reconciler-for-stripe' ),
			$count
		);

		$lines   = array();
		$lines[] = __( 'The following order/payment mismatches were just detected:', 'driftwatch-order-reconciler-for-stripe' );
		$lines[] = '';
		foreach ( $new_alerts as $alert ) {
			$lines[] = sprintf(
				'- Order #%1$s: %2$s (%3$s) — %4$s',
				$alert['order_id'] ? $alert['order_id'] : '—',
				str_replace( '_', ' ', $alert['drift_type'] ),
				$alert['severity'],
				$alert['stripe_object_id']
			);
		}
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: dashboard URL */
			__( 'Review and fix at: %s', 'driftwatch-order-reconciler-for-stripe' ),
			admin_url( 'admin.php?page=wsr-dashboard' )
		);

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
