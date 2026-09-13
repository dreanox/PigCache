<?php
/**
 * The URL firewall against a real Redis.
 *
 * The property that matters most is the one the old allowlist design could not
 * guarantee: a URL the firewall has never seen must be let through. Everything
 * else — blocking, forgetting, purging — is secondary to never 404ing real
 * content.
 *
 * @package PigCache
 */

declare( strict_types=1 );

final class UrlFirewallTest extends PigCache_Integration_TestCase {

	private function host(): string {
		$h = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return 0 === strncmp( $h, 'www.', 4 ) ? substr( $h, 4 ) : $h;
	}

	private function deny_key( string $path ): string {
		return $this->track_key( PigCache_Url_Firewall::deny_key( $this->host(), $path ) );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->require_redis();
		PigCache_Url_Firewall::reset_connection();
	}

	public function test_an_unknown_path_is_not_blocked(): void {
		$redis = $this->require_redis();
		$path  = '/nunca-vista-' . uniqid() . '/';

		$this->assertSame(
			0,
			$redis->exists( $this->deny_key( $path ) ),
			'a path nobody has resolved yet must carry no verdict, so the request reaches WordPress'
		);
	}

	public function test_a_confirmed_404_is_remembered_and_blocked_next_time(): void {
		$redis = $this->require_redis();
		$path  = '/inexistente-' . uniqid() . '/';
		$key   = $this->deny_key( $path );

		PigCache_Url_Firewall::remember_404( $path );

		$this->assertSame( 1, $redis->exists( $key ), 'the verdict must be persisted' );
		$this->assertGreaterThan( 0, $redis->ttl( $key ), 'a verdict without a TTL could outlive the content decision' );
		$this->assertLessThanOrEqual( PigCache_Url_Firewall::ttl(), $redis->ttl( $key ) );
	}

	public function test_remembering_is_idempotent(): void {
		$redis = $this->require_redis();
		$path  = '/inexistente-' . uniqid() . '/';
		$key   = $this->deny_key( $path );

		PigCache_Url_Firewall::remember_404( $path );
		PigCache_Url_Firewall::remember_404( $path );

		$this->assertSame( 1, $redis->exists( $key ) );
	}

	public function test_publishing_a_post_clears_the_verdict_for_its_url(): void {
		$redis = $this->require_redis();

		// Somebody probes a URL before the article exists.
		$slug = 'pigcache-fw-' . uniqid();
		$path = '/' . $slug . '/';
		PigCache_Url_Firewall::remember_404( $path );
		$key = $this->deny_key( $path );
		$this->assertSame( 1, $redis->exists( $key ), 'precondition: the probe was recorded' );

		// Now the article is published at exactly that URL.
		$post_id = $this->make_post( array( 'post_name' => $slug ) );
		PigCache_Url_Firewall::on_save_post( $post_id );

		$this->assertSame(
			0,
			$redis->exists( $key ),
			'a published permalink must never stay on the denylist'
		);
	}

	public function test_forget_is_safe_for_an_unknown_path(): void {
		PigCache_Url_Firewall::forget_404( '/jamas-vista-' . uniqid() . '/' );
		$this->addToAssertionCount( 1 );
	}

	public function test_bypassed_paths_are_never_evaluated(): void {
		foreach ( array( '/', '/wp-admin/edit.php', '/wp-json/wp/v2/posts', '/hello/feed/' ) as $path ) {
			$this->assertTrue(
				PigCache_Url_Firewall::is_bypassed( $path ),
				"{$path} must bypass the firewall entirely"
			);
		}
	}

	public function test_purge_forgets_everything_for_this_host(): void {
		$redis = $this->require_redis();

		$paths = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$path    = '/purga-' . uniqid() . '/';
			$paths[] = $path;
			PigCache_Url_Firewall::remember_404( $path );
			$this->deny_key( $path );
		}

		$this->assertGreaterThanOrEqual( 5, PigCache_Url_Firewall::learned_count() );

		$deleted = PigCache_Url_Firewall::purge();
		$this->assertNotNull( $deleted, 'purge must reach Redis' );

		foreach ( $paths as $path ) {
			$this->assertSame(
				0,
				$redis->exists( PigCache_Url_Firewall::deny_key( $this->host(), $path ) ),
				'purge must leave no verdict behind, otherwise a permalink change keeps 404ing real URLs'
			);
		}
	}

	public function test_purge_does_not_touch_another_hosts_verdicts(): void {
		$redis = $this->require_redis();

		$foreign = $this->track_key( PigCache_Url_Firewall::deny_key( 'otro-sitio.example', '/algo/' ) );
		$redis->setEx( $foreign, 300, (string) time() );

		PigCache_Url_Firewall::purge();

		$this->assertSame(
			1,
			$redis->exists( $foreign ),
			'sites sharing a Redis must not purge each other'
		);
	}

	public function test_a_structural_change_purges_the_denylist(): void {
		$redis = $this->require_redis();

		$path = '/antes-del-cambio-' . uniqid() . '/';
		PigCache_Url_Firewall::remember_404( $path );
		$key = $this->deny_key( $path );
		$this->assertSame( 1, $redis->exists( $key ) );

		// Changing the permalink structure rewrites every URL on the site, so
		// every learned verdict becomes a potential false positive.
		PigCache_Url_Firewall::on_structure_change();

		$this->assertSame( 0, $redis->exists( $key ) );
	}

	public function test_ttl_constant_is_clamped(): void {
		// Guards against a wp-config typo freezing a verdict for years.
		$this->assertGreaterThanOrEqual( 300, PigCache_Url_Firewall::ttl() );
		$this->assertLessThanOrEqual( 2592000, PigCache_Url_Firewall::ttl() );
	}
}
