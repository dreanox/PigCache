<?php
/**
 * HTTP client for the PigCache Cloud API.
 *
 * Wraps wp_remote_* with authentication, retries, and graceful fallback.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Cloud_Client {

	const DEFAULT_API_URL = 'https://api.pigcache.com/v1';
	const TIMEOUT         = 10;
	const MAX_RETRIES     = 1;

	/**
	 * Base URL for the API (no trailing slash).
	 *
	 * @return string
	 */
	public static function base_url() {
		if ( defined( 'PIGCACHE_CLOUD_API_URL' ) && PIGCACHE_CLOUD_API_URL ) {
			return rtrim( (string) PIGCACHE_CLOUD_API_URL, '/' );
		}

		return self::DEFAULT_API_URL;
	}

	/**
	 * Perform a GET request.
	 *
	 * @param string $endpoint  Relative path (e.g. "license/status").
	 * @param array  $query     Optional query params.
	 * @return array|WP_Error   Decoded JSON body or error.
	 */
	public static function get( $endpoint, array $query = array() ) {
		$url = self::build_url( $endpoint, $query );

		return self::request( 'GET', $url );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string $endpoint  Relative path.
	 * @param array  $body      JSON body data.
	 * @return array|WP_Error   Decoded JSON body or error.
	 */
	public static function post( $endpoint, array $body = array() ) {
		$url = self::build_url( $endpoint );

		return self::request( 'POST', $url, $body );
	}

	/**
	 * @param string $endpoint
	 * @param array  $query
	 * @return string
	 */
	private static function build_url( $endpoint, array $query = array() ) {
		$url = self::base_url() . '/' . ltrim( $endpoint, '/' );

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		return $url;
	}

	/**
	 * @return array Headers for every request.
	 */
	private static function headers() {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'User-Agent'   => 'PigCache/' . PIGCACHE_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
		);

		$key = PigCache_License::get_key();
		if ( $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$site_id = PigCache_License::get_site_id();
		if ( $site_id ) {
			$headers['X-Site-Id'] = $site_id;
		}

		return $headers;
	}

	/**
	 * Execute an HTTP request with optional retry on 5xx.
	 *
	 * @param string     $method  "GET" or "POST".
	 * @param string     $url     Full URL.
	 * @param array|null $body    JSON-encodable body for POST.
	 * @return array|WP_Error
	 */
	private static function request( $method, $url, $body = null ) {
		$args = array(
			'method'  => $method,
			'headers' => self::headers(),
			'timeout' => self::TIMEOUT,
		);

		if ( null !== $body && 'POST' === $method ) {
			$args['body'] = wp_json_encode( $body );
		}

		$attempt  = 0;
		$response = null;

		while ( $attempt <= self::MAX_RETRIES ) {
			$response = wp_remote_request( $url, $args );
			$attempt++;

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code < 500 ) {
				break;
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$message = isset( $data['message'] ) ? $data['message'] : "HTTP {$code}";

			return new WP_Error( 'pigcache_api_' . $code, $message, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Quick connectivity check — true if the API responds at all.
	 *
	 * @return bool
	 */
	public static function is_reachable() {
		$response = wp_remote_head( self::base_url() . '/ping', array(
			'timeout' => 5,
		) );

		return ! is_wp_error( $response );
	}
}
