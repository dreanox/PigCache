<?php
/**
 * The wpdb drop-in must never hand a caller another query's result.
 *
 * Caching or invalidating a query can itself reach the database, and wpdb keeps
 * the outcome of the most recent query in shared properties. Every test here
 * starts from a cold object cache, because that is the only state in which the
 * nested lookups actually run: with a warm cache the bug is invisible.
 *
 * @package PigCache
 */

declare( strict_types=1 );

final class WpdbResultStateTest extends PigCache_Integration_TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->require_redis();
	}

	/**
	 * Drop everything so the TTL and epoch lookups behind the cache have to go
	 * to the database, which is what used to clobber the caller's rows.
	 */
	private function cold_cache(): void {
		wp_cache_flush();
	}

	public function test_first_select_after_a_write_returns_its_own_rows(): void {
		global $wpdb;

		$tag = 'state_' . uniqid();
		$key = 'state_key_' . uniqid();

		$this->cold_cache();

		PigCache_Tag_Index::store_tags( $key, 'pigcache_state', array( $tag ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cache_key, grp FROM ' . PigCache_Tag_Index::table_name() . ' WHERE tag = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$tag
			),
			ARRAY_A
		);

		$this->assertCount( 1, $rows, 'the first SELECT after a write must return its own rows, not a nested query\'s' );
		$this->assertSame( $key, $rows[0]['cache_key'] );

		$wpdb->delete( PigCache_Tag_Index::table_name(), array( 'tag' => $tag ) );
	}

	public function test_num_rows_matches_the_rows_returned(): void {
		global $wpdb;

		$this->cold_cache();

		$rows = $wpdb->get_results( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' LIMIT 3", ARRAY_A );

		$this->assertSame(
			count( $rows ),
			(int) $wpdb->num_rows,
			'num_rows must describe the query the caller ran'
		);
	}

	public function test_last_query_is_the_callers_query(): void {
		global $wpdb;

		$this->cold_cache();

		$sql = "SELECT ID FROM {$wpdb->posts} LIMIT 1";
		$wpdb->get_results( $sql, ARRAY_A );

		$this->assertSame( $sql, $wpdb->last_query, 'last_query must not be left pointing at an internal lookup' );
	}

	/**
	 * The write path has the same hazard, and here it is worse: bumping a table
	 * epoch can query the database, and a lost insert_id means the caller links
	 * its new row to the wrong ID.
	 */
	public function test_insert_id_survives_cache_invalidation(): void {
		global $wpdb;

		$this->cold_cache();

		$wpdb->insert(
			$wpdb->posts,
			array(
				'post_title'   => 'pigcache insert id ' . uniqid(),
				'post_content' => '',
				'post_status'  => 'draft',
				'post_type'    => 'post',
				'post_author'  => 1,
			)
		);

		$insert_id = (int) $wpdb->insert_id;

		$this->assertGreaterThan( 0, $insert_id, 'insert_id must survive the epoch bump that follows the write' );
		$this->assertSame(
			$insert_id,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $insert_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'the reported insert_id must be the row that was actually written'
		);

		$wpdb->delete( $wpdb->posts, array( 'ID' => $insert_id ) );
	}

	public function test_rows_affected_survives_cache_invalidation(): void {
		global $wpdb;

		$post_id = $this->make_post( array( 'post_title' => 'pigcache rows affected' ) );

		$this->cold_cache();

		$affected = $wpdb->update(
			$wpdb->posts,
			array( 'post_title' => 'pigcache rows affected updated' ),
			array( 'ID' => $post_id )
		);

		$this->assertSame( 1, $affected, 'the caller must see its own affected-row count' );
	}

	/**
	 * The rows that get cached must be the caller's rows too: caching the wrong
	 * ones turns a one-request glitch into a wrong answer for the whole TTL.
	 */
	public function test_a_cold_cache_stores_the_correct_rows(): void {
		global $wpdb;

		$tag = 'state_' . uniqid();
		$key = 'state_key_' . uniqid();

		PigCache_Tag_Index::store_tags( $key, 'pigcache_state', array( $tag ) );

		$this->cold_cache();

		$sql = $wpdb->prepare(
			'SELECT cache_key, grp FROM ' . PigCache_Tag_Index::table_name() . ' WHERE tag = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$tag
		);

		$first  = $wpdb->get_results( $sql, ARRAY_A ); // Cold: populates the cache.
		$second = $wpdb->get_results( $sql, ARRAY_A ); // Warm: served from it.

		$this->assertSame( $first, $second, 'the cached copy must match what the cold query returned' );
		$this->assertCount( 1, $second );

		$wpdb->delete( PigCache_Tag_Index::table_name(), array( 'tag' => $tag ) );
	}
}
