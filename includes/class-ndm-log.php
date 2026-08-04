<?php
/**
 * Ring-buffer activity log stored in one option.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight migration log.
 */
class NDM_Log {

	const OPTION      = 'ndm_log';
	const MAX_ENTRIES = 300;

	/**
	 * Append an entry.
	 *
	 * @param string $level   One of info|warn|error.
	 * @param string $message Message.
	 */
	public static function add( $level, $message ) {
		$log   = get_option( self::OPTION, array() );
		$log[] = array(
			'time'    => time(),
			'level'   => $level,
			'message' => $message,
		);
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, - self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Info shortcut.
	 *
	 * @param string $message Message.
	 */
	public static function info( $message ) {
		self::add( 'info', $message );
	}

	/**
	 * Warning shortcut.
	 *
	 * @param string $message Message.
	 */
	public static function warn( $message ) {
		self::add( 'warn', $message );
	}

	/**
	 * Error shortcut.
	 *
	 * @param string $message Message.
	 */
	public static function error( $message ) {
		self::add( 'error', $message );
	}

	/**
	 * Get latest entries, newest last.
	 *
	 * @param int $count Number of entries.
	 * @return array
	 */
	public static function tail( $count = 50 ) {
		$log = get_option( self::OPTION, array() );
		return array_slice( $log, - $count );
	}

	/**
	 * Clear the log.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
