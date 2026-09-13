<?php
/**
 * Path canonicalisation and bypass rules for the URL firewall.
 *
 * These two functions decide whether a request is eligible to be blocked, and
 * which Redis key it maps to. A regression here either lets attacks through
 * (harmless) or blocks real content (not harmless), so every rule has a case.
 *
 * @package PigCache
 */

declare( strict_types=1 );

namespace PigCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PigCache_Test_WP;
use PigCache_Url_Firewall;

final class UrlFirewallPathTest extends TestCase {

	protected function setUp(): void {
		PigCache_Test_WP::reset();
	}

	/**
	 * @dataProvider canonicalPathCases
	 */
	public function test_canonical_path( string $input, string $expected, string $why ): void {
		$this->assertSame( $expected, PigCache_Url_Firewall::canonical_path( $input ), $why );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function canonicalPathCases(): array {
		return array(
			'root stays root' => array( '/', '/', 'root must not grow a second slash' ),

			'query string is dropped' => array(
				'/hello-world/?utm_source=x',
				'/hello-world/',
				'query params never take part in the path identity',
			),

			'fragment is dropped' => array( '/hello/#anchor', '/hello/', 'fragments never reach the server anyway' ),

			'duplicate slashes collapse' => array( '//a///b//', '/a/b/', 'proxies and bad links produce these' ),

			'trailing slash is added' => array( '/hello-world', '/hello-world/', 'permalinks are stored with it' ),

			'file extension keeps no trailing slash' => array(
				'/robots.txt',
				'/robots.txt',
				'adding a slash would turn a file into a directory',
			),

			'pagination suffix is folded away' => array(
				'/category/news/page/4/',
				'/category/news/',
				'page N of an archive is the same resource for firewall purposes',
			),

			'leading slash is forced' => array( 'hello/', '/hello/', 'REQUEST_URI should always start with /' ),

			// The bug that made emoji URLs miss the cache: REQUEST_URI arrives
			// percent-encoded while get_permalink() returns raw UTF-8. Both sides
			// must land on the same string or the md5 differs.
			'percent-encoded utf8 decodes to raw' => array(
				'/%F0%9F%8F%86-campeon/',
				"/\u{1F3C6}-campeon/",
				'encoded and raw forms of the same slug must canonicalise identically',
			),

			'accented characters decode' => array( '/ni%C3%B1os/', '/niños/', 'same reason as emoji' ),

			'raw utf8 is left alone' => array( '/niños/', '/niños/', 'decoding must be idempotent' ),

			'encoded null byte is stripped' => array(
				'/hello%00evil/',
				'/helloevil/',
				'control bytes must never reach a Redis key',
			),

			'encoded newline is stripped' => array(
				'/hello%0Aevil/',
				'/helloevil/',
				'newlines could forge a second Redis command in a naive client',
			),
		);
	}

	public function test_canonical_path_is_idempotent(): void {
		foreach ( self::canonicalPathCases() as $case ) {
			$once  = PigCache_Url_Firewall::canonical_path( $case[0] );
			$twice = PigCache_Url_Firewall::canonical_path( $once );
			$this->assertSame( $once, $twice, "canonical_path must be stable for {$case[0]}" );
		}
	}

	/**
	 * @dataProvider bypassedCases
	 */
	public function test_bypassed_paths( string $path, bool $expected, string $why ): void {
		$this->assertSame( $expected, PigCache_Url_Firewall::is_bypassed( $path ), $why );
	}

	/**
	 * @return array<string, array{0:string,1:bool,2:string}>
	 */
	public static function bypassedCases(): array {
		return array(
			'home'        => array( '/', true, 'the front page is never a 404' ),
			'wp-admin'    => array( '/wp-admin/edit.php', true, 'admin must never be intercepted' ),
			'wp-login'    => array( '/wp-login.php', true, 'login must never be intercepted' ),
			'rest api'    => array( '/wp-json/wp/v2/posts', true, 'the REST API handles its own 404s' ),
			'uploads'     => array( '/wp-content/uploads/a.jpg', true, 'static files are the webserver job' ),
			'cron'        => array( '/wp-cron.php', true, 'cron must always run' ),
			'well-known'  => array( '/.well-known/acme-challenge/x', true, 'certificate renewal must not 404' ),
			'author'      => array( '/author/jane/', true, 'author archives are not in any index we keep' ),
			'feed'        => array( '/hello/feed/', true, 'feeds are generated, not stored' ),
			'sitemap'     => array( '/wp-sitemap.xml', true, 'sitemaps are generated' ),
			'date archive' => array( '/2026/09/', true, 'date archives are generated from the query' ),

			'plain post'  => array( '/hello-world/', false, 'a normal permalink is exactly what we evaluate' ),
			'category'    => array( '/category/news/', false, 'term archives are evaluated too' ),
			'deep path'   => array( '/a/b/c/d/', false, 'nested paths are evaluated' ),
		);
	}

	public function test_bypass_filter_can_add_exceptions(): void {
		$this->assertFalse(
			PigCache_Url_Firewall::is_bypassed( '/tienda/carrito/' ),
			'precondition: the path is evaluated by default'
		);

		PigCache_Test_WP::add_filter(
			'pigcache_fw_bypass',
			static fn( $bypass, $path ) => $bypass || 0 === strpos( (string) $path, '/tienda/' )
		);

		$this->assertTrue(
			PigCache_Url_Firewall::is_bypassed( '/tienda/carrito/' ),
			'sites must be able to exempt plugin endpoints such as WooCommerce'
		);
	}

	public function test_deny_key_is_namespaced_per_host(): void {
		$a = PigCache_Url_Firewall::deny_key( 'a.example', '/hello/' );
		$b = PigCache_Url_Firewall::deny_key( 'b.example', '/hello/' );

		$this->assertNotSame( $a, $b, 'two sites sharing a Redis must not share verdicts' );
		$this->assertStringStartsWith( 'pigcache:fw:404:a.example:', $a );
	}

	public function test_deny_key_matches_the_purge_pattern(): void {
		$prefix = PigCache_Url_Firewall::deny_prefix( 'a.example' );
		$key    = PigCache_Url_Firewall::deny_key( 'a.example', '/hello/' );

		// purge() deletes by SCAN over deny_prefix().'*' — if the key ever stops
		// living under that prefix, purging silently becomes a no-op.
		$this->assertStringStartsWith( $prefix, $key );
	}

	public function test_ttl_is_clamped_to_a_sane_range(): void {
		$this->assertSame( 604800, PigCache_Url_Firewall::ttl(), 'default is one week' );
	}
}
