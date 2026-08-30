<?php
/**
 * WP_List_Table showing wsr_drift_log rows, with Fix/Dismiss row actions.
 *
 * Fix is only offered for stuck_pending/wrongly_cancelled_paid rows at
 * non-info severity — orphaned_charge/needs_review have no order to act
 * on, and info-severity rows are currently disputed/under-review, where
 * PROBLEM.md's corrected principle requires no automated action while
 * that's active. WSR_Fixer::apply_fix() enforces both of these itself
 * too, so this is a UX nicety, not the only safety check.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WSR_Drift_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'drift',
				'plural'   => 'drifts',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'order'    => __( 'Order', 'woo-stripe-reconcile' ),
			'object'   => __( 'Stripe object', 'woo-stripe-reconcile' ),
			'type'     => __( 'Type', 'woo-stripe-reconcile' ),
			'severity' => __( 'Severity', 'woo-stripe-reconcile' ),
			'states'   => __( 'Local → Stripe', 'woo-stripe-reconcile' ),
			'seen'     => __( 'Detected', 'woo-stripe-reconcile' ),
			'actions'  => __( 'Actions', 'woo-stripe-reconcile' ),
		);
	}

	protected function get_current_status() {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change.
		return in_array( $status, array( 'open', 'fixed', 'dismissed' ), true ) ? $status : 'open';
	}

	protected function get_views() {
		global $wpdb;
		$table   = $wpdb->prefix . 'wsr_drift_log';
		$current = $this->get_current_status();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is our own constant prefix.
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) as c FROM {$table} GROUP BY status", OBJECT_K );

		$labels = array(
			'open'      => __( 'Open', 'woo-stripe-reconcile' ),
			'fixed'     => __( 'Fixed', 'woo-stripe-reconcile' ),
			'dismissed' => __( 'Dismissed', 'woo-stripe-reconcile' ),
		);

		$views = array();
		foreach ( $labels as $status => $label ) {
			$count = isset( $counts[ $status ] ) ? (int) $counts[ $status ]->c : 0;
			$class = ( $current === $status ) ? ' class="current"' : '';
			$url   = add_query_arg(
				array(
					'page'   => 'wsr-dashboard',
					'status' => $status,
				),
				admin_url( 'admin.php' )
			);
			$views[ $status ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), $count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $class is one of two literal constant strings, not user input.
		}
		return $views;
	}

	public function prepare_items() {
		global $wpdb;
		$table  = $wpdb->prefix . 'wsr_drift_log';
		$status = $this->get_current_status();

		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is our own constant prefix.
		$total_items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is our own constant prefix; $offset/PER_PAGE are ints from our own pagination.
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s
				 ORDER BY FIELD(severity, 'critical', 'high', 'info') ASC, detected_at DESC
				 LIMIT %d OFFSET %d",
				$status,
				self::PER_PAGE,
				$offset
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'order':
				return $this->render_order_column( $item );
			case 'object':
				return $this->render_object_column( $item );
			case 'type':
				return esc_html( str_replace( '_', ' ', $item->drift_type ) );
			case 'severity':
				return $this->render_severity_badge( $item->severity );
			case 'states':
				return esc_html( ( $item->local_status_at_detection ?: '—' ) . ' → ' . ( $item->stripe_status_at_detection ?: '—' ) );
			case 'seen':
				return sprintf(
					/* translators: 1: human-readable time since detection, 2: number of times detected */
					esc_html__( '%1$s ago (×%2$d)', 'woo-stripe-reconcile' ),
					esc_html( human_time_diff( strtotime( $item->detected_at . ' UTC' ), time() ) ),
					(int) $item->detection_count
				);
			case 'actions':
				return $this->render_actions( $item );
			default:
				return '';
		}
	}

	private function render_order_column( $item ) {
		if ( ! $item->order_id ) {
			return '—';
		}
		$order = wc_get_order( $item->order_id );
		if ( ! $order ) {
			return sprintf( '#%d (deleted)', (int) $item->order_id );
		}
		return sprintf(
			'<a href="%s">#%d</a> — %s',
			esc_url( $order->get_edit_order_url() ), // HPOS-aware; correct across storage modes.
			$order->get_id(),
			esc_html( wc_get_order_status_name( $order->get_status() ) )
		);
	}

	private function render_object_column( $item ) {
		$is_test = 0 === strpos( (string) WSR_Settings::get_api_key(), 'rk_test_' );
		$mode    = $is_test ? 'test/' : '';
		// stripe_object_id is a PaymentIntent ID in every v1 drift type except
		// the rare case where a Checkout Session ID had to be logged directly
		// (no resolvable PaymentIntent) — the two have different dashboard URLs.
		$path = ( 0 === strpos( $item->stripe_object_id, 'cs_' ) ) ? 'checkout/sessions' : 'payments';
		$url  = 'https://dashboard.stripe.com/' . $mode . $path . '/' . rawurlencode( $item->stripe_object_id );
		return sprintf( '<code><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></code>', esc_url( $url ), esc_html( $item->stripe_object_id ) );
	}

	private function render_severity_badge( $severity ) {
		$colors = array(
			'critical' => '#b32d2e',
			'high'     => '#996800',
			'info'     => '#646970',
		);
		$color = isset( $colors[ $severity ] ) ? $colors[ $severity ] : '#646970';
		return sprintf( '<span style="color:%s;font-weight:600;">%s</span>', esc_attr( $color ), esc_html( $severity ) );
	}

	private function render_actions( $item ) {
		if ( 'open' !== $item->status ) {
			return $item->resolved_by ? esc_html( 'by ' . $item->resolved_by ) : '';
		}

		$actions = array();

		$can_fix = in_array( $item->drift_type, array( 'stuck_pending', 'wrongly_cancelled_paid' ), true )
			&& 'info' !== $item->severity;

		// Bug fix (independent review, round 2): a single shared
		// NONCE_FIX/NONCE_DISMISS action meant a nonce lifted from one
		// row's link would also verify against any other row — same
		// capability either way so not a privilege escalation, but it
		// weakens per-object CSRF protection more than it needs to. A
		// nonce scoped to the specific drift_id (WSR_Fixer::fix_nonce_action())
		// only ever verifies for that one row.
		if ( $can_fix ) {
			$fix_url    = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'wsr_fix_drift',
						'drift_id' => $item->id,
					),
					admin_url( 'admin-post.php' )
				),
				WSR_Fixer::fix_nonce_action( $item->id )
			);
			$actions[] = sprintf(
				'<a class="button button-small button-primary" href="%s" onclick="return confirm(%s);">%s</a>',
				esc_url( $fix_url ),
				esc_attr( wp_json_encode( __( 'Mark this order as paid and completed based on Stripe\'s current record? This emails the customer and cannot be undone from here.', 'woo-stripe-reconcile' ) ) ),
				esc_html__( 'Fix', 'woo-stripe-reconcile' )
			);
		} elseif ( 'info' === $item->severity ) {
			$actions[] = '<span class="description">' . esc_html__( 'No fix while disputed/under review', 'woo-stripe-reconcile' ) . '</span>';
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'wsr_dismiss_drift',
					'drift_id' => $item->id,
				),
				admin_url( 'admin-post.php' )
			),
			WSR_Fixer::dismiss_nonce_action( $item->id )
		);
		$actions[] = sprintf( '<a class="button button-small" href="%s">%s</a>', esc_url( $dismiss_url ), esc_html__( 'Dismiss', 'woo-stripe-reconcile' ) );

		return implode( ' ', $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each entry already escaped when built above.
	}

	public function no_items() {
		esc_html_e( 'No drift in this category.', 'woo-stripe-reconcile' );
	}
}
