<?php
/**
 * PigCache database drop-in: replaces $wpdb with PigCache_WPDB for SQL result caching.
 *
 * Install: copy this file to wp-content/db.php (use the button on Settings → PigCache).
 *
 * Version: 1.0.1
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	return;
}

/**
 * Locate class-pigcache-wpdb.php (plugin slug may differ; may live under mu-plugins).
 *
 * require_wp_db() includes this drop-in before the plugin API exists, so
 * plugin_dir_path() is unavailable and WP_PLUGIN_DIR / WPMU_PLUGIN_DIR are usually
 * still undefined — core defines them later in wp_plugin_directory_constants().
 * Use them when present, otherwise fall back to the core default locations.
 *
 * @return string|null Absolute path if readable.
 */
if ( ! function_exists( 'pigcache_db_dropin_locate_wpdb_class' ) ) {
function pigcache_db_dropin_locate_wpdb_class() {
	$roots = array();

	if ( defined( 'WP_PLUGIN_DIR' ) ) {
		$roots[] = WP_PLUGIN_DIR;
	} else {
		$roots[] = WP_CONTENT_DIR . '/plugins';
	}

	if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
		$roots[] = WPMU_PLUGIN_DIR;
	} else {
		$roots[] = WP_CONTENT_DIR . '/mu-plugins';
	}

	foreach ( $roots as $root ) {
		$path = $root . '/pigcache/includes/class-pigcache-wpdb.php';
		if ( is_readable( $path ) ) {
			return $path;
		}
	}

	if ( function_exists( 'glob' ) ) {
		foreach ( $roots as $root ) {
			$matches = glob( $root . '/*/includes/class-pigcache-wpdb.php' );
			if ( ! is_array( $matches ) ) {
				continue;
			}
			foreach ( $matches as $path ) {
				if ( is_readable( $path ) ) {
					return $path;
				}
			}
		}
	}

	return null;
}
}

$pigcache_wpdb_file = pigcache_db_dropin_locate_wpdb_class();

if ( ! is_string( $pigcache_wpdb_file ) ) {
	return;
}

require_once $pigcache_wpdb_file;

global $wpdb;

if ( isset( $wpdb ) ) {
	return;
}

$dbuser     = defined( 'DB_USER' ) ? DB_USER : '';
$dbpassword = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
$dbname     = defined( 'DB_NAME' ) ? DB_NAME : '';
$dbhost     = defined( 'DB_HOST' ) ? DB_HOST : '';

$wpdb = new PigCache_WPDB( $dbuser, $dbpassword, $dbname, $dbhost );
