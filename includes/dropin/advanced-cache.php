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

/*
 * Resolving the plugin directory from a drop-in.
 *
 * plugin_dir_path() and plugins_url() do not exist yet: wp-settings.php includes
 * advanced-cache.php long before the plugin API is loaded. WP_PLUGIN_DIR and
 * WPMU_PLUGIN_DIR are also still undefined here, because core only defines them
 * later in wp_plugin_directory_constants(). So we use those constants when they
 * happen to be available (custom layouts, sunrise.php, mu-plugin bootstraps) and
 * only fall back to the core default under WP_CONTENT_DIR when they are not.
 * A glob() pass covers installs where the plugin folder was renamed.
 */
$_pigcache_html_class = null;
$_pigcache_roots      = array();

if ( defined( 'WP_PLUGIN_DIR' ) ) {
	$_pigcache_roots[] = WP_PLUGIN_DIR;
} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
	$_pigcache_roots[] = WP_CONTENT_DIR . '/plugins';
}

if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	$_pigcache_roots[] = WPMU_PLUGIN_DIR;
} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
	$_pigcache_roots[] = WP_CONTENT_DIR . '/mu-plugins';
}

foreach ( $_pigcache_roots as $_pigcache_root ) {
	$_pigcache_path = $_pigcache_root . '/pigcache/includes/class-pigcache-html-cache.php';
	if ( is_readable( $_pigcache_path ) ) {
		$_pigcache_html_class = $_pigcache_path;
		break;
	}
}

if ( null === $_pigcache_html_class && function_exists( 'glob' ) ) {
	foreach ( $_pigcache_roots as $_pigcache_root ) {
		$_pigcache_matches = glob( $_pigcache_root . '/*/includes/class-pigcache-html-cache.php' );
		if ( ! is_array( $_pigcache_matches ) ) {
			continue;
		}
		foreach ( $_pigcache_matches as $_pigcache_path ) {
			if ( is_readable( $_pigcache_path ) ) {
				$_pigcache_html_class = $_pigcache_path;
				break 2;
			}
		}
	}
}
unset( $_pigcache_roots, $_pigcache_root, $_pigcache_path, $_pigcache_matches );

if ( null !== $_pigcache_html_class ) {
	require_once $_pigcache_html_class;
	unset( $_pigcache_html_class );
	if ( class_exists( 'PigCache_Html_Cache', false ) ) {
		PigCache_Html_Cache::serve_early();
	}
}
