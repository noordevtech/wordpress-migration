<?php
/**
 * HMAC authentication for site-to-site requests.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared-secret request signing and verification.
 *
 * Every request between the two sites carries:
 *  - X-NDM-Timestamp: unix time when the request was signed.
 *  - X-NDM-Signature: hex HMAC-SHA256 of "{timestamp}.{raw body}" with the shared secret.
 */
class NDM_Auth {

	const TIMESTAMP_TOLERANCE = 300; // seconds.

	/**
	 * Generate a new shared secret.
	 *
	 * @return string
	 */
	public static function generate_secret() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Sign a request body.
	 *
	 * @param string $body   Raw body.
	 * @param string $secret Shared secret.
	 * @param int    $time   Unix timestamp.
	 * @return string Hex signature.
	 */
	public static function sign( $body, $secret, $time ) {
		return hash_hmac( 'sha256', $time . '.' . $body, $secret );
	}

	/**
	 * Verify an incoming REST request against this site's secret.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function verify_request( WP_REST_Request $request ) {
		$secret = get_option( 'ndm_secret' );
		if ( ! $secret ) {
			return new WP_Error( 'ndm_no_secret', 'This site has no connection key configured.', array( 'status' => 403 ) );
		}

		$time      = (int) $request->get_header( 'X-NDM-Timestamp' );
		$signature = (string) $request->get_header( 'X-NDM-Signature' );

		if ( ! $time || ! $signature ) {
			return new WP_Error( 'ndm_unsigned', 'Missing authentication headers.', array( 'status' => 401 ) );
		}

		if ( abs( time() - $time ) > self::TIMESTAMP_TOLERANCE ) {
			return new WP_Error( 'ndm_stale', 'Request timestamp outside the allowed window. Check server clocks.', array( 'status' => 401 ) );
		}

		$expected = self::sign( $request->get_body(), $secret, $time );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'ndm_bad_signature', 'Invalid request signature.', array( 'status' => 401 ) );
		}

		return true;
	}
}
