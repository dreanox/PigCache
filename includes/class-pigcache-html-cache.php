<?php
/**
 * Full-page HTML cache via output buffering and wp_cache_*.
 *
 * Uses URI-based keys with tag-based selective invalidation. Pages are only
 * purged when a specific tagged object changes (post, term, etc.), not globally.
 *
 * Stampede protection is retained: on cache MISS a lock prevents multiple
 * processes from regenerating the same page simultaneously.
 *
 * Pack format (`$pack`) stored in Redis under group `pigcache_html`:
 *
 *   array(
 *       'v'       => int   pack schema version (see PACK_VERSION),
 *       'html'    => string raw HTML body,
 *       'html_gz' => string gzencoded HTML for clients that send `Accept-Encoding: gzip`,
 *       'status'  => int   HTTP response status code captured at render time,
 *       'ctype'   => string Content-Type header captured at render time,
 *       'tags'    => array tag set (Pro tag-based invalidation),
 *       'ttl'     => int   TTL the entry was stored with (seconds),
 *       'time'    => int   unix timestamp when stored,
 *   )
 *
 * Older packs (pre-v2) only have html/tags/time; the serve path handles both.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Html_Cache {

	const GROUP_HTML   = 'pigcache_html';
	const GROUP_META   = 'pigcache';
	const PACK_VERSION = 2;

	/**
	 * Cookies whose presence indicates personalised content. Matched as a
	 * "starts with" against $_COOKIE keys. Filterable via
	 * `pigcache_personalisation_cookie_prefixes`.
	 */
	private static $personalisation_cookie_prefixes = array(
		'wordpress_logged_in_',     // logged-in WordPress user.
		'comment_author_',          // saved commenter identity.
		'wp-postpass_',             // password-protected post.
		'wp-resetpass-',            // password-reset flow.
		'wp_woocommerce_session_',  // WooCommerce session.
		'woocommerce_cart_hash',    // WC cart contents hash.
		'woocommerce_items_in_cart',// WC cart presence.
		'edd_items_in_cart',        // Easy Digital Downloads.
		'mp_session_',              // MemberPress.
		'_wp_session_',             // WP Session Manager.
	);

	/**
	 * Pure-tracking query params that do NOT affect rendered HTML, stripped
	 * from the cache key so we don't store one entry per Facebook click ID.
	 * Filterable via `pigcache_tracking_query_params`.
	 */
	private static $tracking_query_params = array(
		'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'yclid', 'dclid',
		'mc_cid', 'mc_eid',
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
		'utm_id', 'utm_name',
		'_ga', '_gl', '_kx', '_branch_match_id',
		'fb_action_ids', 'fb_action_types', 'fb_source',
	);

	/** True while our ob_start() buffer is open and not yet flushed. */
	private static bool $buffering = false;

	/** Bootstrap hooks. */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_buffer' ), 0 );
	}

	/**
	 * Called from advanced-cache.php BEFORE WordPress finishes booting.
	 *
	 * Uses only $_SERVER / $_COOKIE — no WP functions available yet, except
	 * `wp_cache_get` because advanced-cache.php loads object-cache.php before
	 * us. Serves a cached response and exits, or returns if no cache hit.
	 */
	public static function serve_early() {
		// Object cache must be loaded (advanced-cache.php loads it for us).
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return;
		}

		// Only GET requests.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] )
			|| 'GET' !== strtoupper( (string) filter_var( $_SERVER['REQUEST_METHOD'], FILTER_SANITIZE_SPECIAL_CHARS ) ) ) {
			return;
		}

		// Form submissions or anything carrying POST body data.
		if ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// Any cookie that marks the request as personalised.
		if ( self::has_personalisation_cookie_early() ) {
			return;
		}

		$uri_raw = isset( $_SERVER['REQUEST_URI'] )
			? (string) filter_var( stripslashes( $_SERVER['REQUEST_URI'] ), FILTER_SANITIZE_URL )
			: '/';

		// Never serve admin / login from cache.
		if ( false !== strpos( $uri_raw, '/wp-admin/' ) || false !== strpos( $uri_raw, '/wp-login.php' ) ) {
			return;
		}

		$host = self::canonical_host_early();
		if ( '' === $host ) {
			return;
		}

		$uri = self::canonical_uri_early( $uri_raw );
		$key = self::build_key( $host, $uri );

		$pack = wp_cache_get( $key, self::GROUP_HTML );
		if ( ! is_array( $pack ) || empty( $pack['html'] ) ) {
			return;
		}

		self::serve_pack( $pack, 'EARLY' );
	}

	/**
	 * Start output buffer or serve cached page.
	 *
	 * Flow:
	 *  1. HIT  -> echo + exit.
	 *  2. MISS -> brief retry (lock contention), then acquire stampede lock,
	 *             start OB + tag collector.
	 */
	public static function maybe_start_buffer() {
		if ( ! self::should_cache_request() ) {
			return;
		}

		if ( ! wp_using_ext_object_cache() ) {
			return;
		}

		$pack = self::get_pack();

		if ( is_array( $pack ) && ! empty( $pack['html'] ) ) {
			// ── PRO_START ─────────────────────────────────────────────────────────────────
			// Record this HIT for Adaptive TTL traffic tracking (own-counter fallback).
			if ( class_exists( 'PigCache_Traffic_Reader', false ) ) {
				PigCache_Traffic_Reader::record_hit( self::cache_key(), self::request_uri() );
			}
			// ── PRO_END ───────────────────────────────────────────────────────────────────

			self::serve_pack( $pack, 'LATE' );
		}

		$lock_key = self::lock_key();
		$lock_ttl = (int) apply_filters( 'pigcache_lock_ttl_seconds', 10 );
		$lock_ttl = max( 2, min( 60, $lock_ttl ) );
		$acquired = wp_cache_add( $lock_key, 1, self::GROUP_META, $lock_ttl );

		if ( ! $acquired ) {
			// Another worker is regenerating. Wait briefly then re-check; very
			// often the other process just finished and we can serve fresh
			// HTML without joining the thundering herd.
			$wait_us = (int) apply_filters( 'pigcache_lock_wait_microseconds', 50000 ); // 50ms.
			$wait_us = max( 0, min( 250000, $wait_us ) );
			if ( $wait_us > 0 ) {
				usleep( $wait_us );
				$pack = self::get_pack();
				if ( is_array( $pack ) && ! empty( $pack['html'] ) ) {
					self::serve_pack( $pack, 'LATE-RETRY' );
				}
			}
			return;
		}

		// ── PRO_START ─────────────────────────────────────────────────────────────────
		$tag_inv = class_exists( 'PigCache_Tag_Index', false );

		if ( $tag_inv && class_exists( 'PigCache_Tag_Collector', false ) ) {
			PigCache_Tag_Collector::start();
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────

		ob_start( array( __CLASS__, 'ob_callback' ) );
		self::$buffering = true;
		add_action( 'shutdown', array( __CLASS__, 'end_buffer' ), 0 );
	}

	/**
	 * Explicitly close the output buffer at WordPress shutdown.
	 * Paired with the ob_start() in maybe_start_buffer() to satisfy
	 * WordPress.org's requirement that every ob_start() has an explicit close.
	 */
	public static function end_buffer(): void {
		if ( self::$buffering && ob_get_level() > 0 ) {
			ob_end_flush();
		}
	}

	/**
	 * @param string $html Captured output buffer.
	 * @return string
	 */
	public static function ob_callback( $html ) {
		self::$buffering = false;

		if ( '' === $html ) {
			self::release_lock();
			return $html;
		}

		// Refuse to cache pages where a fatal error tripped mid-render. Caching
		// half-rendered HTML would otherwise pin a broken page until the TTL
		// expires.
		$last = error_get_last();
		if ( is_array( $last ) && in_array(
			(int) $last['type'],
			array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ),
			true
		) ) {
			self::release_lock();
			return $html;
		}

		// Refuse to cache 5xx (and optionally other) responses.
		$status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		if ( $status < 1 || $status > 599 ) {
			$status = 200;
		}
		if ( $status >= 500 ) {
			self::release_lock();
			return $html;
		}

		$tags = array();
		// ── PRO_START ─────────────────────────────────────────────────────────────────
		if ( class_exists( 'PigCache_Tag_Collector', false ) && PigCache_Tag_Collector::is_active() ) {
			$tags = PigCache_Tag_Collector::stop();
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────

		$base = class_exists( 'PigCache_Config', false )
			? PigCache_Config::get_html_cache_ttl()
			: 60;

		$ttl = (int) apply_filters( 'pigcache_html_ttl', $base, self::request_uri() );
		if ( $ttl < 1 ) {
			$ttl = $base > 0 ? $base : 60;
		}

		// ── PRO_START ─────────────────────────────────────────────────────────────────
		// Adaptive TTL v2 — overrides static TTL when enabled and data is available.
		if ( class_exists( 'PigCache_Adaptive_Ttl', false ) ) {
			$adaptive = PigCache_Adaptive_Ttl::decide( self::request_uri(), $tags );

			if ( 0 === $adaptive ) {
				// ❄️ Cold — skip caching entirely.
				self::log_tier_decision( self::request_uri(), 0, $tags );
				self::release_lock();
				return $html;
			}

			if ( $adaptive > 0 ) {
				// Hot + Stable or Hot + Dynamic tier.
				$ttl = $adaptive;
				self::log_tier_decision( self::request_uri(), $adaptive, $tags );
			}
			// $adaptive === -1 → no data yet or disabled → keep static $ttl.
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────

		// Capture the upstream Content-Type so the serve path can re-send it
		// verbatim (RSS feeds, XML sitemaps, etc.).
		$ctype = 'text/html; charset=UTF-8';
		if ( function_exists( 'headers_list' ) ) {
			foreach ( headers_list() as $h ) {
				if ( 0 === stripos( $h, 'content-type:' ) ) {
					$ctype = trim( substr( $h, 13 ) );
					break;
				}
			}
		}

		// Pre-encode a gzip body so HIT serves can skip the runtime gzip cost
		// AND we use ~5x less Redis memory for cacheable HTML pages.
		$html_gz = '';
		if ( function_exists( 'gzencode' ) ) {
			$level   = (int) apply_filters( 'pigcache_gzip_level', 6 );
			$encoded = gzencode( $html, max( 1, min( 9, $level ) ) );
			if ( false !== $encoded ) {
				$html_gz = $encoded;
			}
		}

		$pack = array(
			'v'       => self::PACK_VERSION,
			'html'    => $html,
			'html_gz' => $html_gz,
			'status'  => $status,
			'ctype'   => $ctype,
			'tags'    => $tags,
			'ttl'     => $ttl,
			'time'    => time(),
		);

		$key = self::cache_key();
		wp_cache_set( $key, $pack, self::GROUP_HTML, $ttl );

		// ── PRO_START ─────────────────────────────────────────────────────────────────
		$tag_inv = class_exists( 'PigCache_Tag_Index', false );

		if ( $tag_inv && ! empty( $tags ) ) {
			PigCache_Tag_Index::store_tags( $key, self::GROUP_HTML, $tags );
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────

		self::release_lock();

		return $html;
	}

	/**
	 * @return array|false Raw pack from Redis.
	 */
	public static function get_pack() {
		$pack = wp_cache_get( self::cache_key(), self::GROUP_HTML );

		if ( ! is_array( $pack ) || empty( $pack['html'] ) ) {
			return false;
		}

		return $pack;
	}

	/**
	 * Back-compat helper: returns HTML string or false.
	 *
	 * @return string|false
	 */
	public static function get_cached_html() {
		$pack = self::get_pack();

		if ( ! is_array( $pack ) ) {
			return false;
		}

		return $pack['html'];
	}

	// ── Cache key derivation ───────────────────────────────────────────────

	/**
	 * @return string
	 */
	public static function cache_key() {
		return self::build_key( self::host_key(), self::canonical_uri( self::request_uri() ) );
	}

	private static function build_key( string $host, string $uri ): string {
		return 'doc_' . md5( $host . '|' . $uri );
	}

	private static function lock_key(): string {
		return 'pigcache_lock_' . md5( self::host_key() . '|' . self::canonical_uri( self::request_uri() ) );
	}

	/**
	 * Late-path host resolver — runs once WP is loaded so we can use site_url().
	 *
	 * @return string Hostname used to namespace cache keys across sites.
	 */
	private static function host_key() {
		$http_host = isset( $_SERVER['HTTP_HOST'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) )
			: '';

		$canonical = '';
		if ( function_exists( 'site_url' ) ) {
			$canonical = (string) wp_parse_url( site_url(), PHP_URL_HOST );
		}

		// Trust the WordPress-configured host first to avoid cache-key poisoning
		// via spoofed Host headers. Fall back to HTTP_HOST when WP has none
		// (which would be rare).
		if ( $canonical !== '' ) {
			// Honour multisite domain mapping: accept HTTP_HOST when it is a
			// known site in the network.
			if ( $http_host === $canonical ) {
				return $canonical;
			}
			if ( function_exists( 'is_multisite' ) && is_multisite()
				&& function_exists( 'get_sites' ) && $http_host !== '' ) {
				$matches = get_sites( array( 'domain' => $http_host, 'fields' => 'ids', 'number' => 1 ) );
				if ( ! empty( $matches ) ) {
					return $http_host;
				}
			}
			return $canonical;
		}

		return $http_host;
	}

	/**
	 * Early-path host resolver — runs before WP functions are available, so
	 * we rely on SERVER_NAME (set by the webserver, not the client) when it
	 * matches HTTP_HOST. Otherwise we reject the request (returning '' so
	 * serve_early() bails) to avoid poisoning the cache via spoofed Host.
	 */
	private static function canonical_host_early(): string {
		$http_host   = isset( $_SERVER['HTTP_HOST'] )
			? strtolower( (string) filter_var( stripslashes( $_SERVER['HTTP_HOST'] ), FILTER_SANITIZE_URL ) )
			: '';
		$server_name = isset( $_SERVER['SERVER_NAME'] )
			? strtolower( (string) filter_var( $_SERVER['SERVER_NAME'], FILTER_SANITIZE_URL ) )
			: '';

		// Allow callers to define a whitelist of hosts via a constant. When
		// set, only requests whose Host matches one of these will be served
		// from the early cache path.
		if ( defined( 'PIGCACHE_ALLOWED_HOSTS' ) ) {
			$allowed = (array) PIGCACHE_ALLOWED_HOSTS;
			if ( $http_host !== '' && in_array( $http_host, $allowed, true ) ) {
				return $http_host;
			}
			return '';
		}

		// Fast path: HTTP_HOST matches the webserver-configured SERVER_NAME.
		if ( '' !== $server_name && $server_name === $http_host ) {
			return $http_host;
		}

		// Sensible default for typical cPanel / shared-hosting setups: trust
		// SERVER_NAME (cannot be spoofed by clients) and skip serve_early
		// otherwise. The late path (maybe_start_buffer) will still serve the
		// page using site_url() — at a higher latency, but safely.
		if ( '' !== $server_name ) {
			// Returning the SERVER_NAME here would let us serve, but the cache
			// was written by the late path under the site_url() host, so the
			// keys would not match. Return '' to consistently route through the
			// late path.
			return '';
		}

		// No SERVER_NAME at all (very rare) — return '' to bail safely.
		return '';
	}

	// ── URI canonicalisation ───────────────────────────────────────────────

	/**
	 * @return string
	 */
	private static function request_uri() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}

	/**
	 * Late-path URI canonicalisation — runs after WP is loaded so it can
	 * call apply_filters().
	 */
	private static function canonical_uri( string $uri ): string {
		$strip = (array) apply_filters(
			'pigcache_tracking_query_params',
			self::$tracking_query_params
		);
		return self::strip_query_params( $uri, $strip );
	}

	/**
	 * Early-path URI canonicalisation — same as canonical_uri() but without
	 * the filter call (filters are not available before plugins load).
	 */
	private static function canonical_uri_early( string $uri ): string {
		return self::strip_query_params( $uri, self::$tracking_query_params );
	}

	private static function strip_query_params( string $uri, array $strip ): string {
		$qpos = strpos( $uri, '?' );
		if ( false === $qpos ) {
			return $uri;
		}

		$path  = substr( $uri, 0, $qpos );
		$query = substr( $uri, $qpos + 1 );

		if ( '' === $query ) {
			return $path;
		}

		parse_str( $query, $params );
		if ( ! is_array( $params ) || empty( $params ) ) {
			return $path;
		}

		foreach ( $strip as $param ) {
			unset( $params[ $param ] );
		}

		if ( empty( $params ) ) {
			return $path;
		}

		ksort( $params );
		return $path . '?' . http_build_query( $params );
	}

	// ── Personalisation cookie detection ───────────────────────────────────

	private static function has_personalisation_cookie_early(): bool {
		if ( empty( $_COOKIE ) ) {
			return false;
		}
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			$name = (string) $cookie_name;
			foreach ( self::$personalisation_cookie_prefixes as $prefix ) {
				if ( 0 === strncmp( $name, $prefix, strlen( $prefix ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function has_personalisation_cookie_late(): bool {
		if ( empty( $_COOKIE ) ) {
			return false;
		}
		$prefixes = (array) apply_filters(
			'pigcache_personalisation_cookie_prefixes',
			self::$personalisation_cookie_prefixes
		);
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			$name = (string) $cookie_name;
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strncmp( $name, (string) $prefix, strlen( (string) $prefix ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	// ── Serve helpers ──────────────────────────────────────────────────────

	/**
	 * Build the HTTP response for a cached pack — pure function, returns
	 * an array of (status, headers, body). Separated from serve_pack() so
	 * the response can be unit-tested without side effects.
	 *
	 * Handles backward compatibility with v1 packs (no status/ctype/html_gz).
	 *
	 * @param array       $pack            Cached payload.
	 * @param string      $hit_kind        "EARLY" | "LATE" | "LATE-RETRY".
	 * @param string|null $accept_encoding Optional override (defaults to $_SERVER).
	 * @param int|null    $now             Optional time override for tests.
	 * @return array{status:int,headers:string[],body:string}
	 */
	public static function build_response( array $pack, string $hit_kind = 'HIT',
		?string $accept_encoding = null, ?int $now = null ): array {

		if ( null === $accept_encoding ) {
			$accept_encoding = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
				? (string) $_SERVER['HTTP_ACCEPT_ENCODING']
				: '';
		}
		if ( null === $now ) {
			$now = time();
		}

		$status = isset( $pack['status'] ) ? (int) $pack['status'] : 200;
		if ( $status < 100 || $status > 599 ) {
			$status = 200;
		}
		$ctype = isset( $pack['ctype'] ) && '' !== $pack['ctype']
			? (string) $pack['ctype']
			: 'text/html; charset=UTF-8';
		$ttl   = isset( $pack['ttl'] ) ? (int) $pack['ttl'] : 0;
		$age   = isset( $pack['time'] ) ? max( 0, $now - (int) $pack['time'] ) : 0;
		$rem   = $ttl > 0 ? max( 0, $ttl - $age ) : 0;

		$gzip_ok = ! empty( $pack['html_gz'] )
			&& false !== stripos( $accept_encoding, 'gzip' );

		$headers = array(
			'Content-Type: ' . $ctype,
			'X-PigCache: HIT',
			'X-PigCache-Kind: ' . $hit_kind,
			'X-PigCache-Age: ' . $age,
		);
		if ( $ttl > 0 ) {
			$headers[] = 'X-PigCache-TTL: ' . $ttl;
			$headers[] = 'Cache-Control: public, max-age=' . $rem . ', stale-while-revalidate=60';
			$headers[] = 'CDN-Cache-Control: public, max-age=' . $rem;
		}
		$headers[] = 'Vary: Accept-Encoding, Cookie';

		if ( $gzip_ok ) {
			$body = (string) $pack['html_gz'];
			$headers[] = 'Content-Encoding: gzip';
		} else {
			$body = (string) $pack['html'];
		}
		$headers[] = 'Content-Length: ' . strlen( $body );

		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * Emit headers + body for a cached pack and exit.
	 *
	 * @param array  $pack     Cached payload.
	 * @param string $hit_kind "EARLY" | "LATE" | "LATE-RETRY".
	 */
	private static function serve_pack( array $pack, string $hit_kind = 'HIT' ): void {
		$resp = self::build_response( $pack, $hit_kind );

		if ( ! headers_sent() ) {
			http_response_code( $resp['status'] );
			foreach ( $resp['headers'] as $h ) {
				header( $h );
			}
		}

		echo $resp['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Flush before exit so the client gets the bytes even if a shutdown
		// callback elsewhere is slow.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		exit;
	}

	// ── Gating ─────────────────────────────────────────────────────────────

	/**
	 * @return bool
	 */
	public static function should_cache_request() {
		if ( is_user_logged_in() ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( isset( $_POST ) && ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		if ( is_preview() ) {
			return false;
		}

		if ( self::has_personalisation_cookie_late() ) {
			return false;
		}

		if ( self::is_woocommerce_sensitive() ) {
			return false;
		}

		if ( apply_filters( 'pigcache_skip_html_cache', false ) ) {
			return false;
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] )
			|| 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @return bool
	 */
	private static function is_woocommerce_sensitive() {
		if ( ! function_exists( 'is_cart' ) ) {
			return false;
		}

		if ( is_cart() || is_checkout() ) {
			return true;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		return false;
	}

	// ── Lock release ───────────────────────────────────────────────────────

	private static function release_lock() {
		wp_cache_delete( self::lock_key(), self::GROUP_META );
	}

	// ── Invalidation ───────────────────────────────────────────────────────

	/**
	 * Flush the entire HTML cache group (global invalidation for Free tier).
	 */
	public static function flush_all() {
		wp_cache_flush_group( self::GROUP_HTML );
	}

	// ── PRO_START ─────────────────────────────────────────────────────────────────
	/**
	 * Record the adaptive TTL tier decision for a URI in wp_options.
	 * The cron harvests this log and reports it to the PigCache API.
	 * Deduplicates by URI and throttles to one write per URI per hour.
	 *
	 * @param string   $uri  Request URI.
	 * @param int      $ttl  Assigned TTL (0 = cold, >0 = hot tier).
	 * @param string[] $tags Tags collected during render.
	 */
	private static function log_tier_decision( string $uri, int $ttl, array $tags ): void {
		if ( ! class_exists( 'PigCache_Adaptive_Ttl', false ) ) {
			return;
		}

		$tier = 'cold';
		if ( $ttl > 0 ) {
			$tier = $ttl >= PigCache_Adaptive_Ttl::ttl_hot_stable() ? 'hot_stable' : 'hot_dynamic';
		}

		$log = get_option( 'pigcache_html_tier_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$key = md5( $uri );

		// Throttle: skip if same URI was logged in the last hour.
		if ( isset( $log[ $key ] ) && ( time() - $log[ $key ]['time'] ) < 3600 ) {
			return;
		}

		$log[ $key ] = array(
			'uri'  => $uri,
			'tier' => $tier,
			'ttl'  => $ttl,
			'time' => time(),
		);

		// Keep the 500 most recently seen URIs.
		if ( count( $log ) > 500 ) {
			uasort( $log, static function ( $a, $b ) { return $a['time'] - $b['time']; } );
			$log = array_slice( $log, -500, null, true );
		}

		update_option( 'pigcache_html_tier_log', $log, false );
	}
	// ── PRO_END ───────────────────────────────────────────────────────────────────
}
