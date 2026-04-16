<?php
/**
 * License management for PigCache Pro.
 *
 * Handles API key storage, activation, deactivation, and plan status
 * with transient-cached remote checks so the backend is hit at most
 * once per day.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_License {

	const OPTION_KEY     = 'pigcache_license_key';
	const OPTION_SITE_ID = 'pigcache_cloud_site_id';
	const OPTION_TRIAL   = 'pigcache_trial_started';
	const TRANSIENT      = 'pigcache_license_status';
	const CACHE_TTL      = DAY_IN_SECONDS;
	const TRIAL_DAYS     = 14;

	/**
	 * Get the stored API key (wp-config constant takes precedence).
	 *
	 * @return string
	 */
	public static function get_key() {
		if ( defined( 'PIGCACHE_LICENSE_KEY' ) && PIGCACHE_LICENSE_KEY ) {
			return (string) PIGCACHE_LICENSE_KEY;
		}

		return (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * @return bool
	 */
	public static function has_key() {
		return '' !== self::get_key();
	}

	/**
	 * Store the API key in wp_options.
	 *
	 * @param string $key
	 */
	public static function set_key( $key ) {
		update_option( self::OPTION_KEY, sanitize_text_field( $key ), false );
		delete_transient( self::TRANSIENT );
	}

	/**
	 * @return string Site ID assigned by the backend, or empty string.
	 */
	public static function get_site_id() {
		return (string) get_option( self::OPTION_SITE_ID, '' );
	}

	/**
	 * @param string $id
	 */
	public static function set_site_id( $id ) {
		update_option( self::OPTION_SITE_ID, sanitize_text_field( $id ), false );
	}

	/**
	 * Activate the license against the remote API.
	 *
	 * @param string $api_key
	 * @return array|WP_Error Decoded response body or error.
	 */
	public static function activate( $api_key = '' ) {
		if ( ! $api_key ) {
			$api_key = self::get_key();
		}

		if ( ! $api_key ) {
			return new WP_Error( 'pigcache_no_key', 'No API key provided.' );
		}

		$response = PigCache_Cloud_Client::post( 'license/activate', array(
			'api_key'   => $api_key,
			'site_url'  => home_url(),
			'site_name' => get_bloginfo( 'name' ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['valid'] ) ) {
			self::set_key( $api_key );

			if ( ! empty( $response['site_id'] ) ) {
				self::set_site_id( $response['site_id'] );
			}

			self::cache_status( $response );
		}

		return $response;
	}

	/**
	 * Deactivate the current license seat.
	 *
	 * @return array|WP_Error
	 */
	public static function deactivate() {
		$site_id = self::get_site_id();

		if ( $site_id ) {
			$response = PigCache_Cloud_Client::post( 'license/deactivate', array(
				'site_id' => $site_id,
			) );
		} else {
			$response = array( 'deactivated' => true );
		}

		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_SITE_ID );
		delete_transient( self::TRANSIENT );

		return is_wp_error( $response ) ? $response : $response;
	}

	/**
	 * Whether the current site has an active Pro plan.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		if ( ! self::has_key() ) {
			return false;
		}

		$status = self::get_status();

		return ! empty( $status['valid'] ) && ! empty( $status['plan'] ) && 'free' !== $status['plan'];
	}

	/**
	 * Get the current plan label (e.g. "pro", "agency", "free").
	 *
	 * @return string
	 */
	public static function get_plan() {
		$status = self::get_status();

		return ! empty( $status['plan'] ) ? $status['plan'] : 'free';
	}

	/**
	 * Get the cached license status, refreshing from the API if stale.
	 *
	 * @return array
	 */
	public static function get_status() {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! self::has_key() ) {
			return array( 'valid' => false, 'plan' => 'free' );
		}

		$response = PigCache_Cloud_Client::get( 'license/status' );

		if ( is_wp_error( $response ) ) {
			return array( 'valid' => false, 'plan' => 'free', 'error' => $response->get_error_message() );
		}

		self::cache_status( $response );

		return $response;
	}

	/**
	 * Store the status in a transient so we don't hit the API on every load.
	 *
	 * @param array $status
	 */
	private static function cache_status( array $status ) {
		set_transient( self::TRANSIENT, $status, self::CACHE_TTL );
	}

	/**
	 * Force a fresh status check next time.
	 */
	public static function flush_cache() {
		delete_transient( self::TRANSIENT );
	}

	// ------------------------------------------------------------------
	// Trial management — SQL Profiler is a premium feature
	// ------------------------------------------------------------------

	/**
	 * Start the trial period (called on first plugin activation).
	 */
	public static function maybe_start_trial() {
		if ( false === get_option( self::OPTION_TRIAL ) ) {
			update_option( self::OPTION_TRIAL, time(), false );
		}
	}

	/**
	 * Unix timestamp when the trial started, or 0 if never.
	 *
	 * @return int
	 */
	public static function trial_started() {
		return (int) get_option( self::OPTION_TRIAL, 0 );
	}

	/**
	 * Whether the trial is currently active (not expired, not Pro).
	 *
	 * @return bool
	 */
	public static function is_trial() {
		if ( self::is_pro() ) {
			return false;
		}

		$start = self::trial_started();
		if ( $start < 1 ) {
			return false;
		}

		return ( time() - $start ) < ( self::TRIAL_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Whether the trial has expired (started but past the window).
	 *
	 * @return bool
	 */
	public static function is_trial_expired() {
		if ( self::is_pro() ) {
			return false;
		}

		$start = self::trial_started();
		if ( $start < 1 ) {
			return false;
		}

		return ( time() - $start ) >= ( self::TRIAL_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Days remaining in the trial, or 0.
	 *
	 * @return int
	 */
	public static function trial_days_remaining() {
		$start = self::trial_started();
		if ( $start < 1 ) {
			return 0;
		}

		$end  = $start + ( self::TRIAL_DAYS * DAY_IN_SECONDS );
		$left = $end - time();

		return $left > 0 ? (int) ceil( $left / DAY_IN_SECONDS ) : 0;
	}

	/**
	 * Whether the SQL Profiler can be used.
	 *
	 * The profiler is available when:
	 * - The site has an active Pro license, OR
	 * - The trial period is still active.
	 *
	 * Free tier after trial expiration = global epoch only.
	 *
	 * @return bool
	 */
	public static function can_use_profiler() {
		if ( self::is_pro() ) {
			return true;
		}

		return self::is_trial();
	}

	/**
	 * Human-readable label for the current profiler access level.
	 *
	 * @return string "pro", "trial", or "expired"
	 */
	public static function profiler_access_label() {
		if ( self::is_pro() ) {
			return 'pro';
		}

		if ( self::is_trial() ) {
			return 'trial';
		}

		return 'expired';
	}
}
