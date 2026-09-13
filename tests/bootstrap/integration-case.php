<?php
/**
 * Shared base class for integration tests.
 *
 * These tests run against a live site, so every one of them has to leave the
 * database and Redis the way it found them. The helpers here make the cleanup
 * automatic rather than something each test has to remember.
 *
 * @package PigCache
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

abstract class PigCache_Integration_TestCase extends TestCase {

	/**
	 * Every class that exists only in the Pro package. build.sh must leave no
	 * trace of any of them in the Free build.
	 *
	 * @var string[]
	 */
	public const PRO_CLASSES = array(
		'PigCache_Adaptive_Ttl',
		'PigCache_Cloud_Client',
		'PigCache_Cloud_Sync',
		'PigCache_Continuous_Learner',
		'PigCache_Environment',
		'PigCache_License',
		'PigCache_Mutation_Tracker',
		'PigCache_Query_Buffer',
		'PigCache_Query_Stats',
		'PigCache_Sql_Profile_Store',
		'PigCache_Sql_Profiler',
		'PigCache_Stats',
		'PigCache_Traffic_Reader',
		'PigCache_Updates',
	);

	/**
	 * Which package is under test.
	 *
	 * Detected from the code that is actually loaded rather than from a version
	 * string, so it cannot disagree with reality.
	 */
	public static function is_pro(): bool {
		return class_exists( 'PigCache_License' );
	}

	/**
	 * True when the suite is running against an extracted ZIP rather than the
	 * working tree. The working tree is neither package: it is the Pro source
	 * with the PRO markers still in it.
	 */
	public static function is_packaged(): bool {
		return is_readable( dirname( __DIR__ ) . '/.packaged-edition' );
	}

	protected function require_packaged(): void {
		if ( ! self::is_packaged() ) {
			$this->markTestSkipped( 'Only meaningful against a built package. Run: bash tests/run.sh packages' );
		}
	}

	protected function require_pro(): void {
		if ( ! self::is_pro() ) {
			$this->markTestSkipped( 'Pro-only feature; this is the Free package.' );
		}
	}

	protected function require_free(): void {
		if ( self::is_pro() ) {
			$this->markTestSkipped( 'Free-only assertion; this is the Pro package.' );
		}
	}

	/** @var int[] Posts created by the current test. */
	private $created_posts = array();

	/** @var string[] Redis keys written directly by the current test. */
	private $created_keys = array();

	protected function tearDown(): void {
		foreach ( $this->created_posts as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created_posts = array();

		$redis = $this->redis();
		if ( $redis ) {
			foreach ( $this->created_keys as $key ) {
				try {
					$redis->del( $key );
				} catch ( \Throwable $e ) {
				}
			}
		}
		$this->created_keys = array();

		parent::tearDown();
	}

	/**
	 * Creates a published post that is removed again in tearDown().
	 */
	protected function make_post( array $args = array() ): int {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'PigCache integration ' . uniqid( '', true ),
					'post_content' => str_repeat( 'Contenido de prueba. ', 30 ),
					'post_status'  => 'publish',
					'post_type'    => 'post',
				),
				$args
			),
			true
		);

		$this->assertIsInt( $id, 'wp_insert_post must return an ID' );
		$this->created_posts[] = $id;

		return $id;
	}

	/**
	 * Registers a Redis key for cleanup.
	 */
	protected function track_key( string $key ): string {
		$this->created_keys[] = $key;
		return $key;
	}

	/**
	 * Direct phpredis connection to the same database the firewall uses.
	 *
	 * @return \Redis|null
	 */
	protected function redis() {
		static $redis = null;

		if ( null !== $redis ) {
			return $redis ?: null;
		}
		if ( ! class_exists( 'Redis' ) ) {
			$redis = false;
			return null;
		}

		try {
			$r    = new \Redis();
			$host = defined( 'PIGCACHE_REDIS_HOST' ) ? PIGCACHE_REDIS_HOST : '127.0.0.1';
			$port = defined( 'PIGCACHE_REDIS_PORT' ) ? (int) PIGCACHE_REDIS_PORT : 6379;
			if ( ! $r->connect( $host, $port, 1.0 ) ) {
				$redis = false;
				return null;
			}
			if ( defined( 'PIGCACHE_REDIS_PASSWORD' ) && PIGCACHE_REDIS_PASSWORD !== '' ) {
				$r->auth( PIGCACHE_REDIS_PASSWORD );
			}
			$redis = $r;
			return $r;
		} catch ( \Throwable $e ) {
			$redis = false;
			return null;
		}
	}

	protected function require_redis(): \Redis {
		$redis = $this->redis();
		if ( ! $redis ) {
			$this->markTestSkipped( 'phpredis is unavailable or Redis is not reachable.' );
		}
		return $redis;
	}
}
