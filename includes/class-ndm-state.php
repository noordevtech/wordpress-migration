<?php
/**
 * Persistent, resumable migration state (checkpoints).
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkpointed state for both roles.
 *
 * The source stores its push progress under 'ndm_source_state'; the
 * destination stores its receive/cutover progress under 'ndm_dest_state'.
 * Every batch that completes updates the checkpoint, so an interrupted
 * migration continues from the last acknowledged batch instead of restarting.
 */
class NDM_State {

	const SOURCE_OPTION = 'ndm_source_state';
	const DEST_OPTION   = 'ndm_dest_state';

	// Source stages.
	const STAGE_IDLE       = 'idle';
	const STAGE_DB         = 'db';
	const STAGE_FILES      = 'files';
	const STAGE_VERIFY     = 'verify';
	const STAGE_READY      = 'ready';      // 100% synced, waiting for cutover confirmation.
	const STAGE_FINALIZING = 'finalizing';
	const STAGE_DONE       = 'done';
	const STAGE_ERROR      = 'error';

	/**
	 * Default source state.
	 *
	 * @return array
	 */
	public static function default_source_state() {
		return array(
			'stage'        => self::STAGE_IDLE,
			'paused'       => false,
			'tables'       => array(),
			'table_index'  => 0,
			'files_total'  => 0,
			'files_done'   => 0,
			'bytes_total'  => 0,
			'bytes_done'   => 0,
			'file_index'   => 0,
			'file_offset'  => 0,
			'manifest'     => '',
			'files_completed' => false,
			'drift_passes' => 0,
			'started_at'   => 0,
			'updated_at'   => 0,
			'finished_at'  => 0,
			'error'        => '',
			'retries'      => 0,
			'verify'       => array(),
		);
	}

	/**
	 * Read source state.
	 *
	 * @return array
	 */
	public static function get_source() {
		$state = get_option( self::SOURCE_OPTION, array() );
		return wp_parse_args( is_array( $state ) ? $state : array(), self::default_source_state() );
	}

	/**
	 * Save source state.
	 *
	 * @param array $state State.
	 */
	public static function save_source( $state ) {
		$state['updated_at'] = time();
		update_option( self::SOURCE_OPTION, $state, false );
	}

	/**
	 * Reset source state.
	 */
	public static function reset_source() {
		delete_option( self::SOURCE_OPTION );
	}

	/**
	 * Read destination state.
	 *
	 * @return array
	 */
	public static function get_dest() {
		$state = get_option( self::DEST_OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'active'         => false,
				'source_url'     => '',
				'source_abspath' => '',
				'source_prefix'  => '',
				'replacements'   => array(),
				'tables'         => array(),
				'files_received' => 0,
				'bytes_received' => 0,
				'finalized_at'   => 0,
				'updated_at'     => 0,
			)
		);
	}

	/**
	 * Save destination state.
	 *
	 * @param array $state State.
	 */
	public static function save_dest( $state ) {
		$state['updated_at'] = time();
		update_option( self::DEST_OPTION, $state, false );
	}

	/**
	 * Reset destination state.
	 */
	public static function reset_dest() {
		delete_option( self::DEST_OPTION );
	}

	/**
	 * Overall source progress as a 0-100 float.
	 *
	 * DB and files each weigh half of the total.
	 *
	 * @param array $state Source state.
	 * @return float
	 */
	public static function progress( $state ) {
		$db_total = 0;
		$db_done  = 0;
		foreach ( $state['tables'] as $table ) {
			$db_total += max( 1, (int) $table['rows_total'] );
			$db_done  += min( (int) $table['rows_sent'], max( 1, (int) $table['rows_total'] ) );
			if ( ! empty( $table['done'] ) ) {
				$db_done = $db_done - min( (int) $table['rows_sent'], max( 1, (int) $table['rows_total'] ) ) + max( 1, (int) $table['rows_total'] );
			}
		}
		$db_pct = $db_total > 0 ? ( $db_done / $db_total ) : 0;

		$file_pct = $state['bytes_total'] > 0 ? ( $state['bytes_done'] / $state['bytes_total'] ) : ( self::stage_at_least( $state['stage'], self::STAGE_VERIFY ) ? 1 : 0 );

		if ( in_array( $state['stage'], array( self::STAGE_READY, self::STAGE_FINALIZING, self::STAGE_DONE ), true ) ) {
			return 100.0;
		}
		if ( self::STAGE_IDLE === $state['stage'] ) {
			return 0.0;
		}

		return round( min( 99.9, ( $db_pct * 50 ) + ( $file_pct * 50 ) ), 1 );
	}

	/**
	 * Whether a stage is at or past another in the pipeline.
	 *
	 * @param string $stage   Current stage.
	 * @param string $compare Stage to compare against.
	 * @return bool
	 */
	public static function stage_at_least( $stage, $compare ) {
		$order = array( self::STAGE_IDLE, self::STAGE_DB, self::STAGE_FILES, self::STAGE_VERIFY, self::STAGE_READY, self::STAGE_FINALIZING, self::STAGE_DONE );
		return array_search( $stage, $order, true ) >= array_search( $compare, $order, true );
	}
}
