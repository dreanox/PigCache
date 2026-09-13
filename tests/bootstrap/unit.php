<?php
/**
 * Bootstrap for the unit suite.
 *
 * These tests run without WordPress, without Redis and without MySQL, so they
 * are fast enough to run on every save and work anywhere PHP does. Everything
 * the plugin calls from WordPress is stubbed below with the smallest behaviour
 * that is faithful to core.
 *
 * Anything that genuinely needs WordPress, Redis or MySQL belongs in
 * tests/integration/ instead.
 *
 * @package PigCache
 */

declare( strict_types=1 );

define( 'PIGCACHE_TESTS_ROOT', dirname( __DIR__ ) );
define( 'PIGCACHE_PLUGIN_ROOT', dirname( PIGCACHE_TESTS_ROOT ) );

// The plugin files all start with `defined( 'ABSPATH' ) || exit;`.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', PIGCACHE_PLUGIN_ROOT . '/tests/fixtures/fake-wp/' );
}

require_once PIGCACHE_TESTS_ROOT . '/bootstrap/wp-stubs.php';

// Load the plugin classes under test. Order matters: some declare functions
// guarded by function_exists() that later files reuse.
require_once PIGCACHE_PLUGIN_ROOT . '/includes/class-pigcache-url-firewall.php';
require_once PIGCACHE_PLUGIN_ROOT . '/includes/class-pigcache-sql-cache.php';
require_once PIGCACHE_PLUGIN_ROOT . '/includes/class-pigcache-html-cache.php';
