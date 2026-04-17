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
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Html_Cache {

	const GROUP_HTML = 'pigcache_html';
	const GROUP_META = 'pigcache';

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_buffer' ), 0 );
	}

	/**
	 * Start output buffer or serve cached page.
	 *
	 * Flow:
	 *  1. HIT  -> echo + exit.
	 *  2. MISS -> acquire stampede lock, start OB + tag collector.
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
			echo $pack['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		$lock_key = 'pigcache_lock_' . md5( self::host_key() . self::request_uri() );
		$acquired = wp_cache_add( $lock_key, 1, self::GROUP_META, 30 );

		if ( ! $acquired ) {
			return;
		}

		if ( class_exists( 'PigCache_Tag_Collector', false ) ) {
			PigCache_Tag_Collector::start();
		}

		ob_start( array( __CLASS__, 'ob_callback' ) );
	}

	/**
	 * @param string $html Captured output buffer.
	 * @return string
	 */
	public static function ob_callback( $html ) {
		if ( $html === '' ) {
			self::release_lock();
			return $html;
		}

		$tags = array();
		if ( class_exists( 'PigCache_Tag_Collector', false ) && PigCache_Tag_Collector::is_active() ) {
			$tags = PigCache_Tag_Collector::stop();
		}

		$base = class_exists( 'PigCache_Config', false )
			? PigCache_Config::get_html_cache_ttl()
			: 60;

		$ttl = (int) apply_filters( 'pigcache_html_ttl', $base, self::request_uri() );
		if ( $ttl < 1 ) {
			$ttl = $base > 0 ? $base : 60;
		}

		$key = self::cache_key();

		$pack = array(
			'html' => $html,
			'tags' => $tags,
			'time' => time(),
		);

		wp_cache_set( $key, $pack, self::GROUP_HTML, $ttl );

		if ( class_exists( 'PigCache_Tag_Index', false ) && ! empty( $tags ) ) {
			PigCache_Tag_Index::store_tags( $key, self::GROUP_HTML, $tags );
		}

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

	/**
	 * Host + URI cache key. Includes the hostname so multiple sites sharing
	 * the same Redis instance (even on the same logical DB) never collide.
	 *
	 * @return string
	 */
	public static function cache_key() {
		return 'doc_' . md5( self::host_key() . self::request_uri() );
	}

	/**
	 * @return string Hostname used to namespace cache keys across sites.
	 */
	private static function host_key() {
		if ( isset( $_SERVER['HTTP_HOST'] ) && $_SERVER['HTTP_HOST'] !== '' ) {
			return strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) );
		}

		if ( function_exists( 'site_url' ) ) {
			return (string) wp_parse_url( site_url(), PHP_URL_HOST );
		}

		return '';
	}

	/**
	 * Release the stampede lock for the current URI.
	 */
	private static function release_lock() {
		wp_cache_delete( 'pigcache_lock_' . md5( self::host_key() . self::request_uri() ), self::GROUP_META );
	}

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

		if ( self::is_woocommerce_sensitive() ) {
			return false;
		}

		if ( apply_filters( 'pigcache_skip_html_cache', false ) ) {
			return false;
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) !== 'GET' ) {
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

	/**
	 * @return string
	 */
	private static function request_uri() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		return (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	}
}
