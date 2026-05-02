<?php
/**
 * Mutation frequency tracker for Adaptive TTL v2.
 *
 * Accumulates per-table mutation counts during each request (via
 * PigCache_Sql_Cache::bump_table_epoch), flushes to wp_options on
 * shutdown, and lets the cron harvest that data into MySQL so the
 * Adaptive TTL decision engine can read a stable stability map.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Mutation_Tracker {

	const OPT_MUT_WINDOW  = 'pigcache_mut_window';
	const TRANSIENT_MAP   = 'pigcache_stability_map';
	const TRANSIENT_TTL   = 25 * MINUTE_IN_SECONDS;

	/** @var array<string,int> Mutations accumulated during this request. */
	private static $pending = array();

	/** @var bool Whether the shutdown hook has been registered. */
	private static $shutdown_registered = false;

	// ── Recording ─────────────────────────────────────────────────────────────

	/**
	 * Record one mutation for a table. Called from bump_table_epoch().
	 *
	 * @param string $table Fully-qualified table name (e.g. "wp_posts").
	 */
	public static function record_mutation( string $table ): void {
		if ( isset( self::$pending[ $table ] ) ) {
			self::$pending[ $table ]++;
		} else {
			self::$pending[ $table ] = 1;
		}

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'flush_pending' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Merge pending counts into the wp_options accumulator. Called on shutdown.
	 */
	public static function flush_pending(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		$raw     = get_option( self::OPT_MUT_WINDOW, '' );
		$current = ( $raw && is_string( $raw ) ) ? json_decode( $raw, true ) : array();
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		foreach ( self::$pending as $table => $count ) {
			$current[ $table ] = ( isset( $current[ $table ] ) ? (int) $current[ $table ] : 0 ) + $count;
		}

		update_option( self::OPT_MUT_WINDOW, wp_json_encode( $current ), false );

		self::$pending = array();
	}

	// ── Harvest (called by cron) ──────────────────────────────────────────────

	/**
	 * Read the mutation window option, persist to MySQL, rebuild stability map.
	 * Called every 15 min by the WP-Cron fallback or the standalone cron.
	 */
	public static function harvest(): void {
		global $wpdb;

		$raw     = get_option( self::OPT_MUT_WINDOW, '' );
		$current = ( $raw && is_string( $raw ) ) ? json_decode( $raw, true ) : array();

		if ( is_array( $current ) && ! empty( $current ) ) {
			$db_table     = $wpdb->prefix . 'pigcache_table_stability';
			$window_start = gmdate( 'Y-m-d H:i:s', (int) floor( time() / 900 ) * 900 );

			foreach ( $current as $table_name => $count ) {
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO `{$db_table}` (table_name, window_start, mutation_count)
					 VALUES (%s, %s, %d)
					 ON DUPLICATE KEY UPDATE mutation_count = mutation_count + VALUES(mutation_count)",
					(string) $table_name,
					$window_start,
					(int) $count
				) );
			}

			update_option( self::OPT_MUT_WINDOW, '{}', false );

			// Prune data older than 30 days.
			$wpdb->query( "DELETE FROM `{$db_table}` WHERE window_start < DATE_SUB(NOW(), INTERVAL 30 DAY)" ); // phpcs:ignore
		}

		self::rebuild_transient();
	}

	// ── Stability map ─────────────────────────────────────────────────────────

	/**
	 * Build stability map from MySQL and cache as transient.
	 * Map: table_name → mutations in last 24 h.
	 */
	private static function rebuild_transient(): void {
		global $wpdb;

		$db_table = $wpdb->prefix . 'pigcache_table_stability';

		$rows = $wpdb->get_results( // phpcs:ignore
			"SELECT table_name, SUM(mutation_count) AS total
			   FROM `{$db_table}`
			  WHERE window_start >= DATE_SUB(NOW(), INTERVAL 1 DAY)
			  GROUP BY table_name",
			ARRAY_A
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ $row['table_name'] ] = (int) $row['total'];
			}
		}

		set_transient( self::TRANSIENT_MAP, $map, self::TRANSIENT_TTL );
	}

	/**
	 * Get the stability map (table → mutations in last 24 h).
	 *
	 * @return array<string,int>
	 */
	public static function get_stability_map(): array {
		$map = get_transient( self::TRANSIENT_MAP );
		return is_array( $map ) ? $map : array();
	}
}
