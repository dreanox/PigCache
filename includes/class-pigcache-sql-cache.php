<?php
/**
 * SQL result cache coordination — global epoch and per-table epochs.
 *
 * Storage is handled in PigCache_WPDB. This class manages:
 *  - The global epoch (fallback when no compiled profile exists).
 *  - Per-table epochs (when a profile maps queries to their tables).
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Sql_Cache {

	const GROUP_META = 'pigcache';
	const EPOCH_KEY  = 'pigcache_sql_epoch';

	const DEFAULT_TTL = 120;

	/** @var int Long TTL for epoch keys (~10 years). */
	private static $long_ttl = 0;

	public static function init() {
		add_action( 'pigcache_object_cache_flush', array( __CLASS__, 'bump_epoch' ), 99 );
	}

	// ------------------------------------------------------------------
	// Global epoch (fallback / legacy)
	// ------------------------------------------------------------------

	/**
	 * Atomic global epoch bump via INCR.
	 */
	public static function bump_epoch() {
		if ( ! function_exists( 'wp_cache_incr' ) ) {
			return;
		}

		$new = wp_cache_incr( self::EPOCH_KEY, 1, self::GROUP_META );

		// get_epoch() reports 1 for an epoch that was never set, and incrementing
		// a missing key also lands on 1 — so the first bump after a flush would
		// leave the epoch exactly where readers already believed it was, and
		// everything cached beforehand would still look fresh. Skip to 2.
		if ( false === $new || $new <= 1 ) {
			wp_cache_set( self::EPOCH_KEY, 2, self::GROUP_META, self::long_ttl() );
		}
	}

	/**
	 * @return int
	 */
	public static function get_epoch() {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return 1;
		}

		$epoch = (int) wp_cache_get( self::EPOCH_KEY, self::GROUP_META );

		return $epoch > 0 ? $epoch : 1;
	}

	// ------------------------------------------------------------------
	// Per-table epochs
	// ------------------------------------------------------------------

	/**
	 * Bump the epoch for a specific table.
	 *
	 * @param string $table e.g. "wp_posts".
	 */
	public static function bump_table_epoch( $table ) {
		if ( ! function_exists( 'wp_cache_incr' ) ) {
			return;
		}

		$key = self::table_epoch_key( $table );
		$new = wp_cache_incr( $key, 1, self::GROUP_META );

		// get_table_epoch() reports 1 for a table that has no epoch stored yet, so
		// landing on 1 here would be indistinguishable from never having been
		// bumped — the first write to a table would not invalidate anything cached
		// before it. Skip straight to 2 whenever the increment did not produce a
		// value above that floor.
		if ( false === $new || $new <= 1 ) {
			wp_cache_set( $key, 2, self::GROUP_META, self::long_ttl() );
		}

		// ── PRO_START ─────────────────────────────────────────────────────────────────
		// Record mutation for Adaptive TTL v2 stability tracking.
		if ( class_exists( 'PigCache_Mutation_Tracker', false ) ) {
			PigCache_Mutation_Tracker::record_mutation( $table );
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────
	}

	/**
	 * Get the current epoch for a specific table.
	 *
	 * @param string $table
	 * @return int
	 */
	public static function get_table_epoch( $table ) {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return 1;
		}

		$epoch = (int) wp_cache_get( self::table_epoch_key( $table ), self::GROUP_META );

		return $epoch > 0 ? $epoch : 1;
	}

	/**
	 * Batch-get epochs for multiple tables.
	 *
	 * @param string[] $tables
	 * @return array<string, int> table => epoch
	 */
	public static function get_table_epochs( array $tables ) {
		$epochs = array();

		foreach ( $tables as $table ) {
			$epochs[ $table ] = self::get_table_epoch( $table );
		}

		return $epochs;
	}

	/**
	 * @param string $table
	 * @return string Redis key for this table's epoch.
	 */
	private static function table_epoch_key( $table ) {
		return 'pigcache_sql_epoch:' . $table;
	}

	/**
	 * Extract the table a mutating statement writes to, so only that table's
	 * epoch is bumped instead of the global one.
	 *
	 * @param string $query Raw SQL.
	 * @return string Lowercased table name, or '' when it cannot be determined.
	 */
	public static function extract_mutation_table( $query ) {
		$query = ltrim( (string) $query );

		// INTO is optional for both INSERT and REPLACE in MySQL, and the priority
		// and IGNORE modifiers can appear together.
		$patterns = array(
			'/^\s*INSERT\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)?(?:IGNORE\s+)?(?:INTO\s+)?`?(\w+)`?/i',
			'/^\s*REPLACE\s+(?:LOW_PRIORITY\s+|DELAYED\s+)?(?:INTO\s+)?`?(\w+)`?/i',
			'/^\s*UPDATE\s+(?:LOW_PRIORITY\s+)?(?:IGNORE\s+)?`?(\w+)`?/i',
			'/^\s*DELETE\s+.*?\bFROM\s+`?(\w+)`?/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $query, $m ) ) {
				return strtolower( $m[1] );
			}
		}

		return '';
	}

	// ------------------------------------------------------------------
	// TTL
	// ------------------------------------------------------------------

	/**
	 * @return int
	 */
	public static function ttl() {
		$base = class_exists( 'PigCache_Config', false )
			? PigCache_Config::get_sql_cache_ttl()
			: self::DEFAULT_TTL;

		$ttl = (int) apply_filters( 'pigcache_sql_cache_ttl', $base );

		return $ttl > 0 ? $ttl : $base;
	}

	/**
	 * @return int
	 */
	private static function long_ttl() {
		if ( self::$long_ttl < 1 ) {
			self::$long_ttl = defined( 'YEAR_IN_SECONDS' ) ? (int) YEAR_IN_SECONDS * 10 : 315360000;
		}

		return self::$long_ttl;
	}
}
