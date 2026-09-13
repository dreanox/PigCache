<?php
/**
 * Install/remove wp-content/advanced-cache.php for full-page HTML caching.
 *
 * Also manages the WP_CACHE constant in wp-config.php which WordPress requires
 * to load the advanced-cache.php drop-in.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Dropin_Html_Cache {

	const MARKER = 'PigCache HTML Cache drop-in';

	/**
	 * @return string
	 */
	public static function source_path() {
		return PIGCACHE_DIR . 'includes/dropin/advanced-cache.php';
	}

	/**
	 * @return string
	 */
	public static function dropin_path() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * @return bool
	 */
	public static function is_outdated() {
		if ( ! self::is_our_file() || ! is_readable( self::source_path() ) ) {
			return false;
		}
		$dropin = get_file_data( self::dropin_path(), array( 'Version' => 'Version' ) );
		$source = get_file_data( self::source_path(), array( 'Version' => 'Version' ) );
		return ! empty( $dropin['Version'] ) && ! empty( $source['Version'] )
			&& version_compare( $dropin['Version'], $source['Version'], '<' );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update_dropin() {
		return self::install();
	}

	/**
	 * @return bool
	 */
	public static function file_exists() {
		return file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	}

	/**
	 * @return bool
	 */
	public static function is_our_file() {
		$path = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$head = file_get_contents( $path, false, null, 0, 512 );

		return is_string( $head ) && strpos( $head, self::MARKER ) !== false;
	}

	/**
	 * Whether the WP_CACHE constant is defined and truthy in the current request.
	 * This is a runtime check — it doesn't read wp-config.php.
	 *
	 * @return bool
	 */
	public static function wp_cache_constant_active() {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * Returns true when advanced-cache.php is our file AND WP_CACHE is true.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::is_our_file() && self::wp_cache_constant_active();
	}

	/**
	 * Install the drop-in from the plugin's dropin template.
	 * Also attempts to inject `define('WP_CACHE', true)` into wp-config.php.
	 *
	 * @return true|WP_Error
	 */
	public static function install() {
		if ( self::file_exists() && ! self::is_our_file() ) {
			return new WP_Error(
				'pigcache_ac_exists',
				__( 'wp-content/advanced-cache.php already exists and is not managed by PigCache. Remove or merge it manually.', 'pigcache' )
			);
		}

		$src = PIGCACHE_DIR . 'includes/dropin/advanced-cache.php';
		if ( ! is_readable( $src ) ) {
			return new WP_Error( 'pigcache_ac_template', __( 'Drop-in template is missing from the plugin.', 'pigcache' ) );
		}

		$body = file_get_contents( $src );
		if ( false === $body || '' === $body ) {
			return new WP_Error( 'pigcache_ac_template', __( 'Could not read drop-in template.', 'pigcache' ) );
		}

		$written = file_put_contents( WP_CONTENT_DIR . '/advanced-cache.php', $body );
		if ( false === $written ) {
			return new WP_Error( 'pigcache_ac_write', __( 'Could not write wp-content/advanced-cache.php. Check filesystem permissions.', 'pigcache' ) );
		}

		return true;
	}

	/**
	 * Remove the drop-in.
	 * Optionally removes the WP_CACHE constant if it was added by PigCache.
	 *
	 * @return true|WP_Error
	 */
	public static function remove() {
		if ( ! self::file_exists() ) {
			return true;
		}

		if ( ! self::is_our_file() ) {
			return new WP_Error(
				'pigcache_ac_foreign',
				__( 'wp-content/advanced-cache.php is not the PigCache drop-in; not removed.', 'pigcache' )
			);
		}

		if ( ! unlink( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			return new WP_Error( 'pigcache_ac_unlink', __( 'Could not delete wp-content/advanced-cache.php.', 'pigcache' ) );
		}

		return true;
	}

	/**
	 * The snippet the site owner has to add to wp-config.php for WordPress to
	 * load the drop-in. PigCache never edits wp-config.php itself.
	 *
	 * @return string
	 */
	public static function wp_cache_snippet() {
		return "define( 'WP_CACHE', true );";
	}

	/**
	 * Human-readable status label.
	 *
	 * @return string
	 */
	public static function status_label() {
		if ( ! self::file_exists() ) {
			return __( 'Not installed', 'pigcache' );
		}

		if ( ! self::is_our_file() ) {
			return __( 'Present (other plugin)', 'pigcache' );
		}

		if ( ! self::wp_cache_constant_active() ) {
			return __( 'Installed (WP_CACHE not set — add to wp-config.php)', 'pigcache' );
		}

		return __( 'Active', 'pigcache' );
	}
}
