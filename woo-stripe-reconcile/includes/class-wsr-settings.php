<?php
/**
 * Settings screen: restricted API key entry + validation, environment
 * mismatch warning. The drift dashboard is a separate class added in a
 * later build-order step (TECHNICAL_SPEC.md step 6) — this one is settings
 * only, per step 2.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Settings {

	const OPTION_API_KEY       = 'wsr_stripe_restricted_key';
	const OPTION_SCOPE_STATUS  = 'wsr_stripe_scope_status'; // Map of scope => true|error string. Never the key itself.
	const KEY_CONSTANT         = 'WSR_STRIPE_RESTRICTED_KEY';
	const NONCE_ACTION         = 'wsr_save_settings';
	const NONCE_ACTION_RUN     = 'wsr_run_pass_a';
	const CAPABILITY           = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_wsr_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wsr_run_pass_a', array( __CLASS__, 'handle_run_pass_a' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Stripe Reconcile', 'woo-stripe-reconcile' ),
			__( 'Stripe Reconcile', 'woo-stripe-reconcile' ),
			self::CAPABILITY,
			'wsr-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * The key actually in use: a wp-config constant takes priority over the
	 * stored option. Per TECHNICAL_SPEC.md, this is the documented secure
	 * path since WordPress core's own Secrets API (wp_set_secret()) hasn't
	 * shipped yet — WordPress.org doesn't currently mandate encrypting a
	 * plugin-stored option, but the constant path avoids the question
	 * entirely for merchants who have file access.
	 */
	public static function get_api_key() {
		if ( self::key_is_from_constant() ) {
			return constant( self::KEY_CONSTANT );
		}
		return get_option( self::OPTION_API_KEY, '' );
	}

	public static function key_is_from_constant() {
		return defined( self::KEY_CONSTANT ) && '' !== constant( self::KEY_CONSTANT );
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-stripe-reconcile' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		if ( self::key_is_from_constant() ) {
			// The constant always wins — saving from the UI would be
			// misleading, so refuse rather than silently no-op.
			wp_safe_redirect( add_query_arg( 'wsr_notice', 'constant_locked', wp_get_referer() ) );
			exit;
		}

		$submitted_key = isset( $_POST['wsr_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wsr_api_key'] ) ) : '';

		if ( '' === $submitted_key ) {
			delete_option( self::OPTION_API_KEY );
			delete_option( self::OPTION_SCOPE_STATUS );
			wp_safe_redirect( add_query_arg( 'wsr_notice', 'cleared', wp_get_referer() ) );
			exit;
		}

		// Enforce the trust model at the UI level, not just in docs: refuse
		// anything that isn't a restricted key. A regular secret key
		// (sk_live_/sk_test_) has full write access, which this plugin must
		// never hold even if a merchant pastes one in by mistake.
		if ( ! preg_match( '/^rk_(live|test)_/', $submitted_key ) ) {
			wp_safe_redirect( add_query_arg( 'wsr_notice', 'not_restricted_key', wp_get_referer() ) );
			exit;
		}

		update_option( self::OPTION_API_KEY, $submitted_key, false ); // autoload=false: only needed on this settings screen and the reconciler run, not every page load.

		$client  = new WSR_Stripe_Client( $submitted_key );
		$results = $client->check_scopes();
		update_option( self::OPTION_SCOPE_STATUS, $results, false );

		wp_safe_redirect( add_query_arg( 'wsr_notice', 'saved', wp_get_referer() ) );
		exit;
	}

	/**
	 * Runs Pass A synchronously on admin demand — the "manual Run now
	 * button" from TECHNICAL_SPEC.md's build-order step 3, standing in for
	 * the admin dashboard (step 6) and the ActionScheduler wrapper (step 4)
	 * that don't exist yet. Stores the run summary in a short-lived,
	 * per-user transient so it survives the redirect-after-post.
	 */
	public static function handle_run_pass_a() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-stripe-reconcile' ) );
		}
		check_admin_referer( self::NONCE_ACTION_RUN );

		$result = WSR_Reconciler::run_pass_a();

		if ( is_wp_error( $result ) ) {
			set_transient( 'wsr_pass_a_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'wsr_notice', 'pass_a_error', wp_get_referer() ) );
			exit;
		}

		set_transient( 'wsr_pass_a_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'wsr_notice', 'pass_a_ran', wp_get_referer() ) );
		exit;
	}

	/**
	 * Environment mismatch check. wp_get_environment_type() defaults to
	 * 'production' when unset, which is true of most real-world WordPress
	 * installs — so it's combined with a restricted-key-prefix vs.
	 * site-URL heuristic. See TECHNICAL_SPEC.md's Setup section: this is
	 * the check that actually catches a stale key copied into a staging
	 * clone, which wp_get_environment_type() alone would miss.
	 */
	public static function environment_warning() {
		$key = self::get_api_key();
		if ( '' === $key ) {
			return '';
		}

		$is_test_key = ( 0 === strpos( $key, 'rk_test_' ) );
		$env_type    = wp_get_environment_type();
		$host        = wp_parse_url( home_url(), PHP_URL_HOST );
		$looks_dev   = 'production' !== $env_type || (bool) preg_match( '/\.(local|test)$|localhost|staging|\bdev\b/i', (string) $host );

		if ( ! $is_test_key && $looks_dev ) {
			return __( 'This looks like a non-production site, but a LIVE restricted key (rk_live_...) is configured. Double-check that\'s intentional — reconciliation checks would run against real payment data.', 'woo-stripe-reconcile' );
		}

		if ( $is_test_key && 'production' === $env_type && ! $looks_dev ) {
			return __( 'This looks like a production site, but a TEST restricted key (rk_test_...) is configured — reconciliation checks will run against sandbox data, not real payments, until a live key is set.', 'woo-stripe-reconcile' );
		}

		return '';
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$current_key   = self::get_api_key();
		$from_constant = self::key_is_from_constant();
		$masked_key    = $current_key ? substr( $current_key, 0, 12 ) . str_repeat( '•', 8 ) : '';
		$scope_status  = get_option( self::OPTION_SCOPE_STATUS, array() );
		$env_warning   = self::environment_warning();
		$notice        = isset( $_GET['wsr_notice'] ) ? sanitize_key( wp_unslash( $_GET['wsr_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stripe Reconcile — Settings', 'woo-stripe-reconcile' ); ?></h1>

			<?php self::render_notice( $notice ); ?>

			<?php if ( $env_warning ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $env_warning ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				esc_html_e(
					'Paste a Stripe restricted, read-only API key here. It should never have permission to charge, refund, or move money — only to read PaymentIntents, Charges, Checkout Sessions, Events, Webhook Endpoints, and Disputes. Create one in Stripe under Developers → API keys → Create restricted key, setting exactly those six resources to Read and everything else to None.',
					'woo-stripe-reconcile'
				);
				?>
			</p>

			<?php if ( $from_constant ) : ?>
				<p>
					<em>
						<?php
						printf(
							/* translators: %s: wp-config.php constant name, as a <code> tag */
							esc_html__( 'This key is set via the %s constant in wp-config.php and cannot be changed here.', 'woo-stripe-reconcile' ),
							'<code>' . esc_html( self::KEY_CONSTANT ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant tag is our own literal string, already escaped.
						);
						?>
					</em>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="wsr_save_settings" />
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wsr_api_key"><?php esc_html_e( 'Restricted API key', 'woo-stripe-reconcile' ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="wsr_api_key"
									name="wsr_api_key"
									class="regular-text"
									autocomplete="off"
									placeholder="<?php echo esc_attr( $masked_key ? $masked_key : 'rk_live_... or rk_test_...' ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Leave blank and save to remove the currently configured key.', 'woo-stripe-reconcile' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save & Validate', 'woo-stripe-reconcile' ) ); ?>
				</form>
			<?php endif; ?>

			<?php if ( ! empty( $scope_status ) ) : ?>
				<h2><?php esc_html_e( 'Scope check results', 'woo-stripe-reconcile' ); ?></h2>
				<table class="widefat" style="max-width: 640px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Scope', 'woo-stripe-reconcile' ); ?></th>
							<th><?php esc_html_e( 'Status', 'woo-stripe-reconcile' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $scope_status as $scope => $result ) : ?>
						<tr>
							<td><?php echo esc_html( $scope ); ?></td>
							<td>
								<?php if ( true === $result ) : ?>
									<span style="color:#1a7f37;">&#10003; <?php esc_html_e( 'OK', 'woo-stripe-reconcile' ); ?></span>
								<?php else : ?>
									<span style="color:#b32d2e;">&#10007; <?php echo esc_html( $result ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'If any scope failed, edit the restricted key in your Stripe Dashboard (Developers → API keys) to add the missing permission, then save again here.', 'woo-stripe-reconcile' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $current_key ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Manual reconciliation (testing)', 'woo-stripe-reconcile' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Temporary testing affordance for build-order step 3 — this becomes a proper dashboard with Fix/Dismiss actions in a later step. Runs Pass A once, synchronously, against the last 30 days of PaymentIntents.', 'woo-stripe-reconcile' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION_RUN ); ?>
					<input type="hidden" name="action" value="wsr_run_pass_a" />
					<?php submit_button( __( 'Run reconciliation now', 'woo-stripe-reconcile' ), 'secondary' ); ?>
				</form>

				<?php self::render_pass_a_result(); ?>
				<?php self::render_drift_log_preview(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Displays the summary from the most recent manual Pass A run, read
	 * from the short-lived per-user transient set in handle_run_pass_a().
	 */
	private static function render_pass_a_result() {
		$result = get_transient( 'wsr_pass_a_result_' . get_current_user_id() );
		if ( ! is_array( $result ) ) {
			return;
		}

		$labels = array(
			'payment_intents_scanned'   => __( 'PaymentIntents scanned', 'woo-stripe-reconcile' ),
			'resolved_to_order'         => __( 'Resolved to a local order', 'woo-stripe-reconcile' ),
			'no_drift'                  => __( 'Resolved, no drift found', 'woo-stripe-reconcile' ),
			'stuck_pending_flagged'     => __( 'Stuck-pending flagged', 'woo-stripe-reconcile' ),
			'wrongly_cancelled_flagged' => __( 'Wrongly-cancelled-paid flagged', 'woo-stripe-reconcile' ),
			'needs_review'              => __( 'Needs review (unresolved)', 'woo-stripe-reconcile' ),
			'orphaned_charge_escalated' => __( 'Escalated to orphaned charge', 'woo-stripe-reconcile' ),
			'skipped_other_site'        => __( 'Skipped (belongs to another site)', 'woo-stripe-reconcile' ),
			'skipped_too_fresh'         => __( 'Skipped (too fresh, revisit next run)', 'woo-stripe-reconcile' ),
			'pages_fetched'             => __( 'API pages fetched', 'woo-stripe-reconcile' ),
		);

		echo '<h3>' . esc_html__( 'Last run results', 'woo-stripe-reconcile' ) . '</h3>';
		echo '<table class="widefat" style="max-width: 480px;"><tbody>';
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $result[ $key ] ) ) {
				continue;
			}
			echo '<tr><td>' . esc_html( $label ) . '</td><td><strong>' . esc_html( $result[ $key ] ) . '</strong></td></tr>';
		}
		echo '</tbody></table>';

		if ( ! empty( $result['errors'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( implode( '; ', $result['errors'] ) ) . '</p></div>';
		}
	}

	/**
	 * Raw preview of the most recent drift_log rows — a stand-in for the
	 * real admin dashboard (TECHNICAL_SPEC.md build-order step 6), just
	 * enough to see what Pass A actually wrote during manual testing.
	 */
	private static function render_drift_log_preview() {
		global $wpdb;
		$table = $wpdb->prefix . 'wsr_drift_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $table is our own constant prefix; read-only preview, no user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY detected_at DESC LIMIT 50" );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No drift recorded yet.', 'woo-stripe-reconcile' ) . '</p>';
			return;
		}

		echo '<h3>' . esc_html__( 'Drift log (most recent 50)', 'woo-stripe-reconcile' ) . '</h3>';
		echo '<table class="widefat"><thead><tr>
			<th>' . esc_html__( 'Order', 'woo-stripe-reconcile' ) . '</th>
			<th>' . esc_html__( 'Stripe object', 'woo-stripe-reconcile' ) . '</th>
			<th>' . esc_html__( 'Type', 'woo-stripe-reconcile' ) . '</th>
			<th>' . esc_html__( 'Severity', 'woo-stripe-reconcile' ) . '</th>
			<th>' . esc_html__( 'Status', 'woo-stripe-reconcile' ) . '</th>
			<th>' . esc_html__( 'Local → Stripe', 'woo-stripe-reconcile' ) . '</th>
			<th>' . esc_html__( 'Seen', 'woo-stripe-reconcile' ) . '</th>
		</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . ( $row->order_id ? esc_html( '#' . $row->order_id ) : '—' ) . '</td>';
			echo '<td><code>' . esc_html( $row->stripe_object_id ) . '</code></td>';
			echo '<td>' . esc_html( $row->drift_type ) . '</td>';
			echo '<td>' . esc_html( $row->severity ) . '</td>';
			echo '<td>' . esc_html( $row->status ) . '</td>';
			echo '<td>' . esc_html( $row->local_status_at_detection . ' → ' . $row->stripe_status_at_detection ) . '</td>';
			echo '<td>' . esc_html( $row->detected_at ) . ' (&times;' . (int) $row->detection_count . ')</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_notice( $notice ) {
		$messages = array(
			'saved'              => array( 'success', __( 'Key saved. See scope check results below.', 'woo-stripe-reconcile' ) ),
			'cleared'            => array( 'success', __( 'Key removed.', 'woo-stripe-reconcile' ) ),
			'not_restricted_key' => array(
				'error',
				__( 'That doesn\'t look like a restricted key (should start with rk_live_ or rk_test_). A regular secret key (sk_...) has full write access and is not supported by this plugin — create a restricted, read-only key instead.', 'woo-stripe-reconcile' ),
			),
			'constant_locked'    => array( 'error', __( 'The API key is locked via wp-config.php and cannot be changed from this screen.', 'woo-stripe-reconcile' ) ),
			'pass_a_ran'         => array( 'success', __( 'Reconciliation run complete. See results below.', 'woo-stripe-reconcile' ) ),
			'pass_a_error'       => array( 'error', self::get_pass_a_error_message() ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $text ) = $messages[ $notice ];
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	private static function get_pass_a_error_message() {
		$message = get_transient( 'wsr_pass_a_error_' . get_current_user_id() );
		return $message ? sprintf(
			/* translators: %s: the underlying error message */
			__( 'Reconciliation run failed: %s', 'woo-stripe-reconcile' ),
			$message
		) : __( 'Reconciliation run failed.', 'woo-stripe-reconcile' );
	}
}
