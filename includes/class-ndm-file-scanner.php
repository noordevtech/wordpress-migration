<?php
/**
 * Source-side file manifest builder and chunk reader.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds a manifest of wp-content files (uploads, themes, plugins, mu-plugins,
 * languages) and reads them in chunks for transfer. The manifest is written to
 * a JSON-lines file so very large sites don't blow up the options table; the
 * migration state only stores the current line index and byte offset.
 */
class NDM_File_Scanner {

	const CHUNK_SIZE = 524288; // 512 KB raw per chunk.

	/**
	 * Directories under wp-content that are synced.
	 *
	 * @return string[]
	 */
	public static function sync_roots() {
		return array( 'uploads', 'themes', 'plugins', 'mu-plugins', 'languages' );
	}

	/**
	 * Relative paths (from wp-content) that are never synced.
	 *
	 * @return string[]
	 */
	public static function excluded_paths() {
		$excluded = array(
			'plugins/' . basename( NDM_PLUGIN_DIR ),
			'ndm-staging',
			'ndm-tmp',
			'cache',
			'uploads/cache',
			'upgrade',
			'upgrade-temp-backup',
			'debug.log',
		);

		/**
		 * Filter the excluded relative paths.
		 *
		 * @param string[] $excluded Paths relative to wp-content.
		 */
		return apply_filters( 'ndm_excluded_paths', $excluded );
	}

	/**
	 * Path of the manifest file.
	 *
	 * @return string
	 */
	public static function manifest_path() {
		$dir = WP_CONTENT_DIR . '/ndm-tmp';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir . '/manifest.jsonl';
	}

	/**
	 * Build the manifest file. Returns totals.
	 *
	 * @return array { files: int, bytes: int, path: string }
	 */
	public static function build_manifest() {
		$manifest_path = self::manifest_path();
		$handle        = fopen( $manifest_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$files         = 0;
		$bytes         = 0;
		$excluded      = self::excluded_paths();
		$content_dir   = wp_normalize_path( WP_CONTENT_DIR );

		foreach ( self::sync_roots() as $root ) {
			$root_path = $content_dir . '/' . $root;
			if ( ! is_dir( $root_path ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root_path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || $file->isLink() ) {
					continue;
				}

				$rel = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $content_dir ) ), '/' );

				$skip = false;
				foreach ( $excluded as $exclude ) {
					if ( $rel === $exclude || 0 === strpos( $rel, trailingslashit( $exclude ) ) ) {
						$skip = true;
						break;
					}
				}
				if ( $skip ) {
					continue;
				}

				$size = $file->getSize();
				fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions
					$handle,
					wp_json_encode(
						array(
							'path'  => $rel,
							'size'  => $size,
							'mtime' => $file->getMTime(),
						)
					) . "\n"
				);
				$files++;
				$bytes += $size;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array(
			'files' => $files,
			'bytes' => $bytes,
			'path'  => $manifest_path,
		);
	}

	/**
	 * Read a slice of manifest entries starting at a line index.
	 *
	 * @param int $start Line index (0-based).
	 * @param int $count Number of entries.
	 * @return array[]
	 */
	public static function read_entries( $start, $count ) {
		$handle = fopen( self::manifest_path(), 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return array();
		}

		$entries = array();
		$line_no = 0;
		while ( ( $line = fgets( $handle ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			if ( $line_no >= $start && $line_no < $start + $count ) {
				$entry = json_decode( trim( $line ), true );
				if ( $entry ) {
					$entry['index'] = $line_no;
					$entries[]      = $entry;
				}
			}
			$line_no++;
			if ( $line_no >= $start + $count ) {
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $entries;
	}

	/**
	 * MD5 of a synced file.
	 *
	 * @param string $rel Relative path from wp-content.
	 * @return string|null
	 */
	public static function file_md5( $rel ) {
		$path = WP_CONTENT_DIR . '/' . $rel;
		return file_exists( $path ) ? md5_file( $path ) : null;
	}

	/**
	 * Read one chunk of a file.
	 *
	 * @param string $rel    Relative path from wp-content.
	 * @param int    $offset Byte offset.
	 * @return array|WP_Error { data: base64 string, offset: int, size: int, eof: bool, md5: string|null (on eof) }
	 */
	public static function read_chunk( $rel, $offset ) {
		$path = WP_CONTENT_DIR . '/' . $rel;
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'ndm_file_gone', 'File disappeared or unreadable: ' . $rel );
		}

		$size   = filesize( $path );
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return new WP_Error( 'ndm_file_open', 'Could not open file: ' . $rel );
		}

		fseek( $handle, $offset );
		$data = $size > $offset ? fread( $handle, self::CHUNK_SIZE ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$eof = ( $offset + strlen( $data ) ) >= $size;

		return array(
			'data'   => base64_encode( $data ),
			'offset' => $offset,
			'size'   => $size,
			'eof'    => $eof,
			'md5'    => $eof ? md5_file( $path ) : null,
		);
	}

	/**
	 * Remove the manifest/tmp dir.
	 */
	public static function cleanup() {
		$path = WP_CONTENT_DIR . '/ndm-tmp/manifest.jsonl';
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
