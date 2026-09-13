<?php
/**
 * The object-cache drop-in against a real Redis.
 *
 * WordPress assumes a very specific contract from wp_cache_*. Most of the
 * production crashes in caching plugins come from one of these edge cases
 * returning the wrong type rather than from the happy path being broken.
 *
 * @package PigCache
 */

declare( strict_types=1 );

final class ObjectCacheTest extends PigCache_Integration_TestCase {

	private const GROUP = 'pigcache_tests';

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_cache_set' ) ) {
			$this->markTestSkipped( 'No object cache API available.' );
		}
	}

	public function test_dropin_is_the_pigcache_one(): void {
		$this->assertTrue(
			PigCache_Dropin_Object_Cache::dropin_exists(),
			'object-cache.php must be installed for the rest of the suite to mean anything'
		);
		$this->assertTrue( PigCache_Dropin_Object_Cache::validate() );
	}

	public function test_set_and_get_round_trip(): void {
		$key = 'rt_' . uniqid();
		$this->assertTrue( wp_cache_set( $key, 'valor', self::GROUP, 60 ) );
		$this->assertSame( 'valor', wp_cache_get( $key, self::GROUP ) );
	}

	public function test_missing_key_returns_false_with_found_flag(): void {
		$found = null;
		$value = wp_cache_get( 'nope_' . uniqid(), self::GROUP, false, $found );

		$this->assertFalse( $value );
		$this->assertFalse( $found, '$found is how callers distinguish a miss from a stored false' );
	}

	public function test_a_stored_false_is_not_mistaken_for_a_miss(): void {
		// The classic object-cache bug: storing false and then treating every
		// subsequent read as a cache miss, hammering the database forever.
		$key = 'false_' . uniqid();
		wp_cache_set( $key, false, self::GROUP, 60 );

		$found = null;
		$value = wp_cache_get( $key, self::GROUP, false, $found );

		$this->assertFalse( $value );
		$this->assertTrue( $found, 'a stored false must report as found' );
	}

	/**
	 * @dataProvider roundTripTypes
	 */
	public function test_value_types_survive_the_round_trip( string $label, $value ): void {
		$key = 'type_' . uniqid();
		wp_cache_set( $key, $value, self::GROUP, 60 );

		$this->assertSame( $value, wp_cache_get( $key, self::GROUP ), "{$label} must survive serialisation" );
	}

	public static function roundTripTypes(): array {
		return array(
			array( 'int', 42 ),
			array( 'zero', 0 ),
			array( 'float', 3.5 ),
			array( 'empty string', '' ),
			array( 'null', null ),
			array( 'array', array( 'a' => 1, 'b' => array( 2, 3 ) ) ),
			array( 'utf8', 'niños 🏆' ),
		);
	}

	public function test_add_does_not_overwrite(): void {
		$key = 'add_' . uniqid();

		$this->assertTrue( wp_cache_add( $key, 'first', self::GROUP, 60 ) );
		$this->assertFalse( wp_cache_add( $key, 'second', self::GROUP, 60 ), 'add must refuse an existing key' );
		$this->assertSame( 'first', wp_cache_get( $key, self::GROUP ) );
	}

	public function test_replace_requires_an_existing_key(): void {
		$key = 'repl_' . uniqid();

		$this->assertFalse( wp_cache_replace( $key, 'x', self::GROUP, 60 ), 'replace must refuse a missing key' );
		wp_cache_set( $key, 'a', self::GROUP, 60 );
		$this->assertTrue( wp_cache_replace( $key, 'b', self::GROUP, 60 ) );
		$this->assertSame( 'b', wp_cache_get( $key, self::GROUP ) );
	}

	public function test_delete_removes_the_entry(): void {
		$key = 'del_' . uniqid();
		wp_cache_set( $key, 'x', self::GROUP, 60 );

		$this->assertTrue( wp_cache_delete( $key, self::GROUP ) );
		$this->assertFalse( wp_cache_get( $key, self::GROUP ) );
	}

	public function test_incr_and_decr(): void {
		$key = 'ctr_' . uniqid();
		wp_cache_set( $key, 10, self::GROUP, 60 );

		$this->assertSame( 13, wp_cache_incr( $key, 3, self::GROUP ) );
		$this->assertSame( 11, wp_cache_decr( $key, 2, self::GROUP ) );
	}

	public function test_decr_does_not_go_below_zero(): void {
		// Core's contract: decrementing past zero clamps rather than going negative.
		$key = 'clamp_' . uniqid();
		wp_cache_set( $key, 1, self::GROUP, 60 );

		$this->assertSame( 0, wp_cache_decr( $key, 5, self::GROUP ) );
	}

	public function test_groups_are_isolated(): void {
		$key = 'iso_' . uniqid();
		wp_cache_set( $key, 'a', 'group_a', 60 );
		wp_cache_set( $key, 'b', 'group_b', 60 );

		$this->assertSame( 'a', wp_cache_get( $key, 'group_a' ) );
		$this->assertSame( 'b', wp_cache_get( $key, 'group_b' ) );
	}

	public function test_get_multiple_returns_one_entry_per_requested_key(): void {
		if ( ! function_exists( 'wp_cache_get_multiple' ) ) {
			$this->markTestSkipped( 'wp_cache_get_multiple is not implemented.' );
		}

		$hit  = 'multi_hit_' . uniqid();
		$miss = 'multi_miss_' . uniqid();
		wp_cache_set( $hit, 'yes', self::GROUP, 60 );

		$result = wp_cache_get_multiple( array( $hit, $miss ), self::GROUP );

		$this->assertArrayHasKey( $hit, $result );
		$this->assertArrayHasKey( $miss, $result, 'misses must still be present as false' );
		$this->assertSame( 'yes', $result[ $hit ] );
		$this->assertFalse( $result[ $miss ] );
	}

	public function test_expired_entries_disappear(): void {
		$key = 'ttl_' . uniqid();
		wp_cache_set( $key, 'x', self::GROUP, 1 );

		$this->assertSame( 'x', wp_cache_get( $key, self::GROUP ) );
		sleep( 2 );

		// The runtime cache would hide the expiry, so force a cold read.
		wp_cache_delete( $key . '__warm', self::GROUP );
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		$this->assertFalse( wp_cache_get( $key, self::GROUP ), 'the TTL must actually be applied in Redis' );
	}
}
