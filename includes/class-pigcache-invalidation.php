<?php
/**
 * Cache invalidation with tag-based selective purging.
 *
 * Post hooks resolve specific tags (post:ID, terms, author, archives) and
 * delete only the Redis keys that reference those objects. SQL cache still
 * uses epoch-based invalidation. Additional scopes can be enabled via
 * wp-config.php constants.
 *
 * Constants:
 *   PIGCACHE_INVALIDATE_THROTTLE  int   Min seconds between invalidation runs (default 2).
 *   PIGCACHE_INVALIDATE_ON_OPTION bool  Purge on option add/update/delete.
 *   PIGCACHE_INVALIDATE_ON_TERM   bool  Purge on term create/edit/delete.
 *   PIGCACHE_INVALIDATE_ON_COMMENT bool Purge on comment changes.
 *   PIGCACHE_INVALIDATE_ON_NAV_MENU bool Purge on menu update/delete.
 *   PIGCACHE_INVALIDATE_ON_WIDGET  bool Purge on sidebar/widget changes.
 *   PIGCACHE_INVALIDATE_ON_THEME   bool Purge on theme switch / customizer save.
 *   PIGCACHE_INVALIDATE_ON_USER    bool Purge on user profile/register/delete.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Invalidation {

	/**
	 * Register WordPress hooks based on active constants.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 1 );
		add_action( 'deleted_post', array( __CLASS__, 'on_delete_post' ), 20, 1 );
		add_action( 'trashed_post', array( __CLASS__, 'on_delete_post' ), 20, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_status' ), 20, 3 );

		if ( defined( 'PIGCACHE_INVALIDATE_ON_OPTION' ) && PIGCACHE_INVALIDATE_ON_OPTION ) {
			add_action( 'updated_option', array( __CLASS__, 'on_generic_change' ), 20 );
			add_action( 'added_option', array( __CLASS__, 'on_generic_change' ), 20 );
			add_action( 'deleted_option', array( __CLASS__, 'on_generic_change' ), 20 );
		}

		if ( defined( 'PIGCACHE_INVALIDATE_ON_TERM' ) && PIGCACHE_INVALIDATE_ON_TERM ) {
			add_action( 'created_term', array( __CLASS__, 'on_term_change' ), 20, 3 );
			add_action( 'edited_term', array( __CLASS__, 'on_term_change' ), 20, 3 );
			add_action( 'delete_term', array( __CLASS__, 'on_term_change' ), 20, 3 );
		}

		if ( defined( 'PIGCACHE_INVALIDATE_ON_COMMENT' ) && PIGCACHE_INVALIDATE_ON_COMMENT ) {
			add_action( 'wp_insert_comment', array( __CLASS__, 'on_comment_change' ), 20, 2 );
			add_action( 'edit_comment', array( __CLASS__, 'on_comment_change' ), 20, 2 );
			add_action( 'delete_comment', array( __CLASS__, 'on_comment_change' ), 20, 2 );
			add_action( 'transition_comment_status', array( __CLASS__, 'on_comment_transition' ), 20, 3 );
		}

		if ( defined( 'PIGCACHE_INVALIDATE_ON_NAV_MENU' ) && PIGCACHE_INVALIDATE_ON_NAV_MENU ) {
			add_action( 'wp_update_nav_menu', array( __CLASS__, 'on_nav_menu_change' ), 20, 1 );
			add_action( 'wp_delete_nav_menu', array( __CLASS__, 'on_nav_menu_change' ), 20, 1 );
		}

		if ( defined( 'PIGCACHE_INVALIDATE_ON_WIDGET' ) && PIGCACHE_INVALIDATE_ON_WIDGET ) {
			add_action( 'update_option_sidebars_widgets', array( __CLASS__, 'on_generic_change' ), 20 );
			add_action( 'widget_update_callback', array( __CLASS__, 'on_widget_update' ), 20, 4 );
		}

		if ( defined( 'PIGCACHE_INVALIDATE_ON_THEME' ) && PIGCACHE_INVALIDATE_ON_THEME ) {
			add_action( 'switch_theme', array( __CLASS__, 'on_generic_change' ), 20 );
			add_action( 'customize_save_after', array( __CLASS__, 'on_generic_change' ), 20 );
		}

		if ( defined( 'PIGCACHE_INVALIDATE_ON_USER' ) && PIGCACHE_INVALIDATE_ON_USER ) {
			add_action( 'profile_update', array( __CLASS__, 'on_user_change' ), 20, 1 );
			add_action( 'user_register', array( __CLASS__, 'on_user_change' ), 20, 1 );
			add_action( 'delete_user', array( __CLASS__, 'on_user_change' ), 20, 1 );
		}
	}

	// ------------------------------------------------------------------
	// Post hooks — tag-based selective purge
	// ------------------------------------------------------------------

	/**
	 * @param int $post_id
	 */
	public static function on_save_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::invalidate_post( (int) $post_id );
	}

	/**
	 * @param int $post_id
	 */
	public static function on_delete_post( $post_id ) {
		self::invalidate_post( (int) $post_id );
	}

	/**
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 */
	public static function on_transition_status( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( $post instanceof WP_Post ) {
			self::invalidate_post( $post->ID );
		}
	}

	/**
	 * Resolve tags for a post and purge matching cache keys.
	 *
	 * @param int $post_id
	 */
	public static function invalidate_post( $post_id ) {
		if ( ! self::acquire_throttle() ) {
			return;
		}

		$tags = self::resolve_post_tags( $post_id );
		$tags = apply_filters( 'pigcache_invalidation_tags', $tags, $post_id );

		if ( class_exists( 'PigCache_Tag_Index', false ) && ! empty( $tags ) ) {
			PigCache_Tag_Index::purge_by_tags( $tags );
		}

		self::bump_sql_epoch();
	}

	/**
	 * Build the tag list for a given post.
	 *
	 * @param int $post_id
	 * @return string[]
	 */
	public static function resolve_post_tags( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'post:' . $post_id, 'home', 'feed' );
		}

		$tags = array(
			'post:' . $post->ID,
			'post_type:' . $post->post_type,
			'author:' . $post->post_author,
			'home',
			'feed',
		);

		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$tags[] = 'term:' . $term->term_id;
					$tags[] = 'taxonomy:' . $taxonomy;
				}
			}
		}

		$date_tag = 'date:' . get_the_date( 'Y-m', $post );
		if ( $date_tag !== 'date:' ) {
			$tags[] = $date_tag;
		}

		return array_unique( $tags );
	}

	// ------------------------------------------------------------------
	// Term hooks — tag-based
	// ------------------------------------------------------------------

	/**
	 * @param int    $term_id
	 * @param int    $tt_id
	 * @param string $taxonomy
	 */
	public static function on_term_change( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( ! self::acquire_throttle() ) {
			return;
		}

		$tags = array(
			'term:' . (int) $term_id,
			'taxonomy:' . $taxonomy,
		);

		if ( class_exists( 'PigCache_Tag_Index', false ) ) {
			PigCache_Tag_Index::purge_by_tags( $tags );
		}

		self::bump_sql_epoch();
	}

	// ------------------------------------------------------------------
	// Comment hooks — tag-based (purge the parent post)
	// ------------------------------------------------------------------

	/**
	 * @param int              $comment_id
	 * @param WP_Comment|array $comment
	 */
	public static function on_comment_change( $comment_id, $comment = null ) {
		if ( ! self::acquire_throttle() ) {
			return;
		}

		$comment_obj = get_comment( $comment_id );
		if ( $comment_obj && $comment_obj->comment_post_ID ) {
			$tags = self::resolve_post_tags( (int) $comment_obj->comment_post_ID );
			if ( class_exists( 'PigCache_Tag_Index', false ) ) {
				PigCache_Tag_Index::purge_by_tags( $tags );
			}
		}

		self::bump_sql_epoch();
	}

	/**
	 * @param string     $new_status
	 * @param string     $old_status
	 * @param WP_Comment $comment
	 */
	public static function on_comment_transition( $new_status, $old_status, $comment ) {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( $comment instanceof WP_Comment && $comment->comment_post_ID ) {
			self::on_comment_change( $comment->comment_ID, $comment );
		}
	}

	// ------------------------------------------------------------------
	// Nav menu hooks — tag-based
	// ------------------------------------------------------------------

	/**
	 * @param int $menu_id
	 */
	public static function on_nav_menu_change( $menu_id ) {
		if ( ! self::acquire_throttle() ) {
			return;
		}

		$tags = array( 'nav_menu:' . (int) $menu_id );

		if ( class_exists( 'PigCache_Tag_Index', false ) ) {
			PigCache_Tag_Index::purge_by_tags( $tags );
		}

		self::bump_sql_epoch();
	}

	// ------------------------------------------------------------------
	// User hooks — tag-based
	// ------------------------------------------------------------------

	/**
	 * @param int $user_id
	 */
	public static function on_user_change( $user_id ) {
		if ( ! self::acquire_throttle() ) {
			return;
		}

		$tags = array( 'author:' . (int) $user_id );

		if ( class_exists( 'PigCache_Tag_Index', false ) ) {
			PigCache_Tag_Index::purge_by_tags( $tags );
		}

		self::bump_sql_epoch();
	}

	// ------------------------------------------------------------------
	// Widget update — passthrough filter
	// ------------------------------------------------------------------

	/**
	 * @param array  $instance
	 * @param array  $new_instance
	 * @param array  $old_instance
	 * @param object $widget
	 * @return array
	 */
	public static function on_widget_update( $instance, $new_instance, $old_instance, $widget ) {
		self::on_generic_change();

		return $instance;
	}

	// ------------------------------------------------------------------
	// Generic change — full HTML purge via SQL-like epoch bump for cases
	// where we can't resolve specific tags (options, theme, widgets).
	// These still use tag-based purge if possible, but fall back to
	// bumping the SQL epoch only.
	// ------------------------------------------------------------------

	/**
	 * Generic content change handler for non-taggable events.
	 * Only bumps the SQL epoch (HTML pages remain cached until TTL expires
	 * or until a tagged object changes).
	 */
	public static function on_generic_change() {
		if ( ! self::acquire_throttle() ) {
			return;
		}

		self::bump_sql_epoch();
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * Throttle mechanism to prevent rapid-fire invalidation.
	 *
	 * @return bool True if allowed to proceed.
	 */
	private static function acquire_throttle() {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return false;
		}

		$throttle = defined( 'PIGCACHE_INVALIDATE_THROTTLE' ) ? (int) PIGCACHE_INVALIDATE_THROTTLE : 2;

		if ( $throttle > 0 ) {
			$lock = wp_cache_get( 'pigcache_inv_lock', 'pigcache' );

			if ( false !== $lock ) {
				return false;
			}

			wp_cache_set( 'pigcache_inv_lock', time(), 'pigcache', $throttle );
		}

		return true;
	}

	/**
	 * Bump the SQL cache epoch (epoch-based invalidation for queries).
	 */
	private static function bump_sql_epoch() {
		if ( class_exists( 'PigCache_Sql_Cache', false ) ) {
			PigCache_Sql_Cache::bump_epoch();
		}
	}
}
