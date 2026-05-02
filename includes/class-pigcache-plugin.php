<?php
/**
 * Plugin bootstrap.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Plugin {

	const DB_VERSION_OPTION = 'pigcache_db_version';
	const DB_VERSION        = 3;

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		self::maybe_install_tables();

		PigCache_Sql_Cache::init();

		if ( class_exists( 'PigCache_Sql_Profiler', false ) ) {
			PigCache_Sql_Profiler::init();
		}

		PigCache_Html_Cache::init();
		PigCache_Invalidation::init();

		if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
			PigCache_Continuous_Learner::init();
			if ( PigCache_Continuous_Learner::using_wp_cron_fallback() ) {
				PigCache_Continuous_Learner::schedule();
			}
		}

		if ( is_admin() ) {
			PigCache_Admin::init();
			if ( class_exists( 'PigCache_Admin_Pro', false ) ) {
				PigCache_Admin_Pro::init();
			}
			PigCache_Metrics::init();
			add_action( 'admin_notices', array( $this, 'maybe_notice_standalone_redis_plugin' ) );
		}
	}

	/**
	 * Create or upgrade Pro-specific MySQL tables.
	 * Runs only when the stored DB version is behind DB_VERSION.
	 */
	public static function maybe_install_tables(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}

		self::install_tables();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Create all Pro MySQL tables using dbDelta (idempotent).
	 */
	public static function install_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Per-table mutation frequency for Adaptive TTL v2.
		dbDelta( "CREATE TABLE {$wpdb->prefix}pigcache_table_stability (
			table_name      varchar(128)  NOT NULL,
			window_start    datetime      NOT NULL,
			mutation_count  int unsigned  NOT NULL DEFAULT 0,
			PRIMARY KEY  (table_name, window_start),
			KEY idx_window (window_start)
		) {$charset};" );

		// Per-URL traffic hits for Adaptive TTL v2.
		dbDelta( "CREATE TABLE {$wpdb->prefix}pigcache_url_traffic (
			url_hash     char(32)      NOT NULL,
			url          varchar(2083) NOT NULL,
			hit_count    int unsigned  NOT NULL DEFAULT 0,
			period_start datetime      NOT NULL,
			PRIMARY KEY  (url_hash, period_start),
			KEY idx_period (period_start)
		) {$charset};" );

		// General-purpose key-value store — bypasses object cache.
		// Used for cron heartbeat timestamps, harvest metadata, and any
		// plugin state that must be read directly from MySQL (never Redis/APCu).
		dbDelta( "CREATE TABLE {$wpdb->prefix}pigcache_kv (
			`key`      varchar(128) NOT NULL,
			value      mediumtext   NOT NULL,
			updated_at datetime     NOT NULL,
			expires_at datetime     DEFAULT NULL,
			PRIMARY KEY  (`key`),
			KEY idx_expires (expires_at)
		) {$charset};" );
	}

	/**
	 * Drop all Pro MySQL tables. Called on plugin uninstall.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pigcache_table_stability" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pigcache_url_traffic" );     // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pigcache_kv" );              // phpcs:ignore

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Warn if the standalone Redis Object Cache plugin is also active (duplicate stack).
	 */
	public function maybe_notice_standalone_redis_plugin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'redis-cache/redis-cache.php' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'PigCache is a full replacement for the “Redis Object Cache” plugin. Deactivate Redis Object Cache to avoid loading two Redis stacks.', 'pigcache' );
		echo '</p></div>';
	}
}
