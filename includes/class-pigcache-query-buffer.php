<?php
/**
 * Per-request query buffer with APCu or Redis persistence.
 *
 * Flow:
 *   1. PigCache_WPDB calls record() on every sampled SELECT (cache miss).
 *   2. On shutdown, flush_request() merges the PHP-memory buffer into APCu or
 *      a Redis HASH — a single external call per request, zero overhead on the
 *      hot path.
 *   3. The cron job calls read_and_clear() to harvest data for MySQL insertion.
 *
 * APCu keys:  pigcache_qbuf:{md5(normalized)}
 * Redis key:  pigcache_qbuf:{15-min window}  (HASH)
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Query_Buffer {

	const APCU_PREFIX  = 'pigcache_qbuf:';
	const REDIS_KEY    = 'pigcache_qbuf';
	const BUFFER_LIMIT = 300;
	const REDIS_TTL    = 7200;

	/** @var array<string, array> In-request accumulator. */
	private static $buffer = array();

	/** @var bool Whether flush was already registered for this request. */
	private static $shutdown_registered = false;

	// ── Public API ──────────────────────────────────────────────────────────

	/**
	 * Record a query during the current request.
	 *
	 * @param string   $normalized  Normalized SQL (literals replaced with ?).
	 * @param string[] $tables      Tables touched by the query.
	 * @param float    $exec_ms     Execution time in milliseconds.
	 * @param int      $memory_kb   Memory delta in kilobytes.
	 */
	public static function record( $normalized, array $tables, $exec_ms, $memory_kb ) {
		if ( count( self::$buffer ) >= self::BUFFER_LIMIT ) {
			return;
		}

		$hash = md5( $normalized );

		if ( isset( self::$buffer[ $hash ] ) ) {
			self::$buffer[ $hash ]['count']++;
			self::$buffer[ $hash ]['total_ms']  += $exec_ms;
			self::$buffer[ $hash ]['max_ms']     = max( self::$buffer[ $hash ]['max_ms'], $exec_ms );
			self::$buffer[ $hash ]['total_mem'] += $memory_kb;
		} else {
			self::$buffer[ $hash ] = array(
				'normalized' => $normalized,
				'tables'     => $tables,
				'count'      => 1,
				'total_ms'   => $exec_ms,
				'max_ms'     => $exec_ms,
				'total_mem'  => $memory_kb,
			);
		}

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'flush_request' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Flush request buffer to persistent store (called on shutdown).
	 */
	public static function flush_request() {
		if ( empty( self::$buffer ) ) {
			return;
		}

		if ( self::apcu_enabled() ) {
			self::flush_to_apcu();
		} elseif ( self::redis_available() ) {
			self::flush_to_redis();
		}

		self::$buffer = array();
	}

	/**
	 * Read all buffered data and clear the persistent store.
	 * Used by the cron job.
	 *
	 * @return array<string, array> hash => data
	 */
	public static function read_and_clear() {
		if ( self::apcu_enabled() ) {
			return self::read_and_clear_apcu();
		}

		if ( self::redis_available() ) {
			return self::read_and_clear_redis();
		}

		return array();
	}

	/**
	 * Whether APCu buffering is enabled and available.
	 *
	 * @return bool
	 */
	public static function apcu_enabled() {
		if ( ! defined( 'PIGCACHE_APCU_BUFFER' ) || ! PIGCACHE_APCU_BUFFER ) {
			return false;
		}

		return function_exists( 'apcu_enabled' ) && apcu_enabled();
	}

	/**
	 * Whether a direct Redis connection is available as fallback.
	 *
	 * @return bool
	 */
	public static function redis_available() {
		return class_exists( 'Redis', false );
	}

	// ── APCu ────────────────────────────────────────────────────────────────

	private static function flush_to_apcu() {
		foreach ( self::$buffer as $hash => $data ) {
			$key      = self::APCU_PREFIX . $hash;
			$existing = apcu_fetch( $key, $success );

			if ( $success && is_array( $existing ) ) {
				$existing['count']     += $data['count'];
				$existing['total_ms']  += $data['total_ms'];
				$existing['max_ms']     = max( $existing['max_ms'], $data['max_ms'] );
				$existing['total_mem'] += $data['total_mem'];
				apcu_store( $key, $existing, self::REDIS_TTL );
			} else {
				apcu_store( $key, $data, self::REDIS_TTL );
			}
		}
	}

	private static function read_and_clear_apcu() {
		if ( ! class_exists( 'APCUIterator', false ) ) {
			return array();
		}

		$result   = array();
		$pattern  = '/^' . preg_quote( self::APCU_PREFIX, '/' ) . '/';
		$iterator = new APCUIterator( $pattern );

		foreach ( $iterator as $item ) {
			$hash            = substr( $item['key'], strlen( self::APCU_PREFIX ) );
			$result[ $hash ] = $item['value'];
			apcu_delete( $item['key'] );
		}

		return $result;
	}

	// ── Redis ────────────────────────────────────────────────────────────────

	private static function get_redis_period_key() {
		$window = (int) floor( time() / 900 );
		return self::REDIS_KEY . ':' . $window;
	}

	private static function flush_to_redis() {
		$redis = self::connect_redis();
		if ( ! $redis ) {
			return;
		}

		$key = self::get_redis_period_key();

		try {
			foreach ( self::$buffer as $hash => $data ) {
				$existing_raw = $redis->hGet( $key, $hash );
				if ( $existing_raw ) {
					$existing = json_decode( $existing_raw, true );
					if ( is_array( $existing ) ) {
						$data['count']     += $existing['count'];
						$data['total_ms']  += $existing['total_ms'];
						$data['max_ms']     = max( $data['max_ms'], $existing['max_ms'] );
						$data['total_mem'] += $existing['total_mem'];
					}
				}
				$redis->hSet( $key, $hash, wp_json_encode( $data ) );
			}
			$redis->expire( $key, self::REDIS_TTL );
			$redis->close();
		} catch ( \Exception $e ) {
			// Silently skip — analytics are non-critical.
		}
	}

	private static function read_and_clear_redis() {
		$redis = self::connect_redis();
		if ( ! $redis ) {
			return array();
		}

		$result = array();

		try {
			$prev_window = (int) floor( time() / 900 ) - 1;
			$key         = self::REDIS_KEY . ':' . $prev_window;

			$raw = $redis->hGetAll( $key );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $hash => $json ) {
					$data = json_decode( $json, true );
					if ( is_array( $data ) ) {
						$result[ $hash ] = $data;
					}
				}
			}
			$redis->del( $key );
			$redis->close();
		} catch ( \Exception $e ) {
			// Silently skip.
		}

		return $result;
	}

	private static function connect_redis() {
		if ( ! class_exists( 'Redis', false ) ) {
			return null;
		}

		$host = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
		$port = defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379;

		try {
			$redis = new Redis();
			$redis->connect( $host, $port, 2 );

			if ( defined( 'WP_REDIS_PASSWORD' ) && WP_REDIS_PASSWORD ) {
				$redis->auth( WP_REDIS_PASSWORD );
			}

			return $redis;
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
