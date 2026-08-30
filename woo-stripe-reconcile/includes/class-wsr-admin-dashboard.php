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

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Stripe Reconciliation', 'woo-stripe-reconcile' ),
			__( 'Stripe Reconciliation', 'woo-stripe-reconcile' ),
			self::CAPABILITY,
			'wsr-dashboard',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		require_once WSR_PLUGIN_DIR . 'includes/class-wsr-drift-list-table.php';
		$table = new WSR_Drift_List_Table();
		$table->prepare_items();

		$notice = isset( $_GET['wsr_notice'] ) ? sanitize_key( wp_unslash( $_GET['wsr_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice.

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stripe Reconciliation', 'woo-stripe-reconcile' ); ?></h1>
			<?php self::render_notice( $notice ); ?>
			<form method="get">
				<input type="hidden" name="page" value="wsr-dashboard" />
				<?php $table->views(); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_notice( $notice ) {
		$messages = array(
			'fix_applied' => array( 'success', __( 'Fix applied — the order status was synced to Stripe\'s record.', 'woo-stripe-reconcile' ) ),
			'dismissed'   => array( 'success', __( 'Drift dismissed. It will not reappear unless it recurs after being fixed.', 'woo-stripe-reconcile' ) ),
			'fix_error'   => array( 'error', self::get_fix_error_message() ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $text ) = $messages[ $notice ];
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	private static function get_fix_error_message() {
		$message = get_transient( 'wsr_fix_error_' . get_current_user_id() );
		return $message ? $message : __( 'The fix could not be applied.', 'woo-stripe-reconcile' );
	}
}
