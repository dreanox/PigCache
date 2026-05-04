<?php
/**
 * PigCache HTML Cache drop-in — do not edit by hand without a backup.
 * Installed and managed by the PigCache plugin.
 *
 * Version: 1.0.1
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

// This dropin runs from wp-content/advanced-cache.php before the plugin or mu-plugins load.
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
