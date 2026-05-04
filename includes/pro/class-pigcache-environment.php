<?php
/**
 * Detects the site's plugin/theme/WP environment for profile matching.
 *
 * The backend uses this information to find or build pre-compiled SQL
 * profiles shared across sites with the same stack.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Environment {

	const TRANSIENT_SIGNATURE = 'pigcache_env_signature';

	/**
	 * Get a deterministic hash representing the site's active stack.
	 *
	 * Plugins are identified by directory slug (e.g. "woocommerce"),
	 * sorted alphabetically. Theme uses its stylesheet slug.
	 * Only the WP major.minor version is included so patch releases
	 * don't invalidate the signature.
	 *
	 * @return string 32-char md5 hex.
	 */
	public static function get_signature() {
		$cached = get_transient( self::TRANSIENT_SIGNATURE );

		if ( is_string( $cached ) && strlen( $cached ) === 32 ) {
			return $cached;
		}

		$details   = self::get_details();
		$canonical = implode( '|', $details['plugins'] )
			. '||' . $details['theme']
			. '||' . $details['wp_major'];

		$hash = md5( $canonical );

		set_transient( self::TRANSIENT_SIGNATURE, $hash, DAY_IN_SECONDS );

		return $hash;
	}

	/**
	 * Get the full environment detail array.
	 *
	 * @return array{plugins: string[], theme: string, wp_version: string, wp_major: string, php_version: string}
	 */
	public static function get_details() {
		$plugins = self::get_active_plugin_slugs();
		sort( $plugins );

		$theme   = get_stylesheet();
		$wp      = get_bloginfo( 'version' );
		$parts   = explode( '.', $wp );
		$major   = isset( $parts[0], $parts[1] ) ? $parts[0] . '.' . $parts[1] : $wp;

		return array(
			'plugins'     => $plugins,
			'theme'       => $theme,
			'wp_version'  => $wp,
			'wp_major'    => $major,
			'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
		);
	}

	/**
	 * Extract the directory slug for every active plugin.
	 *
	 * "woocommerce/woocommerce.php" -> "woocommerce"
	 * "hello.php" -> "hello.php" (single-file plugin)
	 *
	 * @return string[]
	 */
	private static function get_active_plugin_slugs() {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$active = (array) get_option( 'active_plugins', array() );
		$slugs  = array();

		foreach ( $active as $file ) {
			$dir = dirname( $file );
			$slugs[] = ( '.' === $dir ) ? $file : $dir;
		}

		return $slugs;
	}

	/**
	 * Flush the cached signature (e.g. after plugin/theme change).
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT_SIGNATURE );
	}
}
