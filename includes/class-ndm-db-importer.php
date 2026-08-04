<?php
/**
 * Destination-side database import into staging tables.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives table structures and row batches into staging tables
 * ({dest_prefix}ndmstg_{base}). URL/path search-replace and table-prefix
 * remapping happen here, at import time, so the final cutover is a fast
 * atomic table swap.
 */
class NDM_DB_Importer {

	/**
	 * Staging table name for a source base table name.
	 *
	 * @param string $base Table name without the source prefix (e.g. "posts").
	 * @return string
	 */
	public static function stage_table( $base ) {
		global $wpdb;
		return $wpdb->prefix . NDM_STAGE_TABLE_PREFIX . $base;
	}

	/**
	 * Final (live) table name for a source base table name.
	 *
	 * @param string $base Table name without the source prefix.
	 * @return string
	 */
	public static function live_table( $base ) {
		global $wpdb;
		return $wpdb->prefix . $base;
	}

	/**
	 * Create (or recreate) a staging table from a source CREATE statement.
	 *
	 * @param string $base       Base table name.
	 * @param string $create_sql CREATE statement from the source, already renamed by the exporter.
	 * @param bool   $fresh      Whether to drop an existing staging table first.
	 * @return true|WP_Error
	 */
	public static function create_stage_table( $base, $create_sql, $fresh = true ) {
		global $wpdb;

		$stage = self::stage_table( $base );

		if ( ! preg_match( '/^CREATE TABLE `/', $create_sql ) ) {
			return new WP_Error( 'ndm_bad_create', 'Refused table definition: not a CREATE TABLE statement.' );
		}
		// Re-point the statement at our staging table name regardless of what the source sent.
		$create_sql = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `' . str_replace( '`', '', $stage ) . '`', $create_sql, 1 );

		// Drop FOREIGN KEY constraints: their REFERENCES clauses point at the
		// source's live table names (which don't exist here), and staging
		// tables are filled in alphabetical order, not dependency order.
		$create_sql = self::strip_foreign_keys( $create_sql );

		if ( $fresh ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $stage ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$result = $wpdb->query( $create_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$error  = $wpdb->last_error; // Capture now — any later query resets it.

		if ( false === $result && $error && false !== stripos( $error, 'collation' ) ) {
			// MySQL-8-only collations (utf8mb4_0900_*) don't exist on MariaDB;
			// retry with the universally available equivalent.
			$fallback = preg_replace( '/utf8mb4_0900_\w+/i', 'utf8mb4_unicode_ci', $create_sql );
			$result   = $wpdb->query( $fallback ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$error    = $wpdb->last_error;
		}

		// Trust nothing: confirm the table is really there before accepting rows.
		if ( ! self::stage_table_exists( $base ) ) {
			return new WP_Error( 'ndm_create_failed', 'Could not create staging table ' . $stage . ': ' . ( $error ? $error : 'unknown database error' ) );
		}

		return true;
	}

	/**
	 * Remove FOREIGN KEY constraint definitions from a SHOW CREATE TABLE
	 * statement, fixing up the trailing comma left behind.
	 *
	 * @param string $create_sql CREATE statement (one definition per line, as
	 *                           emitted by SHOW CREATE TABLE).
	 * @return string
	 */
	public static function strip_foreign_keys( $create_sql ) {
		$lines = preg_split( '/\r?\n/', $create_sql );
		$kept  = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*CONSTRAINT\b.*\bFOREIGN KEY\b/i', $line ) || preg_match( '/^\s*FOREIGN KEY\b/i', $line ) ) {
				continue;
			}
			$kept[] = $line;
		}
		$sql = implode( "\n", $kept );

		// A removed final constraint leaves ",\n)" behind.
		return preg_replace( '/,(\s*\))/', '$1', $sql );
	}

	/**
	 * Whether the staging table for a base name exists.
	 *
	 * @param string $base Base table name.
	 * @return bool
	 */
	public static function stage_table_exists( $base ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::stage_table( $base ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Import a batch of rows.
	 *
	 * Uses REPLACE INTO so re-sending a batch after a lost acknowledgement is
	 * idempotent (rows are keyed on the primary key).
	 *
	 * @param string             $base     Base table name.
	 * @param string[]           $columns  Column names.
	 * @param array[]            $rows     Rows of base64-encoded values (null preserved).
	 * @param NDM_Search_Replace $replacer Replacer built from the stored replacement pairs.
	 * @param string             $source_prefix Source table prefix, used for options/usermeta key remapping.
	 * @return int|WP_Error Number of rows written.
	 */
	public static function import_rows( $base, $columns, $rows, NDM_Search_Replace $replacer, $source_prefix ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$stage = str_replace( '`', '', self::stage_table( $base ) );

		$columns = array_map(
			static function ( $column ) {
				return str_replace( '`', '', $column );
			},
			$columns
		);
		$column_sql = '`' . implode( '`, `', $columns ) . '`';

		$written = 0;
		foreach ( array_chunk( $rows, 50 ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $row ) {
				$row_placeholders = array();
				foreach ( $row as $index => $value ) {
					if ( null === $value ) {
						$row_placeholders[] = 'NULL';
						continue;
					}
					$decoded = base64_decode( $value, true );
					$decoded = false === $decoded ? '' : $decoded;
					$decoded = $replacer->replace_value( $decoded );
					$decoded = self::remap_prefixed_key( $base, $columns[ $index ], $decoded, $source_prefix );

					$row_placeholders[] = '%s';
					$values[]           = $decoded;
				}
				$placeholders[] = '(' . implode( ', ', $row_placeholders ) . ')';
			}

			$sql = 'REPLACE INTO `' . $stage . '` (' . $column_sql . ') VALUES ' . implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $values ? $wpdb->prepare( $sql, $values ) : $sql );

			if ( false === $result ) {
				return new WP_Error( 'ndm_import_failed', 'Import into ' . $stage . ' failed: ' . $wpdb->last_error );
			}
			$written += count( $chunk );
		}

		return $written;
	}

	/**
	 * Remap table-prefix-dependent keys when source and destination prefixes differ.
	 *
	 * WordPress stores the table prefix inside data: the "{prefix}user_roles"
	 * option and "{prefix}capabilities" / "{prefix}user_level" / other
	 * usermeta keys. Without this remap, all users would lose their roles
	 * after cutover on sites with different prefixes.
	 *
	 * @param string $base          Base table name.
	 * @param string $column        Column name.
	 * @param string $value         Decoded value.
	 * @param string $source_prefix Source prefix.
	 * @return string
	 */
	private static function remap_prefixed_key( $base, $column, $value, $source_prefix ) {
		global $wpdb;

		if ( ! $source_prefix || $source_prefix === $wpdb->prefix ) {
			return $value;
		}

		$is_option_name  = ( 'options' === $base && 'option_name' === $column );
		$is_usermeta_key = ( 'usermeta' === $base && 'meta_key' === $column );

		if ( ( $is_option_name || $is_usermeta_key ) && 0 === strpos( $value, $source_prefix ) ) {
			return $wpdb->prefix . substr( $value, strlen( $source_prefix ) );
		}

		return $value;
	}

	/**
	 * Row counts of staging tables, keyed by base name.
	 *
	 * @param string[] $bases Base table names.
	 * @return array
	 */
	public static function stage_counts( $bases ) {
		global $wpdb;

		$counts = array();
		foreach ( $bases as $base ) {
			$stage = str_replace( '`', '', self::stage_table( $base ) );
			$counts[ $base ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $stage . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $counts;
	}

	/**
	 * Drop all staging tables (fresh start or cancel).
	 */
	public static function drop_all_stage_tables() {
		global $wpdb;

		$like  = $wpdb->esc_like( $wpdb->prefix . NDM_STAGE_TABLE_PREFIX ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $names as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $name ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
