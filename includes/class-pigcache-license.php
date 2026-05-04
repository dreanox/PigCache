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

	/**
	 * Whether this install includes the Pro-only modules (cloud client, profiler, etc.).
	 *
	 * The WordPress.org "Free" ZIP omits these files; trial and license API apply only
	 * to the full Pro package — activating a key does not download code, you must install
	 * the Pro ZIP from your account.
	 *
	 * @return bool
	 */
	public static function has_pro_distribution() {
		return class_exists( 'PigCache_Cloud_Client', false );
	}

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
		if ( ! self::has_pro_distribution() ) {
			return new WP_Error(
				'pigcache_community_build',
				__( 'License activation requires the full PigCache Pro plugin package. The WordPress.org build does not include license or cloud code — install the Pro ZIP from your purchase; your site does not download Pro features automatically.', 'pigcache' )
			);
		}

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

			$status_payload = array_merge(
				array(
					'valid'             => true,
					'plan'              => isset( $response['plan'] ) ? (string) $response['plan'] : 'pro',
					'updates_allowed'   => array_key_exists( 'updates_allowed', $response ) ? (bool) $response['updates_allowed'] : true,
					'trial_ends_at'     => isset( $response['trial_ends_at'] ) ? (string) $response['trial_ends_at'] : '',
					'trial_expired'     => false,
					'sites_used'        => isset( $response['sites_used'] ) ? (int) $response['sites_used'] : 0,
					'sites_max'         => isset( $response['sites_max'] ) ? (int) $response['sites_max'] : 1,
					'expires_at'        => isset( $response['expires_at'] ) ? (string) $response['expires_at'] : '',
					'features'          => isset( $response['features'] ) && is_array( $response['features'] ) ? $response['features'] : array(),
				),
				$response
			);

			self::cache_status( $status_payload );
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

		if ( $site_id && self::has_pro_distribution() ) {
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

		if ( empty( $status['valid'] ) ) {
			return false;
		}

		$plan = isset( $status['plan'] ) ? (string) $status['plan'] : 'pro';

		return '' !== $plan && 'free' !== $plan;
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
			return array(
				'valid'             => false,
				'plan'              => 'free',
				'updates_allowed'   => true,
				'trial_expired'     => false,
				'trial_ends_at'     => '',
				'sites_used'        => 0,
				'sites_max'         => 0,
				'features'          => array(),
			);
		}

		if ( ! self::has_pro_distribution() ) {
			return array(
				'valid'             => false,
				'plan'              => 'free',
				'updates_allowed'   => true,
				'trial_expired'     => false,
				'error'             => __( 'License status checks are not available in the WordPress.org community build.', 'pigcache' ),
			);
		}

		$response = PigCache_Cloud_Client::get( 'license/status' );

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'             => false,
				'plan'              => 'free',
				'updates_allowed'   => true,
				'trial_expired'     => false,
				'error'             => $response->get_error_message(),
			);
		}

		if ( ! isset( $response['updates_allowed'] ) ) {
			$response['updates_allowed'] = ! empty( $response['valid'] );
		}

		self::cache_status( $response );

		return $response;
	}

	/**
	 * Whether this install should receive PigCache Pro package updates (from your update server / transient).
	 *
	 * @return bool
	 */
	public static function updates_allowed() {
		if ( ! self::has_pro_distribution() || ! self::has_key() ) {
			return true;
		}

		$status = self::get_status();

		if ( array_key_exists( 'updates_allowed', $status ) ) {
			return (bool) $status['updates_allowed'];
		}

		return ! empty( $status['valid'] );
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
	// Trial — server-managed (see API license status / trial_ends_at).
	// ------------------------------------------------------------------

	/**
	 * Legacy hook: trial window is defined by the PigCache API, not local options.
	 */
	public static function maybe_start_trial() {
	}

	/**
	 * @return int Always 0; trial start is tracked on the license server.
	 */
	public static function trial_started() {
		return 0;
	}

	/**
	 * Active API trial (plan "trial" with valid entitlement).
	 *
	 * @return bool
	 */
	public static function is_trial() {
		return self::has_pro_distribution()
			&& self::has_key()
			&& self::is_pro()
			&& 'trial' === self::get_plan();
	}

	/**
	 * Trial window ended according to the last license/status response.
	 *
	 * @return bool
	 */
	public static function is_trial_expired() {
		if ( ! self::has_pro_distribution() || ! self::has_key() ) {
			return false;
		}

		$status = self::get_status();

		return ! empty( $status['trial_expired'] );
	}

	/**
	 * Days remaining on an API trial (from trial_ends_at), or 0.
	 *
	 * @return int
	 */
	public static function trial_days_remaining() {
		if ( ! self::has_key() ) {
			return 0;
		}

		$status = self::get_status();

		if ( empty( $status['trial_ends_at'] ) ) {
			return 0;
		}

		$end = strtotime( (string) $status['trial_ends_at'] );
		if ( ! $end ) {
			return 0;
		}

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
		return class_exists( 'PigCache_Sql_Profiler', false );
	}

	/**
	 * Whether tag-based selective invalidation is available.
	 *
	 * Returns true only in the Pro build, where PigCache_Tag_Index is present.
	 * The Free build omits that class entirely and falls back to global flush.
	 *
	 * @return bool
	 */
	public static function can_use_tag_invalidation() {
		return class_exists( 'PigCache_Tag_Index', false );
	}

	/**
	 * Human-readable label for the current profiler access level.
	 *
	 * @return string "community", "pro", "trial", or "expired"
	 */
	public static function profiler_access_label() {
		if ( ! self::has_pro_distribution() ) {
			return 'community';
		}

		$status = self::get_status();

		if ( ! empty( $status['trial_expired'] ) ) {
			return 'expired';
		}

		if ( self::is_pro() ) {
			return 'trial' === self::get_plan() ? 'trial' : 'pro';
		}

		// Key set but plan is free — never had a trial, or it was never granted.
		return 'free';
	}
}
