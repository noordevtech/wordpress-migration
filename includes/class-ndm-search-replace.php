<?php
/**
 * Serialized-data-safe search & replace.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces source URLs/paths with destination values without corrupting
 * PHP-serialized data (string length prefixes are recalculated by
 * unserializing, replacing recursively, and re-serializing).
 */
class NDM_Search_Replace {

	/**
	 * Search => replace pairs.
	 *
	 * @var array
	 */
	private $pairs = array();

	/**
	 * Constructor.
	 *
	 * @param array $pairs Map of search => replace strings, longest keys first is handled internally.
	 */
	public function __construct( array $pairs ) {
		// Replace longer needles first so "https://a.com" wins over "a.com".
		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$this->pairs = $pairs;
	}

	/**
	 * Build the standard replacement pairs between two sites.
	 *
	 * Covers plain URLs, protocol variants, JSON-escaped URLs, URL-encoded
	 * URLs, bare domains and filesystem paths.
	 *
	 * @param string $source_url     Source home URL.
	 * @param string $dest_url       Destination home URL.
	 * @param string $source_abspath Source ABSPATH.
	 * @param string $dest_abspath   Destination ABSPATH.
	 * @return array
	 */
	public static function build_pairs( $source_url, $dest_url, $source_abspath, $dest_abspath ) {
		$pairs = array();

		$source_url = untrailingslashit( $source_url );
		$dest_url   = untrailingslashit( $dest_url );

		if ( $source_url && $dest_url && $source_url !== $dest_url ) {
			$src_http  = preg_replace( '#^https://#', 'http://', $source_url );
			$src_https = preg_replace( '#^http://#', 'https://', $source_url );

			$pairs[ $src_https ] = $dest_url;
			$pairs[ $src_http ]  = $dest_url;

			// JSON-escaped variants (Gutenberg block attributes, JSON meta).
			$pairs[ str_replace( '/', '\/', $src_https ) ] = str_replace( '/', '\/', $dest_url );
			$pairs[ str_replace( '/', '\/', $src_http ) ]  = str_replace( '/', '\/', $dest_url );

			// URL-encoded variants.
			$pairs[ rawurlencode( $src_https ) ] = rawurlencode( $dest_url );
			$pairs[ rawurlencode( $src_http ) ]  = rawurlencode( $dest_url );

			// Protocol-relative / bare host (only when hosts differ).
			$src_host  = wp_parse_url( $source_url, PHP_URL_HOST );
			$dest_host = wp_parse_url( $dest_url, PHP_URL_HOST );
			if ( $src_host && $dest_host && $src_host !== $dest_host ) {
				$src_path  = (string) wp_parse_url( $source_url, PHP_URL_PATH );
				$dest_path = (string) wp_parse_url( $dest_url, PHP_URL_PATH );
				$pairs[ '//' . $src_host . $src_path ] = '//' . $dest_host . $dest_path;
			}
		}

		$source_abspath = untrailingslashit( $source_abspath );
		$dest_abspath   = untrailingslashit( $dest_abspath );
		if ( $source_abspath && $dest_abspath && $source_abspath !== $dest_abspath ) {
			$pairs[ $source_abspath ] = $dest_abspath;
		}

		return $pairs;
	}

	/**
	 * Replace within a single value.
	 *
	 * @param mixed $value Value (only strings are transformed).
	 * @return mixed
	 */
	public function replace_value( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			$decoded = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
			if ( false !== $decoded || 'b:0;' === $value ) {
				return serialize( $this->replace_recursive( $decoded ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
			// Corrupt serialized data: fall through to plain replace as a best effort.
		}

		return strtr( $value, $this->pairs );
	}

	/**
	 * Recursive replace inside unserialized structures.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private function replace_recursive( $data ) {
		if ( is_string( $data ) ) {
			// Nested serialization (serialized string stored inside an array).
			if ( is_serialized( $data ) ) {
				return $this->replace_value( $data );
			}
			return strtr( $data, $this->pairs );
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$new_key         = is_string( $key ) ? strtr( $key, $this->pairs ) : $key;
				$out[ $new_key ] = $this->replace_recursive( $value );
			}
			return $out;
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->{$key} = $this->replace_recursive( $value );
			}
			return $data;
		}

		return $data;
	}
}
