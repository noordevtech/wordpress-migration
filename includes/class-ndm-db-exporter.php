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

			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . str_replace( '`', '', $name ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

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

		$columns = $rows ? array_keys( $rows[0] ) : array();
		$encoded = array();
		$last_pk = (int) $table['last_pk'];

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $column ) {
				// Base64 every non-null value so binary-safe transport is guaranteed.
				$values[] = null === $row[ $column ] ? null : base64_encode( $row[ $column ] );
			}
			$encoded[] = $values;
			if ( $table['pk'] && isset( $row[ $table['pk'] ] ) ) {
				$last_pk = max( $last_pk, (int) $row[ $table['pk'] ] );
			}
		}

		return array(
			'rows'         => $encoded,
			'columns'      => $columns,
			'next_last_pk' => $last_pk,
			'next_offset'  => (int) $table['offset'] + count( $rows ),
			'finished'     => count( $rows ) < $limit,
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
			$name                      = str_replace( '`', '', $table['name'] );
			$counts[ $table['base'] ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $name . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $counts;
	}
}
