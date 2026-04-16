<?php
/**
 * Fragment caching helper with optional tag-based invalidation.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Fragments {

	/**
	 * @param string   $key
	 * @param callable $callback
	 * @param int      $ttl
	 * @param string   $group
	 * @param string[] $tags  Optional tags for selective invalidation.
	 * @return mixed
	 */
	public static function remember( $key, $callback, $ttl = 60, $group = 'pigcache_fragments', $tags = array() ) {
		if ( ! is_callable( $callback ) ) {
			return null;
		}

		$safe_key = 'f_' . md5( (string) $key );
		$data     = wp_cache_get( $safe_key, $group );

		if ( false !== $data ) {
			return $data;
		}

		$data = call_user_func( $callback );

		wp_cache_set( $safe_key, $data, $group, (int) $ttl );

		if ( ! empty( $tags ) && class_exists( 'PigCache_Tag_Index', false ) ) {
			PigCache_Tag_Index::store_tags( $safe_key, $group, $tags );
		}

		return $data;
	}
}
