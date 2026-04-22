<?php
/**
 * Cloud sync orchestrator — manages the lifecycle of syncing local
 * SQL fingerprints to the PigCache Cloud API and downloading
 * pre-compiled profiles.
 *
 * Uses WP-Cron for periodic background work.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Cloud_Sync {

	const CRON_HOOK       = 'pigcache_cloud_sync';
	const CRON_SCHEDULE   = 'twicedaily';
	const BATCH_SIZE      = 500;
	const OPTION_LAST     = 'pigcache_cloud_last_sync';
	const OPTION_PROFILE  = 'pigcache_cloud_profile_source';

	/**
	 * Register cron hooks (called during plugin init).
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule the twice-daily sync event if not already scheduled.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Remove the cron event.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Whether cloud sync is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( defined( 'PIGCACHE_CLOUD_SYNC' ) && ! PIGCACHE_CLOUD_SYNC ) {
			return false;
		}

		return PigCache_License::is_pro();
	}

	/**
	 * Main cron callback — runs all sync steps.
	 */
	public static function run() {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::sync_environment();
		self::sync_fingerprints();
		self::check_profile();

		update_option( self::OPTION_LAST, time(), false );
	}

	/**
	 * Send the site environment to the backend for profile matching.
	 *
	 * @return array|WP_Error
	 */
	public static function sync_environment() {
		$site_id = PigCache_License::get_site_id();
		if ( ! $site_id ) {
			return new WP_Error( 'pigcache_no_site', 'No site ID registered.' );
		}

		$details = PigCache_Environment::get_details();

		return PigCache_Cloud_Client::post( "sites/{$site_id}/environment", $details );
	}

	/**
	 * Upload unsynced fingerprints in batches.
	 *
	 * @return int Number of fingerprints sent.
	 */
	public static function sync_fingerprints() {
		if ( ! class_exists( 'PigCache_Sql_Profile_Store', false ) ) {
			return 0;
		}

		$site_id = PigCache_License::get_site_id();
		if ( ! $site_id ) {
			return 0;
		}

		$total = 0;

		do {
			$rows = PigCache_Sql_Profile_Store::get_unsynced( self::BATCH_SIZE );

			if ( empty( $rows ) ) {
				break;
			}

			$payload = array();
			$ids     = array();

			foreach ( $rows as $row ) {
				$payload[] = array(
					'fingerprint' => $row->fingerprint,
					'template'    => $row->template,
					'tables'      => json_decode( $row->tables_json, true ),
					'hit_count'   => (int) $row->hit_count,
					'avg_rows'    => round( (float) $row->avg_rows, 2 ),
				);
				$ids[] = $row->fingerprint;
			}

			$response = PigCache_Cloud_Client::post( "sites/{$site_id}/fingerprints", array(
				'fingerprints' => $payload,
			) );

			if ( is_wp_error( $response ) ) {
				break;
			}

			PigCache_Sql_Profile_Store::mark_synced( $ids );
			$total += count( $ids );

		} while ( count( $rows ) >= self::BATCH_SIZE );

		return $total;
	}

	/**
	 * Ask the backend for a compiled profile and write it locally.
	 *
	 * @return bool True if a new profile was downloaded and written.
	 */
	public static function check_profile() {
		$site_id = PigCache_License::get_site_id();
		if ( ! $site_id ) {
			return false;
		}

		$response = PigCache_Cloud_Client::get( "sites/{$site_id}/profile" );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return false;
		}

		$data = $response['data'];

		if ( ! isset( $data['map'] ) || empty( $data['map'] ) ) {
			return false;
		}

		$written = PigCache_Sql_Profiler::write_cloud_profile( $data );

		if ( $written ) {
			update_option( self::OPTION_PROFILE, 'cloud', false );
		}

		return $written;
	}

	/**
	 * Force an immediate sync (e.g. from admin button).
	 *
	 * @return array{environment: mixed, fingerprints: int, profile: bool}
	 */
	public static function force_sync() {
		$env = self::sync_environment();
		$fp  = self::sync_fingerprints();
		$pro = self::check_profile();

		update_option( self::OPTION_LAST, time(), false );

		return array(
			'environment'  => $env,
			'fingerprints' => $fp,
			'profile'      => $pro,
		);
	}

	/**
	 * Get the last sync timestamp.
	 *
	 * @return int Unix timestamp or 0.
	 */
	public static function last_sync() {
		wp_cache_delete( self::OPTION_LAST, 'options' );
		return (int) get_option( self::OPTION_LAST, 0 );
	}

	/**
	 * Whether the current profile was downloaded from the cloud.
	 *
	 * @return bool
	 */
	public static function is_cloud_profile() {
		return 'cloud' === get_option( self::OPTION_PROFILE, '' );
	}

	/**
	 * Mark the profile source as local (used when compiling locally).
	 */
	public static function mark_local_profile() {
		update_option( self::OPTION_PROFILE, 'local', false );
	}
}
