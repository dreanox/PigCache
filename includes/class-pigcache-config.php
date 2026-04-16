<?php
/**
 * Site-specific Redis DB registration and wp-config hints.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Config {

	const OPTION_DB        = 'pigcache_redis_db';
	const OPTION_SITE_META = 'pigcache_site_registry';
	const OPTION_SQL_TTL   = 'pigcache_sql_cache_ttl';
	const OPTION_HTML_TTL  = 'pigcache_html_cache_ttl';
	const OPTION_EXTRA_NON_PERSISTENT = 'pigcache_extra_non_persistent_groups';
	const REGISTRY_KEY     = 'pigcache:db_registry';

	/** Max length for a single cache group name (admin textarea lines). */
	const GROUP_NAME_MAX_LEN = 128;
	const MIN_DB           = 5;
	const MAX_DB           = 15;

	/** Default TTL (seconds) for SQL SELECT payloads in Redis. */
	const DEFAULT_SQL_TTL = 120;

	/** Default TTL (seconds) for full-page HTML in Redis. */
	const DEFAULT_HTML_TTL = 60;

	const TTL_MIN_SECONDS = 1;

	/** Upper bound for admin-editable TTL (7 days). */
	const TTL_MAX_SECONDS = 604800;

	/**
	 * Assign a Redis logical DB index for this install and optionally register in Redis.
	 */
	public static function on_activate() {
		$site_url = self::site_fingerprint();
		$existing = (int) get_option( self::OPTION_DB, 0 );

		if ( $existing >= self::MIN_DB && $existing <= self::MAX_DB ) {
			self::persist_registry_meta( $site_url, $existing );
			self::try_redis_register( $site_url, $existing );
			return;
		}

		$assigned = self::pick_database_index( $site_url );
		update_option( self::OPTION_DB, $assigned, false );
		self::persist_registry_meta( $site_url, $assigned );
		self::try_redis_register( $site_url, $assigned );
	}

	/**
	 * @return string
	 */
	public static function site_fingerprint() {
		return untrailingslashit( site_url() );
	}

	/**
	 * @return int
	 */
	public static function get_assigned_db() {
		return (int) get_option( self::OPTION_DB, 0 );
	}

	/**
	 * Index shown in wp-admin: wp-config wins when WP_REDIS_DATABASE is set.
	 *
	 * @return int
	 */
	public static function get_display_redis_database_index() {
		if ( defined( 'WP_REDIS_DATABASE' ) ) {
			return (int) WP_REDIS_DATABASE;
		}

		return self::get_assigned_db();
	}

	/**
	 * Sanitized SQL result cache TTL from settings (seconds).
	 *
	 * @return int
	 */
	public static function get_sql_cache_ttl() {
		$v = (int) get_option( self::OPTION_SQL_TTL, self::DEFAULT_SQL_TTL );
		if ( $v < self::TTL_MIN_SECONDS || $v > self::TTL_MAX_SECONDS ) {
			return self::DEFAULT_SQL_TTL;
		}

		return $v;
	}

	/**
	 * Sanitized full-page HTML cache TTL from settings (seconds).
	 *
	 * @return int
	 */
	public static function get_html_cache_ttl() {
		$v = (int) get_option( self::OPTION_HTML_TTL, self::DEFAULT_HTML_TTL );
		if ( $v < self::TTL_MIN_SECONDS || $v > self::TTL_MAX_SECONDS ) {
			return self::DEFAULT_HTML_TTL;
		}

		return $v;
	}

	/**
	 * Persist TTL options from admin POST (already validated range).
	 *
	 * @param int $sql_ttl
	 * @param int $html_ttl
	 * @return void
	 */
	public static function set_cache_ttls( $sql_ttl, $html_ttl ) {
		$sql_ttl  = (int) $sql_ttl;
		$html_ttl = (int) $html_ttl;

		if ( $sql_ttl >= self::TTL_MIN_SECONDS && $sql_ttl <= self::TTL_MAX_SECONDS ) {
			update_option( self::OPTION_SQL_TTL, $sql_ttl, false );
		}

		if ( $html_ttl >= self::TTL_MIN_SECONDS && $html_ttl <= self::TTL_MAX_SECONDS ) {
			update_option( self::OPTION_HTML_TTL, $html_ttl, false );
		}
	}

	/**
	 * @param string $site_url
	 * @param int    $db
	 */
	private static function persist_registry_meta( $site_url, $db ) {
		update_option(
			self::OPTION_SITE_META,
			array(
				'site_url' => $site_url,
				'db'       => $db,
				'plugin'   => 'pigcache',
				'updated'  => time(),
			),
			false
		);
	}

	/**
	 * Choose next free DB in range, using Redis registry when possible.
	 *
	 * @param string $site_url
	 * @return int
	 */
	private static function pick_database_index( $site_url ) {
		$used = array();

		$redis = self::connect_redis();
		if ( $redis instanceof Redis ) {
			$raw = $redis->get( self::REGISTRY_KEY );
			if ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $db => $url ) {
						$used[ (int) $db ] = (string) $url;
					}
				}
			}
			// Reserve URLs already registered to another DB.
			foreach ( $used as $db => $url ) {
				if ( $url === $site_url ) {
					$redis->close();
					return (int) $db;
				}
			}
		}

		for ( $i = self::MIN_DB; $i <= self::MAX_DB; $i++ ) {
			if ( ! isset( $used[ $i ] ) ) {
				if ( $redis instanceof Redis ) {
					$redis->close();
				}
				return $i;
			}
		}

		if ( $redis instanceof Redis ) {
			$redis->close();
		}

		return self::MIN_DB;
	}

	/**
	 * @param string $site_url
	 * @param int    $db
	 */
	private static function try_redis_register( $site_url, $db ) {
		$redis = self::connect_redis();
		if ( ! $redis instanceof Redis ) {
			return;
		}

		$raw  = $redis->get( self::REGISTRY_KEY );
		$map  = array();
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$map = $decoded;
			}
		}

		$map[ (string) $db ] = $site_url;
		$redis->set( self::REGISTRY_KEY, wp_json_encode( $map ) );
		$redis->close();
	}

	/**
	 * @return Redis|null
	 */
	private static function connect_redis() {
		if ( ! class_exists( 'Redis', false ) ) {
			return null;
		}

		$host = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
		$port = defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379;

		$redis = new Redis();
		try {
			$connected = $redis->connect( $host, $port, 1.0 );
		} catch ( Exception $e ) {
			return null;
		}

		if ( ! $connected ) {
			return null;
		}

		if ( defined( 'WP_REDIS_PASSWORD' ) && WP_REDIS_PASSWORD !== '' ) {
			$pass = WP_REDIS_PASSWORD;
			if ( is_array( $pass ) && isset( $pass[1] ) ) {
				$redis->auth( $pass );
			} else {
				$redis->auth( $pass );
			}
		}

		return $redis;
	}

	/**
	 * Register extra non-persistent (ignored) groups early so Redis skips them.
	 *
	 * @return void
	 */
	public static function apply_extra_non_persistent_groups() {
		if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) || ! function_exists( 'wp_using_ext_object_cache' ) ) {
			return;
		}

		if ( ! wp_using_ext_object_cache() ) {
			return;
		}

		$extra = self::get_extra_non_persistent_groups();
		if ( empty( $extra ) ) {
			return;
		}

		wp_cache_add_non_persistent_groups( $extra );
	}

	/**
	 * Group names saved in options (additional ignored groups).
	 *
	 * @return string[]
	 */
	public static function get_extra_non_persistent_groups() {
		$v = get_option( self::OPTION_EXTRA_NON_PERSISTENT, array() );
		if ( ! is_array( $v ) ) {
			return array();
		}

		$out = array();
		foreach ( $v as $g ) {
			if ( ! is_string( $g ) ) {
				continue;
			}
			$g = trim( $g );
			if ( $g !== '' && strlen( $g ) <= self::GROUP_NAME_MAX_LEN ) {
				$out[] = $g;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @return string
	 */
	public static function get_extra_non_persistent_textarea_value() {
		return implode( "\n", self::get_extra_non_persistent_groups() );
	}

	/**
	 * @param string $text
	 * @return void
	 */
	public static function set_extra_non_persistent_groups_from_text( $text ) {
		$groups = self::parse_groups_multiline( $text );
		update_option( self::OPTION_EXTRA_NON_PERSISTENT, $groups, false );
	}

	/**
	 * @param string $text
	 * @return string[]
	 */
	public static function parse_groups_multiline( $text ) {
		if ( ! is_string( $text ) ) {
			return array();
		}

		$lines = preg_split( '/\r\n|\r|\n/', $text );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' || strlen( $line ) > self::GROUP_NAME_MAX_LEN ) {
				continue;
			}
			if ( count( $out ) >= 200 ) {
				break;
			}
			$out[] = $line;
		}

		return array_values( array_unique( $out ) );
	}
}
