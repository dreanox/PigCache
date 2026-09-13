<?php
/**
 * EARLY vs LATE cache-key symmetry.
 *
 * The HTML cache writes its entry from the late path (after WordPress boots) and
 * tries to read it from the early path (before WordPress boots). The two paths
 * derive the key with different code, because the early one cannot call filters
 * or site_url(). If they ever disagree the cache still "works" — every request
 * just silently falls through to the slow path and the early path never hits.
 * That failure is invisible in production, which is exactly why it is pinned here.
 *
 * @package PigCache
 */

declare( strict_types=1 );

namespace PigCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PigCache_Html_Cache;
use PigCache_Test_WP;
use ReflectionMethod;

final class HtmlCacheKeyTest extends TestCase {

	protected function setUp(): void {
		PigCache_Test_WP::reset();
	}

	/**
	 * Calls a private static method on PigCache_Html_Cache.
	 *
	 * @param mixed ...$args
	 * @return mixed
	 */
	private static function call( string $method, ...$args ) {
		$m = new ReflectionMethod( PigCache_Html_Cache::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	/**
	 * Rebuilds the key the early path computes, mirroring serve_early().
	 *
	 * @return string Empty string when the early path would bail.
	 */
	private static function early_key(): string {
		$host = self::call( 'canonical_host_early' );
		if ( '' === $host ) {
			return '';
		}
		$uri = self::call( 'canonical_uri_early', $_SERVER['REQUEST_URI'] ?? '/' );
		return self::call( 'build_key', $host, $uri );
	}

	private static function request( string $host, string $uri ): void {
		$_SERVER['HTTP_HOST']   = $host;
		$_SERVER['SERVER_NAME'] = $host;
		$_SERVER['REQUEST_URI'] = $uri;
	}

	public function test_plain_url_produces_the_same_key_on_both_paths(): void {
		self::request( 'example.com', '/hello-world/' );

		$this->assertSame(
			PigCache_Html_Cache::cache_key(),
			self::early_key(),
			'a plain permalink must be readable from the early path'
		);
	}

	public function test_emoji_url_produces_the_same_key_on_both_paths(): void {
		// Percent-encoded emoji slug — the shape that used to always miss.
		self::request( 'example.com', '/%F0%9F%8F%86-todo-o-nada/' );

		$this->assertSame(
			PigCache_Html_Cache::cache_key(),
			self::early_key(),
			'non-ASCII slugs must not fall out of the early path'
		);
	}

	public function test_tracking_params_are_stripped_identically(): void {
		self::request( 'example.com', '/hello-world/?utm_source=fb&utm_medium=social' );

		$this->assertSame(
			PigCache_Html_Cache::cache_key(),
			self::early_key(),
			'the default tracking params must be stripped by both paths'
		);
	}

	public function test_meaningful_query_params_still_separate_entries(): void {
		self::request( 'example.com', '/search/?q=redis' );
		$with = PigCache_Html_Cache::cache_key();

		self::request( 'example.com', '/search/?q=mysql' );
		$other = PigCache_Html_Cache::cache_key();

		$this->assertNotSame( $with, $other, 'non-tracking params change the resource' );
	}

	/**
	 * The trap: a theme or plugin extends the tracking-param list through the
	 * filter. The late path honours the filter, the early path cannot see it, so
	 * the two keys diverge and the early cache goes permanently cold.
	 */
	public function test_filter_added_tracking_params_break_symmetry(): void {
		// A param that is NOT in the built-in list, so the filter is what strips it.
		self::request( 'example.com', '/hello-world/?ref=newsletter' );

		$before_late  = PigCache_Html_Cache::cache_key();
		$before_early = self::early_key();
		$this->assertSame( $before_late, $before_early, 'precondition: symmetric without the filter' );

		PigCache_Test_WP::add_filter(
			'pigcache_tracking_query_params',
			static fn( $params ) => array_merge( (array) $params, array( 'ref' ) )
		);

		$after_late  = PigCache_Html_Cache::cache_key();
		$after_early = self::early_key();

		$this->assertNotSame(
			$after_late,
			$before_late,
			'the filter must actually affect the late path, otherwise this test proves nothing'
		);

		// Documents current behaviour. If the early path is ever taught to read
		// the filtered list (for example by persisting it in Redis at shutdown),
		// flip this to assertSame — the divergence is a real cost, not a feature.
		$this->assertNotSame(
			$after_late,
			$after_early,
			'known limitation: the early path cannot see filters, so custom tracking params cost the early hit'
		);
	}

	public function test_host_is_taken_from_site_url_not_the_host_header(): void {
		// A spoofed Host header must not be able to write to another site's key.
		PigCache_Test_WP::$site_url = 'https://example.com';
		$_SERVER['HTTP_HOST']       = 'evil.test';
		$_SERVER['SERVER_NAME']     = 'example.com';
		$_SERVER['REQUEST_URI']     = '/hello-world/';

		$spoofed = PigCache_Html_Cache::cache_key();

		self::request( 'example.com', '/hello-world/' );
		$honest = PigCache_Html_Cache::cache_key();

		$this->assertSame( $honest, $spoofed, 'the late path keys on site_url(), so the Host header cannot poison it' );
	}

	public function test_early_path_bails_when_host_header_disagrees_with_server_name(): void {
		$_SERVER['HTTP_HOST']       = 'evil.test';
		$_SERVER['SERVER_NAME']     = 'example.com';
		$_SERVER['REQUEST_URI']     = '/hello-world/';

		$this->assertSame(
			'',
			self::early_key(),
			'a mismatched Host must route through the late path rather than serve a guessed key'
		);
	}

	public function test_different_hosts_do_not_share_entries(): void {
		PigCache_Test_WP::$site_url = 'https://a.example';
		self::request( 'a.example', '/hello/' );
		$a = PigCache_Html_Cache::cache_key();

		PigCache_Test_WP::$site_url = 'https://b.example';
		self::request( 'b.example', '/hello/' );
		$b = PigCache_Html_Cache::cache_key();

		$this->assertNotSame( $a, $b, 'multisite installs share one Redis' );
	}
}
