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

		// Attempt to ensure WP_CACHE is true in wp-config.php.
		self::maybe_inject_wp_cache_constant();

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
	 * Try to add `define('WP_CACHE', true)` to wp-config.php if it's not set.
	 * Silently returns on any filesystem error.
	 *
	 * @return bool True if the constant was injected or already present.
	 */
	public static function maybe_inject_wp_cache_constant() {
		if ( defined( 'WP_CACHE' ) ) {
			return true;
		}

		$config_path = self::locate_wp_config();
		if ( null === $config_path ) {
			return false;
		}

		if ( ! is_writable( $config_path ) ) {
			return false;
		}

		$contents = file_get_contents( $config_path );
		if ( false === $contents ) {
			return false;
		}

		// Already contains WP_CACHE definition.
		if ( preg_match( "/define\s*\(\s*['\"]WP_CACHE['\"/i", $contents ) ) {
			return true;
		}

		// Insert before "/* That's all, stop editing!" marker, or before closing PHP tag.
		$marker = "/* That's all, stop editing!";
		$line   = "define( 'WP_CACHE', true ); // Added by PigCache\n";

		if ( strpos( $contents, $marker ) !== false ) {
			$contents = str_replace( $marker, $line . $marker, $contents );
		} else {
			// Append before the last closing PHP tag or at end.
			$contents = rtrim( $contents );
			if ( substr( $contents, -2 ) === '?>' ) {
				$contents = substr( $contents, 0, -2 ) . $line . '?>';
			} else {
				$contents .= "\n" . $line;
			}
		}

		$result = file_put_contents( $config_path, $contents );

		return false !== $result;
	}

	/**
	 * Locate wp-config.php. WordPress core always finds it in one of two places.
	 * This mirrors the exact check in wp-load.php — no WordPress function exists
	 * to retrieve the wp-config.php path directly, so dirname(ABSPATH) is correct.
	 *
	 * @return string|null Absolute path or null if not found.
	 */
	private static function locate_wp_config() {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php', // standard location when wp-config.php is one level above ABSPATH
		);

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) && ! is_dir( $path ) ) {
				return $path;
			}
		}

		return null;
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
