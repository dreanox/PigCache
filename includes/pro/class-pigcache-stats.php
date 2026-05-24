<?php
/**
 * Cache hit/miss analytics and write-pattern monitoring.
 *
 * Accumulates per-event counters in PHP memory during each request, then
 * flushes them atomically to a Redis HASH via HINCRBY on shutdown.
 * The standalone cron (pigcache-cron.php) reads the previous 15-min window,
 * deletes it, and forwards the payload to the PigCache API.
 *
 * Redis key format : pigcache_stats:{15-min-window-unix-ts}
 * Redis key type   : HASH  (field → integer counter)
 * Redis TTL        : 7200 s (2 hours — cron runs every 15 min)
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

// ── PRO_START ─────────────────────────────────────────────────────────────────
class PigCache_Stats {

	const REDIS_KEY_PREFIX = 'pigcache_stats:';
	const REDIS_TTL        = 7200;

	/** @var array<string,int> In-request accumulator: field → increment. */
	private static $pending = array();

	/** @var bool Whether the shutdown flush has been registered. */
	private static $shutdown_registered = false;

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Track every content-publish that would invalidate cache entries.
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status_transition' ), 10, 3 );
	}

	/**
	 * Fire when a post transitions TO the 'publish' status.
	 * Counts by post_type and by hour-of-day (UTC) for scheduling recommendations.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       The post object.
	 */
	public static function on_post_status_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status ) {
			return;
		}
		// Only track public, cacheable post types; skip WP-internal types.
		$non_public = array( 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );
		if ( in_array( $post->post_type, $non_public, true ) ) {
			return;
		}

		$hour = (int) gmdate( 'G' ); // 0-23 UTC
		self::inc( 'write_pub' );
		self::inc( 'write_pub_type_' . sanitize_key( $post->post_type ) );
		self::inc( 'write_h' . $hour );
	}

	// ── HTML cache events ─────────────────────────────────────────────────────

	/**
	 * Record an HTML cache HIT served to the client.
	 *
	 * @param string $kind  "EARLY" | "LATE" | "LATE-RETRY"
	 */
	public static function html_hit( string $kind ): void {
		self::inc( 'html_hit' );
		self::inc( 'html_hit_' . strtolower( str_replace( '-', '_', $kind ) ) );
	}

	/**
	 * Record a request that was bypassed before any cache lookup.
	 *
	 * @param string $reason  e.g. 'logged_in', 'cookie', 'admin', 'woocommerce'.
	 */
	public static function html_bypass( string $reason ): void {
		self::inc( 'html_bypass' );
		if ( '' !== $reason ) {
			self::inc( 'html_bypass_' . sanitize_key( $reason ) );
		}
	}

	/**
	 * Record a cache MISS where the ob_callback chose not to store the output.
	 *
	 * @param string $reason  e.g. 'partial', 'php_error', '5xx', 'cold', 'empty'.
	 */
	public static function html_miss( string $reason ): void {
		self::inc( 'html_miss' );
		if ( '' !== $reason ) {
			self::inc( 'html_miss_' . sanitize_key( $reason ) );
		}
	}

	/**
	 * Record a successful HTML cache write (new entry stored in Redis).
	 */
	public static function html_write(): void {
		self::inc( 'html_write' );
	}

	/**
	 * Record a URL sample for a partial render (no </html> detected).
	 * Pushes a JSON entry to a Redis list ring buffer (max 100, 3-day TTL).
	 * The cron reads and forwards these to the API for instrumentation.
	 *
	 * @param string $url  Request URI.
	 * @param string $tail Last ~300 characters of the captured HTML.
	 */
	public static function sample_partial( string $url, string $tail ): void {
		self::push_sample( 'pigcache_partial_samples', array(
			'type' => 'partial',
			'url'  => $url,
			'tail' => $tail,
			'ts'   => time(),
		) );
	}

	/**
	 * Record a URL sample for a bypassed request.
	 * Pushes a JSON entry to a Redis list ring buffer (max 100, 3-day TTL).
	 * The cron reads and forwards these to the API for instrumentation.
	 *
	 * @param string   $reason       Bypass reason (e.g. 'logged_in', 'cookie').
	 * @param string   $url          Request URI.
	 * @param string[] $cookie_names Cookie names present on the request.
	 */
	public static function sample_bypass( string $reason, string $url, array $cookie_names ): void {
		self::push_sample( 'pigcache_bypass_samples', array(
			'type'    => 'bypass',
			'reason'  => $reason,
			'url'     => $url,
			'cookies' => array_slice( $cookie_names, 0, 20 ),
			'ts'      => time(),
		) );
	}

	// ── DB cache events ───────────────────────────────────────────────────────

	/**
	 * Record a SQL cache HIT (query result served from Redis).
	 */
	public static function db_hit(): void {
		self::inc( 'db_hit' );
	}

	/**
	 * Record a SQL cache MISS (query executed against MySQL).
	 *
	 * @param string $reason  e.g. 'not_found', 'stale', 'uncacheable'.
	 */
	public static function db_miss( string $reason = '' ): void {
		self::inc( 'db_miss' );
		if ( '' !== $reason ) {
			self::inc( 'db_miss_' . sanitize_key( $reason ) );
		}
	}

	// ── Object cache events ───────────────────────────────────────────────────

	/**
	 * Record an object cache HIT.
	 */
	public static function obj_hit(): void {
		self::inc( 'obj_hit' );
	}

	/**
	 * Record an object cache MISS.
	 */
	public static function obj_miss(): void {
		self::inc( 'obj_miss' );
	}

	// ── Flush ─────────────────────────────────────────────────────────────────

	/**
	 * Flush the in-request buffer to the Redis HASH for this 15-min window.
	 * Called automatically on shutdown (registered once, on first inc()).
	 */
	public static function flush(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		$redis = self::connect_redis();
		if ( ! $redis ) {
			self::$pending = array();
			return;
		}

		$window = self::current_window();
		$key    = self::REDIS_KEY_PREFIX . $window;

		try {
			foreach ( self::$pending as $field => $delta ) {
				$redis->hIncrBy( $key, $field, $delta );
			}
			$redis->expire( $key, self::REDIS_TTL );
			$redis->close();
		} catch ( \Exception $e ) {
			// Analytics are non-critical; swallow silently.
		}

		self::$pending = array();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Increment a field in the pending buffer by $delta.
	 * Registers the shutdown flush on the first call.
	 */
	private static function inc( string $field, int $delta = 1 ): void {
		if ( isset( self::$pending[ $field ] ) ) {
			self::$pending[ $field ] += $delta;
		} else {
			self::$pending[ $field ] = $delta;
		}

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'flush' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Push a sample entry to a Redis list ring buffer.
	 * Caps at 100 entries and keeps a 3-day TTL so stale data self-clears.
	 *
	 * @param string  $key    Redis list key.
	 * @param mixed[] $sample Associative array to JSON-encode.
	 */
	private static function push_sample( string $key, array $sample ): void {
		$redis = self::connect_redis();
		if ( ! $redis ) {
			return;
		}

		try {
			$redis->rPush( $key, (string) json_encode( $sample ) );
			$redis->lTrim( $key, -100, -1 ); // keep last 100
			$redis->expire( $key, 259200 );   // 3 days
			$redis->close();
		} catch ( \Exception $e ) {
			// Non-critical — swallow silently.
		}
	}

	/**
	 * The start of the current 15-minute window (Unix timestamp).
	 */
	private static function current_window(): int {
		return (int) floor( time() / 900 ) * 900;
	}

	/**
	 * Open a direct Redis connection using the plugin's configured credentials.
	 *
	 * @return \Redis|null
	 */
	private static function connect_redis(): ?\Redis {
		if ( ! class_exists( 'Redis', false ) ) {
			return null;
		}

		$host = defined( 'PIGCACHE_REDIS_HOST' ) ? (string) PIGCACHE_REDIS_HOST : '127.0.0.1';
		$port = defined( 'PIGCACHE_REDIS_PORT' ) ? (int) PIGCACHE_REDIS_PORT   : 6379;

		try {
			$redis = new \Redis();
			$redis->connect( $host, $port, 2 );
			if ( defined( 'PIGCACHE_REDIS_PASSWORD' ) && PIGCACHE_REDIS_PASSWORD ) {
				$redis->auth( PIGCACHE_REDIS_PASSWORD );
			}
			return $redis;
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
// ── PRO_END ───────────────────────────────────────────────────────────────────
