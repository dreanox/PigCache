<?php
/**
 * Pro-only functionality.
 *
 * Every test here skips on the Free package, which is the point: the two
 * packages are different programs and the Pro one has surface the Free one does
 * not. Running the same assertions against both would only prove that nothing
 * Pro is being tested at all.
 *
 * Nothing in this file is allowed to reach the network. The cloud client is
 * exercised only for the decisions it makes before any request goes out.
 *
 * @package PigCache
 */

declare( strict_types=1 );

final class ProFeaturesTest extends PigCache_Integration_TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->require_pro();
	}

	// ── Adaptive TTL ────────────────────────────────────────────────────────

	public function test_adaptive_ttl_classifies_a_url_into_a_known_tier(): void {
		$result = PigCache_Adaptive_Ttl::classify( '/hello-world/', array( 'post:1' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tier', $result );
		$this->assertContains(
			$result['tier'],
			array( 'hot_stable', 'hot_dynamic', 'cold', 'unknown' ),
			'a tier outside this set would make decide() fall through to an undefined TTL'
		);
	}

	/**
	 * decide() has a three-way contract that the HTML cache depends on:
	 * -1 means "no opinion, use the static TTL", 0 means "do not cache", and
	 * anything above 0 is a TTL in seconds. Returning a negative other than -1
	 * would be stored by Redis as "never expires".
	 */
	public function test_adaptive_ttl_decide_honours_its_sentinel_contract(): void {
		$ttl = PigCache_Adaptive_Ttl::decide( '/hello-world/', array( 'post:1' ) );

		$this->assertIsInt( $ttl );
		$this->assertGreaterThanOrEqual( -1, $ttl );
	}

	public function test_adaptive_ttl_defers_to_the_static_ttl_when_disabled(): void {
		$this->assertFalse( PigCache_Adaptive_Ttl::is_enabled(), 'precondition: the stack disables it' );

		$this->assertSame(
			-1,
			PigCache_Adaptive_Ttl::decide( '/hello-world/', array( 'post:1' ) ),
			'a disabled adaptive TTL must hand the decision back, not force a value'
		);
	}

	public function test_adaptive_ttl_tiers_are_ordered_sensibly(): void {
		// A page that rarely changes must be allowed to live longer than one
		// that changes constantly, otherwise the whole feature is upside down.
		$this->assertGreaterThan(
			PigCache_Adaptive_Ttl::ttl_hot_dynamic(),
			PigCache_Adaptive_Ttl::ttl_hot_stable(),
			'stable pages must get a longer TTL than dynamic ones'
		);
	}

	public function test_adaptive_ttl_maps_tags_to_tables(): void {
		$tables = PigCache_Adaptive_Ttl::tags_to_tables( array( 'post:1', 'term:5' ) );

		$this->assertIsArray( $tables );
	}

	public function test_adaptive_ttl_respects_the_disable_constant(): void {
		// The Docker stack sets PIGCACHE_ADAPTIVE_TTL_ENABLED to false so the
		// other cache tests get deterministic TTLs.
		$this->assertFalse(
			PigCache_Adaptive_Ttl::is_enabled(),
			'the constant must be honoured, or every TTL assertion elsewhere is unreliable'
		);
	}

	// ── SQL profiler ────────────────────────────────────────────────────────

	public function test_sql_profiler_learning_window_can_be_started_and_stopped(): void {
		// Options are cached in the shared object cache, and these tests run in
		// one WordPress process, so start from a known state.
		PigCache_Sql_Profiler::stop_learning();

		PigCache_Sql_Profiler::start_learning( 3 );

		$this->assertTrue( PigCache_Sql_Profiler::is_learning(), 'learning must switch on' );
		$this->assertGreaterThan( 0, PigCache_Sql_Profiler::learning_remaining() );

		PigCache_Sql_Profiler::stop_learning();

		$this->assertFalse( PigCache_Sql_Profiler::is_learning(), 'learning must switch off again' );
	}

	public function test_sql_profiler_is_not_learning_by_default(): void {
		PigCache_Sql_Profiler::stop_learning();

		$this->assertFalse( PigCache_Sql_Profiler::is_learning() );
		$this->assertSame( 0, PigCache_Sql_Profiler::learning_remaining() );
	}

	public function test_sql_profiler_recording_is_safe_outside_a_learning_window(): void {
		PigCache_Sql_Profiler::stop_learning();

		// Recording while not learning must be a no-op rather than an error:
		// this runs on every query in production.
		PigCache_Sql_Profiler::record( 'SELECT * FROM wp_posts WHERE ID = 1', 1 );
		PigCache_Sql_Profiler::flush_record_buffer();

		$this->addToAssertionCount( 1 );
	}

	// ── Query stats ─────────────────────────────────────────────────────────

	public function test_query_stats_table_exists(): void {
		global $wpdb;

		$table = PigCache_Query_Stats::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		// This is the table that a DEFAULT on a TEXT column stopped MySQL from
		// ever creating, silently, on every Pro site.
		$this->assertSame( $table, $found, 'the query stats table must actually be created' );
	}

	/**
	 * upsert_batch() takes rows keyed by query hash.
	 */
	private static function stat_row( string $hash, int $count = 5, int $total_ms = 250 ): array {
		return array(
			$hash => array(
				'normalized' => 'SELECT * FROM wp_posts WHERE ID = ?',
				'tables'     => array( 'wp_posts' ),
				'count'      => $count,
				'total_ms'   => $total_ms,
				'max_ms'     => 90,
				'total_mem'  => 128,
			),
		);
	}

	public function test_query_stats_upsert_and_read_back(): void {
		$hash   = substr( md5( uniqid( '', true ) ), 0, 32 );
		$period = gmdate( 'Y-m-d H:00:00' );

		PigCache_Query_Stats::upsert_batch( self::stat_row( $hash ), $period );

		$rows   = PigCache_Query_Stats::get_top_slow( 50 );
		$hashes = array_column( $rows, 'query_hash' );

		$this->assertContains( $hash, $hashes, 'a recorded query must be readable back' );

		global $wpdb;
		$wpdb->delete( PigCache_Query_Stats::table_name(), array( 'query_hash' => $hash ) );
	}

	public function test_query_stats_upsert_accumulates_instead_of_duplicating(): void {
		global $wpdb;

		$hash   = substr( md5( uniqid( '', true ) ), 0, 32 );
		$period = gmdate( 'Y-m-d H:00:00' );

		PigCache_Query_Stats::upsert_batch( self::stat_row( $hash, 3, 30 ), $period );
		PigCache_Query_Stats::upsert_batch( self::stat_row( $hash, 3, 30 ), $period );

		$table = PigCache_Query_Stats::table_name();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE query_hash = %s", $hash ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$hits = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT hit_count FROM `{$table}` WHERE query_hash = %s", $hash ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( 1, $count, 'the unique key on (hash, period) must collapse the two writes into one row' );
		$this->assertSame( 6, $hits, 'counts must accumulate across flushes within the same period' );

		$wpdb->delete( $table, array( 'query_hash' => $hash ) );
	}

	// ── Mutation tracker ────────────────────────────────────────────────────

	public function test_mutation_tracker_builds_a_stability_map(): void {
		PigCache_Mutation_Tracker::record_mutation( 'wp_posts' );
		PigCache_Mutation_Tracker::flush_pending();

		$map = PigCache_Mutation_Tracker::get_stability_map();

		$this->assertIsArray( $map, 'adaptive TTL reads this map on every decision' );
	}

	// ── Stats ───────────────────────────────────────────────────────────────

	public function test_stats_counters_do_not_throw(): void {
		// These run on the hot path of every request, so a fatal here takes the
		// whole site with it.
		PigCache_Stats::html_hit( 'early' );
		PigCache_Stats::html_miss( 'no_entry' );
		PigCache_Stats::html_bypass( 'logged_in' );
		PigCache_Stats::html_write();
		PigCache_Stats::db_hit();
		PigCache_Stats::db_miss( 'epoch' );
		PigCache_Stats::obj_hit();
		PigCache_Stats::obj_miss();

		$this->addToAssertionCount( 1 );
	}

	// ── Continuous learner ──────────────────────────────────────────────────

	public function test_learner_flush_interval_is_within_the_cron_bounds(): void {
		$minutes = PigCache_Continuous_Learner::flush_interval_minutes();

		$this->assertIsInt( $minutes );
		$this->assertGreaterThanOrEqual( 1, $minutes );
		$this->assertLessThanOrEqual( 60, $minutes, 'the cron_schedules filter clamps to an hour' );
	}

	// ── Licensing ───────────────────────────────────────────────────────────

	public function test_license_reports_a_pro_distribution(): void {
		$this->assertTrue(
			PigCache_License::has_pro_distribution(),
			'the Pro package must recognise itself as such'
		);
	}

	public function test_license_has_no_key_by_default(): void {
		$this->assertFalse( PigCache_License::has_key(), 'a fresh install must not claim to be licensed' );
		$this->assertSame( '', PigCache_License::get_key() );
	}

	public function test_license_key_round_trips(): void {
		PigCache_License::set_key( 'test-key-123' );

		$this->assertTrue( PigCache_License::has_key() );
		$this->assertSame( 'test-key-123', PigCache_License::get_key() );

		PigCache_License::set_key( '' );
		$this->assertFalse( PigCache_License::has_key() );
	}

	public function test_an_unlicensed_pro_install_still_serves_pages(): void {
		// Pro without a key must degrade to the free feature set, not break the
		// site. This is the failure mode that would take a customer down at
		// renewal time.
		PigCache_License::set_key( '' );

		// Port 80 inside the container: home_url() carries the host-side port,
		// which the container cannot reach through its own loopback. The Host
		// header keeps WordPress resolving the request as the canonical site.
		$response = wp_remote_get(
			'http://localhost/',
			array(
				'timeout' => 20,
				'headers' => array( 'Host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . ( getenv( 'PIGCACHE_TEST_PORT' ) ?: '9520' ) ),
			)
		);

		$this->assertFalse( is_wp_error( $response ), 'the site must answer without a licence' );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
	}
}
