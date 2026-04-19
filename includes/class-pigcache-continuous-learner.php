<?php
/**
 * Continuous query learning orchestrator (Pro only).
 *
 * Responsibilities:
 *  - Decide per-request whether to sample (sampling rate from remote config).
 *  - Route sampled queries to PigCache_Query_Buffer::record().
 *  - Schedule/unschedule two cron jobs.
 *  - cron_flush(): harvest buffer → MySQL.
 *  - cron_send(): MySQL unsent rows → API.
 *
 * Remote config (GET /api/v1/learning-config) is cached 6 h.
 * Constants override remote:
 *   PIGCACHE_CONTINUOUS_LEARNING  bool  (true = force-on, false = force-off)
 *   PIGCACHE_LEARNING_SAMPLE_RATE float 0.0–1.0 (e.g. 0.10 = 10 %)
 *   PIGCACHE_ADAPTIVE_TTL         bool  enables adaptive TTL tiers
 *   PIGCACHE_APCU_BUFFER          bool  opt-in to APCu buffer (see Query_Buffer)
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Continuous_Learner {

	const CRON_FLUSH       = 'pigcache_flush_query_stats';
	const CRON_SEND        = 'pigcache_send_query_stats';
	const REMOTE_CFG_KEY   = 'pigcache_learning_cfg';
	const REMOTE_CFG_TTL   = 6 * HOUR_IN_SECONDS;
	const REMOTE_CFG_ERR_TTL = HOUR_IN_SECONDS;
	const DEFAULT_RATE     = 0.10;

	// Adaptive TTL tiers (ms → seconds)
	const TTL_FAST   = 60;    // < 20 ms
	const TTL_MED    = 300;   // 20–200 ms
	const TTL_SLOW   = 900;   // > 200 ms

	/** @var bool|null Runtime cache so sampling is decided only once per request. */
	private static $sampled = null;

	// ── Init ────────────────────────────────────────────────────────────────

	public static function init() {
		add_action( self::CRON_FLUSH, array( __CLASS__, 'cron_flush' ) );
		add_action( self::CRON_SEND,  array( __CLASS__, 'cron_send' ) );
	}

	// ── Sampling ─────────────────────────────────────────────────────────────

	/**
	 * Should this request record query analytics?
	 * Result is memoised — called multiple times per request.
	 *
	 * @return bool
	 */
	public static function is_request_sampled() {
		if ( null !== self::$sampled ) {
			return self::$sampled;
		}

		// Hard override via constant.
		if ( defined( 'PIGCACHE_CONTINUOUS_LEARNING' ) ) {
			self::$sampled = (bool) PIGCACHE_CONTINUOUS_LEARNING;
			return self::$sampled;
		}

		$cfg = self::get_remote_config();

		if ( empty( $cfg['learning_enabled'] ) ) {
			self::$sampled = false;
			return false;
		}

		$rate = defined( 'PIGCACHE_LEARNING_SAMPLE_RATE' )
			? (float) PIGCACHE_LEARNING_SAMPLE_RATE
			: (float) ( $cfg['sample_rate'] ?? self::DEFAULT_RATE );

		$rate = max( 0.0, min( 1.0, $rate ) );

		self::$sampled = ( mt_rand( 1, 1000 ) <= (int) round( $rate * 1000 ) );
		return self::$sampled;
	}

	/**
	 * Record a sampled query.
	 *
	 * @param string   $normalized  Normalized SQL.
	 * @param string[] $tables      Tables touched.
	 * @param float    $exec_ms     Execution time ms.
	 * @param int      $memory_kb   Memory delta KB.
	 */
	public static function record( $normalized, array $tables, $exec_ms, $memory_kb ) {
		if ( ! self::is_request_sampled() ) {
			return;
		}

		PigCache_Query_Buffer::record( $normalized, $tables, $exec_ms, $memory_kb );
	}

	// ── Adaptive TTL ─────────────────────────────────────────────────────────

	/**
	 * Compute an adaptive TTL based on observed execution time.
	 * Returns null when adaptive TTL is disabled so caller uses default.
	 *
	 * @param float $exec_ms
	 * @return int|null
	 */
	public static function adaptive_ttl( $exec_ms ) {
		$enabled = defined( 'PIGCACHE_ADAPTIVE_TTL' )
			? (bool) PIGCACHE_ADAPTIVE_TTL
			: (bool) ( self::get_remote_config()['adaptive_ttl'] ?? false );

		if ( ! $enabled ) {
			return null;
		}

		if ( $exec_ms >= 200 ) {
			return self::TTL_SLOW;
		}

		if ( $exec_ms >= 20 ) {
			return self::TTL_MED;
		}

		return self::TTL_FAST;
	}

	// ── Cron ─────────────────────────────────────────────────────────────────

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_FLUSH ) ) {
			wp_schedule_event( time(), 'pigcache_15min', self::CRON_FLUSH );
		}

		if ( ! wp_next_scheduled( self::CRON_SEND ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_SEND );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_FLUSH );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_FLUSH );
		}

		$ts = wp_next_scheduled( self::CRON_SEND );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_SEND );
		}
	}

	/**
	 * Flush in-memory/APCu/Redis query buffer into MySQL.
	 * Runs every 15 minutes.
	 */
	public static function cron_flush() {
		$rows = PigCache_Query_Buffer::read_and_clear();

		if ( empty( $rows ) ) {
			return;
		}

		$window       = (int) floor( time() / 900 ) * 900;
		$period_start = gmdate( 'Y-m-d H:i:s', $window );

		PigCache_Query_Stats::upsert_batch( $rows, $period_start );
	}

	/**
	 * Send unsent MySQL rows to the cloud API.
	 * Runs every hour.
	 */
	public static function cron_send() {
		$rows = PigCache_Query_Stats::get_unsent( 200 );

		if ( empty( $rows ) ) {
			PigCache_Query_Stats::prune();
			return;
		}

		$sent = self::push_to_api( $rows );

		if ( ! empty( $sent ) ) {
			PigCache_Query_Stats::mark_sent( $sent );
		}

		PigCache_Query_Stats::prune();
	}

	// ── Remote config ────────────────────────────────────────────────────────

	/**
	 * @return array{learning_enabled: bool, sample_rate: float, adaptive_ttl: bool}
	 */
	public static function get_remote_config() {
		$cached = get_transient( self::REMOTE_CFG_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$cfg = self::fetch_remote_config();

		set_transient(
			self::REMOTE_CFG_KEY,
			$cfg,
			isset( $cfg['_error'] ) ? self::REMOTE_CFG_ERR_TTL : self::REMOTE_CFG_TTL
		);

		return $cfg;
	}

	private static function fetch_remote_config() {
		$defaults = array(
			'learning_enabled' => false,
			'sample_rate'      => self::DEFAULT_RATE,
			'adaptive_ttl'     => false,
		);

		if ( ! class_exists( 'PigCache_Cloud_Client', false ) ) {
			return $defaults;
		}

		$response = PigCache_Cloud_Client::get( 'learning-config' );

		if ( is_wp_error( $response ) || empty( $response['data'] ) ) {
			$defaults['_error'] = true;
			return $defaults;
		}

		$data = $response['data'];

		return array(
			'learning_enabled' => (bool) ( $data['learning_enabled'] ?? false ),
			'sample_rate'      => (float) ( $data['sample_rate'] ?? self::DEFAULT_RATE ),
			'adaptive_ttl'     => (bool) ( $data['adaptive_ttl'] ?? false ),
		);
	}

	// ── API push ─────────────────────────────────────────────────────────────

	/**
	 * @param array $rows  Rows from PigCache_Query_Stats::get_unsent().
	 * @return int[]  IDs successfully sent.
	 */
	private static function push_to_api( array $rows ) {
		if ( ! class_exists( 'PigCache_Cloud_Client', false ) ) {
			return array();
		}

		$response = PigCache_Cloud_Client::post(
			'query-stats',
			array( 'rows' => $rows )
		);

		if ( is_wp_error( $response ) || empty( $response['data']['accepted'] ) ) {
			return array();
		}

		return array_map( 'intval', (array) $response['data']['accepted'] );
	}
}
