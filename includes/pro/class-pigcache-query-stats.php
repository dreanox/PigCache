<?php
/**
 * MySQL persistence layer for per-period query analytics.
 *
 * Table: {prefix}pigcache_query_stats
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Query_Stats {

	const KEEP_DAYS = 30;

	// ── Schema ───────────────────────────────────────────────────────────────

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'pigcache_query_stats';
	}

	public static function create_table() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			query_hash    VARCHAR(32)     NOT NULL,
			normalized    TEXT            NOT NULL,
			-- No DEFAULT here: MySQL rejects defaults on TEXT columns outright, and
			-- dbDelta reports the failure without aborting, so the whole table
			-- silently never got created. Callers always write this column.
			tables        TEXT            NOT NULL,
			hit_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			total_exec_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
			max_exec_ms   INT UNSIGNED    NOT NULL DEFAULT 0,
			total_mem_kb  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			period_start  DATETIME        NOT NULL,
			sent_at       DATETIME                 DEFAULT NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uq_hash_period (query_hash, period_start),
			KEY idx_period  (period_start),
			KEY idx_sent    (sent_at),
			KEY idx_exec_ms (total_exec_ms)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// ── Write ────────────────────────────────────────────────────────────────

	/**
	 * Upsert a batch of query data for the given period window.
	 *
	 * @param array  $rows         hash => {normalized, tables, count, total_ms, max_ms, total_mem}
	 * @param string $period_start MySQL DATETIME string for the period start.
	 */
	public static function upsert_batch( array $rows, $period_start ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return;
		}

		$table = self::table_name();

		foreach ( array_chunk( $rows, 50, true ) as $chunk ) {
			$values      = array();
			$placeholders = array();

			foreach ( $chunk as $hash => $data ) {
				$placeholders[] = '(%s, %s, %s, %d, %d, %d, %d, %s)';
				$values[]       = $hash;
				$values[]       = $data['normalized'];
				$values[]       = wp_json_encode( $data['tables'] ?? array() );
				$values[]       = (int) ( $data['count'] ?? 0 );
				$values[]       = (int) ( $data['total_ms'] ?? 0 );
				$values[]       = (int) ( $data['max_ms'] ?? 0 );
				$values[]       = (int) ( $data['total_mem'] ?? 0 );
				$values[]       = $period_start;
			}

			$sql = $wpdb->prepare(
				"INSERT INTO {$table}
					(query_hash, normalized, tables, hit_count, total_exec_ms, max_exec_ms, total_mem_kb, period_start)
				VALUES " . implode( ', ', $placeholders ) . "
				ON DUPLICATE KEY UPDATE
					hit_count     = hit_count     + VALUES(hit_count),
					total_exec_ms = total_exec_ms + VALUES(total_exec_ms),
					max_exec_ms   = GREATEST(max_exec_ms, VALUES(max_exec_ms)),
					total_mem_kb  = total_mem_kb  + VALUES(total_mem_kb)",
				$values
			);

			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	// ── Read ─────────────────────────────────────────────────────────────────

	/**
	 * @param int $limit
	 * @return array
	 */
	public static function get_top_slow( $limit = 10 ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT query_hash, normalized, tables,
				SUM(hit_count) as total_hits,
				SUM(total_exec_ms) as sum_ms,
				MAX(max_exec_ms) as peak_ms,
				ROUND(SUM(total_exec_ms) / NULLIF(SUM(hit_count), 0), 2) as avg_ms
			FROM {$table}
			GROUP BY query_hash, normalized, tables
			ORDER BY avg_ms DESC
			LIMIT %d",
			(int) $limit
		), ARRAY_A );
	}

	/**
	 * @param int $limit
	 * @return array
	 */
	public static function get_top_frequent( $limit = 10 ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT query_hash, normalized, tables,
				SUM(hit_count) as total_hits,
				ROUND(SUM(total_exec_ms) / NULLIF(SUM(hit_count), 0), 2) as avg_ms,
				MAX(max_exec_ms) as peak_ms
			FROM {$table}
			GROUP BY query_hash, normalized, tables
			ORDER BY total_hits DESC
			LIMIT %d",
			(int) $limit
		), ARRAY_A );
	}

	/**
	 * @param int $limit
	 * @return array
	 */
	public static function get_unsent( $limit = 200 ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE sent_at IS NULL ORDER BY period_start ASC LIMIT %d",
			(int) $limit
		), ARRAY_A );
	}

	/**
	 * @param int[] $ids
	 */
	public static function mark_sent( array $ids ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return;
		}

		$table       = self::table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET sent_at = NOW() WHERE id IN ({$placeholders})",
			$ids
		) );
	}

	/**
	 * Delete rows older than KEEP_DAYS that have been sent.
	 */
	public static function prune() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE sent_at IS NOT NULL AND period_start < DATE_SUB(NOW(), INTERVAL %d DAY)",
			self::KEEP_DAYS
		) );
	}
}
