<?php
/**
 * Per-table SQL invalidation.
 *
 * The whole value of per-table epochs is that a write to one table does not
 * throw away cached reads of every other table. If that isolation breaks the
 * cache still returns correct data, so nothing looks wrong — the hit rate just
 * silently collapses to near zero under normal write traffic.
 *
 * @package PigCache
 */

declare( strict_types=1 );

final class SqlEpochTest extends PigCache_Integration_TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->require_redis();
	}

	public function test_bumping_a_table_epoch_changes_only_that_table(): void {
		$posts_before   = PigCache_Sql_Cache::get_table_epoch( 'wp_posts' );
		$options_before = PigCache_Sql_Cache::get_table_epoch( 'wp_options' );

		PigCache_Sql_Cache::bump_table_epoch( 'wp_posts' );

		$this->assertNotSame(
			$posts_before,
			PigCache_Sql_Cache::get_table_epoch( 'wp_posts' ),
			'the written table must be invalidated'
		);
		$this->assertSame(
			$options_before,
			PigCache_Sql_Cache::get_table_epoch( 'wp_options' ),
			'an unrelated table must keep its cached reads'
		);
	}

	public function test_get_table_epochs_returns_one_entry_per_table(): void {
		$epochs = PigCache_Sql_Cache::get_table_epochs( array( 'wp_posts', 'wp_options', 'wp_terms' ) );

		$this->assertCount( 3, $epochs );
		foreach ( array( 'wp_posts', 'wp_options', 'wp_terms' ) as $table ) {
			$this->assertArrayHasKey( $table, $epochs );
		}
	}

	public function test_global_epoch_bump_invalidates_everything(): void {
		$before = PigCache_Sql_Cache::get_epoch();
		PigCache_Sql_Cache::bump_epoch();

		$this->assertNotSame(
			$before,
			PigCache_Sql_Cache::get_epoch(),
			'the global epoch is the fallback when a query cannot be attributed to a table'
		);
	}

	public function test_inserting_a_post_bumps_the_posts_epoch(): void {
		global $wpdb;

		$before = PigCache_Sql_Cache::get_table_epoch( $wpdb->posts );
		$this->make_post();

		$this->assertNotSame(
			$before,
			PigCache_Sql_Cache::get_table_epoch( $wpdb->posts ),
			'a real write through $wpdb must reach the epoch, not just a direct bump call'
		);
	}

	public function test_a_cached_select_is_reused_until_its_table_changes(): void {
		global $wpdb;

		if ( ! method_exists( $wpdb, 'pigcache_extract_tables' ) ) {
			$this->markTestSkipped( 'The db drop-in is not active.' );
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'";

		$first = (int) $wpdb->get_var( $sql );
		$this->assertGreaterThanOrEqual( 0, $first );

		// Reading again must agree with itself.
		$this->assertSame( $first, (int) $wpdb->get_var( $sql ) );

		// A write to wp_posts must be visible on the next read.
		$this->make_post();
		$this->assertSame(
			$first + 1,
			(int) $wpdb->get_var( $sql ),
			'a cached SELECT must not survive a write to its own table'
		);
	}

	public function test_writing_to_an_unrelated_table_does_not_disturb_a_cached_select(): void {
		global $wpdb;

		$sql   = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'";
		$count = (int) $wpdb->get_var( $sql );

		$posts_epoch = PigCache_Sql_Cache::get_table_epoch( $wpdb->posts );

		update_option( 'pigcache_test_unrelated', uniqid() );

		$this->assertSame(
			$posts_epoch,
			PigCache_Sql_Cache::get_table_epoch( $wpdb->posts ),
			'touching wp_options must leave wp_posts reads cached'
		);
		$this->assertSame( $count, (int) $wpdb->get_var( $sql ) );

		delete_option( 'pigcache_test_unrelated' );
	}
}
