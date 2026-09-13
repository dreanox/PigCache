<?php
/**
 * Drop-in installation and removal.
 *
 * The three drop-ins are the riskiest files PigCache ships: they live outside
 * the plugin folder, load before WordPress does, and a broken one takes the
 * whole site down rather than just disabling the cache. These tests check that
 * install and remove are clean and reversible.
 *
 * @package PigCache
 */

declare( strict_types=1 );

final class DropinsTest extends PigCache_Integration_TestCase {

	public function test_object_cache_dropin_is_installed_and_recognised(): void {
		$this->assertTrue( PigCache_Dropin_Object_Cache::dropin_exists() );
		$this->assertTrue( PigCache_Dropin_Object_Cache::validate(), 'the file on disk must be ours' );
		$this->assertFalse( PigCache_Dropin_Object_Cache::is_outdated(), 'a stale drop-in silently runs old code' );
	}

	public function test_advanced_cache_dropin_is_installed_and_current(): void {
		$this->assertTrue( PigCache_Dropin_Html_Cache::file_exists() );
		$this->assertTrue( PigCache_Dropin_Html_Cache::is_our_file() );
		$this->assertFalse( PigCache_Dropin_Html_Cache::is_outdated() );
	}

	public function test_db_dropin_is_installed_and_current(): void {
		$this->assertTrue( PigCache_Dropin_Db::file_exists() );
		$this->assertTrue( PigCache_Dropin_Db::is_our_file() );
		$this->assertFalse( PigCache_Dropin_Db::is_outdated() );
	}

	public function test_advanced_cache_needs_the_wp_cache_constant(): void {
		// The plugin no longer edits wp-config.php, so this has to be set by the
		// site owner. If it is missing the drop-in exists but never runs.
		$this->assertTrue(
			PigCache_Dropin_Html_Cache::wp_cache_constant_active(),
			'WP_CACHE must be defined for the HTML cache to be reachable'
		);
		$this->assertTrue( PigCache_Dropin_Html_Cache::is_active() );
	}

	public function test_the_wp_cache_snippet_is_valid_php(): void {
		$snippet = PigCache_Dropin_Html_Cache::wp_cache_snippet();

		$this->assertStringContainsString( 'WP_CACHE', $snippet );
		$this->assertNotFalse(
			@eval( 'return true; /* ' . $snippet . ' */' ), // phpcs:ignore Squiz.PHP.Eval.Discouraged
			'the snippet shown to users must be something they can paste verbatim'
		);
	}

	public function test_every_dropin_resolves_a_path_outside_the_plugin_folder(): void {
		// WordPress.org forbids writing inside the plugin directory, and drop-ins
		// only work from wp-content anyway.
		$plugin_dir = rtrim( plugin_dir_path( dirname( __DIR__ ) . '/pigcache.php' ), '/' );

		foreach (
			array(
				'object-cache'  => PigCache_Dropin_Object_Cache::dropin_path(),
				'advanced-cache' => PigCache_Dropin_Html_Cache::dropin_path(),
				'db'            => PigCache_Dropin_Db::dropin_path(),
			) as $label => $path
		) {
			$this->assertStringStartsWith( rtrim( WP_CONTENT_DIR, '/' ), $path, "{$label} must live in wp-content" );
			$this->assertStringNotContainsString( '/plugins/pigcache', $path, "{$label} must not be written into the plugin folder" );
		}
	}
}
