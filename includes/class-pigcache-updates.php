<?php
/**
 * Pro distribution only: hide plugin updates when the license server denies them (e.g. expired trial).
 *
 * Omitted from the WordPress.org Free ZIP — Plugin Directory rules forbid filtering update transients.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Updates {

	/**
	 * Register hooks (Pro package only).
	 */
	public static function init() {
		if ( ! class_exists( 'PigCache_License', false ) || ! PigCache_License::has_pro_distribution() ) {
			return;
		}

		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'filter_update_plugins' ), 20 );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_pre_set_update_plugins' ), 20, 2 );
	}

	/**
	 * @param object|false $transient
	 * @return object|false
	 */
	public static function filter_update_plugins( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->response ) ) {
			return $transient;
		}

		if ( PigCache_License::updates_allowed() ) {
			return $transient;
		}

		self::strip_pigcache_response( $transient );

		return $transient;
	}

	/**
	 * @param object|false $transient
	 * @param string       $transient_name
	 * @return object|false
	 */
	public static function filter_pre_set_update_plugins( $transient, $transient_name ) {
		unset( $transient_name );

		if ( ! is_object( $transient ) || empty( $transient->response ) ) {
			return $transient;
		}

		if ( PigCache_License::updates_allowed() ) {
			return $transient;
		}

		self::strip_pigcache_response( $transient );

		return $transient;
	}

	/**
	 * @param object $transient
	 */
	private static function strip_pigcache_response( $transient ) {
		$slug = plugin_basename( PIGCACHE_FILE );

		if ( isset( $transient->response[ $slug ] ) ) {
			unset( $transient->response[ $slug ] );
		}
	}
}
