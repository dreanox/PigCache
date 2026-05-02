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
		add_action( 'redis_object_cache_flush', array( __CLASS__, 'bump_epoch' ), 99 );
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

		if ( false === $new || 0 === $new ) {
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

		if ( false === $new || 0 === $new ) {
			wp_cache_set( $key, 2, self::GROUP_META, self::long_ttl() );
		}

		// Record mutation for Adaptive TTL v2 stability tracking.
		if ( class_exists( 'PigCache_Mutation_Tracker', false ) ) {
			PigCache_Mutation_Tracker::record_mutation( $table );
		}
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
