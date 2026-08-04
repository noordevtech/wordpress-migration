<?php
/**
 * Destination-side REST API.
 *
 * @package NoorDev_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ndm/v1 endpoints the source pushes data to. Every endpoint is
 * protected by HMAC request signing (see NDM_Auth) — no cookies or user
 * accounts are involved in site-to-site calls.
 */
class NDM_Rest_Api {

	/**
	 * Singleton.
	 *
	 * @var NDM_Rest_Api
	 */
	private static $instance;

	/**
	 * Singleton accessor.
	 *
	 * @return NDM_Rest_Api
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route registration.
	 */
	public function register_routes() {
		$routes = array(
			'handshake'   => 'route_handshake',
			'prepare'     => 'route_prepare',
			'table'       => 'route_table',
			'rows'        => 'route_rows',
			'files-check' => 'route_files_check',
			'file-chunk'  => 'route_file_chunk',
			'counts'      => 'route_counts',
			'finalize'    => 'route_finalize',
			'cancel'      => 'route_cancel',
			'status'      => 'route_status',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				NDM_REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( 'NDM_Auth', 'verify_request' ),
				)
			);
		}
	}

	/**
	 * Handshake: capability/URL exchange.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_handshake( WP_REST_Request $request ) {
		global $wpdb;

		return rest_ensure_response(
			array(
				'ok'         => true,
				'home_url'   => home_url(),
				'abspath'    => untrailingslashit( wp_normalize_path( ABSPATH ) ),
				'prefix'     => $wpdb->prefix,
				'wp_version' => get_bloginfo( 'version' ),
				'php'        => PHP_VERSION,
				'plugin'     => NDM_VERSION,
			)
		);
	}

	/**
	 * Prepare: store source identity + replacement pairs; optionally reset staging.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_prepare( WP_REST_Request $request ) {
		$source_url     = esc_url_raw( (string) $request->get_param( 'source_url' ) );
		$source_abspath = sanitize_text_field( (string) $request->get_param( 'source_abspath' ) );
		$source_prefix  = sanitize_text_field( (string) $request->get_param( 'source_prefix' ) );
		$fresh          = (bool) $request->get_param( 'fresh' );

		if ( ! $source_url || ! $source_prefix ) {
			return new WP_Error( 'ndm_bad_prepare', 'Missing source identity.', array( 'status' => 400 ) );
		}

		if ( $fresh ) {
			NDM_DB_Importer::drop_all_stage_tables();
			NDM_File_Receiver::drop_staging();
			delete_option( 'ndm_dest_manifest' );
		}

		$state                   = NDM_State::get_dest();
		$state['active']         = true;
		$state['source_url']     = $source_url;
		$state['source_abspath'] = $source_abspath;
		$state['source_prefix']  = $source_prefix;
		$state['replacements']   = NDM_Search_Replace::build_pairs(
			$source_url,
			home_url(),
			$source_abspath,
			untrailingslashit( wp_normalize_path( ABSPATH ) )
		);
		if ( $fresh ) {
			$state['tables']         = array();
			$state['files_received'] = 0;
			$state['bytes_received'] = 0;
			$state['finalized_at']   = 0;
		}
		NDM_State::save_dest( $state );

		NDM_Log::info( 'Incoming migration prepared from ' . $source_url . ( $fresh ? ' (fresh start).' : ' (resume).' ) );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Table: create a staging table.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_table( WP_REST_Request $request ) {
		$base   = $this->sanitize_base( (string) $request->get_param( 'base' ) );
		$create = (string) $request->get_param( 'create' );
		$fresh  = (bool) $request->get_param( 'fresh' );

		if ( ! $base || ! $create ) {
			return new WP_Error( 'ndm_bad_table', 'Missing table definition.', array( 'status' => 400 ) );
		}

		$result = NDM_DB_Importer::create_stage_table( $base, $create, $fresh );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		$state = NDM_State::get_dest();
		if ( ! in_array( $base, $state['tables'], true ) ) {
			$state['tables'][] = $base;
		}
		NDM_State::save_dest( $state );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Rows: import a row batch into staging.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_rows( WP_REST_Request $request ) {
		$this->raise_limits();

		$base    = $this->sanitize_base( (string) $request->get_param( 'base' ) );
		$columns = (array) $request->get_param( 'columns' );
		$rows    = (array) $request->get_param( 'rows' );

		if ( ! $base || empty( $columns ) ) {
			return new WP_Error( 'ndm_bad_rows', 'Missing row payload.', array( 'status' => 400 ) );
		}

		if ( ! NDM_DB_Importer::stage_table_exists( $base ) ) {
			// 409 tells the source to re-send the table structure and retry.
			return new WP_Error( 'ndm_no_stage_table', 'Staging table missing for ' . $base . '; structure must be re-sent.', array( 'status' => 409 ) );
		}

		$state    = NDM_State::get_dest();
		$replacer = new NDM_Search_Replace( (array) $state['replacements'] );

		try {
			$written = NDM_DB_Importer::import_rows( $base, $columns, $rows, $replacer, $state['source_prefix'] );
		} catch ( \Throwable $e ) {
			// Surface the real cause to the source's activity log instead of
			// letting WordPress render an opaque critical-error page.
			NDM_Log::error( 'Import crashed on ' . $base . ': ' . $e->getMessage() );
			return new WP_Error(
				'ndm_import_crash',
				sprintf( 'Import crashed on %s: %s (%s:%d)', $base, $e->getMessage(), basename( $e->getFile() ), $e->getLine() ),
				array( 'status' => 500 )
			);
		}
		if ( is_wp_error( $written ) ) {
			$written->add_data( array( 'status' => 500 ) );
			return $written;
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'written' => $written,
			)
		);
	}

	/**
	 * Files-check: report which manifest entries still need uploading.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_files_check( WP_REST_Request $request ) {
		$entries = (array) $request->get_param( 'entries' );
		$needed  = NDM_File_Receiver::files_needed( $entries );

		// Record every manifest path so cutover can delete extraneous files.
		$manifest = get_option( 'ndm_dest_manifest', array() );
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['path'] ) ) {
				$manifest[] = wp_normalize_path( (string) $entry['path'] );
			}
		}
		update_option( 'ndm_dest_manifest', array_values( array_unique( $manifest ) ), false );

		return rest_ensure_response(
			array(
				'ok'     => true,
				'needed' => $needed,
			)
		);
	}

	/**
	 * File-chunk: append a chunk to a staged file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_file_chunk( WP_REST_Request $request ) {
		$this->raise_limits();

		$path   = (string) $request->get_param( 'path' );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$data   = (string) $request->get_param( 'data' );
		$eof    = (bool) $request->get_param( 'eof' );
		$md5    = $request->get_param( 'md5' ) ? preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'md5' ) ) : null;

		$result = NDM_File_Receiver::write_chunk( $path, $offset, $data, $eof, $md5 );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		if ( ! empty( $result['complete'] ) ) {
			$state = NDM_State::get_dest();
			$state['files_received']++;
			$state['bytes_received'] += (int) $result['staged'];
			NDM_State::save_dest( $state );
		}

		return rest_ensure_response( array_merge( array( 'ok' => true ), $result ) );
	}

	/**
	 * Counts: staged row counts for verification.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_counts( WP_REST_Request $request ) {
		$bases = array_filter( array_map( array( $this, 'sanitize_base' ), (array) $request->get_param( 'bases' ) ) );

		return rest_ensure_response(
			array(
				'ok'     => true,
				'counts' => NDM_DB_Importer::stage_counts( $bases ),
			)
		);
	}

	/**
	 * Finalize: perform the cutover.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_finalize( WP_REST_Request $request ) {
		$result = NDM_Finalizer::run( (bool) $request->get_param( 'delete_extra_files' ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Cancel: drop all staging data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_cancel( WP_REST_Request $request ) {
		NDM_DB_Importer::drop_all_stage_tables();
		NDM_File_Receiver::drop_staging();
		delete_option( 'ndm_dest_manifest' );
		NDM_State::reset_dest();
		NDM_Log::info( 'Incoming migration cancelled by source; staging discarded.' );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Status: destination-side progress snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_status( WP_REST_Request $request ) {
		$state = NDM_State::get_dest();

		return rest_ensure_response(
			array(
				'ok'             => true,
				'active'         => $state['active'],
				'tables_staged'  => count( $state['tables'] ),
				'files_received' => $state['files_received'],
				'bytes_received' => $state['bytes_received'],
				'finalized_at'   => $state['finalized_at'],
			)
		);
	}

	/**
	 * Give import requests as much headroom as the host allows.
	 */
	private function raise_limits() {
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Sanitize a base table name (prefix-less, [A-Za-z0-9_] only).
	 *
	 * @param string $base Raw base name.
	 * @return string
	 */
	private function sanitize_base( $base ) {
		$base = preg_replace( '/[^A-Za-z0-9_]/', '', $base );
		return substr( $base, 0, 64 );
	}
}
