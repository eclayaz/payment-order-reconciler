<?php
/**
 * wsr_drift_log's WP Privacy integration. Drift rows are order-linked data
 * (order ID, Stripe object ID, status snapshots) — the same category of
 * personal data WooCommerce's own order tables already are — so they
 * belong in a customer's data export/erasure request the same way order
 * data does. Documented as a real requirement in TECHNICAL_SPEC.md's
 * Compliance section; this was the one piece of that section not actually
 * built until now.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Audit_Log {

	const EXPORTER_ERASER_ID = 'payment-order-reconciler';

	/** Matches WordPress core's own page size convention for privacy exporters/erasers. */
	const PAGE_SIZE = 50;

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters[ self::EXPORTER_ERASER_ID ] = array(
			'exporter_friendly_name' => __( 'Payment Order Reconciler', 'payment-order-reconciler-for-stripe' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers[ self::EXPORTER_ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'Payment Order Reconciler', 'payment-order-reconciler-for-stripe' ),
			'callback'              => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * Same lookup WooCommerce's own privacy exporters/erasers use: orders
	 * matched by billing email, paginated identically to how WP core's
	 * Tools > Export/Erase Personal Data screen drives every registered
	 * exporter/eraser (repeated calls with an incrementing $page until
	 * 'done' is true).
	 */
	private static function get_order_ids_for_email( $email_address, $page ) {
		return wc_get_orders(
			array(
				'billing_email' => $email_address,
				'limit'         => self::PAGE_SIZE,
				'page'          => $page,
				'return'        => 'ids',
			)
		);
	}

	public static function export_data( $email_address, $page = 1 ) {
		$order_ids    = self::get_order_ids_for_email( $email_address, (int) $page );
		$export_items = array();

		if ( ! empty( $order_ids ) ) {
			global $wpdb;
			$table        = $wpdb->prefix . 'wsr_drift_log';
			$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- %i escapes the table identifier; $placeholders is a fixed count of %d tokens, values bound via prepare() below.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id IN ({$placeholders})", array_merge( array( $table ), $order_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders} is a fixed-count string of literal %d tokens, not a value.

			foreach ( $rows as $row ) {
				$export_items[] = array(
					'group_id'    => 'woo-stripe-reconcile-drift',
					'group_label' => __( 'Payment Reconciliation Records', 'payment-order-reconciler-for-stripe' ),
					'item_id'     => 'wsr-drift-' . $row->id,
					'data'        => array(
						array(
							'name'  => __( 'Order', 'payment-order-reconciler-for-stripe' ),
							'value' => '#' . $row->order_id,
						),
						array(
							'name'  => __( 'Stripe object', 'payment-order-reconciler-for-stripe' ),
							'value' => $row->stripe_object_id,
						),
						array(
							'name'  => __( 'Drift type', 'payment-order-reconciler-for-stripe' ),
							'value' => $row->drift_type,
						),
						array(
							'name'  => __( 'Status', 'payment-order-reconciler-for-stripe' ),
							'value' => $row->status,
						),
						array(
							'name'  => __( 'First detected', 'payment-order-reconciler-for-stripe' ),
							'value' => $row->first_detected_at,
						),
					),
				);
			}
		}

		return array(
			'data' => $export_items,
			'done' => count( $order_ids ) < self::PAGE_SIZE,
		);
	}

	public static function erase_data( $email_address, $page = 1 ) {
		$order_ids     = self::get_order_ids_for_email( $email_address, (int) $page );
		$items_removed = false;

		if ( ! empty( $order_ids ) ) {
			global $wpdb;
			$table        = $wpdb->prefix . 'wsr_drift_log';
			$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- %i escapes the table identifier; $placeholders is a fixed count of %d tokens, values bound via prepare() below.
			$deleted       = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE order_id IN ({$placeholders})", array_merge( array( $table ), $order_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders} is a fixed-count string of literal %d tokens, not a value.
			$items_removed = $deleted > 0;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $order_ids ) < self::PAGE_SIZE,
		);
	}
}
