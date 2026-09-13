<?php
/**
 * wpdb subclass: cache SELECT results in the object cache (Redis).
 *
 * Supports two invalidation modes:
 *  1. Per-table epochs (when a compiled SQL profile exists) — only queries
 *     touching the mutated table go stale.
 *  2. Global epoch fallback (no profile) — all queries stale on any mutation.
 *
 * During learning mode the profiler records every SELECT for later compilation.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'wpdb', false ) ) {
	return;
}

class PigCache_WPDB extends wpdb {

	/**
	 * @var bool
	 */
	private $pigcache_bypass = false;

	/**
	 * @param string $query Database query.
	 * @return int|bool
	 */
	public function query( $query ) {
		if ( $this->pigcache_bypass ) {
			return parent::query( $query );
		}

		return $this->pigcache_query_with_cache( $query );
	}

	/**
	 * @param string $query Database query.
	 * @return int|bool
	 */
	private function pigcache_query_with_cache( $query ) {
		if ( ! apply_filters( 'pigcache_sql_cache_enabled', true ) ) {
			$this->pigcache_bypass = true;
			$out                   = parent::query( $query );
			$this->pigcache_bypass = false;

			return $out;
		}

		if ( ! function_exists( 'wp_cache_get' ) || $this->pigcache_skip_context() ) {
			$this->pigcache_bypass = true;
			$out                   = parent::query( $query );
			$this->pigcache_bypass = false;

			return $out;
		}

		if ( ! $this->ready ) {
			$this->check_current_query = true;

			return false;
		}

		$query = apply_filters( 'query', $query );

		if ( ! $query ) {
			$this->insert_id = 0;

			return false;
		}

		$trim = ltrim( $query );

		if ( $this->pigcache_is_mutating( $trim ) ) {
			$this->pigcache_bypass = true;
			$out                   = parent::query( $query );
			$this->pigcache_bypass = false;

			if ( false !== $out ) {
				$state = $this->pigcache_snapshot_result();
				$this->pigcache_on_mutation( $trim );
				$this->pigcache_restore_result( $state );
			}

			return $out;
		}

		if ( $this->pigcache_is_cacheable_select( $trim ) ) {
			$key   = $this->pigcache_cache_key( $query );
			$group = 'pigcache_sql';
			$pack  = wp_cache_get( $key, $group );

			if ( $this->pigcache_valid_pack( $pack ) && $this->pigcache_is_fresh( $pack, $query ) ) {
				// ── PRO_START ─────────────────────────────────────────────────────────────────
				// Counted before hydrating, never after: recording the hit can
				// reach the database, and whatever it leaves behind would then
				// be what the caller reads instead of the cached rows.
				if ( class_exists( 'PigCache_Stats', false ) ) {
					$this->pigcache_bypass = true;
					PigCache_Stats::db_hit();
					$this->pigcache_bypass = false;
				}
				// ── PRO_END ───────────────────────────────────────────────────────────────────
				$this->pigcache_hydrate_select( $query, $pack );

				return $pack['return_val'];
			}
		}

		$t_start               = microtime( true );
		$this->pigcache_bypass = true;
		$out                   = parent::query( $query );
		$this->pigcache_bypass = false;
		$exec_ms               = ( microtime( true ) - $t_start ) * 1000;

		// From here to the return, every line is bookkeeping the caller knows
		// nothing about, and any of it may query the database: a TTL behind an
		// uncached get_option(), an epoch lookup, the Pro instrumentation. Put
		// the caller's result aside for the duration and hand it back intact.
		$state                 = $this->pigcache_snapshot_result();
		$this->pigcache_bypass = true;

		if ( false !== $out && '' === $state['last_error'] && $this->pigcache_is_cacheable_select( ltrim( $state['last_query'] ) ) ) {
			$this->pigcache_store_select( $this->pigcache_cache_key( $state['last_query'] ), $out, $state['last_query'], $exec_ms, $state );
			// ── PRO_START ─────────────────────────────────────────────────────────────────
			if ( class_exists( 'PigCache_Stats', false ) ) {
				PigCache_Stats::db_miss( 'not_found' );
			}
			// ── PRO_END ───────────────────────────────────────────────────────────────────
		}

		// ── PRO_START ─────────────────────────────────────────────────────────────────
		if ( false !== $out
			&& class_exists( 'PigCache_Sql_Profiler', false )
			&& PigCache_Sql_Profiler::is_learning()
			&& $this->pigcache_is_cacheable_select( $trim )
		) {
			PigCache_Sql_Profiler::record( $query, $state['num_rows'] );
		}

		if ( false !== $out
			&& class_exists( 'PigCache_Continuous_Learner', false )
			&& $this->pigcache_is_cacheable_select( $trim )
		) {
			$normalized  = $this->pigcache_normalize( $query );
			$tables      = $this->pigcache_extract_tables( $trim );
			$mem_kb      = (int) round( memory_get_usage() / 1024 );
			PigCache_Continuous_Learner::record( $normalized, $tables, $exec_ms, $mem_kb );
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────

		$this->pigcache_bypass = false;
		$this->pigcache_restore_result( $state );

		return $out;
	}

	/**
	 * @return bool
	 */
	private function pigcache_skip_context() {
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
			return true;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) {
			return true;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return true;
		}

		return (bool) apply_filters( 'pigcache_sql_cache_skip_context', false );
	}

	/**
	 * @param string $trim Left-trimmed SQL.
	 * @return bool
	 */
	private function pigcache_is_mutating( $trim ) {
		return (bool) preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE|RENAME|GRANT|REVOKE)\b/i', $trim );
	}

	/**
	 * @param string $trim Left-trimmed SQL.
	 * @return bool
	 */
	private function pigcache_is_cacheable_select( $trim ) {
		if ( stripos( $trim, 'SELECT' ) !== 0 ) {
			return false;
		}

		if ( stripos( $trim, 'FOR UPDATE' ) !== false || stripos( $trim, 'INTO OUTFILE' ) !== false ) {
			return false;
		}

		if ( stripos( $trim, 'SQL_CALC_FOUND_ROWS' ) !== false ) {
			return false;
		}

		return (bool) apply_filters( 'pigcache_sql_cache_is_cacheable', true, $trim );
	}

	/**
	 * @param string $query SQL (after query filter).
	 * @return string
	 */
	private function pigcache_cache_key( $query ) {
		$norm = preg_replace( '/\s+/', ' ', trim( $query ) );

		return 'sql_' . md5( $norm );
	}

	/**
	 * @param mixed $pack
	 * @return bool
	 */
	private function pigcache_valid_pack( $pack ) {
		return is_array( $pack )
			&& array_key_exists( 'last_result', $pack )
			&& array_key_exists( 'num_rows', $pack )
			&& array_key_exists( 'return_val', $pack );
	}

	/**
	 * Check if a cached pack is still fresh. Uses per-table epochs when a
	 * compiled profile is available, falls back to global epoch otherwise.
	 *
	 * @param array  $pack  Cache payload.
	 * @param string $query Raw SQL (for profile lookup on per-table mode).
	 * @return bool
	 */
	private function pigcache_is_fresh( $pack, $query = '' ) {
		if ( ! empty( $pack['table_epochs'] ) && is_array( $pack['table_epochs'] ) ) {
			if ( class_exists( 'PigCache_Sql_Cache', false ) ) {
				$current = PigCache_Sql_Cache::get_table_epochs( array_keys( $pack['table_epochs'] ) );
				foreach ( $pack['table_epochs'] as $table => $stored_epoch ) {
					$live = isset( $current[ $table ] ) ? $current[ $table ] : 1;
					if ( (int) $stored_epoch !== (int) $live ) {
						return false;
					}
				}
				return true;
			}
		}

		if ( ! isset( $pack['epoch'] ) ) {
			return false;
		}

		return (int) $pack['epoch'] === PigCache_Sql_Cache::get_epoch();
	}

	/**
	 * @param string $query
	 * @param array  $pack
	 */
	private function pigcache_hydrate_select( $query, array $pack ) {
		$this->last_result   = $pack['last_result'];
		$this->last_query    = $query;
		$this->last_error    = '';
		$this->num_rows      = (int) $pack['num_rows'];
		$this->result        = null;
		$this->rows_affected = 0;
		$this->insert_id     = 0;
		$this->func_call     = "\$db->query(\"$query\")";
		$this->col_info      = null;
	}

	/**
	 * @param string $key
	 * @param int    $return_val
	 * @param string $query
	 * @param float  $exec_ms
	 */
	/**
	 * Capture the outcome of the query that just ran.
	 *
	 * wpdb reports a query's outcome through shared properties, and callers read
	 * them only after query() has returned: get_results() reads last_result,
	 * insert() reads insert_id, and so on. Anything we do in between must leave
	 * those properties exactly as parent::query() left them.
	 *
	 * That is easy to get wrong, because filling or invalidating the cache can
	 * itself reach the database — a TTL lookup behind an uncached get_option()
	 * is enough — and the nested query overwrites all of it. The window is
	 * invisible on a warm cache and opens on every cold one, which is why it
	 * survived so long: right after a flush, a restart or a fresh install, the
	 * first query of each kind in a request returned another query's rows.
	 *
	 * $result is deliberately left out. It is a live mysqli handle that a nested
	 * query may have freed, and restoring a freed handle is worse than leaving
	 * the current one in place; col_info, the only thing read through it, is
	 * restored directly.
	 *
	 * @return array
	 */
	private function pigcache_snapshot_result() {
		return array(
			'last_result'   => $this->last_result,
			'num_rows'      => $this->num_rows,
			'last_query'    => $this->last_query,
			'last_error'    => $this->last_error,
			'col_info'      => $this->col_info,
			'insert_id'     => $this->insert_id,
			'rows_affected' => $this->rows_affected,
		);
	}

	/**
	 * @param array $state Snapshot from pigcache_snapshot_result().
	 */
	private function pigcache_restore_result( array $state ) {
		$this->last_result   = $state['last_result'];
		$this->num_rows      = $state['num_rows'];
		$this->last_query    = $state['last_query'];
		$this->last_error    = $state['last_error'];
		$this->col_info      = $state['col_info'];
		$this->insert_id     = $state['insert_id'];
		$this->rows_affected = $state['rows_affected'];
	}

	private function pigcache_store_select( $key, $return_val, $query = '', $exec_ms = 0.0, array $state = array() ) {
		$ttl = 120;
		if ( class_exists( 'PigCache_Sql_Cache', false ) ) {
			$ttl = PigCache_Sql_Cache::ttl();
		}

		// ── PRO_START ─────────────────────────────────────────────────────────────────
		if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
			$adaptive = PigCache_Continuous_Learner::adaptive_ttl( $exec_ms );
			if ( null !== $adaptive ) {
				$ttl = $adaptive;
			}
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────

		// Read the rows from the snapshot, not from $this: resolving the TTL just
		// above may already have run a query of its own and replaced them.
		$pack = array(
			'last_result' => array_key_exists( 'last_result', $state ) ? $state['last_result'] : $this->last_result,
			'num_rows'    => (int) ( array_key_exists( 'num_rows', $state ) ? $state['num_rows'] : $this->num_rows ),
			'return_val'  => $return_val,
		);

		$table_epochs = $this->pigcache_resolve_table_epochs( $query );

		if ( ! empty( $table_epochs ) ) {
			$pack['table_epochs'] = $table_epochs;
		} elseif ( class_exists( 'PigCache_Sql_Cache', false ) ) {
			$pack['epoch'] = PigCache_Sql_Cache::get_epoch();
		} else {
			// Drop-in loaded before plugin — skip caching this query.
			return;
		}

		wp_cache_set( $key, $pack, 'pigcache_sql', $ttl );
	}

	/**
	 * Resolve the per-table epochs a cached SELECT depends on, so a write to one
	 * table does not invalidate results that never read it.
	 *
	 * @param string $query
	 * @return array<string, int> table => epoch, or empty when no table could be resolved.
	 */
	private function pigcache_resolve_table_epochs( $query ) {
		if ( ! class_exists( 'PigCache_Sql_Cache', false ) ) {
			return array();
		}

		$tables = array();

		// ── PRO_START ─────────────────────────────────────────────────────────────────
		// A compiled profile resolves tables the parser cannot see (subqueries,
		// aliases, UNIONs). Fall through to the parser when it has no answer.
		if ( class_exists( 'PigCache_Sql_Profiler', false ) && PigCache_Sql_Profiler::has_profile() ) {
			$profiled = PigCache_Sql_Profiler::get_tables_for_query( $query );

			if ( is_array( $profiled ) && ! empty( $profiled ) ) {
				$tables = $profiled;
			}
		}
		// ── PRO_END ───────────────────────────────────────────────────────────────────

		if ( empty( $tables ) ) {
			$tables = $this->pigcache_extract_tables( ltrim( (string) $query ) );
		}

		if ( empty( $tables ) ) {
			return array();
		}

		return PigCache_Sql_Cache::get_table_epochs( array_map( 'strtolower', $tables ) );
	}

	/**
	 * Handle a successful mutation: bump only the mutated table's epoch when
	 * a profile exists, otherwise fall back to the global epoch.
	 *
	 * @param string $trim Left-trimmed SQL of the mutating statement.
	 */
	private function pigcache_on_mutation( $trim ) {
		if ( class_exists( 'PigCache_Sql_Cache', false ) ) {
			$table = PigCache_Sql_Cache::extract_mutation_table( $trim );

			if ( $table !== '' ) {
				PigCache_Sql_Cache::bump_table_epoch( $table );
				return;
			}
		}

		$this->pigcache_bump_epoch();
	}

	// ── PRO_START ─────────────────────────────────────────────────────────────────
	/**
	 * Normalize a SQL query for analytics (replace literals with ?).
	 *
	 * @param string $query
	 * @return string
	 */
	private function pigcache_normalize( $query ) {
		// Replace quoted strings.
		$q = preg_replace( "/'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'/", '?', $query );
		// Replace numbers.
		$q = preg_replace( '/\b\d+\b/', '?', $q );
		// Collapse whitespace.
		return preg_replace( '/\s+/', ' ', trim( $q ) );
	}
	// ── PRO_END ───────────────────────────────────────────────────────────────────

	/**
	 * Extract the table names a SELECT reads from, so the cached result can be
	 * tied to those tables' epochs. Not exhaustive — covers FROM/JOIN patterns.
	 *
	 * @param string $trim Left-trimmed SQL.
	 * @return string[]
	 */
	private function pigcache_extract_tables( $trim ) {
		$tables = array();
		if ( preg_match_all( '/(?:FROM|JOIN)\s+`?(\w+)`?/i', $trim, $m ) ) {
			$tables = array_unique( $m[1] );
		}
		return array_values( $tables );
	}

	/**
	 * Global epoch bump fallback.
	 */
	private function pigcache_bump_epoch() {
		if ( class_exists( 'PigCache_Sql_Cache', false ) ) {
			PigCache_Sql_Cache::bump_epoch();
			return;
		}

		if ( ! function_exists( 'wp_cache_incr' ) ) {
			return;
		}

		$new = wp_cache_incr( 'pigcache_sql_epoch', 1, 'pigcache' );

		// Same reasoning as PigCache_Sql_Cache::bump_epoch(): a bump that lands on
		// 1 is indistinguishable from never having been set.
		if ( false === $new || $new <= 1 ) {
			$ttl = defined( 'YEAR_IN_SECONDS' ) ? (int) YEAR_IN_SECONDS * 10 : 315360000;
			wp_cache_set( 'pigcache_sql_epoch', 2, 'pigcache', $ttl );
		}
	}
}
