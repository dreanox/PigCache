<?php
/**
 * Adaptive TTL v2 — HTML cache TTL decision engine.
 *
 * Crosses two signals to classify every cached page into one of three tiers:
 *
 *  🔥 Hot + Stable   → TTL high (default 86400 s = 1 day)
 *  ⚡ Hot + Dynamic  → TTL low  (default 60 s)
 *  ❄️ Cold           → TTL 0   (skip cache entirely)
 *
 * Signal 1 — Traffic (hot vs cold)
 *   Sourced from AWStats data files or own hit counters.
 *   Managed by PigCache_Traffic_Reader.
 *
 * Signal 2 — Stability (stable vs dynamic)
 *   Derived from per-table mutation frequency tracked by PigCache_Mutation_Tracker.
 *   Tags collected during render are mapped to the MySQL tables they depend on.
 *
 * Applies only to the HTML cache. SQL cache uses epoch invalidation.
 * Object cache is not controlled by PigCache.
 *
 * Constants (all optional, override defaults):
 *   PIGCACHE_ADAPTIVE_TTL               bool    Enable/disable (default off)
 *   PIGCACHE_TTL_HOT_STABLE             int     Seconds (default 86400)
 *   PIGCACHE_TTL_HOT_DYNAMIC            int     Seconds (default 60)
 *   PIGCACHE_TTL_COLD                   int     Seconds, 0 = skip (default 0)
 *   PIGCACHE_TRAFFIC_COLD_THRESHOLD     int     Hits/30d below = Cold (default 100)
 *   PIGCACHE_MUTATION_DYNAMIC_THRESHOLD int     Mutations/day above = Dynamic (default 10)
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Adaptive_Ttl {

	const DEFAULT_TTL_HOT_STABLE  = 86400;
	const DEFAULT_TTL_HOT_DYNAMIC = 60;
	const DEFAULT_TTL_COLD        = 0;
	const DEFAULT_COLD_THRESHOLD  = 100;
	const DEFAULT_DYN_THRESHOLD   = 10;

	/**
	 * Maps tag prefixes to the MySQL table suffixes they depend on.
	 * Suffixes are joined with the actual $wpdb->prefix at runtime.
	 *
	 * @var array<string,string[]>
	 */
	private static $tag_table_suffixes = array(
		'post'      => array( 'posts', 'postmeta' ),
		'post_type' => array( 'posts' ),
		'author'    => array( 'users', 'posts' ),
		'term'      => array( 'terms', 'termmeta', 'term_relationships' ),
		'taxonomy'  => array( 'terms', 'term_relationships' ),
		'nav_menu'  => array( 'posts', 'term_relationships' ),
		'sidebar'   => array( 'posts' ),
		'home'      => array( 'posts', 'postmeta', 'options' ),
		'feed'      => array( 'posts' ),
		'zone'      => array(),
	);

	// ── Enable / disable ──────────────────────────────────────────────────────

	/**
	 * Whether the Adaptive TTL v2 system is enabled.
	 *
	 * Constant takes precedence; falls back to wp_options toggle set from the admin.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( defined( 'PIGCACHE_ADAPTIVE_TTL' ) ) {
			return (bool) PIGCACHE_ADAPTIVE_TTL;
		}

		return (bool) get_option( 'pigcache_adaptive_ttl_enabled', false );
	}

	// ── Decision engine ───────────────────────────────────────────────────────

	/**
	 * Decide the TTL for an HTML cache entry.
	 *
	 * Returns -1 when the system is disabled or has no data yet — the
	 * caller should fall back to the configured static TTL.
	 * Returns 0 when the page should not be cached (Cold tier).
	 *
	 * @param string   $uri  Request URI (e.g. "/about/").
	 * @param string[] $tags Tags collected during page render.
	 * @return int  TTL in seconds, or -1 (use static TTL), or 0 (skip cache).
	 */
	public static function decide( string $uri, array $tags ): int {
		if ( ! self::is_enabled() ) {
			return -1;
		}

		if (
			! class_exists( 'PigCache_Traffic_Reader', false ) ||
			! class_exists( 'PigCache_Mutation_Tracker', false )
		) {
			return -1;
		}

		// ── Signal 1: Traffic ────────────────────────────────────────────────

		$hits = PigCache_Traffic_Reader::get_uri_hits( $uri );

		// -1 = traffic map not yet populated (cron has not run).
		// Don't block caching — use static TTL.
		if ( $hits < 0 ) {
			return -1;
		}

		$cold_threshold = defined( 'PIGCACHE_TRAFFIC_COLD_THRESHOLD' )
			? (int) PIGCACHE_TRAFFIC_COLD_THRESHOLD
			: (int) get_option( 'pigcache_traffic_cold_threshold', self::DEFAULT_COLD_THRESHOLD );

		if ( $hits < $cold_threshold ) {
			return self::ttl_cold(); // ❄️ Cold
		}

		// ── Signal 2: Stability ──────────────────────────────────────────────

		$tables    = self::tags_to_tables( $tags );
		$stability = PigCache_Mutation_Tracker::get_stability_map();
		$max_muts  = 0;

		foreach ( $tables as $table ) {
			$muts     = isset( $stability[ $table ] ) ? (int) $stability[ $table ] : 0;
			$max_muts = max( $max_muts, $muts );
		}

		$dyn_threshold = defined( 'PIGCACHE_MUTATION_DYNAMIC_THRESHOLD' )
			? (int) PIGCACHE_MUTATION_DYNAMIC_THRESHOLD
			: (int) get_option( 'pigcache_mutation_dynamic_threshold', self::DEFAULT_DYN_THRESHOLD );

		if ( $max_muts > $dyn_threshold ) {
			return self::ttl_hot_dynamic(); // ⚡ Hot + Dynamic
		}

		return self::ttl_hot_stable(); // 🔥 Hot + Stable
	}

	// ── Classify (for admin UI) ───────────────────────────────────────────────

	/**
	 * Return a full classification for a URI, including raw signal values.
	 * Used by the admin to render the URL tier table.
	 *
	 * @param string   $uri
	 * @param string[] $tags
	 * @return array{tier:string, hits:int, max_mutations:int, ttl:int}
	 */
	public static function classify( string $uri, array $tags = array() ): array {
		$hits = class_exists( 'PigCache_Traffic_Reader', false )
			? PigCache_Traffic_Reader::get_uri_hits( $uri )
			: -1;

		$tables    = self::tags_to_tables( $tags );
		$stability = class_exists( 'PigCache_Mutation_Tracker', false )
			? PigCache_Mutation_Tracker::get_stability_map()
			: array();

		$max_muts = 0;
		foreach ( $tables as $t ) {
			$max_muts = max( $max_muts, (int) ( $stability[ $t ] ?? 0 ) );
		}

		$cold_threshold = defined( 'PIGCACHE_TRAFFIC_COLD_THRESHOLD' )
			? (int) PIGCACHE_TRAFFIC_COLD_THRESHOLD
			: (int) get_option( 'pigcache_traffic_cold_threshold', self::DEFAULT_COLD_THRESHOLD );

		$dyn_threshold = defined( 'PIGCACHE_MUTATION_DYNAMIC_THRESHOLD' )
			? (int) PIGCACHE_MUTATION_DYNAMIC_THRESHOLD
			: (int) get_option( 'pigcache_mutation_dynamic_threshold', self::DEFAULT_DYN_THRESHOLD );

		if ( $hits < 0 ) {
			$tier = 'unknown';
			$ttl  = -1;
		} elseif ( $hits < $cold_threshold ) {
			$tier = 'cold';
			$ttl  = self::ttl_cold();
		} elseif ( $max_muts > $dyn_threshold ) {
			$tier = 'hot_dynamic';
			$ttl  = self::ttl_hot_dynamic();
		} else {
			$tier = 'hot_stable';
			$ttl  = self::ttl_hot_stable();
		}

		return array(
			'tier'          => $tier,
			'hits'          => $hits,
			'max_mutations' => $max_muts,
			'ttl'           => $ttl,
		);
	}

	// ── Tag → table mapping ───────────────────────────────────────────────────

	/**
	 * Derive MySQL table names (with actual prefix) from page tags.
	 *
	 * @param string[] $tags
	 * @return string[]
	 */
	public static function tags_to_tables( array $tags ): array {
		global $wpdb;

		$suffixes = array();

		foreach ( $tags as $tag ) {
			$prefix = strpos( $tag, ':' ) !== false
				? explode( ':', $tag, 2 )[0]
				: $tag;

			if ( isset( self::$tag_table_suffixes[ $prefix ] ) ) {
				foreach ( self::$tag_table_suffixes[ $prefix ] as $suffix ) {
					$suffixes[ $suffix ] = true;
				}
			}
		}

		$tables = array();
		foreach ( array_keys( $suffixes ) as $suffix ) {
			$tables[] = $wpdb->prefix . $suffix;
		}

		return $tables;
	}

	// ── TTL accessors ─────────────────────────────────────────────────────────

	public static function ttl_hot_stable(): int {
		return defined( 'PIGCACHE_TTL_HOT_STABLE' )
			? (int) PIGCACHE_TTL_HOT_STABLE
			: (int) get_option( 'pigcache_ttl_hot_stable', self::DEFAULT_TTL_HOT_STABLE );
	}

	public static function ttl_hot_dynamic(): int {
		return defined( 'PIGCACHE_TTL_HOT_DYNAMIC' )
			? (int) PIGCACHE_TTL_HOT_DYNAMIC
			: (int) get_option( 'pigcache_ttl_hot_dynamic', self::DEFAULT_TTL_HOT_DYNAMIC );
	}

	public static function ttl_cold(): int {
		return defined( 'PIGCACHE_TTL_COLD' )
			? (int) PIGCACHE_TTL_COLD
			: self::DEFAULT_TTL_COLD;
	}

	/**
	 * Human-readable tier label.
	 *
	 * @param string $tier 'hot_stable' | 'hot_dynamic' | 'cold' | 'unknown'
	 * @return string
	 */
	public static function tier_label( string $tier ): string {
		$labels = array(
			'hot_stable'  => __( '🔥 Hot + Stable', 'pigcache' ),
			'hot_dynamic' => __( '⚡ Hot + Dynamic', 'pigcache' ),
			'cold'        => __( '❄️ Cold', 'pigcache' ),
			'unknown'     => __( '— No data yet', 'pigcache' ),
		);

		return isset( $labels[ $tier ] ) ? $labels[ $tier ] : $tier;
	}
}
