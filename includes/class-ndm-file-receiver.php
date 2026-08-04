<?php
/**
 * Destination-side file staging.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives file chunks into wp-content/ndm-staging/, verifying each completed
 * file against its MD5. Chunk writes are idempotent: a chunk whose offset is
 * below the current staged size is acknowledged without rewriting, so a lost
 * acknowledgement never corrupts a file on retry.
 */
class NDM_File_Receiver {

	/**
	 * Staging root.
	 *
	 * @return string
	 */
	public static function staging_dir() {
		$dir = WP_CONTENT_DIR . '/ndm-staging';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/**
	 * Validate and resolve a relative path inside the staging dir.
	 *
	 * @param string $rel Relative path from wp-content on the source.
	 * @return string|WP_Error Absolute staged path.
	 */
	public static function resolve( $rel ) {
		$rel = wp_normalize_path( $rel );
		if ( '' === $rel || 0 === strpos( $rel, '/' ) || false !== strpos( $rel, '..' ) || preg_match( '/^[a-zA-Z]:/', $rel ) ) {
			return new WP_Error( 'ndm_bad_path', 'Rejected unsafe path: ' . $rel );
		}

		$allowed = false;
		foreach ( NDM_File_Scanner::sync_roots() as $root ) {
			if ( 0 === strpos( $rel, $root . '/' ) || $rel === $root ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			return new WP_Error( 'ndm_bad_path', 'Path outside synced roots: ' . $rel );
		}

		return self::staging_dir() . '/' . $rel;
	}

	/**
	 * Write a chunk.
	 *
	 * @param string      $rel    Relative path.
	 * @param int         $offset Byte offset the chunk starts at.
	 * @param string      $data   Base64 chunk data.
	 * @param bool        $eof    Whether this is the final chunk.
	 * @param string|null $md5    Expected MD5 of the complete file (required on eof).
	 * @return array|WP_Error { staged: int current staged size, complete: bool, restart: bool }
	 */
	public static function write_chunk( $rel, $offset, $data, $eof, $md5 ) {
		$path = self::resolve( $rel );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		wp_mkdir_p( dirname( $path ) );

		$decoded = base64_decode( $data, true );
		if ( false === $decoded ) {
			return new WP_Error( 'ndm_bad_chunk', 'Chunk data is not valid base64.' );
		}

		$current = file_exists( $path ) ? filesize( $path ) : 0;

		if ( $offset > $current ) {
			// Gap in the stream (e.g. staged file was removed between batches): ask the source to restart this file.
			return array(
				'staged'   => 0,
				'complete' => false,
				'restart'  => true,
			);
		}

		if ( $offset < $current ) {
			// Already have these bytes (retry after a lost ack). Truncate to the
			// chunk boundary and rewrite to keep the file consistent.
			$handle = fopen( $path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $handle ) {
				return new WP_Error( 'ndm_write_failed', 'Could not open staged file: ' . $rel );
			}
			ftruncate( $handle, $offset );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$current = $offset;
		}

		$written = file_put_contents( $path, $decoded, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return new WP_Error( 'ndm_write_failed', 'Could not write staged file: ' . $rel );
		}

		$staged   = $current + $written;
		$complete = false;

		if ( $eof ) {
			if ( $md5 && md5_file( $path ) !== $md5 ) {
				wp_delete_file( $path );
				return array(
					'staged'   => 0,
					'complete' => false,
					'restart'  => true,
				);
			}
			$complete = true;
		}

		return array(
			'staged'   => $staged,
			'complete' => $complete,
			'restart'  => false,
		);
	}

	/**
	 * Given manifest entries, report which need to be (re)uploaded.
	 *
	 * A file whose staged copy already matches size + MD5 is skipped, which is
	 * what makes re-running a sync an incremental delta pass.
	 *
	 * @param array[] $entries Each: path, size, md5.
	 * @return string[] Paths that need upload.
	 */
	public static function files_needed( $entries ) {
		$needed = array();
		foreach ( $entries as $entry ) {
			$path = self::resolve( $entry['path'] );
			if ( is_wp_error( $path ) ) {
				continue; // Unsafe paths are simply never requested.
			}
			if ( file_exists( $path )
				&& filesize( $path ) === (int) $entry['size']
				&& ! empty( $entry['md5'] )
				&& md5_file( $path ) === $entry['md5'] ) {
				continue;
			}
			$needed[] = $entry['path'];
		}
		return $needed;
	}

	/**
	 * Delete the whole staging dir.
	 */
	public static function drop_staging() {
		self::rrmdir( WP_CONTENT_DIR . '/ndm-staging' );
	}

	/**
	 * Recursive directory removal.
	 *
	 * @param string $dir Directory.
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
