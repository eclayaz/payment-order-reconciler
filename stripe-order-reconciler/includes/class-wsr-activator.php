<?php
/**
 * Activation: dependency check, table creation, schema versioning.
 *
 * See TECHNICAL_SPEC.md's Data model section for the reasoning behind the
 * two tables, especially wsr_drift_log's generated open_key column, which
 * exists because MySQL/MariaDB don't support the partial ("WHERE status =
 * 'open'") unique index an earlier revision of this spec assumed was
 * available — a generated column plus a plain UNIQUE KEY (which MySQL
 * ignores NULLs for) reproduces the same semantics correctly.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Activator {

	/**
	 * Current schema version. Bump this whenever wsr_event_ledger or
	 * wsr_drift_log's structure changes — create_or_upgrade_tables() below
	 * re-runs dbDelta() (safe/idempotent — it diffs against the existing
	 * schema) whenever the installed version doesn't match this constant.
	 */
	const SCHEMA_VERSION = '1.0.0';

	const SCHEMA_VERSION_OPTION = 'wsr_schema_version';

	public static function activate() {
		if ( ! self::dependencies_met_at_activation() ) {
			// Can't reliably show an admin_notice from inside the
			// activation hook itself (the redirect back to the plugins
			// screen happens first) — deactivate immediately and leave a
			// transient so the plugins screen can explain why.
			deactivate_plugins( WSR_PLUGIN_BASENAME );
			set_transient( 'wsr_activation_error', true, 30 );
			return;
		}

		self::create_or_upgrade_tables();
	}

	/**
	 * Activation-time dependency check.
	 *
	 * Deliberately stricter than the runtime check in the main plugin file:
	 * at activation, WooCommerce/the gateway plugin might not have loaded
	 * yet this request even if they're both listed active, so we check the
	 * active_plugins option directly rather than class_exists().
	 */
	private static function dependencies_met_at_activation() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'woocommerce/woocommerce.php' )
			&& is_plugin_active( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' );
	}

	/**
	 * Creates both tables on first activation, or brings them up to
	 * SCHEMA_VERSION on a version bump after an update.
	 */
	public static function create_or_upgrade_tables() {
		$installed_version = get_option( self::SCHEMA_VERSION_OPTION, '' );

		if ( $installed_version === self::SCHEMA_VERSION ) {
			return;
		}

		self::create_event_ledger_table();
		self::create_drift_log_table();
		self::add_drift_log_open_key_column();

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * wsr_event_ledger — dedup ledger for the real-time hook listeners and
	 * the Pass B (undelivered-events) diagnostic only. PaymentIntents
	 * (Pass A's subject) carry no Stripe event ID, so Pass A's idempotency
	 * is handled entirely by wsr_drift_log's open_key column instead — see
	 * FEATURES.md #5.
	 */
	private static function create_event_ledger_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'wsr_event_ledger';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * wsr_drift_log — the drift record and fix audit log combined.
	 *
	 * The open_key generated column and its unique index are added
	 * separately in add_drift_log_open_key_column(), not here: dbDelta's
	 * parser is regex-based and unreliable for GENERATED ALWAYS AS syntax,
	 * so the base table is created via dbDelta as normal and the generated
	 * column is added with a direct ALTER TABLE afterward.
	 */
	private static function create_drift_log_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'wsr_drift_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NULL DEFAULT NULL,
			stripe_object_id VARCHAR(255) NOT NULL,
			drift_type VARCHAR(30) NOT NULL,
			severity VARCHAR(10) NOT NULL DEFAULT 'high',
			local_status_at_detection VARCHAR(50) NULL DEFAULT NULL,
			stripe_status_at_detection VARCHAR(50) NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			first_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			detection_count INT UNSIGNED NOT NULL DEFAULT 1,
			resolved_at DATETIME NULL DEFAULT NULL,
			resolved_by VARCHAR(20) NULL DEFAULT NULL,
			details LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY stripe_object_id (stripe_object_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Adds wsr_drift_log.open_key: CONCAT(stripe_object_id, ':', drift_type)
	 * when status is 'open' or 'dismissed', NULL when 'fixed' — with a
	 * plain UNIQUE KEY on it (MySQL/MariaDB unique indexes ignore NULLs,
	 * which gives exactly the intended semantics: an open or dismissed
	 * drift blocks re-insertion of the same (object, type) pair; a fixed
	 * row's NULL key allows a genuinely new future occurrence to be
	 * logged). See TECHNICAL_SPEC.md's Data model section for why this
	 * replaced an earlier, MySQL-incompatible partial-unique-index design.
	 *
	 * Requires MySQL 5.7+ / MariaDB 10.2+ for generated columns — both are
	 * well below WordPress's own current minimum supported database
	 * versions, so this isn't an additional constraint on top of running
	 * WordPress/WooCommerce at all.
	 *
	 * Idempotent: checks information_schema before altering, since
	 * activation can run more than once (reactivation, multisite network
	 * activation across sites) and dbDelta itself doesn't manage this
	 * column.
	 */
	private static function add_drift_log_open_key_column() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wsr_drift_log';

		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'open_key'",
				DB_NAME,
				$table_name
			)
		);

		if ( '0' !== $column_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is our own constant prefix, no user input.
		$wpdb->query(
			"ALTER TABLE {$table_name}
			 ADD COLUMN open_key VARCHAR(300)
			 GENERATED ALWAYS AS (
			 	CASE WHEN status IN ('open', 'dismissed')
			 	     THEN CONCAT(stripe_object_id, ':', drift_type)
			 	     ELSE NULL
			 	END
			 ) STORED"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is our own constant prefix, no user input.
		$wpdb->query( "ALTER TABLE {$table_name} ADD UNIQUE KEY open_key (open_key)" );
	}

	public static function deactivate() {
		// Deliberately no data cleanup on deactivation — a merchant
		// deactivating temporarily (e.g. during troubleshooting) shouldn't
		// lose drift history. Uninstall.php (added later) handles opt-in
		// full data removal on delete instead.
	}
}
