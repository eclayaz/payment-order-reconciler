<?php
/**
 * Settings screen: restricted API key entry + validation, environment
 * mismatch warning, coverage status. The drift dashboard itself lives in
 * WSR_Admin_Dashboard.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Settings {

	const OPTION_API_KEY       = 'wsr_stripe_restricted_key';
	const OPTION_SCOPE_STATUS  = 'wsr_stripe_scope_status'; // Map of scope => true|error string. Never the key itself.
	const KEY_CONSTANT         = 'WSR_STRIPE_RESTRICTED_KEY';
	const NONCE_ACTION         = 'wsr_save_settings';
	const NONCE_ACTION_RUN     = 'wsr_run_pass_a';
	const CAPABILITY           = 'manage_woocommerce';

	const NONCE_ACTION_ALERT_EMAIL = 'wsr_save_alert_email';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_wsr_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wsr_run_pass_a', array( __CLASS__, 'handle_run_pass_a' ) );
		add_action( 'admin_post_wsr_save_alert_email', array( __CLASS__, 'handle_save_alert_email' ) );
	}

	/**
	 * Kept as its own form/action, deliberately separate from handle_save()
	 * above — that form's API-key field treats "submitted blank" as "clear
	 * the key" (its own placeholder only ever shows a masked preview, never
	 * the real value). Combining an alert-email field into the same form
	 * would mean saving just the email while leaving the key field blank
	 * silently wipes the configured key. Two small forms avoids that trap.
	 */
	public static function handle_save_alert_email() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'payment-order-reconciler-for-stripe' ) );
		}
		check_admin_referer( self::NONCE_ACTION_ALERT_EMAIL );

		$email = isset( $_POST['wsr_alert_email'] ) ? sanitize_email( wp_unslash( $_POST['wsr_alert_email'] ) ) : '';
		update_option( WSR_Email_Alerts::OPTION_ALERT_EMAIL, $email, false );

		wp_safe_redirect( add_query_arg( 'wsr_notice', 'alert_email_saved', wp_get_referer() ) );
		exit;
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Payment Reconciler', 'payment-order-reconciler-for-stripe' ),
			__( 'Payment Reconciler', 'payment-order-reconciler-for-stripe' ),
			self::CAPABILITY,
			'wsr-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * The key actually in use: a wp-config constant takes priority over the
	 * stored option (per TECHNICAL_SPEC.md, the constant path avoids the
	 * storage question entirely for merchants who have file access). The
	 * option itself is encrypted at rest via WSR_Encryption (independent
	 * review, round 2 — this used to be plain text).
	 *
	 * Transparently migrates a legacy plaintext value (saved before
	 * encryption-at-rest existed) to the encrypted format on first read,
	 * so an already-configured merchant doesn't have to re-enter their
	 * key for it to become protected.
	 */
	public static function get_api_key() {
		if ( self::key_is_from_constant() ) {
			return constant( self::KEY_CONSTANT );
		}

		$stored = get_option( self::OPTION_API_KEY, '' );
		if ( '' === $stored ) {
			return '';
		}

		if ( ! WSR_Encryption::is_encrypted( $stored ) ) {
			update_option( self::OPTION_API_KEY, WSR_Encryption::encrypt( $stored ), false );
			return $stored;
		}

		return WSR_Encryption::decrypt( $stored );
	}

	public static function key_is_from_constant() {
		return defined( self::KEY_CONSTANT ) && '' !== constant( self::KEY_CONSTANT );
	}

	/**
	 * Extracted as its own testable predicate — handle_save() itself
	 * can't be unit tested directly since it always ends in wp_die()/exit.
	 */
	public static function is_restricted_key_format( $key ) {
		return (bool) preg_match( '/^rk_(live|test)_/', (string) $key );
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'payment-order-reconciler-for-stripe' ) );
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
		if ( ! self::is_restricted_key_format( $submitted_key ) ) {
			wp_safe_redirect( add_query_arg( 'wsr_notice', 'not_restricted_key', wp_get_referer() ) );
			exit;
		}

		// autoload=false: only needed on this settings screen and the
		// reconciler run, not every page load. Encrypted at rest — see
		// WSR_Encryption's class comment for the threat model.
		update_option( self::OPTION_API_KEY, WSR_Encryption::encrypt( $submitted_key ), false );

		$client  = new WSR_Stripe_Client( $submitted_key );
		$results = $client->check_scopes();
		update_option( self::OPTION_SCOPE_STATUS, $results, false );

		wp_safe_redirect( add_query_arg( 'wsr_notice', 'saved', wp_get_referer() ) );
		exit;
	}

	/**
	 * Runs the full daily job (Pass A + Pass B + webhook health-check)
	 * synchronously on admin demand — the "manual Run now button" from
	 * TECHNICAL_SPEC.md's build-order step 3, now going through
	 * WSR_Scheduler::run_guarded() (added in step 4) so it shares the same
	 * run-in-progress lock as the scheduled daily job and the two can never
	 * overlap. Stores the combined result in a short-lived, per-user
	 * transient so it survives the redirect-after-post.
	 */
	public static function handle_run_pass_a() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'payment-order-reconciler-for-stripe' ) );
		}
		check_admin_referer( self::NONCE_ACTION_RUN );

		$result = WSR_Scheduler::run_guarded();

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
			return __( 'This looks like a non-production site, but a LIVE restricted key (rk_live_...) is configured. Double-check that\'s intentional — reconciliation checks would run against real payment data.', 'payment-order-reconciler-for-stripe' );
		}

		if ( $is_test_key && 'production' === $env_type && ! $looks_dev ) {
			return __( 'This looks like a production site, but a TEST restricted key (rk_test_...) is configured — reconciliation checks will run against sandbox data, not real payments, until a live key is set.', 'payment-order-reconciler-for-stripe' );
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
			<h1><?php esc_html_e( 'Payment Reconciler — Settings', 'payment-order-reconciler-for-stripe' ); ?></h1>

			<?php self::render_notice( $notice ); ?>

			<?php if ( $env_warning ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $env_warning ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				esc_html_e(
					'Paste a Stripe restricted, read-only API key here. It should never have permission to charge, refund, or move money — only to read PaymentIntents, Charges, Checkout Sessions, Events, Webhook Endpoints, Disputes, and Reviews. Create one in Stripe under Developers → API keys → Create restricted key, setting exactly those seven resources to Read and everything else to None.',
					'payment-order-reconciler-for-stripe'
				);
				?>
			</p>

			<?php if ( $from_constant ) : ?>
				<p>
					<em>
						<?php
						printf(
							/* translators: %s: wp-config.php constant name, as a <code> tag */
							esc_html__( 'This key is set via the %s constant in wp-config.php and cannot be changed here.', 'payment-order-reconciler-for-stripe' ),
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
								<label for="wsr_api_key"><?php esc_html_e( 'Restricted API key', 'payment-order-reconciler-for-stripe' ); ?></label>
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
									<?php esc_html_e( 'Leave blank and save to remove the currently configured key.', 'payment-order-reconciler-for-stripe' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save & Validate', 'payment-order-reconciler-for-stripe' ) ); ?>
				</form>
			<?php endif; ?>

			<?php if ( ! empty( $scope_status ) ) : ?>
				<h2><?php esc_html_e( 'Scope check results', 'payment-order-reconciler-for-stripe' ); ?></h2>
				<table class="widefat" style="max-width: 640px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Scope', 'payment-order-reconciler-for-stripe' ); ?></th>
							<th><?php esc_html_e( 'Status', 'payment-order-reconciler-for-stripe' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $scope_status as $scope => $result ) : ?>
						<tr>
							<td><?php echo esc_html( $scope ); ?></td>
							<td>
								<?php if ( true === $result ) : ?>
									<span style="color:#1a7f37;">&#10003; <?php esc_html_e( 'OK', 'payment-order-reconciler-for-stripe' ); ?></span>
								<?php else : ?>
									<span style="color:#b32d2e;">&#10007; <?php echo esc_html( $result ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'If any scope failed, edit the restricted key in your Stripe Dashboard (Developers → API keys) to add the missing permission, then save again here.', 'payment-order-reconciler-for-stripe' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $current_key ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Alerts', 'payment-order-reconciler-for-stripe' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION_ALERT_EMAIL ); ?>
					<input type="hidden" name="action" value="wsr_save_alert_email" />
					<table class="form-table">
						<tr>
							<th scope="row"><label for="wsr_alert_email"><?php esc_html_e( 'Alert email', 'payment-order-reconciler-for-stripe' ); ?></label></th>
							<td>
								<input type="email" id="wsr_alert_email" name="wsr_alert_email" class="regular-text"
									value="<?php echo esc_attr( get_option( WSR_Email_Alerts::OPTION_ALERT_EMAIL, '' ) ); ?>"
									placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Sent one email per run when new high/critical-severity drift is found. Leave blank to use the site admin email.', 'payment-order-reconciler-for-stripe' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save', 'payment-order-reconciler-for-stripe' ), 'secondary' ); ?>
				</form>

				<hr />
				<?php self::render_coverage_status(); ?>

				<h2><?php esc_html_e( 'Manual reconciliation', 'payment-order-reconciler-for-stripe' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Runs the same three steps the daily scheduled job runs: Pass A (reconciliation), Pass B (undelivered-webhook diagnostic), and the webhook endpoint health-check — useful right after saving a key, or any time you don\'t want to wait for the next scheduled run. See the Drift Log dashboard for results.', 'payment-order-reconciler-for-stripe' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION_RUN ); ?>
					<input type="hidden" name="action" value="wsr_run_pass_a" />
					<?php submit_button( __( 'Run reconciliation now', 'payment-order-reconciler-for-stripe' ), 'secondary' ); ?>
				</form>

				<?php self::render_pass_a_result(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Persistent status block, visible on every page load (not just right
	 * after a manual run) — the forerunner of the "coverage indicator"
	 * TECHNICAL_SPEC.md calls for as a primary dashboard element in step 6.
	 * Reads the options run_all() updates on every run, scheduled or manual.
	 */
	public static function render_coverage_status() {
		$last_run_at      = get_option( 'wsr_last_run_at', '' );
		$last_run_failure = get_option( 'wsr_last_run_failure', array() );
		$pass_b           = get_option( 'wsr_last_pass_b_result', array() );
		$webhook_health   = get_option( 'wsr_last_webhook_health_result', array() );

		echo '<h3>' . esc_html__( 'Coverage', 'payment-order-reconciler-for-stripe' ) . '</h3>';

		// Bug fix (independent review, round 2): wsr_last_run_at is now
		// written only on a genuinely successful run (see run_all()) — a
		// revoked/invalid key used to leave this green even though nothing
		// was actually detected. Surface the failure explicitly whenever
		// it's the more recent event, rather than silently falling back to
		// a stale (or absent) success timestamp.
		$failure_is_newer = ! empty( $last_run_failure['at'] )
			&& ( '' === $last_run_at || strtotime( $last_run_failure['at'] . ' UTC' ) > strtotime( $last_run_at . ' UTC' ) );

		if ( $failure_is_newer ) {
			echo '<p><span style="color:#b32d2e;">&#9679;</span> ';
			printf(
				/* translators: 1: human-readable time difference, e.g. "3 hours", 2: the underlying error message */
				esc_html__( 'Last run attempt failed %1$s ago: %2$s', 'payment-order-reconciler-for-stripe' ),
				esc_html( human_time_diff( strtotime( $last_run_failure['at'] . ' UTC' ), time() ) ),
				esc_html( $last_run_failure['error'] )
			);
			echo '</p>';
		} elseif ( '' === $last_run_at ) {
			echo '<p><span style="color:#b32d2e;">&#9679;</span> ' . esc_html__( 'No reconciliation run has completed yet.', 'payment-order-reconciler-for-stripe' ) . '</p>';
		} else {
			$hours_ago = ( time() - strtotime( $last_run_at . ' UTC' ) ) / HOUR_IN_SECONDS;
			$color     = $hours_ago < 36 ? '#1a7f37' : '#b32d2e'; // daily job + a margin before flagging as stale.
			echo '<p><span style="color:' . esc_attr( $color ) . ';">&#9679;</span> ';
			printf(
				/* translators: %s: human-readable time difference, e.g. "3 hours" */
				esc_html__( 'Last successful run: %s ago.', 'payment-order-reconciler-for-stripe' ),
				esc_html( human_time_diff( strtotime( $last_run_at . ' UTC' ), time() ) )
			);
			echo '</p>';
		}

		if ( isset( $webhook_health['error'] ) ) {
			echo '<p><span style="color:#b32d2e;">&#9679;</span> ' . esc_html__( 'Webhook health check failed: ', 'payment-order-reconciler-for-stripe' ) . esc_html( $webhook_health['error'] ) . '</p>';
		} elseif ( ! empty( $webhook_health ) ) {
			if ( ! empty( $webhook_health['no_endpoints_configured'] ) ) {
				echo '<p><span style="color:#b32d2e;">&#9679;</span> ' . esc_html__( 'No enabled webhook endpoints found in Stripe at all — this store cannot receive any payment notifications.', 'payment-order-reconciler-for-stripe' ) . '</p>';
			} elseif ( empty( $webhook_health['matching_endpoint_found'] ) ) {
				echo '<p><span style="color:#b32d2e;">&#9679;</span> ' . esc_html__( 'None of the enabled webhook endpoints in Stripe point at this site\'s URL — likely the stale-endpoint problem this plugin exists to catch. Mismatched URLs: ', 'payment-order-reconciler-for-stripe' )
					. esc_html( implode( ', ', $webhook_health['mismatched_endpoint_urls'] ) ) . '</p>';
			} else {
				echo '<p><span style="color:#1a7f37;">&#9679;</span> ' . esc_html__( 'A webhook endpoint matching this site was found and is enabled.', 'payment-order-reconciler-for-stripe' ) . '</p>';
			}
		}

		if ( isset( $pass_b['error'] ) ) {
			echo '<p><span style="color:#b32d2e;">&#9679;</span> ' . esc_html__( 'Undelivered-events check failed: ', 'payment-order-reconciler-for-stripe' ) . esc_html( $pass_b['error'] ) . '</p>';
		} elseif ( isset( $pass_b['undelivered_count'] ) ) {
			$color = $pass_b['undelivered_count'] > 0 ? '#b32d2e' : '#1a7f37';
			echo '<p><span style="color:' . esc_attr( $color ) . ';">&#9679;</span> ';
			printf(
				/* translators: %d: number of events that failed webhook delivery in the last 30 days */
				esc_html__( '%d event(s) failed webhook delivery in the last 30 days.', 'payment-order-reconciler-for-stripe' ),
				(int) $pass_b['undelivered_count']
			);
			echo '</p>';
		}
	}

	/**
	 * Displays the summary from the most recent manual run, read from the
	 * short-lived per-user transient set in handle_run_pass_a() — now the
	 * combined {pass_a, pass_b, webhook_health} shape run_all() returns,
	 * not Pass A alone.
	 */
	private static function render_pass_a_result() {
		$result = get_transient( 'wsr_pass_a_result_' . get_current_user_id() );
		if ( ! is_array( $result ) || ! isset( $result['pass_a'] ) ) {
			return;
		}

		$pass_a = $result['pass_a'];
		if ( is_wp_error( $pass_a ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Pass A failed: ', 'payment-order-reconciler-for-stripe' ) . esc_html( $pass_a->get_error_message() ) . '</p></div>';
			return;
		}

		$labels = array(
			'payment_intents_scanned'   => __( 'PaymentIntents scanned', 'payment-order-reconciler-for-stripe' ),
			'resolved_to_order'         => __( 'Resolved to a local order', 'payment-order-reconciler-for-stripe' ),
			'no_drift'                  => __( 'Resolved, no drift found', 'payment-order-reconciler-for-stripe' ),
			'stuck_pending_flagged'     => __( 'Stuck-pending flagged', 'payment-order-reconciler-for-stripe' ),
			'wrongly_cancelled_flagged' => __( 'Wrongly-cancelled-paid flagged', 'payment-order-reconciler-for-stripe' ),
			'needs_review'              => __( 'Needs review (unresolved)', 'payment-order-reconciler-for-stripe' ),
			'orphaned_charge_escalated' => __( 'Escalated to orphaned charge', 'payment-order-reconciler-for-stripe' ),
			'skipped_other_site'        => __( 'Skipped (belongs to another site)', 'payment-order-reconciler-for-stripe' ),
			'skipped_too_fresh'         => __( 'Skipped (too fresh, revisit next run)', 'payment-order-reconciler-for-stripe' ),
			'pages_fetched'             => __( 'API pages fetched', 'payment-order-reconciler-for-stripe' ),
		);

		echo '<h3>' . esc_html__( 'Last run results — Pass A', 'payment-order-reconciler-for-stripe' ) . '</h3>';
		echo '<table class="widefat" style="max-width: 480px;"><tbody>';
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $pass_a[ $key ] ) ) {
				continue;
			}
			echo '<tr><td>' . esc_html( $label ) . '</td><td><strong>' . esc_html( $pass_a[ $key ] ) . '</strong></td></tr>';
		}
		echo '</tbody></table>';

		if ( ! empty( $pass_a['errors'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( implode( '; ', $pass_a['errors'] ) ) . '</p></div>';
		}
	}

	private static function render_notice( $notice ) {
		$messages = array(
			'saved'              => array( 'success', __( 'Key saved. See scope check results below.', 'payment-order-reconciler-for-stripe' ) ),
			'cleared'            => array( 'success', __( 'Key removed.', 'payment-order-reconciler-for-stripe' ) ),
			'not_restricted_key' => array(
				'error',
				__( 'That doesn\'t look like a restricted key (should start with rk_live_ or rk_test_). A regular secret key (sk_...) has full write access and is not supported by this plugin — create a restricted, read-only key instead.', 'payment-order-reconciler-for-stripe' ),
			),
			'constant_locked'    => array( 'error', __( 'The API key is locked via wp-config.php and cannot be changed from this screen.', 'payment-order-reconciler-for-stripe' ) ),
			'pass_a_ran'         => array( 'success', __( 'Reconciliation run complete. See results below.', 'payment-order-reconciler-for-stripe' ) ),
			'pass_a_error'       => array( 'error', self::get_pass_a_error_message() ),
			'alert_email_saved' => array( 'success', __( 'Alert email saved.', 'payment-order-reconciler-for-stripe' ) ),
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
			__( 'Reconciliation run failed: %s', 'payment-order-reconciler-for-stripe' ),
			$message
		) : __( 'Reconciliation run failed.', 'payment-order-reconciler-for-stripe' );
	}
}
