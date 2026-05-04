<?php
/**
 * Install, validate, and remove wp-content/object-cache.php (PigCache Redis drop-in).
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Dropin_Object_Cache {

	const PLUGIN_URI = 'https://pigcache.object-cache/drop-in';

	/**
	 * @return string
	 */
	public static function source_path() {
		return PIGCACHE_DIR . 'includes/dropin/object-cache.php';
	}

	/**
	 * @return string
	 */
	public static function dropin_path() {
		return WP_CONTENT_DIR . '/object-cache.php';
	}

	/**
	 * @return bool
	 */
	public static function dropin_exists() {
		return file_exists( self::dropin_path() );
	}

	/**
	 * @return bool
	 */
	public static function validate() {
		if ( ! self::dropin_exists() || ! is_readable( self::source_path() ) ) {
			return false;
		}

		$dropin = get_plugin_data( self::dropin_path() );
		$source = get_plugin_data( self::source_path() );

		return isset( $dropin['PluginURI'], $source['PluginURI'] )
			&& $dropin['PluginURI'] === $source['PluginURI']
			&& $dropin['PluginURI'] === self::PLUGIN_URI;
	}

	/**
	 * @return bool
	 */
	public static function is_outdated() {
		if ( ! self::validate() ) {
			return false;
		}

		$dropin = get_plugin_data( self::dropin_path() );
		$source = get_plugin_data( self::source_path() );

		return version_compare( $dropin['Version'], $source['Version'], '<' );
	}

	/**
	 * Human-readable status for wp-admin.
	 *
	 * @return string
	 */
	public static function status_label() {
		global $wp_object_cache;

		if ( defined( 'PIGCACHE_REDIS_DISABLED' ) && PIGCACHE_REDIS_DISABLED ) {
			return __( 'Disabled (PIGCACHE_REDIS_DISABLED)', 'pigcache' );
		}

		if ( ! self::dropin_exists() ) {
			return __( 'Drop-in not installed', 'pigcache' );
		}

		if ( ! self::validate() ) {
			return __( 'Drop-in is not the PigCache file (invalid)', 'pigcache' );
		}

		if ( self::is_outdated() ) {
			return __( 'Drop-in outdated — update recommended', 'pigcache' );
		}

		if ( ! wp_using_ext_object_cache() ) {
			return __( 'Not using external object cache', 'pigcache' );
		}

		if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'redis_status' ) ) {
			return $wp_object_cache->redis_status()
				? __( 'Connected to Redis', 'pigcache' )
				: __( 'Not connected to Redis', 'pigcache' );
		}

		return __( 'Unknown', 'pigcache' );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function install() {
		if ( self::dropin_exists() && ! self::validate() ) {
			return new WP_Error(
				'pigcache_oc_foreign',
				__( 'wp-content/object-cache.php exists and is not the PigCache drop-in. Remove it manually before enabling PigCache.', 'pigcache' )
			);
		}

		$src = self::source_path();
		if ( ! is_readable( $src ) ) {
			return new WP_Error( 'pigcache_oc_src', __( 'PigCache object-cache source file is missing.', 'pigcache' ) );
		}

		// WP_CONTENT_DIR is intentional: we are copying the dropin into wp-content/ itself,
		// so we must verify that directory is writable — plugin_dir_path() would be wrong here.
		if ( ! is_writable( WP_CONTENT_DIR ) ) {
			return new WP_Error( 'pigcache_oc_writable', __( 'wp-content is not writable.', 'pigcache' ) );
		}

		if ( ! copy( $src, self::dropin_path() ) ) {
			return new WP_Error( 'pigcache_oc_copy', __( 'Could not copy object-cache.php to wp-content.', 'pigcache' ) );
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'pigcache_object_cache_enable', true );
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function remove() {
		if ( ! self::dropin_exists() ) {
			return true;
		}

		if ( ! self::validate() ) {
			return new WP_Error(
				'pigcache_oc_not_ours',
				__( 'The installed object-cache.php is not PigCache; not removed.', 'pigcache' )
			);
		}

		if ( ! unlink( self::dropin_path() ) ) {
			return new WP_Error( 'pigcache_oc_unlink', __( 'Could not delete wp-content/object-cache.php.', 'pigcache' ) );
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'pigcache_object_cache_disable', true );
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update_dropin() {
		return self::install();
	}
}
