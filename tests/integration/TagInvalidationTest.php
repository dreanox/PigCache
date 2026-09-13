<?php
/**
 * Tag-based selective invalidation.
 *
 * This is the feature that keeps a busy news site from flushing its whole cache
 * every time an editor saves a post. The test that matters is the negative one:
 * purging one post's tag must leave every other entry alone.
 *
 * @package PigCache
 */

declare( strict_types=1 );

final class TagInvalidationTest extends PigCache_Integration_TestCase {

	private const GROUP = 'pigcache_tag_tests';

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'PigCache_Tag_Index' ) ) {
			$this->markTestSkipped( 'PigCache_Tag_Index is not loaded.' );
		}
		$this->require_redis();
	}

	public function test_the_tag_table_exists(): void {
		global $wpdb;

		$table = PigCache_Tag_Index::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame(
			$table,
			$found,
			'the tag index table must be created on activation and on upgrade'
		);
	}

	public function test_stored_tags_map_back_to_their_keys(): void {
		$key = 'tagged_' . uniqid();
		$tag = 'post_' . uniqid();

		PigCache_Tag_Index::store_tags( $key, self::GROUP, array( $tag ) );

		// get_keys_for_tags() returns {cache_key, grp} rows, not bare keys.
		$rows = PigCache_Tag_Index::get_keys_for_tags( array( $tag ) );

		$this->assertContains(
			$key,
			array_column( $rows, 'cache_key' ),
			'a stored tag must resolve to the key that declared it'
		);

		PigCache_Tag_Index::remove_keys( array( $key ) );
	}

	public function test_purging_one_tag_leaves_other_entries_untouched(): void {
		$keep_key = 'keep_' . uniqid();
		$drop_key = 'drop_' . uniqid();
		$keep_tag = 'keep_tag_' . uniqid();
		$drop_tag = 'drop_tag_' . uniqid();

		wp_cache_set( $keep_key, 'sobrevive', self::GROUP, 300 );
		wp_cache_set( $drop_key, 'se-purga', self::GROUP, 300 );

		PigCache_Tag_Index::store_tags( $keep_key, self::GROUP, array( $keep_tag ) );
		PigCache_Tag_Index::store_tags( $drop_key, self::GROUP, array( $drop_tag ) );

		PigCache_Tag_Index::purge_by_tags( array( $drop_tag ) );

		$this->assertFalse(
			wp_cache_get( $drop_key, self::GROUP ),
			'the tagged entry must be gone'
		);
		$this->assertSame(
			'sobrevive',
			wp_cache_get( $keep_key, self::GROUP ),
			'selective invalidation must not degenerate into a global flush'
		);

		PigCache_Tag_Index::remove_keys( array( $keep_key ) );
	}

	public function test_an_entry_with_several_tags_is_purged_by_any_of_them(): void {
		$key  = 'multi_' . uniqid();
		$tags = array( 'a_' . uniqid(), 'b_' . uniqid() );

		wp_cache_set( $key, 'x', self::GROUP, 300 );
		PigCache_Tag_Index::store_tags( $key, self::GROUP, $tags );

		PigCache_Tag_Index::purge_by_tags( array( $tags[1] ) );

		$this->assertFalse( wp_cache_get( $key, self::GROUP ) );
	}

	public function test_purging_an_unknown_tag_is_a_no_op(): void {
		$key = 'untouched_' . uniqid();
		wp_cache_set( $key, 'x', self::GROUP, 300 );

		PigCache_Tag_Index::purge_by_tags( array( 'tag_que_no_existe_' . uniqid() ) );

		$this->assertSame( 'x', wp_cache_get( $key, self::GROUP ) );
		wp_cache_delete( $key, self::GROUP );
	}

	public function test_cleanup_removes_rows_whose_cache_entry_is_gone(): void {
		global $wpdb;

		$key = 'stale_' . uniqid();
		$tag = 'stale_tag_' . uniqid();

		// A tag row with no matching cache entry: what is left behind when Redis
		// evicts or expires an entry the index still points at.
		PigCache_Tag_Index::store_tags( $key, self::GROUP, array( $tag ) );
		wp_cache_delete( $key, self::GROUP );

		PigCache_Tag_Index::cleanup_stale();

		$table = PigCache_Tag_Index::table_name();
		$rows  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE tag = %s", $tag ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( 0, $rows, 'the index must not grow without bound' );
	}
}
