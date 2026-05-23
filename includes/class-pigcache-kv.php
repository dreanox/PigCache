<?php
/**
 * Direct-MySQL key-value store for plugin-internal state.
 *
 * Bypasses the WordPress object cache entirely — all reads go straight to
 * MySQL. This is the correct storage layer for any value written by the
 * standalone cron runner (bin/pigcache-cron.php), which has no access to
 * Redis or APCu, and must be immediately visible to the WordPress admin
 * without going through the object cache.
 *
 * Multi-purpose: cron heartbeat timestamps, harvest metadata, or any
 * plugin state that must survive a Redis flush or be readable straight
 * after a CLI write.
 *
 * Scalar values are stored as-is; arrays/objects are PHP-serialized so
 * they round-trip cleanly through maybe_unserialize().
 *
 * Well-known key constants live here so grep quickly finds every caller.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_KV {

	const TABLE_SUFFIX = 'pigcache_kv';

	// ── Well-known keys ────────────────────────────────────────────────────────

	/** Unix timestamp — when the query-buffer flush last completed. */
	const KEY_CRON_FLUSH_LAST           = 'cron_flush_last';

	/** Unix timestamp — when the API send last completed. */
	const KEY_CRON_SEND_LAST            = 'cron_send_last';

	/** Unix timestamp — when the traffic harvest last completed. */
	const KEY_CRON_TRAFFIC_HARVEST_LAST = 'cron_traffic_harvest_last';

	/** String — 'awstats' or 'self' — traffic source used on last harvest. */
	const KEY_CRON_TRAFFIC_SOURCE_USED  = 'cron_traffic_source_used';

	// ── Table helper ───────────────────────────────────────────────────────────

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	// ── CRUD ───────────────────────────────────────────────────────────────────

	/**
	 * Write a value directly to MySQL.
	 *
	 * @param string $key  Up to 128 characters.
	 * @param mixed  $value  Scalar or array; arrays are PHP-serialized.
	 * @param int    $ttl  Seconds until expiry. 0 = never expires.
	 */
	public static function set( string $key, $value, int $ttl = 0 ): void {
		global $wpdb;

		$table      = self::table();
		$serialized = maybe_serialize( $value );
		$now        = gmdate( 'Y-m-d H:i:s' );

		if ( $ttl > 0 ) {
			$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO `{$table}` (cache_key, value, updated_at, expires_at)
				 VALUES (%s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at), expires_at = VALUES(expires_at)",
				$key, $serialized, $now, $expires
			) );
		} else {
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO `{$table}` (cache_key, value, updated_at, expires_at)
				 VALUES (%s, %s, %s, NULL)
				 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at), expires_at = NULL",
				$key, $serialized, $now
			) );
		}
	}

	/**
	 * Read a value directly from MySQL, bypassing the object cache entirely.
	 *
	 * @param string $key
	 * @param mixed  $default  Returned when key not found or expired.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT value, expires_at FROM `{$table}` WHERE cache_key = %s LIMIT 1",
			$key
		), ARRAY_A );

		if ( ! $row ) {
			return $default;
		}

		if ( null !== $row['expires_at'] && strtotime( $row['expires_at'] ) < time() ) {
			return $default;
		}

		return maybe_unserialize( $row['value'] );
	}

	/**
	 * Delete a single key.
	 *
	 * @param string $key
	 */
	public static function delete( string $key ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'cache_key' => $key ), array( '%s' ) );
	}

	/**
	 * Remove all expired rows. Called periodically by the cron.
	 */
	public static function purge_expired(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DELETE FROM `{$table}` WHERE expires_at IS NOT NULL AND expires_at < NOW()" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Return all non-expired entries as key => value.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results(
			"SELECT cache_key, value FROM `{$table}` WHERE expires_at IS NULL OR expires_at > NOW()", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$result = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[ $row['cache_key'] ] = maybe_unserialize( $row['value'] );
			}
		}

		return $result;
	}
}
