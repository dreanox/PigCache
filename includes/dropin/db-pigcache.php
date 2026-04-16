<?php
/**
 * PigCache database drop-in: replaces $wpdb with PigCache_WPDB for SQL result caching.
 *
 * Install: copy this file to wp-content/db.php (use the button on Settings → PigCache).
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
 * @return string|null Absolute path if readable.
 */
if ( ! function_exists( 'pigcache_db_dropin_locate_wpdb_class' ) ) {
function pigcache_db_dropin_locate_wpdb_class() {
	$candidates = array(
		WP_CONTENT_DIR . '/plugins/pigcache/includes/class-pigcache-wpdb.php',
		WP_CONTENT_DIR . '/mu-plugins/pigcache/includes/class-pigcache-wpdb.php',
	);

	foreach ( $candidates as $path ) {
		if ( is_readable( $path ) ) {
			return $path;
		}
	}

	if ( function_exists( 'glob' ) ) {
		$matches = glob( WP_CONTENT_DIR . '/plugins/*/includes/class-pigcache-wpdb.php' );
		if ( is_array( $matches ) ) {
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
