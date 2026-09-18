<?php
/**
 * The real drift dashboard (TECHNICAL_SPEC.md build-order step 6),
 * replacing the plain preview table that stood in for it during earlier
 * steps. Categories match what v1 actually produces: stuck_pending /
 * wrongly_cancelled_paid / orphaned_charge / needs_review — no
 * refund-mismatch category, since that check is v1.1 scope.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Admin_Dashboard {

	const CAPABILITY = 'manage_woocommerce';

	/** Hook suffix add_submenu_page() returns — used to scope enqueue_assets() to only this page. */
	private static $hook_suffix;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		self::$hook_suffix = add_submenu_page(
			'woocommerce',
			__( 'Payment Reconciliation', 'driftwatch-order-reconciler-for-stripe' ),
			__( 'Payment Reconciliation', 'driftwatch-order-reconciler-for-stripe' ),
			self::CAPABILITY,
			'wsr-dashboard',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Registers the bulk-Fix confirm script as a proper enqueued asset
	 * instead of a raw inline <script> tag (flagged by the WP.org Plugin
	 * Check tool — plugins should use wp_enqueue_script()/
	 * wp_add_inline_script() rather than printing <script> directly, per
	 * https://developer.wordpress.org/plugins/plugin-basics/best-practices/).
	 * `false` as the src registers a handle with no external file, purely
	 * to carry the inline script below — a well-established core pattern
	 * for a script with no standalone file of its own.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( self::$hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_register_script( 'wsr-bulk-fix-confirm', false, array(), WSR_VERSION, true );
		wp_enqueue_script( 'wsr-bulk-fix-confirm' );
		wp_add_inline_script( 'wsr-bulk-fix-confirm', self::bulk_fix_confirm_script() );
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		require_once WSR_PLUGIN_DIR . 'includes/class-wsr-drift-list-table.php';
		$table = new WSR_Drift_List_Table();

		// Mirrors WP core's own edit.php: bulk actions are processed
		// inline on this same page (not admin-post.php) before the table
		// is prepared, then redirected-after-post so a refresh can't
		// resubmit the same action.
		self::maybe_process_bulk_action();

		$table->prepare_items();

		$notice = isset( $_GET['wsr_notice'] ) ? sanitize_key( wp_unslash( $_GET['wsr_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice.

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payment Reconciliation', 'driftwatch-order-reconciler-for-stripe' ); ?></h1>
			<?php self::render_notice( $notice ); ?>
			<?php
			// Bug fix (independent review, round 2): TECHNICAL_SPEC.md
			// names "last successful run" and webhook health as primary
			// dashboard elements — they only ever lived on the Settings
			// screen. Shared with WSR_Settings' own coverage block rather
			// than duplicated, so both stay in sync automatically.
			WSR_Settings::render_coverage_status();
			?>
			<form method="get" id="wsr-drift-table-form">
				<input type="hidden" name="page" value="wsr-dashboard" />
				<?php $table->views(); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Bulk Fix/Dismiss on the drift list table — each selected row goes
	 * through the exact same WSR_Fixer::apply_fix()/dismiss() a single
	 * row's own Fix/Dismiss link would use, so live re-verification, the
	 * lock check, and the fixable-type check all apply identically.
	 * Bounded by the list table's own PER_PAGE (checkboxes only exist for
	 * rows on the current page), so no separate cap is needed here.
	 *
	 * Redirects and exits on an actual bulk submission; returns silently
	 * (nothing to process) otherwise.
	 */
	private static function maybe_process_bulk_action() {
		// Not using WP_List_Table::current_action() here: the installed core's
		// implementation (confirmed against wp-admin/includes/class-wp-list-table.php)
		// only ever checks $_REQUEST['action'] (the top dropdown) and
		// never falls back to 'action2' (the bottom one) — so a bulk
		// action submitted via the bottom "Apply" button would silently
		// never be recognized. Checking both request keys directly here
		// works regardless of which one a given core version's
		// current_action() happens to also check.
		$action = '';
		foreach ( array( 'action', 'action2' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only used to select which branch to take; check_admin_referer() below is the actual verification, before any effectful action.
			if ( isset( $_REQUEST[ $key ] ) && in_array( $_REQUEST[ $key ], array( 'wsr_bulk_fix', 'wsr_bulk_dismiss' ), true ) ) {
				$action = $_REQUEST[ $key ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- validated by the in_array() whitelist check just above; check_admin_referer() below is the actual verification, before any effectful action.
				break;
			}
		}
		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'bulk-drifts' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'driftwatch-order-reconciler-for-stripe' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() just above.
		$ids = isset( $_REQUEST['drift_id'] ) ? array_unique( array_filter( array_map( 'absint', (array) $_REQUEST['drift_id'] ) ) ) : array();

		$ok     = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			if ( 'wsr_bulk_fix' === $action ) {
				$result = WSR_Fixer::apply_fix( $id, get_current_user_id() );
				is_wp_error( $result ) ? $failed++ : $ok++;
			} else {
				WSR_Fixer::dismiss( $id ) ? $ok++ : $failed++;
			}
		}

		$notice = 'wsr_bulk_fix' === $action ? 'bulk_fixed' : 'bulk_dismissed';
		$target = add_query_arg(
			array(
				'wsr_notice' => $notice,
				'wsr_ok'     => $ok,
				'wsr_failed' => $failed,
			),
			remove_query_arg( array( 'action', 'action2', 'drift_id', '_wpnonce', '_wp_http_referer' ), admin_url( 'admin.php?page=wsr-dashboard' ) )
		);

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * A bulk Fix mutates every selected order at once (emails included) —
	 * the single-row Fix link already confirms; this must too, and can't
	 * rely on that link's own onclick since the bulk "Apply" button is a
	 * different control entirely. Checks both the top and bottom bulk
	 * dropdowns WP_List_Table renders — if either is set to Fix at
	 * submit time, confirm. Harmless if it also fires for a Dismiss
	 * submission with an unrelated dropdown left on Fix; it never
	 * suppresses a needed confirmation, only occasionally shows one extra.
	 *
	 * @return string Raw JS, passed to wp_add_inline_script() by enqueue_assets() —
	 *                 never echoed directly, so the $message placeholder below
	 *                 doesn't need escaping beyond wp_json_encode()'s own.
	 */
	private static function bulk_fix_confirm_script() {
		$message = wp_json_encode(
			__( 'Mark the selected orders as paid and completed based on Stripe\'s current record? This emails each customer and cannot be undone from here.', 'driftwatch-order-reconciler-for-stripe' )
		);

		return "( function() {
			var form = document.getElementById( 'wsr-drift-table-form' );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', function( e ) {
				var top    = document.getElementById( 'bulk-action-selector-top' );
				var bottom = document.getElementById( 'bulk-action-selector-bottom' );
				var isFix  = ( top && 'wsr_bulk_fix' === top.value ) || ( bottom && 'wsr_bulk_fix' === bottom.value );
				if ( ! isFix ) {
					return;
				}
				if ( 0 === form.querySelectorAll( 'input[name=\"drift_id[]\"]:checked' ).length ) {
					return;
				}
				if ( ! window.confirm( {$message} ) ) {
					e.preventDefault();
				}
			} );
		} )();";
	}

	private static function render_notice( $notice ) {
		$messages = array(
			'fix_applied' => array( 'success', __( 'Fix applied — the order status was synced to Stripe\'s record.', 'driftwatch-order-reconciler-for-stripe' ) ),
			'dismissed'   => array( 'success', __( 'Drift dismissed. It will not reappear unless it recurs after being fixed.', 'driftwatch-order-reconciler-for-stripe' ) ),
			'fix_error'   => array( 'error', self::get_fix_error_message() ),
		);

		if ( in_array( $notice, array( 'bulk_fixed', 'bulk_dismissed' ), true ) ) {
			$messages[ $notice ] = self::bulk_notice_message( $notice );
		}

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $text ) = $messages[ $notice ];
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	/**
	 * @param string $notice 'bulk_fixed' | 'bulk_dismissed'.
	 * @return array{0: string, 1: string} Notice type + message, same
	 *                                      shape as render_notice()'s
	 *                                      static $messages entries.
	 */
	private static function bulk_notice_message( $notice ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counts from the bulk-action redirect this class itself issued; no state change.
		$ok = isset( $_GET['wsr_ok'] ) ? absint( $_GET['wsr_ok'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$failed = isset( $_GET['wsr_failed'] ) ? absint( $_GET['wsr_failed'] ) : 0;
		$verb   = 'bulk_fixed' === $notice ? __( 'fixed', 'driftwatch-order-reconciler-for-stripe' ) : __( 'dismissed', 'driftwatch-order-reconciler-for-stripe' );

		$text = sprintf(
			/* translators: 1: number succeeded, 2: "fixed" or "dismissed" */
			_n( '%1$d row %2$s.', '%1$d rows %2$s.', $ok, 'driftwatch-order-reconciler-for-stripe' ),
			$ok,
			$verb
		);
		if ( $failed > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: number that could not be processed */
				_n( '%d could not be processed — try it individually to see why (still-disputed, locked, or already resolved are the usual reasons).', '%d could not be processed — try each individually to see why (still-disputed, locked, or already resolved are the usual reasons).', $failed, 'driftwatch-order-reconciler-for-stripe' ),
				$failed
			);
		}

		return array( $failed > 0 ? 'warning' : 'success', $text );
	}

	private static function get_fix_error_message() {
		$message = get_transient( 'wsr_fix_error_' . get_current_user_id() );
		return $message ? $message : __( 'The fix could not be applied.', 'driftwatch-order-reconciler-for-stripe' );
	}
}
