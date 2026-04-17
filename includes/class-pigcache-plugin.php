<?php
/**
 * Plugin bootstrap.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Plugin {

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
		load_plugin_textdomain( 'pigcache', false, dirname( plugin_basename( PIGCACHE_FILE ) ) . '/languages' );

		PigCache_Sql_Cache::init();
		if ( class_exists( 'PigCache_Sql_Profiler', false ) ) {
			PigCache_Sql_Profiler::init();
		}
		PigCache_Html_Cache::init();
		PigCache_Invalidation::init();

		if ( is_admin() ) {
			PigCache_Admin::init();
			PigCache_Metrics::init();
			add_action( 'admin_notices', array( $this, 'maybe_notice_standalone_redis_plugin' ) );
		}
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
