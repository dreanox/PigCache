<?php
/**
 * MySQL persistence layer for the SQL profiler learning phase.
 *
 * Stores query fingerprints with their templates, table lists, and hit stats.
 * Separate from the profiler logic to keep responsibilities clean.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Sql_Profile_Store {

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'pigcache_sql_fingerprints';
	}

	/**
	 * Create or update the table via dbDelta.
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			fingerprint VARCHAR(32)   NOT NULL,
			template    TEXT          NOT NULL,
			tables_json VARCHAR(512)  NOT NULL,
			hit_count   INT UNSIGNED  NOT NULL DEFAULT 1,
			avg_rows    FLOAT         NOT NULL DEFAULT 0,
			first_seen  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			synced_at   DATETIME      DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_fingerprint (fingerprint)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert or update a fingerprint record.
	 *
	 * @param string   $fingerprint md5 hash.
	 * @param string   $template    Normalized query.
	 * @param string[] $tables      Table names.
	 * @param int      $num_rows    Rows returned by this execution.
	 */
	public static function upsert( $fingerprint, $template, array $tables, $num_rows = 0 ) {
		global $wpdb;

		$table       = self::table_name();
		$tables_json = wp_json_encode( array_values( $tables ) );
		$now         = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (fingerprint, template, tables_json, hit_count, avg_rows, first_seen, last_seen)
			 VALUES (%s, %s, %s, 1, %f, %s, %s)
			 ON DUPLICATE KEY UPDATE
			   hit_count = hit_count + 1,
			   avg_rows  = ( avg_rows * ( hit_count - 1 ) + %f ) / hit_count,
			   last_seen = %s",
			$fingerprint,
			$template,
			$tables_json,
			(float) $num_rows,
			$now,
			$now,
			(float) $num_rows,
			$now
		) );
	}

	/**
	 * @return object[] All fingerprint rows.
	 */
	public static function get_all() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY hit_count DESC" );
	}

	/**
	 * @return int Number of recorded fingerprints.
	 */
	public static function count() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * @return int Total hit count across all fingerprints.
	 */
	public static function total_hits() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(hit_count), 0) FROM {$table}" );
	}

	/**
	 * Get fingerprints that have not been synced to the cloud.
	 *
	 * @param int $limit Max rows to return.
	 * @return object[]
	 */
	public static function get_unsynced( $limit = 500 ) {
		global $wpdb;

		$table = self::table_name();
		$limit = (int) $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE synced_at IS NULL ORDER BY hit_count DESC LIMIT %d",
			$limit
		) );
	}

	/**
	 * Mark fingerprints as synced.
	 *
	 * @param string[] $fingerprints Array of fingerprint hashes.
	 */
	public static function mark_synced( array $fingerprints ) {
		global $wpdb;

		if ( empty( $fingerprints ) ) {
			return;
		}

		$table        = self::table_name();
		$now          = current_time( 'mysql', true );
		$placeholders = implode( ',', array_fill( 0, count( $fingerprints ), '%s' ) );
		$values       = array_merge( array( $now ), $fingerprints );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET synced_at = %s WHERE fingerprint IN ({$placeholders})",
			$values
		) );
	}

	/**
	 * Count unsynced fingerprints.
	 *
	 * @return int
	 */
	public static function count_unsynced() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE synced_at IS NULL" );
	}

	/**
	 * Truncate the fingerprints table (for re-learning).
	 */
	public static function truncate() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Drop the table (for uninstall).
	 */
	public static function drop_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS " . self::table_name() );
	}
}
