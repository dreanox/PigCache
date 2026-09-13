<?php
/**
 * Plugin Name:       PigCache
 * Description:       Redis object-cache drop-in with optional SQL, HTML page, and fragment caching.
 * Version: 1.0.24
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PigCache
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pigcache
 *
 * @package PigCache
 *
 * The object cache drop-in is derived from Redis Object Cache (GPLv3, Till Krüss / Rhubarb Group).
 */

defined( 'ABSPATH' ) || exit;

define( 'PIGCACHE_VERSION', '1.0.24' );
define( 'PIGCACHE_FILE', __FILE__ );
define( 'PIGCACHE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIGCACHE_URL', plugin_dir_url( __FILE__ ) );

$pigcache_autoload = PIGCACHE_DIR . 'vendor/autoload.php';
if ( is_readable( $pigcache_autoload ) ) {
	require_once $pigcache_autoload;
}

$oc_meta = get_file_data( PIGCACHE_DIR . 'includes/dropin/object-cache.php', array( 'Version' => 'Version' ) );
if ( ! defined( 'PIGCACHE_OC_VERSION' ) && ! empty( $oc_meta['Version'] ) ) {
	define( 'PIGCACHE_OC_VERSION', $oc_meta['Version'] );
}

// Always loaded.
require_once PIGCACHE_DIR . 'includes/class-pigcache-config.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-kv.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-sql-cache.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-html-cache.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-url-firewall.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-invalidation.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-fragments.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-tag-collector.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-tag-index.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-dropin-db.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-dropin-object-cache.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-dropin-html-cache.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-admin.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-metrics.php';
require_once PIGCACHE_DIR . 'includes/class-pigcache-plugin.php';

// ── PRO_START ─────────────────────────────────────────────────────────────────
// Optional modules — loaded when present on disk.
foreach ( array(
	'class-pigcache-license.php',
	'class-pigcache-environment.php',
	'class-pigcache-cloud-client.php',
	'class-pigcache-cloud-sync.php',
	'class-pigcache-sql-profile-store.php',
	'class-pigcache-sql-profiler.php',
	'class-pigcache-updates.php',
	'class-pigcache-query-buffer.php',
	'class-pigcache-query-stats.php',
	'class-pigcache-continuous-learner.php',
	'class-pigcache-mutation-tracker.php',
	'class-pigcache-traffic-reader.php',
	'class-pigcache-adaptive-ttl.php',
	'class-pigcache-stats.php',
) as $_pigcache_pro_file ) {
	$_pigcache_pro_path = PIGCACHE_DIR . 'includes/pro/' . $_pigcache_pro_file;
	if ( is_readable( $_pigcache_pro_path ) ) {
		require_once $_pigcache_pro_path;
	}
}
unset( $_pigcache_pro_file, $_pigcache_pro_path );
// ── PRO_END ───────────────────────────────────────────────────────────────────

register_activation_hook(
	PIGCACHE_FILE,
	static function () {
		PigCache_Config::on_activate();
		PigCache_Tag_Index::create_table();
		// ── PRO_START ─────────────────────────────────────────────────────────────────
		PigCache_License::maybe_start_trial();

		if ( class_exists( 'PigCache_Sql_Profile_Store', false ) ) {
			PigCache_Sql_Profile_Store::create_table();
		}

		if ( class_exists( 'PigCache_Query_Stats', false ) ) {
			PigCache_Query_Stats::create_table();
		}

		if ( class_exists( 'PigCache_Cloud_Sync', false ) && PigCache_Cloud_Sync::is_enabled() ) {
			PigCache_Cloud_Sync::schedule();
		}

		if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
			PigCache_Continuous_Learner::schedule();
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────
	}
);

register_deactivation_hook(
	PIGCACHE_FILE,
	static function () {
		if ( PigCache_Dropin_Object_Cache::validate() ) {
			PigCache_Dropin_Object_Cache::remove();
		}

		if ( PigCache_Dropin_Html_Cache::is_our_file() ) {
			PigCache_Dropin_Html_Cache::remove();
		}

		wp_clear_scheduled_hook( PigCache_Plugin::TAG_CLEANUP_HOOK );

		// ── PRO_START ─────────────────────────────────────────────────────────────────
		if ( class_exists( 'PigCache_Cloud_Sync', false ) ) {
			PigCache_Cloud_Sync::unschedule();
		}

		if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
			PigCache_Continuous_Learner::unschedule();
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────
	}
);

add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		$minutes = defined( 'PIGCACHE_FLUSH_INTERVAL' ) ? max( 1, min( 60, (int) PIGCACHE_FLUSH_INTERVAL ) ) : 15;

		// ── PRO_START ─────────────────────────────────────────────────────────────────
		if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
			$minutes = PigCache_Continuous_Learner::flush_interval_minutes();
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────

		$schedules['pigcache_flush'] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: number of minutes */
			'display'  => sprintf( __( 'Every %d minutes (PigCache)', 'pigcache' ), $minutes ),
		);

		return $schedules;
	}
);

add_action( 'plugins_loaded', array( 'PigCache_Config', 'apply_extra_non_persistent_groups' ), 1 );
// ── PRO_START ─────────────────────────────────────────────────────────────────
if ( class_exists( 'PigCache_Updates', false ) ) {
	add_action( 'plugins_loaded', array( 'PigCache_Updates', 'init' ), 5 );
}
if ( class_exists( 'PigCache_Cloud_Sync', false ) ) {
	add_action( 'plugins_loaded', array( 'PigCache_Cloud_Sync', 'init' ), 10 );
}
if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
	add_action( 'plugins_loaded', array( 'PigCache_Continuous_Learner', 'init' ), 15 );
}
// ── PRO_END ───────────────────────────────────────────────────────────────────
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
	PigCache_Tag_Collector::add( $tag );
}

// ── PRO_START ─────────────────────────────────────────────────────────────────
/**
 * Full path to the compiled SQL profiler profile (Pro only).
 *
 * @return string Empty string in the Free build.
 */
function pigcache_sql_profile_path() {
	if ( ! class_exists( 'PigCache_Sql_Profiler', false ) ) {
		return '';
	}

	return PigCache_Sql_Profiler::profile_path();
}
// ── PRO_END ───────────────────────────────────────────────────────────────────
