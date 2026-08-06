<?php
/**
 * Destination-side cutover: atomically replace the live site with staged data.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Performs the cutover once the sync is 100% complete.
 *
 * Order of operations:
 *  1. Enable maintenance mode.
 *  2. Snapshot the destination values that must survive (its own domain,
 *     this plugin's connection settings, active state).
 *  3. Swap every staged table into place with a single RENAME TABLE
 *     statement (atomic in MySQL/MariaDB); the previous live tables are
 *     kept as {prefix}ndmbak_* backups for rollback.
 *  4. Restore the preserved options — most importantly siteurl/home, so the
 *     target keeps its own domain.
 *  5. Move staged files into wp-content (optionally deleting files that do
 *     not exist on the source).
 *  6. Flush caches and disable maintenance mode.
 */
class NDM_Finalizer {

	/**
	 * Options on the destination that must survive the table swap.
	 *
	 * @return string[]
	 */
	private static function preserved_option_names() {
		$names = array(
			'siteurl',
			'home',
			'ndm_secret',
			'ndm_dest_state',
			'ndm_settings',
			'ndm_log',
			'blog_charset',
			'template_root',
			'stylesheet_root',
			'upload_path',
			'upload_url_path',
		);

		/**
		 * Filter the destination option names preserved through cutover.
		 *
		 * @param string[] $names Option names.
		 */
		return apply_filters( 'ndm_preserved_options', $names );
	}

	/**
	 * Run the cutover.
	 *
	 * @param bool $delete_extra_files Delete destination files absent from the source manifest.
	 * @return array|WP_Error Summary.
	 */
	public static function run( $delete_extra_files = false ) {
		global $wpdb;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'admin' );

		$dest_state = NDM_State::get_dest();
		if ( empty( $dest_state['active'] ) || empty( $dest_state['tables'] ) ) {
			return new WP_Error( 'ndm_nothing_staged', 'No staged migration to finalize.' );
		}
		if ( ! empty( $dest_state['finalized_at'] ) ) {
			return new WP_Error( 'ndm_already_finalized', 'This staged migration was already applied. Start a new sync from the source to migrate again.' );
		}

		// The essentials must be staged or the swapped site cannot boot.
		foreach ( array( 'options', 'posts', 'users', 'usermeta' ) as $required ) {
			if ( ! in_array( $required, $dest_state['tables'], true ) ) {
				return new WP_Error( 'ndm_incomplete', 'Required table missing from staging: ' . $required );
			}
		}

		self::maintenance( true );
		NDM_Log::info( 'Cutover started: maintenance mode enabled.' );

		try {
			// 2. Snapshot preserved options + active plugins.
			$preserved = array();
			foreach ( self::preserved_option_names() as $name ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $row ) {
					$preserved[ $name ] = $row;
				}
			}
			$plugin_basename = plugin_basename( NDM_PLUGIN_FILE );

			// 3. Atomic table swap.
			$renames = array();
			foreach ( $dest_state['tables'] as $base ) {
				$stage  = str_replace( '`', '', NDM_DB_Importer::stage_table( $base ) );
				$live   = str_replace( '`', '', NDM_DB_Importer::live_table( $base ) );
				$backup = str_replace( '`', '', $wpdb->prefix . NDM_BACKUP_TABLE_PREFIX . $base );

				// Drop any stale backup from a previous run.
				$wpdb->query( 'DROP TABLE IF EXISTS `' . $backup . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				$live_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $live ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $live_exists ) {
					$renames[] = '`' . $live . '` TO `' . $backup . '`';
				}
				$renames[] = '`' . $stage . '` TO `' . $live . '`';
			}

			$result = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $result ) {
				self::maintenance( false );
				return new WP_Error( 'ndm_rename_failed', 'Atomic table swap failed: ' . $wpdb->last_error );
			}
			NDM_Log::info( sprintf( '%d tables swapped in; previous tables kept as %s* backups.', count( $dest_state['tables'] ), $wpdb->prefix . NDM_BACKUP_TABLE_PREFIX ) );

			// 4. Restore preserved options into the (now live) migrated options table.
			foreach ( $preserved as $name => $row ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"REPLACE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
						$name,
						$row['option_value'],
						$row['autoload']
					)
				);
			}

			// Keep this plugin active on the migrated site so the dashboard survives cutover.
			$active = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$active = maybe_unserialize( $active );
			$active = is_array( $active ) ? $active : array();
			if ( ! in_array( $plugin_basename, $active, true ) ) {
				$active[] = $plugin_basename;
				sort( $active );
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'active_plugins'",
						serialize( array_values( $active ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					)
				);
			}

			// 5. Move staged files into place.
			$files_moved = self::promote_files();
			if ( $delete_extra_files ) {
				$deleted = self::delete_extra_files();
				NDM_Log::info( sprintf( '%d files promoted, %d obsolete files removed.', $files_moved, $deleted ) );
			} else {
				NDM_Log::info( sprintf( '%d files promoted from staging.', $files_moved ) );
			}

			// 6. Flush everything cached against the old data.
			wp_cache_flush();
			if ( function_exists( 'opcache_reset' ) ) {
				@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			delete_option( 'rewrite_rules' );

			$dest_state['finalized_at'] = time();
			NDM_State::save_dest( $dest_state );
		} finally {
			self::maintenance( false );
		}

		NDM_Log::info( 'Cutover complete. This site now serves the migrated content on its own domain.' );

		return array(
			'ok'           => true,
			'tables'       => count( $dest_state['tables'] ),
			'finalized_at' => $dest_state['finalized_at'],
		);
	}

	/**
	 * Move staged files over the live wp-content tree.
	 *
	 * @return int Files moved.
	 */
	private static function promote_files() {
		$staging = NDM_File_Receiver::staging_dir();
		$moved   = 0;

		foreach ( NDM_File_Scanner::sync_roots() as $root ) {
			$staged_root = $staging . '/' . $root;
			if ( ! is_dir( $staged_root ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $staged_root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$rel    = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( wp_normalize_path( $staging ) ) ), '/' );
				$target = WP_CONTENT_DIR . '/' . $rel;

				// Never overwrite this plugin with a staged copy mid-request.
				if ( 0 === strpos( $rel, 'plugins/' . basename( NDM_PLUGIN_DIR ) . '/' ) ) {
					continue;
				}

				wp_mkdir_p( dirname( $target ) );
				if ( @rename( $file->getPathname(), $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					$moved++;
				} elseif ( @copy( $file->getPathname(), $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					wp_delete_file( $file->getPathname() );
					$moved++;
				}
			}
		}

		NDM_File_Receiver::drop_staging();
		return $moved;
	}

	/**
	 * Delete live files that were not part of the staged manifest.
	 *
	 * Uses the record of staged relative paths captured during the sync.
	 *
	 * @return int Files deleted.
	 */
	private static function delete_extra_files() {
		$manifest = get_option( 'ndm_dest_manifest', array() );
		if ( empty( $manifest ) || ! is_array( $manifest ) ) {
			return 0; // Without a manifest we never delete anything.
		}

		$keep    = array_flip( $manifest );
		$deleted = 0;
		$plugin  = 'plugins/' . basename( NDM_PLUGIN_DIR );

		foreach ( NDM_File_Scanner::sync_roots() as $root ) {
			$root_path = WP_CONTENT_DIR . '/' . $root;
			if ( ! is_dir( $root_path ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root_path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$rel = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( wp_normalize_path( WP_CONTENT_DIR ) ) ), '/' );

				if ( isset( $keep[ $rel ] ) || 0 === strpos( $rel, $plugin . '/' ) || 0 === strpos( $rel, 'ndm-' ) ) {
					continue;
				}

				wp_delete_file( $file->getPathname() );
				$deleted++;
			}
		}

		delete_option( 'ndm_dest_manifest' );
		return $deleted;
	}

	/**
	 * Toggle maintenance mode via the standard .maintenance file.
	 *
	 * @param bool $on On/off.
	 */
	private static function maintenance( $on ) {
		$file = ABSPATH . '.maintenance';
		if ( $on ) {
			file_put_contents( $file, '<?php $upgrading = ' . time() . ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Roll back to the pre-cutover tables (ndmbak_* backups).
	 *
	 * @return array|WP_Error
	 */
	public static function rollback() {
		global $wpdb;

		$like    = $wpdb->esc_like( $wpdb->prefix . NDM_BACKUP_TABLE_PREFIX ) . '%';
		$backups = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( empty( $backups ) ) {
			return new WP_Error( 'ndm_no_backup', 'No backup tables found.' );
		}

		self::maintenance( true );

		$renames = array();
		foreach ( $backups as $backup ) {
			$base = substr( $backup, strlen( $wpdb->prefix . NDM_BACKUP_TABLE_PREFIX ) );
			$live = str_replace( '`', '', $wpdb->prefix . $base );
			$old  = str_replace( '`', '', $wpdb->prefix . 'ndmold_' . $base );

			$wpdb->query( 'DROP TABLE IF EXISTS `' . $old . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $live ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$renames[] = '`' . $live . '` TO `' . $old . '`';
			}
			$renames[] = '`' . str_replace( '`', '', $backup ) . '` TO `' . $live . '`';
		}

		$result = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::maintenance( false );
		wp_cache_flush();

		if ( false === $result ) {
			return new WP_Error( 'ndm_rollback_failed', 'Rollback failed: ' . $wpdb->last_error );
		}

		NDM_Log::info( 'Rolled back to pre-cutover tables.' );
		return array( 'ok' => true );
	}

	/**
	 * Drop backup tables after the user confirms the migrated site is healthy.
	 */
	public static function cleanup_backups() {
		global $wpdb;

		foreach ( array( NDM_BACKUP_TABLE_PREFIX, 'ndmold_' ) as $prefix ) {
			$like  = $wpdb->esc_like( $wpdb->prefix . $prefix ) . '%';
			$names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $names as $name ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $name ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		NDM_Log::info( 'Backup tables removed.' );
	}
}
