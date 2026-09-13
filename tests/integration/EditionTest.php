<?php
/**
 * What each package is and is not allowed to contain.
 *
 * WordPress.org rejects plugins that ship "trialware": functionality that is
 * present in the code but switched off pending payment. The Free package must
 * therefore contain no Pro code at all — not disabled Pro code — while every
 * feature it does ship has to be fully working, with no licence check anywhere
 * in the path.
 *
 * These are the tests that would have caught the rejection.
 *
 * @package PigCache
 */

declare( strict_types=1 );

final class EditionTest extends PigCache_Integration_TestCase {

	// ── Free: nothing Pro may be present ────────────────────────────────────

	/**
	 * @dataProvider proClasses
	 */
	public function test_free_package_contains_no_pro_class( string $class ): void {
		$this->require_free();

		$this->assertFalse(
			class_exists( $class ),
			"{$class} is a Pro class and must not exist in the Free package at all — not even disabled"
		);
	}

	public static function proClasses(): array {
		return array_map( static fn( $c ) => array( $c ), self::PRO_CLASSES );
	}

	public function test_free_package_has_no_pro_directory(): void {
		$this->require_packaged();
		$this->require_free();

		$this->assertDirectoryDoesNotExist(
			WP_PLUGIN_DIR . '/pigcache/includes/pro',
			'the Pro sources must be stripped from the package, not merely unloaded'
		);
	}

	public function test_free_package_has_no_licence_state(): void {
		$this->require_free();

		foreach ( array( 'pigcache_license_key', 'pigcache_cloud_site_id', 'pigcache_trial_started' ) as $option ) {
			$this->assertFalse(
				get_option( $option, false ),
				"{$option} is licensing state and has no reason to exist in the Free package"
			);
		}
	}

	public function test_free_package_registers_no_pro_cron_jobs(): void {
		$this->require_free();

		foreach (
			array(
				'pigcache_flush_query_stats',
				'pigcache_send_query_stats',
				'pigcache_mutation_harvest',
				'pigcache_traffic_harvest',
			) as $hook
		) {
			$this->assertFalse(
				wp_next_scheduled( $hook ),
				"{$hook} belongs to the Pro learning pipeline and must not be scheduled"
			);
		}
	}

	// ── Free: everything it ships must actually work ────────────────────────

	/**
	 * The specific complaint from the review: tag invalidation was implemented
	 * but the Free build fell back to a global flush. It must now do the real
	 * thing.
	 */
	public function test_free_package_has_working_tag_invalidation(): void {
		$this->require_free();
		$this->require_redis();

		$this->assertTrue( class_exists( 'PigCache_Tag_Index' ), 'tag invalidation must ship in Free' );

		$keep = 'edition_keep_' . uniqid();
		$drop = 'edition_drop_' . uniqid();
		$tag  = 'edition_tag_' . uniqid();

		wp_cache_set( $keep, 'a', 'pigcache_edition', 300 );
		wp_cache_set( $drop, 'b', 'pigcache_edition', 300 );
		PigCache_Tag_Index::store_tags( $drop, 'pigcache_edition', array( $tag ) );

		$rows = PigCache_Tag_Index::get_keys_for_tags( array( $tag ) );
		$this->assertSame(
			array( array( 'cache_key' => $drop, 'grp' => 'pigcache_edition' ) ),
			$rows,
			'the tag must resolve to exactly the entry that declared it'
		);

		$this->assertSame( 1, PigCache_Tag_Index::purge_by_tags( array( $tag ) ), 'one entry must be purged' );

		$this->assertFalse( wp_cache_get( $drop, 'pigcache_edition' ), 'the tagged entry must be purged' );
		$this->assertSame(
			'a',
			wp_cache_get( $keep, 'pigcache_edition' ),
			'Free must purge selectively, not degrade to a global flush'
		);

		wp_cache_delete( $keep, 'pigcache_edition' );
	}

	public function test_free_package_has_working_per_table_sql_invalidation(): void {
		$this->require_free();
		$this->require_redis();

		$other = PigCache_Sql_Cache::get_table_epoch( 'wp_options' );
		PigCache_Sql_Cache::bump_table_epoch( 'wp_posts' );

		$this->assertSame(
			$other,
			PigCache_Sql_Cache::get_table_epoch( 'wp_options' ),
			'Free must invalidate per table, not bump a global epoch'
		);
	}

	public function test_free_package_ships_the_url_firewall(): void {
		$this->require_free();

		$this->assertTrue(
			class_exists( 'PigCache_Url_Firewall' ),
			'the firewall is local logic with no external service, so it ships in Free'
		);
	}

	// ── Pro: everything must be present ─────────────────────────────────────

	/**
	 * @dataProvider proClasses
	 */
	public function test_pro_package_contains_every_pro_class( string $class ): void {
		$this->require_pro();

		$this->assertTrue(
			class_exists( $class ),
			"{$class} is missing from the Pro package — build.sh stripped too much"
		);
	}

	public function test_pro_package_keeps_every_free_feature(): void {
		$this->require_pro();

		// Nothing that ships in Free may be lost on the way to Pro.
		foreach ( array( 'PigCache_Tag_Index', 'PigCache_Tag_Collector', 'PigCache_Url_Firewall', 'PigCache_Sql_Cache' ) as $class ) {
			$this->assertTrue( class_exists( $class ), "{$class} must exist in both packages" );
		}
	}

	// ── Both ────────────────────────────────────────────────────────────────

	/**
	 * Pro keeps its markers — it is built from the same source and never
	 * reviewed. In Free they must be gone, and a surviving one means build.sh
	 * shipped a block it meant to strip.
	 */
	public function test_no_pro_markers_survive_in_the_free_package(): void {
		$this->require_packaged();
		$this->require_free();

		$plugin = WP_PLUGIN_DIR . '/pigcache';
		$found  = array();

		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( false !== strpos( $file->getPathname(), '/vendor/' ) || false !== strpos( $file->getPathname(), '/tests/' ) ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			if ( false !== strpos( $contents, 'PRO_START' ) || false !== strpos( $contents, 'PRO_END' ) ) {
				$found[] = str_replace( $plugin . '/', '', $file->getPathname() );
			}
		}

		$this->assertSame( array(), $found, 'build markers must not reach a shipped package' );
	}
}
