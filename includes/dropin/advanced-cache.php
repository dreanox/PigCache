<?php
/**
 * PigCache HTML Cache drop-in — do not edit by hand without a backup.
 * Installed and managed by the PigCache plugin.
 *
 * Version: 1.0.2
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

/*
 * This drop-in runs from wp-content/advanced-cache.php inside wp-settings.php,
 * BEFORE WordPress loads cache.php or wp-content/object-cache.php and BEFORE
 * wp_start_object_cache() is called. That means wp_cache_get() does not yet
 * exist by default — calling it from serve_early() would silently fail (the
 * guard returns immediately) and the whole "skip the WP bootstrap and serve
 * HTML in <5 ms" optimisation would never run.
 *
 * To make serve_early() actually serve, we must load the Redis-backed
 * object-cache drop-in HERE, ahead of wp_start_object_cache(). WordPress
 * tolerates this: wp_start_object_cache() detects that wp_cache_init() is
 * already defined and skips re-loading (the WP source even acknowledges:
 * "Sometimes advanced-cache.php can load object-cache.php before this
 * function is run.").
 *
 * We only do it when:
 *   - the object-cache drop-in file exists (i.e. PigCache's object cache is
 *     installed), AND
 *   - wp_cache_get is not already defined (paranoia; another caching plugin
 *     might have beaten us to it).
 */
if ( ! function_exists( 'wp_cache_get' )
	&& file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
	require_once WP_CONTENT_DIR . '/object-cache.php';
	if ( function_exists( 'wp_cache_init' ) ) {
		wp_cache_init();
	}
}

// plugin_dir_path() is unavailable here. WP_CONTENT_DIR is the only viable base.
// We try fixed paths first (standard + mu-plugins install), then fall back to glob()
// so custom plugin directory layouts are also supported.
$_pigcache_html_class = null;
$_pigcache_candidates = array(
	WP_CONTENT_DIR . '/plugins/pigcache/includes/class-pigcache-html-cache.php',
	WP_CONTENT_DIR . '/mu-plugins/pigcache/includes/class-pigcache-html-cache.php',
);
foreach ( $_pigcache_candidates as $_pigcache_path ) {
	if ( is_readable( $_pigcache_path ) ) {
		$_pigcache_html_class = $_pigcache_path;
		break;
	}
}
if ( null === $_pigcache_html_class && function_exists( 'glob' ) ) {
	$_pigcache_matches = glob( WP_CONTENT_DIR . '/plugins/*/includes/class-pigcache-html-cache.php' );
	if ( is_array( $_pigcache_matches ) ) {
		foreach ( $_pigcache_matches as $_pigcache_path ) {
			if ( is_readable( $_pigcache_path ) ) {
				$_pigcache_html_class = $_pigcache_path;
				break;
			}
		}
	}
}
unset( $_pigcache_candidates, $_pigcache_path, $_pigcache_matches );

if ( null !== $_pigcache_html_class ) {
	require_once $_pigcache_html_class;
	unset( $_pigcache_html_class );
	if ( class_exists( 'PigCache_Html_Cache', false ) ) {
		PigCache_Html_Cache::serve_early();
	}
}
