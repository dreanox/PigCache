<?php
/**
 * Table extraction for per-table SQL invalidation.
 *
 * Getting this wrong is silent and expensive in both directions: miss the table
 * a write touches and cached results go stale forever; attribute a read to the
 * wrong table and it gets invalidated constantly for no reason.
 *
 * @package PigCache
 */

declare( strict_types=1 );

namespace PigCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PigCache_Sql_Cache;

final class SqlCacheTableTest extends TestCase {

	/**
	 * @dataProvider mutationCases
	 */
	public function test_extract_mutation_table( string $sql, string $expected ): void {
		$this->assertSame( $expected, PigCache_Sql_Cache::extract_mutation_table( $sql ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function mutationCases(): array {
		return array(
			'simple insert' => array( "INSERT INTO wp_posts (a) VALUES (1)", 'wp_posts' ),
			'insert backticked' => array( "INSERT INTO `wp_posts` (a) VALUES (1)", 'wp_posts' ),
			'insert ignore' => array( "INSERT IGNORE INTO wp_options (a) VALUES (1)", 'wp_options' ),
			'insert low priority' => array( "INSERT LOW_PRIORITY INTO wp_options (a) VALUES (1)", 'wp_options' ),
			'insert delayed ignore' => array( "INSERT DELAYED IGNORE INTO wp_terms (a) VALUES (1)", 'wp_terms' ),
			// INTO is optional in MySQL for both INSERT and REPLACE.
			'insert without into' => array( "INSERT wp_posts SET a = 1", 'wp_posts' ),
			'replace into' => array( "REPLACE INTO wp_options (a) VALUES (1)", 'wp_options' ),
			'replace without into' => array( "REPLACE wp_options (a) VALUES (1)", 'wp_options' ),

			'simple update' => array( "UPDATE wp_postmeta SET a = 1 WHERE b = 2", 'wp_postmeta' ),
			'update ignore' => array( "UPDATE IGNORE wp_postmeta SET a = 1", 'wp_postmeta' ),
			'update low priority' => array( "UPDATE LOW_PRIORITY `wp_postmeta` SET a = 1", 'wp_postmeta' ),
			'update low priority ignore' => array( "UPDATE LOW_PRIORITY IGNORE wp_postmeta SET a = 1", 'wp_postmeta' ),

			'simple delete' => array( "DELETE FROM wp_comments WHERE id = 1", 'wp_comments' ),
			'delete with alias' => array( "DELETE c FROM wp_comments c WHERE c.id = 1", 'wp_comments' ),

			'lowercase keywords' => array( "update wp_posts set a = 1", 'wp_posts' ),
			'leading whitespace' => array( "\n\t  UPDATE wp_posts SET a = 1", 'wp_posts' ),
			'mixed case table is lowercased' => array( "UPDATE WP_Posts SET a = 1", 'wp_posts' ),

			// A SELECT is not a mutation; returning a table here would bump an
			// epoch on every read and defeat the cache entirely.
			'select is not a mutation' => array( "SELECT * FROM wp_posts", '' ),
			'show is not a mutation' => array( "SHOW COLUMNS FROM wp_posts", '' ),
			'empty string' => array( '', '' ),
		);
	}

	public function test_unparseable_mutation_returns_empty_so_caller_falls_back(): void {
		// PigCache_WPDB bumps the global epoch when this returns '' — that is the
		// safe direction (over-invalidate rather than serve stale).
		$this->assertSame(
			'',
			PigCache_Sql_Cache::extract_mutation_table( 'ALTER TABLE wp_posts ADD COLUMN x INT' )
		);
	}
}
