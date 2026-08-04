<?php
/**
 * Source-side database export in resumable batches.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enumerates tables and exports rows batch by batch, checkpointed on the
 * primary key (or row offset for tables without a usable single-column PK).
 */
class NDM_DB_Exporter {

	/**
	 * Soft cap on the encoded payload size of one row batch, in bytes.
	 *
	 * The rows-per-batch setting caps row COUNT, but tables like Action
	 * Scheduler logs or cart histories can carry rows of tens of kilobytes;
	 * 500 of those would exceed the destination's PHP memory or request-size
	 * limits and crash the import. A batch is cut early once its encoded
	 * values pass this cap, whatever the row count.
	 */
	const MAX_BATCH_BYTES = 1048576; // 1 MB.

	/**
	 * List this site's tables (those using the WP prefix) with export metadata.
	 *
	 * @return array[] Each: name, base (name without prefix), pk, last_pk, offset, rows_total, rows_sent, done, created.
	 */
	public static function list_tables() {
		global $wpdb;

		$tables = array();
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$names  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $names as $name ) {
			// Never export our own staging/backup tables if this site was once a destination.
			$base = substr( $name, strlen( $wpdb->prefix ) );
			if ( 0 === strpos( $base, NDM_STAGE_TABLE_PREFIX ) || 0 === strpos( $base, NDM_BACKUP_TABLE_PREFIX ) ) {
				continue;
			}

			$count = self::count_rows( $name, $base );

			$tables[] = array(
				'name'       => $name,
				'base'       => $base,
				'pk'         => self::primary_key( $name ),
				'last_pk'    => 0,
				'offset'     => 0,
				'rows_total' => $count,
				'rows_sent'  => 0,
				'done'       => false,
				'created'    => false,
			);
		}

		return $tables;
	}

	/**
	 * Row count for a table, applying the same skip rules as the export.
	 *
	 * @param string $name Full table name.
	 * @param string $base Base table name.
	 * @return int
	 */
	private static function count_rows( $name, $base ) {
		global $wpdb;

		$name = str_replace( '`', '', $name );

		if ( 'options' === $base ) {
			// Transients are skipped by the export; keep counts consistent so
			// verification doesn't flag a false mismatch.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}` WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether a row is excluded from the migration.
	 *
	 * Transients are cache: they can be large (WooCommerce/API caches), are
	 * regenerated automatically, and bloat the target for no benefit.
	 *
	 * @param string $base Base table name.
	 * @param array  $row  Row (associative).
	 * @return bool
	 */
	public static function skip_row( $base, $row ) {
		$skip = false;

		if ( 'options' === $base && isset( $row['option_name'] ) ) {
			$skip = (bool) preg_match( '/^_(site_)?transient_/', $row['option_name'] );
		}

		/**
		 * Filter whether a row is skipped during export.
		 *
		 * @param bool   $skip Whether to skip.
		 * @param string $base Base table name.
		 * @param array  $row  The row.
		 */
		return (bool) apply_filters( 'ndm_skip_row', $skip, $base, $row );
	}

	/**
	 * Detect a single-column numeric primary key.
	 *
	 * @param string $table Table name.
	 * @return string|null Column name or null when batching must fall back to OFFSET.
	 */
	private static function primary_key( $table ) {
		global $wpdb;

		$table = str_replace( '`', '', $table );
		$keys  = $wpdb->get_results( 'SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( 1 !== count( $keys ) ) {
			return null; // No PK or composite PK.
		}

		return $keys[0]['Column_name'];
	}

	/**
	 * Get the CREATE TABLE statement with the table renamed.
	 *
	 * @param string $table    Source table name.
	 * @param string $new_name Name to substitute.
	 * @return string
	 */
	public static function create_statement( $table, $new_name ) {
		global $wpdb;

		$table = str_replace( '`', '', $table );
		$row   = $wpdb->get_row( 'SHOW CREATE TABLE `' . $table . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $row || empty( $row[1] ) ) {
			return '';
		}

		$sql = $row[1];
		// Replace only the table name in the CREATE clause.
		$sql = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `' . str_replace( '`', '', $new_name ) . '`', $sql, 1 );
		// Constraint names must be unique per database; namespace them.
		$sql = preg_replace( '/CONSTRAINT `([^`]+)`/', 'CONSTRAINT `ndm_$1`', $sql );

		return $sql;
	}

	/**
	 * Fetch the next batch of rows for a table checkpoint.
	 *
	 * @param array $table Table state entry (name, pk, last_pk, offset).
	 * @param int   $limit Max rows.
	 * @return array { rows: array[], columns: string[], next_last_pk: int, next_offset: int, finished: bool }
	 */
	public static function fetch_batch( $table, $limit ) {
		global $wpdb;

		$name = str_replace( '`', '', $table['name'] );

		if ( $table['pk'] ) {
			$pk   = str_replace( '`', '', $table['pk'] );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT * FROM `' . $name . '` WHERE `' . $pk . '` > %d ORDER BY `' . $pk . '` ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$table['last_pk'],
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT * FROM `' . $name . '` LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit,
					$table['offset']
				),
				ARRAY_A
			);
		}

		$columns   = $rows ? array_keys( $rows[0] ) : array();
		$encoded   = array();
		$last_pk   = (int) $table['last_pk'];
		$bytes     = 0;
		$processed = 0;
		$truncated = false;

		/** This filter is documented above; lets hosts with tight limits shrink batches further. */
		$max_bytes = (int) apply_filters( 'ndm_max_batch_bytes', self::MAX_BATCH_BYTES );

		foreach ( $rows as $row ) {
			// Skipped rows still advance the checkpoint — they're excluded, not deferred.
			$processed++;
			if ( $table['pk'] && isset( $row[ $table['pk'] ] ) ) {
				$last_pk = max( $last_pk, (int) $row[ $table['pk'] ] );
			}

			if ( self::skip_row( $table['base'], $row ) ) {
				continue;
			}

			$values = array();
			foreach ( $columns as $column ) {
				// Base64 every non-null value so binary-safe transport is guaranteed.
				$value    = null === $row[ $column ] ? null : base64_encode( $row[ $column ] );
				$values[] = $value;
				$bytes   += null === $value ? 0 : strlen( $value );
			}
			$encoded[] = $values;

			// Cut the batch early on payload size (always keep at least one row).
			if ( $bytes >= $max_bytes && $processed < count( $rows ) ) {
				$truncated = true;
				break;
			}
		}

		return array(
			'rows'         => $encoded,
			'columns'      => $columns,
			'next_last_pk' => $last_pk,
			'next_offset'  => (int) $table['offset'] + $processed,
			'finished'     => ! $truncated && count( $rows ) < $limit,
		);
	}

	/**
	 * Current row counts for verification.
	 *
	 * @param array $tables Table state entries.
	 * @return array Map of base table name => row count.
	 */
	public static function row_counts( $tables ) {
		global $wpdb;

		$counts = array();
		foreach ( $tables as $table ) {
			$counts[ $table['base'] ] = self::count_rows( $table['name'], $table['base'] );
		}
		return $counts;
	}
}
