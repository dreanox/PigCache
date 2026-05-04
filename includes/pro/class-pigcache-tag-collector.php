<?php
/**
 * Collects cache tags during a page render (cache MISS only).
 *
 * Hooks into WordPress read-only actions/filters to detect which posts, terms,
 * menus, sidebars, etc. appear on the current page. The collected tags are then
 * stored alongside the HTML pack and in the MySQL tag index.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Tag_Collector {

	/** @var array<string, true> */
	private static $tags = array();

	/** @var bool */
	private static $active = false;

	/**
	 * Begin collecting tags for this request.
	 * Called by PigCache_Html_Cache just before ob_start().
	 */
	public static function start() {
		if ( self::$active ) {
			return;
		}

		self::$active = true;
		self::$tags   = array();

		add_action( 'the_post', array( __CLASS__, 'on_the_post' ), 10, 1 );
		add_filter( 'get_the_terms', array( __CLASS__, 'on_get_the_terms' ), 999, 3 );
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'on_nav_menu_items' ), 999, 3 );
		add_action( 'dynamic_sidebar_before', array( __CLASS__, 'on_sidebar_before' ), 10, 2 );
		add_action( 'get_header', array( __CLASS__, 'on_get_header' ), 10 );
		add_action( 'get_footer', array( __CLASS__, 'on_get_footer' ), 10 );

		self::add_implicit_tags();
	}

	/**
	 * Stop collecting and return the tag list. Unhooks everything.
	 *
	 * @return string[]
	 */
	public static function stop() {
		self::$active = false;

		remove_action( 'the_post', array( __CLASS__, 'on_the_post' ), 10 );
		remove_filter( 'get_the_terms', array( __CLASS__, 'on_get_the_terms' ), 999 );
		remove_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'on_nav_menu_items' ), 999 );
		remove_action( 'dynamic_sidebar_before', array( __CLASS__, 'on_sidebar_before' ), 10 );
		remove_action( 'get_header', array( __CLASS__, 'on_get_header' ), 10 );
		remove_action( 'get_footer', array( __CLASS__, 'on_get_footer' ), 10 );

		return array_keys( self::$tags );
	}

	/**
	 * @return bool
	 */
	public static function is_active() {
		return self::$active;
	}

	/**
	 * Public API — add a tag from a theme or plugin.
	 *
	 * @param string $tag  e.g. "widget:recent_posts", "custom:my_slider".
	 */
	public static function add( $tag ) {
		if ( self::$active && is_string( $tag ) && $tag !== '' ) {
			self::$tags[ $tag ] = true;
		}
	}

	/**
	 * @return string[]
	 */
	public static function get_tags() {
		return array_keys( self::$tags );
	}

	// ------------------------------------------------------------------
	// WordPress hook callbacks
	// ------------------------------------------------------------------

	/**
	 * @param WP_Post $post
	 */
	public static function on_the_post( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		self::$tags[ 'post:' . $post->ID ]             = true;
		self::$tags[ 'post_type:' . $post->post_type ]  = true;
		self::$tags[ 'author:' . $post->post_author ]   = true;
	}

	/**
	 * @param WP_Term[]|WP_Error $terms
	 * @param int                $post_id
	 * @param string             $taxonomy
	 * @return WP_Term[]|WP_Error passthrough
	 */
	public static function on_get_the_terms( $terms, $post_id, $taxonomy ) {
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term ) {
					self::$tags[ 'term:' . $term->term_id ]    = true;
					self::$tags[ 'taxonomy:' . $taxonomy ]     = true;
				}
			}
		}

		return $terms;
	}

	/**
	 * @param array  $items
	 * @param object $menu
	 * @param array  $args
	 * @return array passthrough
	 */
	public static function on_nav_menu_items( $items, $menu, $args ) {
		if ( is_object( $menu ) && isset( $menu->term_id ) ) {
			self::$tags[ 'nav_menu:' . $menu->term_id ] = true;
		}

		return $items;
	}

	/**
	 * @param string $index   Sidebar ID.
	 * @param bool   $has_widgets
	 */
	public static function on_sidebar_before( $index, $has_widgets ) {
		if ( is_string( $index ) && $index !== '' ) {
			self::$tags[ 'sidebar:' . $index ] = true;
		}
	}

	/**
	 * @param string $name Header template name.
	 */
	public static function on_get_header( $name = '' ) {
		self::$tags['zone:header'] = true;
	}

	/**
	 * @param string $name Footer template name.
	 */
	public static function on_get_footer( $name = '' ) {
		self::$tags['zone:footer'] = true;
	}

	// ------------------------------------------------------------------
	// Implicit tags based on the request context
	// ------------------------------------------------------------------

	private static function add_implicit_tags() {
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

			if ( $uri === '/' || strpos( $uri, '/?p=' ) === 0 ) {
				self::$tags['home'] = true;
			}

			if ( strpos( $uri, '/feed' ) !== false ) {
				self::$tags['feed'] = true;
			}
		}
	}
}
