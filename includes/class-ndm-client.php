<?php
/**
 * Source-side signed HTTP client with retry/backoff.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends signed JSON requests to the destination site's REST API.
 */
class NDM_Client {

	const MAX_RETRIES = 3;

	/**
	 * Destination base URL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Shared secret.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Constructor from saved connection settings.
	 */
	public function __construct() {
		$settings     = get_option( 'ndm_connection', array() );
		$this->url    = isset( $settings['url'] ) ? untrailingslashit( $settings['url'] ) : '';
		$this->secret = isset( $settings['key'] ) ? $settings['key'] : '';
	}

	/**
	 * Whether a connection is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return $this->url && $this->secret;
	}

	/**
	 * POST a payload to a destination endpoint, retrying transient failures
	 * with exponential backoff (2s, 4s, 8s).
	 *
	 * @param string $endpoint Endpoint path (e.g. 'rows').
	 * @param array  $payload  JSON-serializable payload.
	 * @param int    $timeout  Request timeout in seconds.
	 * @return array|WP_Error Decoded response body.
	 */
	public function post( $endpoint, array $payload = array(), $timeout = 60 ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'ndm_not_connected', 'No destination connection configured.' );
		}

		$body     = wp_json_encode( $payload );
		$endpoint = ltrim( $endpoint, '/' );
		$attempt  = 0;
		$last     = null;

		while ( $attempt <= self::MAX_RETRIES ) {
			if ( $attempt > 0 ) {
				sleep( min( 8, 2 ** $attempt ) );
			}
			$attempt++;

			$time     = time();
			$response = wp_remote_post(
				$this->url . '/wp-json/' . NDM_REST_NAMESPACE . '/' . $endpoint,
				array(
					'timeout' => $timeout,
					'headers' => array(
						'Content-Type'    => 'application/json',
						'X-NDM-Timestamp' => (string) $time,
						'X-NDM-Signature' => NDM_Auth::sign( $body, $this->secret, $time ),
					),
					'body'    => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				$last = $response;
				continue; // Network error: retry.
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code >= 200 && $code < 300 ) {
				return is_array( $decoded ) ? $decoded : array();
			}

			if ( isset( $decoded['message'] ) ) {
				$message = $decoded['message'];
			} else {
				// Non-JSON body (WordPress fatal-error page, proxy error page…).
				$raw     = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( wp_remote_retrieve_body( $response ) ) ) );
				$message = 'HTTP ' . $code . ( $raw ? ': ' . substr( $raw, 0, 200 ) : '' );
			}
			$last = new WP_Error( 'ndm_http_' . $code, $message );

			// 4xx (auth, bad payload) won't improve with retries.
			if ( $code >= 400 && $code < 500 ) {
				return $last;
			}
		}

		return $last instanceof WP_Error ? $last : new WP_Error( 'ndm_request_failed', 'Request failed.' );
	}
}
