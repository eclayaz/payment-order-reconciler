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

	const EXPORTER_ERASER_ID = 'stripe-order-reconciler';

	/** Matches WordPress core's own page size convention for privacy exporters/erasers. */
	const PAGE_SIZE = 50;

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters[ self::EXPORTER_ERASER_ID ] = array(
			'exporter_friendly_name' => __( 'Woo Stripe Reconcile', 'stripe-order-reconciler' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers[ self::EXPORTER_ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'Woo Stripe Reconcile', 'stripe-order-reconciler' ),
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is our own constant prefix, $placeholders is a fixed count of %d tokens.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id IN ({$placeholders})", $order_ids ) );

			foreach ( $rows as $row ) {
				$export_items[] = array(
					'group_id'    => 'woo-stripe-reconcile-drift',
					'group_label' => __( 'Stripe Reconciliation Records', 'stripe-order-reconciler' ),
					'item_id'     => 'wsr-drift-' . $row->id,
					'data'        => array(
						array(
							'name'  => __( 'Order', 'stripe-order-reconciler' ),
							'value' => '#' . $row->order_id,
						),
						array(
							'name'  => __( 'Stripe object', 'stripe-order-reconciler' ),
							'value' => $row->stripe_object_id,
						),
						array(
							'name'  => __( 'Drift type', 'stripe-order-reconciler' ),
							'value' => $row->drift_type,
						),
						array(
							'name'  => __( 'Status', 'stripe-order-reconciler' ),
							'value' => $row->status,
						),
						array(
							'name'  => __( 'First detected', 'stripe-order-reconciler' ),
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is our own constant prefix, $placeholders is a fixed count of %d tokens.
			$deleted       = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE order_id IN ({$placeholders})", $order_ids ) );
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
