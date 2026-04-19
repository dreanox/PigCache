<?php
/**
 * Pro distribution only: auto-update checker against the PigCache Cloud API.
 *
 * Hooks into WordPress's native update transient so the standard "Update available"
 * notice appears in WP Admin → Plugins. The ZIP is downloaded directly from the API
 * and validated against the active license key.
 *
 * Omitted from the WordPress.org Free ZIP — Plugin Directory rules forbid filtering
 * update transients with external sources.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Updates {

	const TRANSIENT_KEY   = 'pigcache_update_info';
	const TRANSIENT_TTL   = 12 * HOUR_IN_SECONDS;
	const UPDATE_ENDPOINT = 'plugin/version';

	/**
	 * Register hooks (Pro package only).
	 */
	public static function init() {
		if ( ! class_exists( 'PigCache_License', false ) || ! PigCache_License::has_pro_distribution() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update_info' ), 20 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_transient' ), 10, 2 );
	}

	/**
	 * Inject PigCache update data into WordPress's update transient.
	 *
	 * @param object|false $transient
	 * @return object|false
	 */
	public static function inject_update_info( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( ! PigCache_License::updates_allowed() ) {
			self::strip_response( $transient );
			return $transient;
		}

		$info = self::fetch_update_info();

		if ( ! $info || empty( $info['available'] ) || empty( $info['version'] ) ) {
			return $transient;
		}

		$current_version = defined( 'PIGCACHE_VERSION' ) ? PIGCACHE_VERSION : '0.0.0';

		if ( version_compare( $info['version'], $current_version, '<=' ) ) {
			return $transient;
		}

		$slug        = plugin_basename( PIGCACHE_FILE );
		$plugin_slug = dirname( $slug );

		$update = (object) array(
			'id'           => $slug,
			'slug'         => $plugin_slug,
			'plugin'       => $slug,
			'new_version'  => $info['version'],
			'url'          => 'https://bluecache.pigworlds.com',
			'package'      => $info['download_url'] ?? '',
			'icons'        => array(),
			'banners'      => array(),
			'requires'     => $info['requires'] ?? '5.8',
			'tested'       => $info['tested'] ?? '6.7',
			'requires_php' => $info['requires_php'] ?? '7.4',
		);

		$transient->response[ $slug ] = $update;

		return $transient;
	}

	/**
	 * Provide plugin details for the "View version x.x.x details" popup.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$plugin_slug = dirname( plugin_basename( PIGCACHE_FILE ) );

		if ( empty( $args->slug ) || $args->slug !== $plugin_slug ) {
			return $result;
		}

		$info = self::fetch_update_info();

		if ( ! $info || empty( $info['version'] ) ) {
			return $result;
		}

		$current_version = defined( 'PIGCACHE_VERSION' ) ? PIGCACHE_VERSION : '0.0.0';

		return (object) array(
			'name'              => 'PigCache Pro',
			'slug'              => $plugin_slug,
			'version'           => $info['version'],
			'author'            => '<a href="https://pigcache.com">PigCache</a>',
			'homepage'          => 'https://pigcache.com',
			'requires'          => $info['requires'] ?? '5.8',
			'tested'            => $info['tested'] ?? '6.7',
			'requires_php'      => $info['requires_php'] ?? '7.4',
			'download_link'     => $info['download_url'] ?? '',
			'last_updated'      => '',
			'sections'          => array(
				'changelog' => nl2br( esc_html( $info['changelog'] ?? '' ) ),
			),
			'short_description' => 'Redis object-cache drop-in with SQL, HTML page, and fragment caching for WordPress.',
			'current_version'   => $current_version,
		);
	}

	/**
	 * Clear cached update info after the plugin is upgraded.
	 *
	 * @param \WP_Upgrader $upgrader
	 * @param array        $hook_extra
	 */
	public static function clear_transient( $upgrader, array $hook_extra ) {
		if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === plugin_basename( PIGCACHE_FILE ) ) {
			delete_transient( self::TRANSIENT_KEY );
		}
	}

	/**
	 * Fetch version info from the Cloud API, cached for 12 hours.
	 *
	 * @return array|null Decoded response or null on failure / error sentinel.
	 */
	public static function fetch_update_info() {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		if ( ! class_exists( 'PigCache_Cloud_Client', false ) ) {
			return null;
		}

		$response = PigCache_Cloud_Client::get( self::UPDATE_ENDPOINT );

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			// Cache a short sentinel so we don't hammer the API on every page load.
			set_transient( self::TRANSIENT_KEY, 'error', HOUR_IN_SECONDS );
			return null;
		}

		set_transient( self::TRANSIENT_KEY, $response, self::TRANSIENT_TTL );

		return $response;
	}

	/**
	 * Remove PigCache from the update transient response.
	 *
	 * @param object $transient
	 */
	private static function strip_response( $transient ) {
		$slug = plugin_basename( PIGCACHE_FILE );

		if ( isset( $transient->response[ $slug ] ) ) {
			unset( $transient->response[ $slug ] );
		}
	}
}
