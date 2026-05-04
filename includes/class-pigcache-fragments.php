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

		$tag_inv = class_exists( 'PigCache_Tag_Index', false );

		if ( $tag_inv && ! empty( $tags ) ) {
			PigCache_Tag_Index::store_tags( $safe_key, $group, $tags );
		}

		return $data;
	}

	/**
	 * Flush the entire fragment cache group (global invalidation for Free tier).
	 */
	public static function flush_all() {
		wp_cache_flush_group( 'pigcache_fragments' );
	}
}
