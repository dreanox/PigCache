<?php
/**
 * MySQL-backed tag index for selective cache invalidation.
 *
 * Maps (cache_key, group) <-> tag so that when an object changes, only the
 * specific Redis keys that reference it are purged.
 *
 * The table is written ONLY on cache MISS (page regeneration). On cache HIT
 * MySQL is never touched — zero overhead on the hot path.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Tag_Index {

	/**
	 * @return string Full table name with prefix.
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'pigcache_tags';
	}

	/**
	 * Create or update the table via dbDelta (safe for activation).
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// `created` has no DEFAULT: MySQL only allowed DEFAULT CURRENT_TIMESTAMP on
		// DATETIME columns starting in 5.6.5 (older versions only allowed it on
		// TIMESTAMP). WordPress supports older MySQL than that, and dbDelta()
		// reports the failure without aborting, so the table silently never got
		// created. store_tags() below sets this column explicitly instead.
		$sql = "CREATE TABLE {$table} (
			id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cache_key VARCHAR(255)  NOT NULL,
			grp       VARCHAR(64)   NOT NULL DEFAULT 'pigcache_html',
			tag       VARCHAR(128)  NOT NULL,
			created   DATETIME      NOT NULL,
			PRIMARY KEY  (id),
			INDEX idx_tag (tag),
			INDEX idx_key_grp (cache_key, grp)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Store tag associations for a cache entry (batch INSERT).
	 *
	 * @param string   $cache_key  e.g. "doc_abc123".
	 * @param string   $group      e.g. "pigcache_html".
	 * @param string[] $tags       e.g. ["post:123","term:10","home"].
	 */
	public static function store_tags( $cache_key, $group, array $tags ) {
		global $wpdb;

		if ( empty( $tags ) ) {
			return;
		}

		$table = self::table_name();

		$wpdb->delete( $table, array( 'cache_key' => $cache_key, 'grp' => $group ) );

		$now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

		$values  = array();
		$holders = array();

		foreach ( $tags as $tag ) {
			$tag = (string) $tag;
			if ( $tag === '' || strlen( $tag ) > 128 ) {
				continue;
			}
			$holders[] = '(%s, %s, %s, %s)';
			$values[]  = $cache_key;
			$values[]  = $group;
			$values[]  = $tag;
			$values[]  = $now;
		}

		if ( empty( $holders ) ) {
			return;
		}

		$sql = "INSERT INTO {$table} (cache_key, grp, tag, created) VALUES " . implode( ', ', $holders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Find all cache keys associated with any of the given tags.
	 *
	 * @param string[] $tags
	 * @return array[] Array of {cache_key, grp} rows.
	 */
	public static function get_keys_for_tags( array $tags ) {
		global $wpdb;

		if ( empty( $tags ) ) {
			return array();
		}

		$table       = self::table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $tags ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT DISTINCT cache_key, grp FROM {$table} WHERE tag IN ({$placeholders})",
			$tags
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Remove all tag index rows for the given cache keys.
	 *
	 * @param string[] $cache_keys
	 */
	public static function remove_keys( array $cache_keys ) {
		global $wpdb;

		if ( empty( $cache_keys ) ) {
			return;
		}

		$table        = self::table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $cache_keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"DELETE FROM {$table} WHERE cache_key IN ({$placeholders})",
			$cache_keys
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );
	}

	/**
	 * High-level purge: resolve tags -> delete Redis keys -> cleanup MySQL.
	 *
	 * @param string[] $tags  Tags to purge (e.g. ["post:123","home"]).
	 * @return int Number of Redis keys deleted.
	 */
	public static function purge_by_tags( array $tags ) {
		if ( empty( $tags ) ) {
			return 0;
		}

		$rows = self::get_keys_for_tags( $tags );

		if ( empty( $rows ) ) {
			return 0;
		}

		$deleted    = 0;
		$cache_keys = array();

		foreach ( $rows as $row ) {
			$key = $row['cache_key'];
			$grp = $row['grp'];

			if ( wp_cache_delete( $key, $grp ) ) {
				$deleted++;
			}

			$cache_keys[] = $key;
		}

		self::remove_keys( array_unique( $cache_keys ) );

		return $deleted;
	}

	/**
	 * Cron callback: remove rows whose Redis keys no longer exist.
	 * Run via wp-cron at a low-traffic hour.
	 */
	public static function cleanup_stale() {
		global $wpdb;

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			"SELECT DISTINCT cache_key, grp FROM {$table} ORDER BY id ASC LIMIT 500",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$stale_keys = array();

		foreach ( $rows as $row ) {
			$val = wp_cache_get( $row['cache_key'], $row['grp'] );
			if ( false === $val ) {
				$stale_keys[] = $row['cache_key'];
			}
		}

		if ( ! empty( $stale_keys ) ) {
			self::remove_keys( array_unique( $stale_keys ) );
		}
	}

	/**
	 * Drop the tag table (for uninstall).
	 */
	public static function drop_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS " . self::table_name() );
	}
}
