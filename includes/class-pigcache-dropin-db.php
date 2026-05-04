<?php
/**
 * Install/remove wp-content/db.php for SQL result caching.
 *
 * All references to WP_CONTENT_DIR in this class are intentional: db.php is a
 * WordPress drop-in that must live in wp-content/, not inside the plugin directory.
 * plugin_dir_path() would give the wrong location; WP_CONTENT_DIR is the only
 * WordPress API available for the content directory path.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Dropin_DB {

	const MARKER = 'PigCache DB drop-in';

	/**
	 * @return string
	 */
	public static function source_path() {
		return PIGCACHE_DIR . 'includes/dropin/db-pigcache.php';
	}

	/**
	 * @return string
	 */
	public static function dropin_path() {
		return WP_CONTENT_DIR . '/db.php';
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
	public static function is_active() {
		global $wpdb;

		return class_exists( 'PigCache_WPDB', false ) && $wpdb instanceof PigCache_WPDB;
	}

	/**
	 * @return bool
	 */
	public static function file_exists() {
		return file_exists( WP_CONTENT_DIR . '/db.php' );
	}

	/**
	 * @return bool
	 */
	public static function is_our_file() {
		$path = WP_CONTENT_DIR . '/db.php';
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$head = file_get_contents( $path, false, null, 0, 512 );

		return is_string( $head ) && strpos( $head, self::MARKER ) !== false;
	}

	/**
	 * Same resolution logic as wp-content/db.php (keep in sync with includes/dropin/db-pigcache.php).
	 *
	 * @return string|null Readable path to class-pigcache-wpdb.php or null.
	 */
	public static function locate_wpdb_class_file() {
		$candidates = array(
			PIGCACHE_DIR . 'includes/class-pigcache-wpdb.php',                            // primary: plugin_dir_path()-based
			WP_CONTENT_DIR . '/mu-plugins/pigcache/includes/class-pigcache-wpdb.php',     // mu-plugins fallback
		);

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		if ( function_exists( 'glob' ) ) {
			$matches = glob( WP_CONTENT_DIR . '/plugins/*/includes/class-pigcache-wpdb.php' );
			if ( is_array( $matches ) ) {
				foreach ( $matches as $path ) {
					if ( is_readable( $path ) ) {
						return $path;
					}
				}
			}
		}

		return null;
	}

	/**
	 * PigCache db.php on disk but $wpdb is not PigCache_WPDB (misconfiguration).
	 *
	 * @return bool
	 */
	public static function is_dropin_installed_but_inactive() {
		return self::file_exists() && self::is_our_file() && ! self::is_active();
	}

	/**
	 * Human-readable diagnosis for Settings → PigCache.
	 *
	 * @return string Empty if no problem.
	 */
	public static function get_dropin_inactive_message() {
		if ( ! self::is_dropin_installed_but_inactive() ) {
			return '';
		}

		$resolved = self::locate_wpdb_class_file();

		if ( ! $resolved ) {
			return __( 'PigCache db.php is installed but no readable class-pigcache-wpdb.php was found. Expected wp-content/plugins/pigcache/includes/… or a matching file under plugins/*/includes/. Restore the plugin folder name to “pigcache”, reinstall the plugin, or fix file permissions.', 'pigcache' );
		}

		if ( ! class_exists( 'PigCache_WPDB', false ) ) {
			return sprintf(
				/* translators: %s: filesystem path */
				__( 'PigCache found %s but PigCache_WPDB is not defined. Check for a PHP syntax error in that file or see wp-content/debug.log.', 'pigcache' ),
				$resolved
			);
		}

		global $wpdb;

		if ( is_object( $wpdb ) ) {
			return sprintf(
				/* translators: %s: PHP class name of current $wpdb */
				__( 'PigCache db.php is present and PigCache_WPDB can load, but the global $wpdb is “%s”, not PigCache_WPDB. Another drop-in or custom code may have replaced the database object after bootstrap. Remove wp-content/db.php and click “Install db.php drop-in” again, or disable conflicting database plugins.', 'pigcache' ),
				get_class( $wpdb )
			);
		}

		return __( 'PigCache db.php is present but $wpdb was not replaced. Remove PigCache db.php and install it again from this page.', 'pigcache' );
	}

	/**
	 * @return string|false
	 */
	public static function source_template() {
		$src = PIGCACHE_DIR . 'includes/dropin/db-pigcache.php';
		if ( ! is_readable( $src ) ) {
			return false;
		}

		$data = file_get_contents( $src );

		return false !== $data ? $data : false;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function install() {
		if ( self::file_exists() && ! self::is_our_file() ) {
			return new WP_Error(
				'pigcache_db_exists',
				__( 'wp-content/db.php already exists and is not managed by PigCache. Remove or merge it manually.', 'pigcache' )
			);
		}

		$body = self::source_template();
		if ( ! is_string( $body ) || $body === '' ) {
			return new WP_Error( 'pigcache_db_template', __( 'Drop-in template is missing from the plugin.', 'pigcache' ) );
		}

		$out = preg_replace(
			'/^<\?php\s*/',
			"<?php\n// " . self::MARKER . " — do not edit by hand without a backup.\n",
			$body,
			1
		);

		if ( ! is_string( $out ) || $out === '' ) {
			return new WP_Error( 'pigcache_db_template', __( 'Could not build drop-in file.', 'pigcache' ) );
		}

		$written = file_put_contents( WP_CONTENT_DIR . '/db.php', $out );
		if ( false === $written ) {
			return new WP_Error( 'pigcache_db_write', __( 'Could not write wp-content/db.php. Check filesystem permissions.', 'pigcache' ) );
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function remove() {
		if ( ! self::file_exists() ) {
			return true;
		}

		if ( ! self::is_our_file() ) {
			return new WP_Error(
				'pigcache_db_foreign',
				__( 'wp-content/db.php is not the PigCache drop-in; not removed.', 'pigcache' )
			);
		}

		if ( ! unlink( WP_CONTENT_DIR . '/db.php' ) ) {
			return new WP_Error( 'pigcache_db_unlink', __( 'Could not delete wp-content/db.php.', 'pigcache' ) );
		}

		return true;
	}
}
