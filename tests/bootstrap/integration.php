<?php
/**
 * Bootstrap for the integration suite.
 *
 * Unlike the unit suite, this one boots a real WordPress against a real MySQL
 * and a real Redis. It is meant to run inside the Docker stack in tests/docker/,
 * where $wpdb, the object-cache drop-in and phpredis are all live.
 *
 * The point of these tests is everything the stubs cannot prove: that the
 * drop-ins actually install, that Redis actually round-trips, that invalidation
 * actually reaches the right keys, and that the plugin degrades instead of
 * fataling when Redis disappears.
 *
 * @package PigCache
 */

declare( strict_types=1 );

$wp_root = getenv( 'WP_ROOT' ) ?: '/var/www/html';

if ( ! is_readable( $wp_root . '/wp-load.php' ) ) {
	fwrite(
		STDERR,
		"WordPress not found at {$wp_root}.\n" .
		"The integration suite must run inside the Docker stack:\n" .
		"  bash tests/run.sh integration\n"
	);
	exit( 1 );
}

// WordPress emits notices freely; let PHPUnit judge the assertions, not the noise.
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once $wp_root . '/wp-load.php';

if ( ! class_exists( 'PigCache_Plugin' ) ) {
	fwrite( STDERR, "PigCache is not active in this WordPress install.\n" );
	exit( 1 );
}

require_once __DIR__ . '/integration-case.php';
