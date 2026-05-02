<?php
/**
 * Traffic reader for Adaptive TTL v2.
 *
 * Two data sources (tried in order):
 *  A. AWStats data files   — pre-aggregated by cPanel, zero WP overhead.
 *  B. Own hit counters     — accumulated per request on every HTML cache HIT,
 *                            flushed to wp_options on shutdown.
 *
 * The cron harvests whichever source is available, persists to MySQL, and
 * rebuilds a transient traffic_map read by the decision engine.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Traffic_Reader {

	const OPT_HIT_WINDOW   = 'pigcache_url_hits_window';
	const OPT_LAST_HARVEST = 'pigcache_traffic_harvest_last';
	const OPT_SOURCE_USED  = 'pigcache_traffic_source_used';
	const TRANSIENT_MAP    = 'pigcache_traffic_map';
	const TRANSIENT_TTL    = 25 * HOUR_IN_SECONDS;
	const HARVEST_INTERVAL = 23 * HOUR_IN_SECONDS;
	const MAX_OWN_ENTRIES  = 500;

	/** @var array<string,array> Hits accumulated during this request. */
	private static $pending = array();

	/** @var bool */
	private static $shutdown_registered = false;

	// ── Own hit counter ───────────────────────────────────────────────────────

	/**
	 * Record an HTML cache HIT. Batched and flushed on shutdown.
	 *
	 * @param string $doc_key Cache key (e.g. "doc_abc123").
	 * @param string $uri     Request URI.
	 */
	public static function record_hit( string $doc_key, string $uri ): void {
		if ( isset( self::$pending[ $doc_key ] ) ) {
			self::$pending[ $doc_key ]['hits']++;
		} else {
			self::$pending[ $doc_key ] = array(
				'uri'  => $uri,
				'hits' => 1,
			);
		}

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'flush_pending' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Merge pending hit counts into the wp_options accumulator. Called on shutdown.
	 */
	public static function flush_pending(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		$raw     = get_option( self::OPT_HIT_WINDOW, '' );
		$current = ( $raw && is_string( $raw ) ) ? json_decode( $raw, true ) : array();
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		foreach ( self::$pending as $doc_key => $entry ) {
			if ( isset( $current[ $doc_key ] ) ) {
				$current[ $doc_key ]['hits'] += $entry['hits'];
			} else {
				$current[ $doc_key ] = $entry;
			}
		}

		// Keep only the top N entries to bound option size.
		uasort( $current, static function ( $a, $b ) {
			return $b['hits'] <=> $a['hits'];
		} );
		if ( count( $current ) > self::MAX_OWN_ENTRIES ) {
			$current = array_slice( $current, 0, self::MAX_OWN_ENTRIES, true );
		}

		update_option( self::OPT_HIT_WINDOW, wp_json_encode( $current ), false );

		self::$pending = array();
	}

	// ── Harvest (called by cron) ──────────────────────────────────────────────

	/**
	 * Harvest traffic data and rebuild the traffic map transient.
	 * Skips if run within HARVEST_INTERVAL of the last execution.
	 */
	public static function harvest(): void {
		$last = (int) PigCache_KV::get( PigCache_KV::KEY_CRON_TRAFFIC_HARVEST_LAST, 0 );

		if ( $last > 0 && ( time() - $last ) < self::HARVEST_INTERVAL ) {
			return;
		}

		$source = self::detect_source();
		$data   = array();

		if ( 'awstats' === $source ) {
			$data = self::read_awstats();
		}

		if ( empty( $data ) ) {
			$data   = self::read_own_counters();
			$source = 'self';
		}

		if ( ! empty( $data ) ) {
			self::store_to_db( $data );
		}

		self::rebuild_transient();

		PigCache_KV::set( PigCache_KV::KEY_CRON_TRAFFIC_HARVEST_LAST, time() );
		PigCache_KV::set( PigCache_KV::KEY_CRON_TRAFFIC_SOURCE_USED, $source );
	}

	// ── AWStats parser ────────────────────────────────────────────────────────

	/**
	 * Read URL hits from the most recent AWStats data file.
	 *
	 * @return array<string,int>  uri → hits
	 */
	public static function read_awstats(): array {
		$dir = self::awstats_dir();

		if ( ! $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( $dir . '/awstats*.txt' );
		if ( empty( $files ) ) {
			return array();
		}

		usort( $files, static function ( $a, $b ) {
			return filemtime( $b ) - filemtime( $a );
		} );

		return self::parse_awstats_file( $files[0] );
	}

	/**
	 * Parse BEGIN_URLS section of an AWStats data file.
	 *
	 * Line format: /uri/  pages  hits  bandwidth  date  time  ...
	 *
	 * @return array<string,int>
	 */
	private static function parse_awstats_file( string $path ): array {
		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $handle ) {
			return array();
		}

		$result     = array();
		$in_section = false;
		$static_ext = array(
			'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico',
			'css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'otf',
			'mp4', 'mp3', 'pdf', 'zip', 'gz', 'map',
		);

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = rtrim( $line );

			if ( 'BEGIN_URLS' === substr( $line, 0, 10 ) ) {
				$in_section = true;
				continue;
			}

			if ( 'END_URLS' === $line ) {
				break;
			}

			if ( ! $in_section || '' === $line || '#' === $line[0] ) {
				continue;
			}

			$parts = preg_split( '/\s+/', $line );
			if ( ! isset( $parts[2] ) ) {
				continue;
			}

			$uri  = $parts[0];
			$hits = (int) $parts[2];

			if ( $hits < 1 || '' === $uri ) {
				continue;
			}

			$ext = strtolower( pathinfo( $uri, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, $static_ext, true ) ) {
				continue;
			}

			$result[ $uri ] = isset( $result[ $uri ] ) ? $result[ $uri ] + $hits : $hits;
		}

		fclose( $handle );

		return $result;
	}

	/**
	 * Read own hit counters from wp_options and clear the accumulator.
	 *
	 * @return array<string,int>  uri → hits
	 */
	private static function read_own_counters(): array {
		$raw     = get_option( self::OPT_HIT_WINDOW, '' );
		$current = ( $raw && is_string( $raw ) ) ? json_decode( $raw, true ) : array();

		if ( ! is_array( $current ) || empty( $current ) ) {
			return array();
		}

		$result = array();
		foreach ( $current as $entry ) {
			if ( empty( $entry['uri'] ) || ! isset( $entry['hits'] ) ) {
				continue;
			}
			$uri            = $entry['uri'];
			$result[ $uri ] = ( isset( $result[ $uri ] ) ? $result[ $uri ] : 0 ) + (int) $entry['hits'];
		}

		// Clear after reading so we don't double-count on next harvest.
		update_option( self::OPT_HIT_WINDOW, '{}', false );

		return $result;
	}

	// ── DB persistence ────────────────────────────────────────────────────────

	/**
	 * Upsert traffic data to MySQL.
	 *
	 * @param array<string,int> $data  uri → hits
	 */
	private static function store_to_db( array $data ): void {
		global $wpdb;

		$db_table     = $wpdb->prefix . 'pigcache_url_traffic';
		$period_start = gmdate( 'Y-m-d H:i:s', (int) floor( time() / 86400 ) * 86400 );

		foreach ( array_chunk( $data, 100, true ) as $chunk ) {
			foreach ( $chunk as $uri => $hits ) {
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO `{$db_table}` (url_hash, url, hit_count, period_start)
					 VALUES (%s, %s, %d, %s)
					 ON DUPLICATE KEY UPDATE hit_count = hit_count + VALUES(hit_count)",
					md5( $uri ),
					substr( (string) $uri, 0, 2083 ),
					(int) $hits,
					$period_start
				) );
			}
		}

		// Prune data older than 30 days.
		$wpdb->query( "DELETE FROM `{$db_table}` WHERE period_start < DATE_SUB(NOW(), INTERVAL 30 DAY)" ); // phpcs:ignore
	}

	/**
	 * Rebuild traffic_map transient from MySQL (top 1000 URLs by rolling 30-day hits).
	 */
	private static function rebuild_transient(): void {
		global $wpdb;

		$db_table = $wpdb->prefix . 'pigcache_url_traffic';

		$rows = $wpdb->get_results( // phpcs:ignore
			"SELECT url, SUM(hit_count) AS total_hits
			   FROM `{$db_table}`
			  WHERE period_start >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			  GROUP BY url
			  ORDER BY total_hits DESC
			  LIMIT 1000",
			ARRAY_A
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ $row['url'] ] = (int) $row['total_hits'];
			}
		}

		set_transient( self::TRANSIENT_MAP, $map, self::TRANSIENT_TTL );
	}

	// ── Public accessors ──────────────────────────────────────────────────────

	/**
	 * Get hits for a URI from the traffic map.
	 * Returns -1 if no traffic data is available yet (cron has not run).
	 *
	 * @param string $uri  e.g. "/about/"
	 * @return int
	 */
	public static function get_uri_hits( string $uri ): int {
		$map = get_transient( self::TRANSIENT_MAP );

		if ( ! is_array( $map ) ) {
			return -1;
		}

		return isset( $map[ $uri ] ) ? (int) $map[ $uri ] : 0;
	}

	/**
	 * @return array<string,int>  uri → hits
	 */
	public static function get_traffic_map(): array {
		$map = get_transient( self::TRANSIENT_MAP );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Detect which traffic source is available.
	 *
	 * @return string 'awstats' | 'self'
	 */
	public static function detect_source(): string {
		if ( defined( 'PIGCACHE_TRAFFIC_SOURCE' ) ) {
			$src = PIGCACHE_TRAFFIC_SOURCE;
			if ( in_array( $src, array( 'awstats', 'self' ), true ) ) {
				return $src;
			}
		}

		$dir = self::awstats_dir();
		if ( $dir && is_dir( $dir ) && ! empty( glob( $dir . '/awstats*.txt' ) ) ) {
			return 'awstats';
		}

		return 'self';
	}

	/**
	 * Resolve the AWStats directory (auto-detect or constant override).
	 *
	 * @return string  Absolute path, or empty string if not found.
	 */
	public static function awstats_dir(): string {
		if ( defined( 'PIGCACHE_AWSTATS_DIR' ) && PIGCACHE_AWSTATS_DIR ) {
			return rtrim( (string) PIGCACHE_AWSTATS_DIR, '/\\' );
		}

		$candidates = array();

		if ( defined( 'ABSPATH' ) ) {
			$base         = rtrim( ABSPATH, '/\\' );
			$candidates[] = dirname( dirname( $base ) ) . '/tmp/awstats';
			$candidates[] = dirname( $base ) . '/tmp/awstats';
			$candidates[] = $base . '/tmp/awstats';
		}

		$candidates[] = '/tmp/awstats';

		foreach ( $candidates as $path ) {
			if ( is_dir( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Human-readable label for the last used source.
	 *
	 * @return string
	 */
	public static function last_source_label(): string {
		$src = (string) PigCache_KV::get( PigCache_KV::KEY_CRON_TRAFFIC_SOURCE_USED, '' );
		if ( 'awstats' === $src ) {
			return __( 'AWStats (cPanel)', 'pigcache' );
		}
		if ( 'self' === $src ) {
			return __( 'Own hit counters (fallback)', 'pigcache' );
		}
		return __( 'Not yet harvested', 'pigcache' );
	}
}
