<?php
/**
 * Plugin Name:       PigCache
 * Description:       Standalone Redis object cache (drop-in): defaults to 127.0.0.1:6379 until you set WP_REDIS_*; PhpRedis when available, else Predis. Plus optional SQL cache, HTML cache, fragments.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PigCache
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       pigcache
 *
 * @package PigCache
 *
 * The object cache drop-in is derived from Redis Object Cache (GPLv3, Till Krüss / Rhubarb Group).
 */

defined( 'ABSPATH' ) || exit;

define( 'PIGCACHE_VERSION', '1.0.0' );
define( 'PIGCACHE_FILE', __FILE__ );
define( 'PIGCACHE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIGCACHE_URL', plugin_dir_url( __FILE__ ) );

$pigcache_autoload = PIGCACHE_DIR . 'vendor/autoload.php';
if ( is_readable( $pigcache_autoload ) ) {
	require_once $pigcache_autoload;
}

$oc_meta = get_file_data( PIGCACHE_DIR . 'includes/dropin/object-cache.php', array( 'Version' => 'Version' ) );
if ( ! defined( 'WP_REDIS_VERSION' ) && ! empty( $oc_meta['Version'] ) ) {
	define( 'WP_REDIS_VERSION', $oc_meta['Version'] );
}

require_once PIGCACHE_DIR . 'includes/class-pigcache-config.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-license.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-environment.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-cloud-client.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-cloud-sync.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-sql-cache.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-sql-profile-store.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-sql-profiler.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-tag-collector.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-tag-index.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-html-cache.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-invalidation.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-fragments.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-dropin-db.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-dropin-object-cache.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-admin.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-metrics.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-plugin.php';

register_activation_hook(
	PIGCACHE_FILE,
	static function () {
		PigCache_Config::on_activate();
		PigCache_Tag_Index::create_table();
		PigCache_Sql_Profile_Store::create_table();
		PigCache_License::maybe_start_trial();

		if ( PigCache_Cloud_Sync::is_enabled() ) {
			PigCache_Cloud_Sync::schedule();
		}
	}
);

register_deactivation_hook(
	PIGCACHE_FILE,
	static function () {
		if ( PigCache_Dropin_Object_Cache::validate() ) {
			PigCache_Dropin_Object_Cache::remove();
		}

		PigCache_Cloud_Sync::unschedule();
	}
);

add_action( 'plugins_loaded', array( 'PigCache_Config', 'apply_extra_non_persistent_groups' ), 1 );
add_action( 'plugins_loaded', array( 'PigCache_Cloud_Sync', 'init' ), 10 );
add_action( 'plugins_loaded', array( 'PigCache_Plugin', 'instance' ), 20 );

/**
 * Cache a fragment using the object cache.
 *
 * @param string   $key      Unique key (will be namespaced).
 * @param callable $callback Returns the fragment if not cached.
 * @param int      $ttl      TTL in seconds.
 * @param string   $group    Optional cache group.
 * @param string[] $tags     Optional tags for selective invalidation.
 * @return mixed
 */
function pigcache_fragment( $key, $callback, $ttl = 60, $group = 'pigcache_fragments', $tags = array() ) {
	return PigCache_Fragments::remember( $key, $callback, $ttl, $group, $tags );
}

/**
 * Tag the current page/fragment for selective cache invalidation.
 *
 * @param string $tag  e.g. "widget:recent_posts", "custom:my_slider".
 */
function pigcache_tag( $tag ) {
	if ( class_exists( 'PigCache_Tag_Collector', false ) ) {
		PigCache_Tag_Collector::add( $tag );
	}
}

/**
 * Full path to the compiled SQL profiler profile.
 *
 * @return string
 */
function pigcache_sql_profile_path() {
	return PigCache_Sql_Profiler::profile_path();
}
