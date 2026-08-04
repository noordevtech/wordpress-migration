<?php
/**
 * Source-side migration engine: processes one time-budgeted batch per tick.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drives the migration batch by batch.
 *
 * Ticks come from two places: the admin screen polls via AJAX while it is
 * open, and a WP-Cron event fires as a fallback so the sync keeps moving when
 * nobody is watching. Each tick works for a bounded time slice, saves a
 * checkpoint after every acknowledged batch, and exits. An interruption at any
 * point therefore loses at most one un-acknowledged batch, which is safely
 * re-sent (row imports use REPLACE INTO, file chunks are offset-checked).
 */
class NDM_Batch_Runner {

	const TIME_BUDGET   = 10; // Seconds of work per tick.
	const CRON_HOOK     = 'ndm_cron_tick';
	const MANIFEST_STEP = 100; // Manifest entries checked per files-check call.

	/**
	 * Singleton.
	 *
	 * @var NDM_Batch_Runner
	 */
	private static $instance;

	/**
	 * Singleton accessor.
	 *
	 * @return NDM_Batch_Runner
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
		add_action( self::CRON_HOOK, array( $this, 'cron_tick' ) );
	}

	/**
	 * Rows per DB batch (filterable / user setting).
	 *
	 * @return int
	 */
	public static function batch_size() {
		$settings = get_option( 'ndm_settings', array() );
		$size     = isset( $settings['batch_size'] ) ? (int) $settings['batch_size'] : 500;
		return max( 50, min( 5000, $size ) );
	}

	/**
	 * Start a migration (fresh) or resume an interrupted one.
	 *
	 * @param bool $fresh Force a fresh start, discarding all checkpoints.
	 * @return true|WP_Error
	 */
	public function start( $fresh = false ) {
		$client = new NDM_Client();
		if ( ! $client->is_configured() ) {
			return new WP_Error( 'ndm_not_connected', 'Connect a destination site first.' );
		}

		$state = NDM_State::get_source();

		$resumable = in_array( $state['stage'], array( NDM_State::STAGE_DB, NDM_State::STAGE_FILES, NDM_State::STAGE_VERIFY, NDM_State::STAGE_ERROR ), true );
		if ( ! $fresh && $resumable && ! empty( $state['tables'] ) ) {
			// Resume: keep every checkpoint, just clear the error/pause flags
			// and give the failure budget a fresh start.
			if ( NDM_State::STAGE_ERROR === $state['stage'] ) {
				$state['stage'] = $this->stage_after_error( $state );
			}
			$state['paused']  = false;
			$state['error']   = '';
			$state['retries'] = 0;
			NDM_State::save_source( $state );
			NDM_Log::info( 'Resuming migration from checkpoint (stage: ' . $state['stage'] . ').' );
			$this->check_destination_version( $client );
			$this->schedule_cron();
			return true;
		}

		// Fresh start.
		$handshake = $client->post( 'handshake', array( 'source_url' => home_url() ) );
		if ( is_wp_error( $handshake ) ) {
			return $handshake;
		}
		$this->warn_on_version_mismatch( isset( $handshake['plugin'] ) ? (string) $handshake['plugin'] : '' );

		global $wpdb;
		$prepare = $client->post(
			'prepare',
			array(
				'fresh'          => true,
				'source_url'     => home_url(),
				'source_abspath' => untrailingslashit( wp_normalize_path( ABSPATH ) ),
				'source_prefix'  => $wpdb->prefix,
			)
		);
		if ( is_wp_error( $prepare ) ) {
			return $prepare;
		}

		$state                = NDM_State::default_source_state();
		$state['stage']       = NDM_State::STAGE_DB;
		$state['tables']      = NDM_DB_Exporter::list_tables();
		$state['table_index'] = 0;
		$state['started_at']  = time();
		NDM_State::save_source( $state );

		NDM_Log::clear();
		NDM_Log::info( sprintf( 'Migration started: %d tables to sync to %s.', count( $state['tables'] ), esc_url_raw( $handshake['home_url'] ?? '' ) ) );

		$this->schedule_cron();
		return true;
	}

	/**
	 * Ask the destination for its plugin version and warn on mismatch.
	 *
	 * @param NDM_Client $client Client.
	 */
	private function check_destination_version( NDM_Client $client ) {
		$handshake = $client->post( 'handshake', array( 'source_url' => home_url() ) );
		if ( ! is_wp_error( $handshake ) ) {
			$this->warn_on_version_mismatch( isset( $handshake['plugin'] ) ? (string) $handshake['plugin'] : '' );
		}
	}

	/**
	 * Log a prominent warning when the two sites run different plugin versions.
	 *
	 * Mixed versions are the root of hard-to-diagnose sync failures: fixes
	 * applied on one side silently miss the other.
	 *
	 * @param string $dest_version Destination plugin version ('' when unknown).
	 */
	private function warn_on_version_mismatch( $dest_version ) {
		if ( '' === $dest_version ) {
			NDM_Log::warn( 'Destination did not report a plugin version (very old build?). Update NoorDev Migrate on the target site to v' . NDM_VERSION . '.' );
			return;
		}
		if ( $dest_version !== NDM_VERSION ) {
			NDM_Log::warn( sprintf( 'VERSION MISMATCH: this site runs v%s but the target runs v%s. Update the plugin on the target site, then Resume — mixed versions cause failures that look like data errors.', NDM_VERSION, $dest_version ) );
		}
	}

	/**
	 * Choose the stage to resume into after an error.
	 *
	 * @param array $state Source state.
	 * @return string
	 */
	private function stage_after_error( $state ) {
		foreach ( $state['tables'] as $table ) {
			if ( empty( $table['done'] ) ) {
				return NDM_State::STAGE_DB;
			}
		}
		if ( $state['files_total'] > 0 && $state['file_index'] < $state['files_total'] ) {
			return NDM_State::STAGE_FILES;
		}
		return empty( $state['tables'] ) ? NDM_State::STAGE_DB : NDM_State::STAGE_VERIFY;
	}

	/**
	 * Pause.
	 */
	public function pause() {
		$state           = NDM_State::get_source();
		$state['paused'] = true;
		NDM_State::save_source( $state );
		NDM_Log::info( 'Migration paused.' );
	}

	/**
	 * Cancel: reset local checkpoints and ask the destination to drop staging.
	 */
	public function cancel() {
		$client = new NDM_Client();
		if ( $client->is_configured() ) {
			$client->post( 'cancel' );
		}
		NDM_File_Scanner::cleanup();
		NDM_State::reset_source();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		NDM_Log::info( 'Migration cancelled; staging discarded on destination.' );
	}

	/**
	 * Cron fallback tick. Also auto-resumes after transient errors.
	 */
	public function cron_tick() {
		$state = NDM_State::get_source();

		if ( NDM_State::STAGE_ERROR === $state['stage'] && $state['retries'] <= 10 ) {
			$this->start( false );
		}

		$this->tick();
		$state = NDM_State::get_source();
		if ( $this->is_running( $state ) ) {
			$this->schedule_cron();
		}
	}

	/**
	 * Whether the migration needs more ticks.
	 *
	 * @param array $state Source state.
	 * @return bool
	 */
	public function is_running( $state ) {
		return ! $state['paused'] && in_array( $state['stage'], array( NDM_State::STAGE_DB, NDM_State::STAGE_FILES, NDM_State::STAGE_VERIFY, NDM_State::STAGE_FINALIZING ), true );
	}

	/**
	 * Schedule the fallback cron tick.
	 */
	private function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
		}
	}

	/**
	 * Acquire the cross-request mutex.
	 *
	 * The dashboard AJAX loop and the WP-Cron fallback can both try to drive
	 * the sync at the same time; without exclusion they race on the same
	 * checkpoint (worst case: one loop drops/recreates a staging table while
	 * the other is inserting into it). A MySQL named lock is released
	 * automatically if the PHP process dies, so it can never stay stuck.
	 *
	 * @return bool Whether the lock was obtained.
	 */
	private function acquire_lock() {
		global $wpdb;
		$name = $wpdb->dbname . '.' . $wpdb->prefix . 'ndm_tick';
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Release the cross-request mutex.
	 */
	private function release_lock() {
		global $wpdb;
		$name = $wpdb->dbname . '.' . $wpdb->prefix . 'ndm_tick';
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Process batches for up to TIME_BUDGET seconds. Returns fresh state.
	 *
	 * @return array
	 */
	public function tick() {
		$state = NDM_State::get_source();
		if ( ! $this->is_running( $state ) ) {
			return $state;
		}

		if ( ! $this->acquire_lock() ) {
			// Another process (cron or a second tab) is already syncing.
			return $state;
		}

		try {
			return $this->tick_locked();
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * The actual tick loop; caller must hold the lock.
	 *
	 * @return array
	 */
	private function tick_locked() {
		$client   = new NDM_Client();
		$deadline = time() + self::TIME_BUDGET;

		while ( time() < $deadline ) {
			$state = NDM_State::get_source();
			if ( ! $this->is_running( $state ) ) {
				break;
			}

			switch ( $state['stage'] ) {
				case NDM_State::STAGE_DB:
					$result = $this->tick_db( $client, $state );
					break;
				case NDM_State::STAGE_FILES:
					$result = $this->tick_files( $client, $state );
					break;
				case NDM_State::STAGE_VERIFY:
					$result = $this->tick_verify( $client, $state );
					break;
				case NDM_State::STAGE_FINALIZING:
					$result = $this->tick_finalize( $client, $state );
					break;
				default:
					$result = true;
					break 2;
			}

			if ( is_wp_error( $result ) ) {
				$state          = NDM_State::get_source();
				$state['error'] = $this->clean_error( $result->get_error_message() );
				$state['stage'] = NDM_State::STAGE_ERROR;
				$state['retries']++;
				NDM_State::save_source( $state );
				NDM_Log::error( 'Batch failed (will resume from checkpoint): ' . $state['error'] );

				// Auto-resume via cron unless it keeps failing.
				if ( $state['retries'] <= 10 ) {
					$this->schedule_retry();
				} else {
					NDM_Log::error( 'Too many consecutive failures; waiting for manual resume.' );
				}
				break;
			}
		}

		return NDM_State::get_source();
	}

	/**
	 * Turn an error (possibly a whole WordPress error page) into a short,
	 * readable message.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	private function clean_error( $message ) {
		$message = wp_strip_all_tags( $message );
		$message = trim( preg_replace( '/\s+/', ' ', $message ) );
		if ( strlen( $message ) > 300 ) {
			$message = substr( $message, 0, 300 ) . '…';
		}

		// Translate raw database/server errors into actions the user can take.
		if ( false !== stripos( $message, 'is full' ) || false !== stripos( $message, 'disk full' ) || false !== stripos( $message, 'no space left' ) ) {
			$message .= ' — the TARGET database server is out of disk space. Enlarge the database volume (or free space) on the target host, then press Resume.';
		}

		return $message;
	}

	/**
	 * Schedule an automatic resume after an error (handled by cron_tick).
	 */
	private function schedule_retry() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 120, self::CRON_HOOK );
		}
	}

	/**
	 * DB stage: send one table batch.
	 *
	 * @param NDM_Client $client Client.
	 * @param array      $state  Source state.
	 * @return true|WP_Error
	 */
	private function tick_db( NDM_Client $client, array $state ) {
		$index = (int) $state['table_index'];

		// Advance past finished tables.
		while ( $index < count( $state['tables'] ) && ! empty( $state['tables'][ $index ]['done'] ) ) {
			$index++;
		}

		if ( $index >= count( $state['tables'] ) ) {
			// DB complete: build the file manifest and move to the files stage.
			$manifest               = NDM_File_Scanner::build_manifest();
			$state['stage']         = NDM_State::STAGE_FILES;
			$state['table_index']   = $index;
			$state['files_total']   = $manifest['files'];
			$state['bytes_total']   = $manifest['bytes'];
			$state['files_done']    = 0;
			$state['bytes_done']    = 0;
			$state['file_index']    = 0;
			$state['file_offset']   = 0;
			$state['pending_files'] = array();
			NDM_State::save_source( $state );
			NDM_Log::info( sprintf( 'Database synced. %d files (%s) queued for transfer.', $manifest['files'], size_format( $manifest['bytes'] ) ) );
			return true;
		}

		$table = $state['tables'][ $index ];

		// First batch for this table: send its structure.
		if ( empty( $table['created'] ) ) {
			$response = $client->post(
				'table',
				array(
					'base'   => $table['base'],
					'create' => NDM_DB_Exporter::create_statement( $table['name'], $table['base'] ),
					'fresh'  => 0 === (int) $table['rows_sent'],
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$table['created'] = true;
		}

		$batch    = NDM_DB_Exporter::fetch_batch( $table, self::batch_size() );
		$has_rows = ! empty( $batch['rows'] );

		if ( $has_rows ) {
			$response = $this->send_rows( $client, $table['base'], $batch['columns'], $batch['rows'] );
			if ( is_wp_error( $response ) ) {
				if ( 'ndm_http_409' === $response->get_error_code() ) {
					// Staging table went missing on the destination: re-send the
					// structure on the next pass instead of failing the migration.
					$state                                  = NDM_State::get_source();
					$state['tables'][ $index ]['created']   = false;
					NDM_State::save_source( $state );
					NDM_Log::warn( 'Staging table missing for ' . $table['base'] . '; re-sending table structure.' );
					return true;
				}
				return $response;
			}
		}

		// Batch acknowledged: advance the checkpoint.
		$table['last_pk']   = $batch['next_last_pk'];
		$table['offset']    = $batch['next_offset'];
		$table['rows_sent'] += count( $batch['rows'] );
		if ( $batch['finished'] ) {
			$table['done'] = true;
			NDM_Log::info( sprintf( 'Table %s synced (%d rows).', $table['name'], $table['rows_sent'] ) );
		}

		$state                     = NDM_State::get_source();
		$state['tables'][ $index ] = $table;
		$state['table_index']      = $index;
		$state['retries']          = 0;
		NDM_State::save_source( $state );

		return true;
	}

	/**
	 * Send a row batch, automatically splitting it when the destination
	 * chokes on it.
	 *
	 * A destination fatal (memory limit, max_allowed_packet, proxy body-size
	 * limit) surfaces as a 5xx/invalid response. Rather than fail the whole
	 * migration, the batch is halved and each half retried, down to a floor
	 * of 25 rows. Because imports use REPLACE INTO, re-sending rows from a
	 * partially applied batch is harmless.
	 *
	 * @param NDM_Client $client  Client.
	 * @param string     $base    Base table name.
	 * @param string[]   $columns Columns.
	 * @param array[]    $rows    Encoded rows.
	 * @return true|WP_Error
	 */
	private function send_rows( NDM_Client $client, $base, array $columns, array $rows ) {
		$response = $client->post(
			'rows',
			array(
				'base'    => $base,
				'columns' => $columns,
				'rows'    => $rows,
			),
			120
		);

		if ( ! is_wp_error( $response ) ) {
			return true;
		}

		// Auth/validation/consistency errors won't be cured by smaller batches.
		$code = $response->get_error_code();
		if ( in_array( $code, array( 'ndm_http_400', 'ndm_http_401', 'ndm_http_403', 'ndm_http_409', 'ndm_not_connected' ), true ) ) {
			return $response;
		}

		if ( count( $rows ) <= 25 ) {
			return $response;
		}

		NDM_Log::warn( sprintf( 'Destination rejected a %d-row batch for %s; splitting and retrying.', count( $rows ), $base ) );

		foreach ( array_chunk( $rows, (int) ceil( count( $rows ) / 2 ) ) as $half ) {
			$result = $this->send_rows( $client, $base, $columns, $half );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Files stage: check a manifest slice, then push needed files chunk by chunk.
	 *
	 * @param NDM_Client $client Client.
	 * @param array      $state  Source state.
	 * @return true|WP_Error
	 */
	private function tick_files( NDM_Client $client, array $state ) {
		$pending = isset( $state['pending_files'] ) ? $state['pending_files'] : array();

		// Refill the pending list from the manifest.
		if ( empty( $pending ) ) {
			if ( $state['file_index'] >= $state['files_total'] ) {
				$state['stage'] = NDM_State::STAGE_VERIFY;
				NDM_State::save_source( $state );
				NDM_Log::info( 'File transfer complete. Verifying…' );
				return true;
			}

			$entries = NDM_File_Scanner::read_entries( $state['file_index'], self::MANIFEST_STEP );
			if ( empty( $entries ) ) {
				$state['file_index'] = $state['files_total'];
				NDM_State::save_source( $state );
				return true;
			}

			$check = array();
			foreach ( $entries as $entry ) {
				$check[] = array(
					'path' => $entry['path'],
					'size' => $entry['size'],
					'md5'  => NDM_File_Scanner::file_md5( $entry['path'] ),
				);
			}

			$response = $client->post( 'files-check', array( 'entries' => $check ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$needed   = isset( $response['needed'] ) ? (array) $response['needed'] : array();
			$by_path  = array();
			foreach ( $entries as $entry ) {
				$by_path[ $entry['path'] ] = $entry;
			}

			$skipped_bytes = 0;
			foreach ( $entries as $entry ) {
				if ( ! in_array( $entry['path'], $needed, true ) ) {
					$skipped_bytes += (int) $entry['size'];
				}
			}

			$state                  = NDM_State::get_source();
			$state['pending_files'] = array_values( array_intersect_key( $by_path, array_flip( $needed ) ) );
			$state['file_index']   += count( $entries );
			$state['files_done']   += count( $entries ) - count( $needed );
			$state['bytes_done']   += $skipped_bytes;
			$state['file_offset']   = 0;
			$state['retries']       = 0;
			NDM_State::save_source( $state );
			return true;
		}

		// Send the next chunk of the first pending file.
		$entry = $pending[0];
		$chunk = NDM_File_Scanner::read_chunk( $entry['path'], (int) $state['file_offset'] );

		if ( is_wp_error( $chunk ) ) {
			// File vanished mid-sync (e.g. deleted upload): skip it.
			NDM_Log::warn( $chunk->get_error_message() . ' — skipping.' );
			array_shift( $pending );
			$state['pending_files'] = $pending;
			$state['files_done']++;
			$state['file_offset'] = 0;
			NDM_State::save_source( $state );
			return true;
		}

		$response = $client->post(
			'file-chunk',
			array(
				'path'   => $entry['path'],
				'offset' => $chunk['offset'],
				'data'   => $chunk['data'],
				'eof'    => $chunk['eof'],
				'md5'    => $chunk['md5'],
			),
			120
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$state = NDM_State::get_source();
		$sent  = (int) ( strlen( base64_decode( $chunk['data'] ) ) );

		if ( ! empty( $response['restart'] ) ) {
			// Destination lost the partial file or the hash failed: restart this file from 0.
			$state['file_offset'] = 0;
			NDM_Log::warn( 'Destination requested restart of ' . $entry['path'] . '.' );
		} elseif ( ! empty( $response['complete'] ) || $chunk['eof'] ) {
			array_shift( $pending );
			$state['pending_files'] = $pending;
			$state['files_done']++;
			$state['bytes_done'] += $sent;
			$state['file_offset'] = 0;
		} else {
			$state['file_offset'] = $chunk['offset'] + $sent;
			$state['bytes_done'] += $sent;
		}
		$state['retries'] = 0;
		NDM_State::save_source( $state );

		return true;
	}

	/**
	 * Verify stage: compare row counts, then mark ready (or auto-cutover).
	 *
	 * @param NDM_Client $client Client.
	 * @param array      $state  Source state.
	 * @return true|WP_Error
	 */
	private function tick_verify( NDM_Client $client, array $state ) {
		$source_counts = NDM_DB_Exporter::row_counts( $state['tables'] );

		$response = $client->post( 'counts', array( 'bases' => array_keys( $source_counts ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$dest_counts = isset( $response['counts'] ) ? (array) $response['counts'] : array();
		$mismatched  = array();
		foreach ( $source_counts as $base => $count ) {
			$dest = isset( $dest_counts[ $base ] ) ? (int) $dest_counts[ $base ] : -1;
			if ( $dest !== $count ) {
				$mismatched[ $base ] = array(
					'source' => $count,
					'dest'   => $dest,
				);
			}
		}

		$state           = NDM_State::get_source();
		$state['verify'] = array(
			'checked'    => count( $source_counts ),
			'mismatched' => $mismatched,
			'time'       => time(),
		);

		if ( ! empty( $mismatched ) ) {
			// Rows changed while syncing (live site). Re-queue only the drifted tables — a delta pass.
			foreach ( $state['tables'] as $i => $table ) {
				if ( isset( $mismatched[ $table['base'] ] ) ) {
					$state['tables'][ $i ]['done']      = false;
					$state['tables'][ $i ]['created']   = false;
					$state['tables'][ $i ]['last_pk']   = 0;
					$state['tables'][ $i ]['offset']    = 0;
					$state['tables'][ $i ]['rows_sent'] = 0;
				}
			}
			$state['table_index'] = 0;
			$state['stage']       = NDM_State::STAGE_DB;
			NDM_State::save_source( $state );
			NDM_Log::warn( sprintf( 'Verification found %d drifted table(s); re-syncing them.', count( $mismatched ) ) );
			return true;
		}

		$state['stage'] = NDM_State::STAGE_READY;
		NDM_State::save_source( $state );
		NDM_Log::info( 'Verification passed — sync is 100% complete.' );

		$settings = get_option( 'ndm_settings', array() );
		if ( ! empty( $settings['auto_cutover'] ) ) {
			NDM_Log::info( 'Auto-cutover enabled; replacing target site now.' );
			return $this->request_cutover();
		}

		return true;
	}

	/**
	 * Ask the destination to perform the cutover.
	 *
	 * @return true|WP_Error
	 */
	public function request_cutover() {
		$state = NDM_State::get_source();
		if ( ! in_array( $state['stage'], array( NDM_State::STAGE_READY, NDM_State::STAGE_FINALIZING ), true ) ) {
			return new WP_Error( 'ndm_not_ready', 'Sync is not at 100% yet.' );
		}

		$state['stage'] = NDM_State::STAGE_FINALIZING;
		NDM_State::save_source( $state );

		if ( ! $this->acquire_lock() ) {
			// A cron tick will pick the finalizing stage up; don't send a
			// second, concurrent cutover request.
			return true;
		}

		try {
			$client = new NDM_Client();
			return $this->tick_finalize( $client, $state );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Finalize stage: trigger destination cutover.
	 *
	 * @param NDM_Client $client Client.
	 * @param array      $state  Source state.
	 * @return true|WP_Error
	 */
	private function tick_finalize( NDM_Client $client, array $state ) {
		$settings = get_option( 'ndm_settings', array() );

		$response = $client->post(
			'finalize',
			array(
				'delete_extra_files' => ! empty( $settings['delete_extra_files'] ),
			),
			300
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$state                = NDM_State::get_source();
		$state['stage']       = NDM_State::STAGE_DONE;
		$state['finished_at'] = time();
		NDM_State::save_source( $state );
		NDM_File_Scanner::cleanup();
		wp_clear_scheduled_hook( self::CRON_HOOK );

		NDM_Log::info( 'Cutover complete — the target site now serves this site\'s content on its own domain.' );
		return true;
	}
}
