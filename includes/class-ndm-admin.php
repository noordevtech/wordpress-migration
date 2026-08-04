<?php
/**
 * Admin UI: connection setup, progress dashboard, AJAX tick loop.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single admin screen serving both roles: shows this site's connection key
 * (destination role) and the push dashboard (source role).
 */
class NDM_Admin {

	/**
	 * Singleton.
	 *
	 * @var NDM_Admin
	 */
	private static $instance;

	/**
	 * Singleton accessor.
	 *
	 * @return NDM_Admin
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_ndm_save_connection', array( $this, 'handle_save_connection' ) );
		add_action( 'admin_post_ndm_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_ndm_regenerate_secret', array( $this, 'handle_regenerate_secret' ) );

		foreach ( array( 'start', 'resume', 'pause', 'cancel', 'cutover', 'status', 'tick', 'rollback', 'cleanup_backups' ) as $action ) {
			add_action( 'wp_ajax_ndm_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/**
	 * Menu.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'NoorDev Migrate', 'noordev-migrate' ),
			__( 'Migrate', 'noordev-migrate' ),
			'manage_options',
			'noordev-migrate',
			array( $this, 'render_page' ),
			'dashicons-migrate',
			80
		);
	}

	/**
	 * Assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'toplevel_page_noordev-migrate' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'ndm-admin', NDM_PLUGIN_URL . 'assets/admin.css', array(), NDM_VERSION );
		wp_enqueue_script( 'ndm-admin', NDM_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), NDM_VERSION, true );
		wp_localize_script(
			'ndm-admin',
			'ndmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ndm_ajax' ),
				'i18n'    => array(
					'confirmCutover' => __( 'This will REPLACE the target site with the synced content (its domain is kept). The previous database is backed up, but proceed only if you are sure. Continue?', 'noordev-migrate' ),
					'confirmCancel'  => __( 'Cancel the migration and discard all staged data on the target?', 'noordev-migrate' ),
					'confirmFresh'   => __( 'Start over from the beginning? Existing checkpoints will be discarded.', 'noordev-migrate' ),
				),
			)
		);
	}

	/**
	 * Common AJAX guard.
	 */
	private function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'noordev-migrate' ) ), 403 );
		}
		check_ajax_referer( 'ndm_ajax', 'nonce' );
	}

	/**
	 * AJAX: start (optionally fresh).
	 */
	public function ajax_start() {
		$this->ajax_guard();
		$fresh  = ! empty( $_POST['fresh'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = NDM_Batch_Runner::instance()->start( $fresh );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: resume (alias of start without fresh).
	 */
	public function ajax_resume() {
		$this->ajax_guard();
		$result = NDM_Batch_Runner::instance()->start( false );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: pause.
	 */
	public function ajax_pause() {
		$this->ajax_guard();
		NDM_Batch_Runner::instance()->pause();
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: cancel.
	 */
	public function ajax_cancel() {
		$this->ajax_guard();
		NDM_Batch_Runner::instance()->cancel();
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: cutover.
	 */
	public function ajax_cutover() {
		$this->ajax_guard();
		$result = NDM_Batch_Runner::instance()->request_cutover();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: process one batch slice and return progress.
	 */
	public function ajax_tick() {
		$this->ajax_guard();
		NDM_Batch_Runner::instance()->tick();
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: status only.
	 */
	public function ajax_status() {
		$this->ajax_guard();
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: destination rollback.
	 */
	public function ajax_rollback() {
		$this->ajax_guard();
		$result = NDM_Finalizer::rollback();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: drop backup tables.
	 */
	public function ajax_cleanup_backups() {
		$this->ajax_guard();
		NDM_Finalizer::cleanup_backups();
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * Status payload for the JS dashboard.
	 *
	 * @return array
	 */
	private function status_payload() {
		$state = NDM_State::get_source();

		$tables_done  = 0;
		$rows_sent    = 0;
		foreach ( $state['tables'] as $table ) {
			if ( ! empty( $table['done'] ) ) {
				$tables_done++;
			}
			$rows_sent += (int) $table['rows_sent'];
		}

		return array(
			'stage'       => $state['stage'],
			'paused'      => (bool) $state['paused'],
			'running'     => NDM_Batch_Runner::instance()->is_running( $state ),
			'progress'    => NDM_State::progress( $state ),
			'tables'      => count( $state['tables'] ),
			'tablesDone'  => $tables_done,
			'rowsSent'    => $rows_sent,
			'filesTotal'  => (int) $state['files_total'],
			'filesDone'   => (int) $state['files_done'],
			'bytesTotal'  => (int) $state['bytes_total'],
			'bytesDone'   => (int) $state['bytes_done'],
			'error'       => $state['error'],
			'verify'      => $state['verify'],
			'log'         => array_map(
				static function ( $entry ) {
					return array(
						'time'    => gmdate( 'H:i:s', $entry['time'] ),
						'level'   => $entry['level'],
						'message' => $entry['message'],
					);
				},
				NDM_Log::tail( 30 )
			),
		);
	}

	/**
	 * Save connection (source role).
	 */
	public function handle_save_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'noordev-migrate' ) );
		}
		check_admin_referer( 'ndm_save_connection' );

		$url = isset( $_POST['ndm_dest_url'] ) ? esc_url_raw( wp_unslash( $_POST['ndm_dest_url'] ) ) : '';
		$key = isset( $_POST['ndm_dest_key'] ) ? sanitize_text_field( wp_unslash( $_POST['ndm_dest_key'] ) ) : '';

		update_option(
			'ndm_connection',
			array(
				'url' => untrailingslashit( $url ),
				'key' => $key,
			),
			false
		);

		wp_safe_redirect( add_query_arg( array( 'page' => 'noordev-migrate', 'ndm_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save settings.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'noordev-migrate' ) );
		}
		check_admin_referer( 'ndm_save_settings' );

		update_option(
			'ndm_settings',
			array(
				'batch_size'         => isset( $_POST['ndm_batch_size'] ) ? absint( $_POST['ndm_batch_size'] ) : 500,
				'auto_cutover'       => ! empty( $_POST['ndm_auto_cutover'] ),
				'delete_extra_files' => ! empty( $_POST['ndm_delete_extra_files'] ),
			),
			false
		);

		wp_safe_redirect( add_query_arg( array( 'page' => 'noordev-migrate', 'ndm_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Regenerate this site's connection key (destination role).
	 */
	public function handle_regenerate_secret() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'noordev-migrate' ) );
		}
		check_admin_referer( 'ndm_regenerate_secret' );

		update_option( 'ndm_secret', NDM_Auth::generate_secret(), false );

		wp_safe_redirect( add_query_arg( array( 'page' => 'noordev-migrate', 'ndm_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state      = NDM_State::get_source();
		$connection = get_option( 'ndm_connection', array() );
		$settings   = wp_parse_args(
			get_option( 'ndm_settings', array() ),
			array(
				'batch_size'         => 500,
				'auto_cutover'       => false,
				'delete_extra_files' => true,
			)
		);
		$secret     = get_option( 'ndm_secret', '' );
		$dest_state = NDM_State::get_dest();

		global $wpdb;
		$has_backups = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . NDM_BACKUP_TABLE_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		?>
		<div class="wrap ndm-wrap">
			<h1><?php esc_html_e( 'NoorDev Migrate', 'noordev-migrate' ); ?></h1>

			<?php if ( isset( $_GET['ndm_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'noordev-migrate' ); ?></p></div>
			<?php endif; ?>

			<div class="ndm-columns">
				<!-- SOURCE ROLE -->
				<div class="ndm-card">
					<h2><?php esc_html_e( 'Push this site to a new host (source)', 'noordev-migrate' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Install this plugin on the target site, copy its Site URL and Connection Key below, then start the sync. Data is transferred batch by batch; if anything interrupts the transfer it resumes from the last checkpoint — never from the beginning. The target site keeps serving its current content (and keeps its own domain) until you cut over at 100%.', 'noordev-migrate' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ndm_save_connection' ); ?>
						<input type="hidden" name="action" value="ndm_save_connection" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="ndm_dest_url"><?php esc_html_e( 'Target site URL', 'noordev-migrate' ); ?></label></th>
								<td><input name="ndm_dest_url" id="ndm_dest_url" type="url" class="regular-text" placeholder="https://target-host.example" value="<?php echo esc_attr( isset( $connection['url'] ) ? $connection['url'] : '' ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="ndm_dest_key"><?php esc_html_e( 'Target connection key', 'noordev-migrate' ); ?></label></th>
								<td><input name="ndm_dest_key" id="ndm_dest_key" type="password" class="regular-text" autocomplete="off" value="<?php echo esc_attr( isset( $connection['key'] ) ? $connection['key'] : '' ); ?>" /></td>
							</tr>
						</table>
						<?php submit_button( __( 'Save connection', 'noordev-migrate' ), 'secondary', 'submit', false ); ?>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ndm-settings-form">
						<?php wp_nonce_field( 'ndm_save_settings' ); ?>
						<input type="hidden" name="action" value="ndm_save_settings" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="ndm_batch_size"><?php esc_html_e( 'Rows per batch', 'noordev-migrate' ); ?></label></th>
								<td>
									<input name="ndm_batch_size" id="ndm_batch_size" type="number" min="50" max="5000" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" />
									<p class="description"><?php esc_html_e( 'Lower this on hosts with tight memory or request-size limits.', 'noordev-migrate' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Automatic cutover', 'noordev-migrate' ); ?></th>
								<td>
									<label><input name="ndm_auto_cutover" type="checkbox" value="1" <?php checked( $settings['auto_cutover'] ); ?> /> <?php esc_html_e( 'Replace the target site automatically when the sync reaches 100%', 'noordev-migrate' ); ?></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Replace mode', 'noordev-migrate' ); ?></th>
								<td>
									<label><input name="ndm_delete_extra_files" type="checkbox" value="1" <?php checked( $settings['delete_extra_files'] ); ?> /> <?php esc_html_e( 'On cutover, delete target files that do not exist on this site (full replacement)', 'noordev-migrate' ); ?></label>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save settings', 'noordev-migrate' ), 'secondary', 'submit', false ); ?>
					</form>

					<hr />

					<div id="ndm-dashboard"
						data-stage="<?php echo esc_attr( $state['stage'] ); ?>"
						data-progress="<?php echo esc_attr( NDM_State::progress( $state ) ); ?>">
						<div class="ndm-progress"><div class="ndm-progress-bar" style="width:0%"><span>0%</span></div></div>
						<p class="ndm-stage-label"></p>

						<p class="ndm-actions">
							<button type="button" class="button button-primary" id="ndm-start"><?php esc_html_e( 'Start sync', 'noordev-migrate' ); ?></button>
							<button type="button" class="button button-primary" id="ndm-resume"><?php esc_html_e( 'Resume', 'noordev-migrate' ); ?></button>
							<button type="button" class="button" id="ndm-pause"><?php esc_html_e( 'Pause', 'noordev-migrate' ); ?></button>
							<button type="button" class="button" id="ndm-restart"><?php esc_html_e( 'Start over', 'noordev-migrate' ); ?></button>
							<button type="button" class="button ndm-danger" id="ndm-cancel"><?php esc_html_e( 'Cancel', 'noordev-migrate' ); ?></button>
							<button type="button" class="button button-hero button-primary" id="ndm-cutover"><?php esc_html_e( 'Replace target site now', 'noordev-migrate' ); ?></button>
						</p>

						<div class="ndm-stats">
							<span id="ndm-stat-tables"></span>
							<span id="ndm-stat-rows"></span>
							<span id="ndm-stat-files"></span>
						</div>

						<div class="ndm-error notice notice-error" style="display:none"><p></p></div>

						<h3><?php esc_html_e( 'Activity', 'noordev-migrate' ); ?></h3>
						<ul id="ndm-log"></ul>
					</div>
				</div>

				<!-- DESTINATION ROLE -->
				<div class="ndm-card">
					<h2><?php esc_html_e( 'Receive a migration on this site (target)', 'noordev-migrate' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Give these credentials to the source site. Incoming data is staged next to the live site; nothing is replaced until the source triggers the cutover. This site keeps its own domain after the migration.', 'noordev-migrate' ); ?></p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Site URL', 'noordev-migrate' ); ?></th>
							<td><code><?php echo esc_html( home_url() ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Connection key', 'noordev-migrate' ); ?></th>
							<td>
								<code class="ndm-secret"><?php echo esc_html( $secret ); ?></code>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ndm-inline-form">
									<?php wp_nonce_field( 'ndm_regenerate_secret' ); ?>
									<input type="hidden" name="action" value="ndm_regenerate_secret" />
									<?php submit_button( __( 'Regenerate', 'noordev-migrate' ), 'small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
						<?php if ( ! empty( $dest_state['active'] ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Incoming migration', 'noordev-migrate' ); ?></th>
								<td>
									<?php
									printf(
										/* translators: 1: source URL, 2: staged table count, 3: received file count. */
										esc_html__( 'From %1$s — %2$d tables staged, %3$d files received.', 'noordev-migrate' ),
										esc_html( $dest_state['source_url'] ),
										(int) count( $dest_state['tables'] ),
										(int) $dest_state['files_received']
									);
									?>
								</td>
							</tr>
						<?php endif; ?>
						<?php if ( $has_backups ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Pre-cutover backup', 'noordev-migrate' ); ?></th>
								<td>
									<button type="button" class="button" id="ndm-rollback"><?php esc_html_e( 'Roll back to previous site', 'noordev-migrate' ); ?></button>
									<button type="button" class="button" id="ndm-cleanup-backups"><?php esc_html_e( 'Delete backup tables', 'noordev-migrate' ); ?></button>
								</td>
							</tr>
						<?php endif; ?>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}
