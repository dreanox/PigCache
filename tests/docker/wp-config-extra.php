<?php
/**
 * Constants for the Docker test WordPress instance.
 *
 * This file is mounted into the WordPress root and required from the top of
 * wp-config.php by setup.sh, so it is loaded before wp-settings.php — which is
 * a hard requirement for WP_CACHE and for the Redis constants the object-cache
 * drop-in reads before any plugin runs.
 *
 * It deliberately does not rely on the image's WORDPRESS_CONFIG_EXTRA variable:
 * that is only honoured while wp-config.php is being generated, and newer
 * WordPress images stopped applying it, which left every constant silently
 * undefined and every cache layer quietly switched off.
 *
 * DO NOT put real credentials here — local and CI test use only.
 */

// Enables the advanced-cache.php drop-in. Without it the HTML cache never runs.
define( 'WP_CACHE', true );

// WP_DEBUG itself comes from the WORDPRESS_DEBUG=1 env var in
// docker-compose.yml — the base image's wp-config.php template defines it
// later in the same file with a plain define(), so defining it again here
// would just produce a "Constant already defined" warning of our own making.
//
// WP_DEBUG_LOG / WP_DEBUG_DISPLAY / SCRIPT_DEBUG are not wired to any env var
// by the base image, so these are safe to set explicitly: everything
// (including deprecation notices) goes to wp-content/debug.log AND to the
// screen, so both `wp` command output and page responses surface problems
// immediately during activation/deactivation testing.
if ( ! defined( 'WP_DEBUG_LOG' ) ) {
	define( 'WP_DEBUG_LOG', true );
}
if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
	define( 'WP_DEBUG_DISPLAY', true );
}
if ( ! defined( 'SCRIPT_DEBUG' ) ) {
	define( 'SCRIPT_DEBUG', true );
}
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// Redis — points at the 'redis' Compose service.
define( 'PIGCACHE_REDIS_HOST', 'redis' );
define( 'PIGCACHE_REDIS_PORT', 6379 );

// Emit X-PigCache-* response headers so the tests can inspect cache state
// without needing a REST endpoint.
define( 'PIGCACHE_TESTING', true );

// Short HTML cache TTL so re-caching can be verified quickly.
define( 'PIGCACHE_HTML_TTL', 30 );

// Deterministic static TTL — adaptive TTL would make assertions flaky.
// The constant is PIGCACHE_ADAPTIVE_TTL, not ..._ENABLED: the latter is what
// this file used to define, and PigCache_Adaptive_Ttl::is_enabled() never read
// it, so adaptive TTL was quietly left at its wp_options default.
define( 'PIGCACHE_ADAPTIVE_TTL', false );

// Verbose cron output for CI logs.
define( 'PIGCACHE_CRON_VERBOSE', true );

// The early HTML cache path only serves hosts on this list. Follows the port
// the stack was actually started on, so moving it never silently disables the
// early path.
define( 'PIGCACHE_ALLOWED_HOSTS', array( 'localhost:' . ( getenv( 'PIGCACHE_TEST_PORT' ) ?: '9520' ) ) );

// URL firewall in enforcing mode, with a short TTL so tests do not have to wait
// a week for a learned 404 to expire.
define( 'PIGCACHE_URL_FIREWALL', true );
define( 'PIGCACHE_URL_FIREWALL_MODE', 'enforce' );
define( 'PIGCACHE_FW_TTL', 3600 );
