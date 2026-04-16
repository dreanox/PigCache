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
				$this->pigcache_on_mutation( $trim );
			}

			return $out;
		}

		if ( $this->pigcache_is_cacheable_select( $trim ) ) {
			$key   = $this->pigcache_cache_key( $query );
			$group = 'pigcache_sql';
			$pack  = wp_cache_get( $key, $group );

			if ( $this->pigcache_valid_pack( $pack ) && $this->pigcache_is_fresh( $pack, $query ) ) {
				$this->pigcache_hydrate_select( $query, $pack );

				return $pack['return_val'];
			}
		}

		$this->pigcache_bypass = true;
		$out                   = parent::query( $query );
		$this->pigcache_bypass = false;

		if ( false !== $out && '' === $this->last_error && $this->pigcache_is_cacheable_select( ltrim( $this->last_query ) ) ) {
			$this->pigcache_store_select( $this->pigcache_cache_key( $this->last_query ), $out, $this->last_query );
		}

		if ( false !== $out
			&& class_exists( 'PigCache_Sql_Profiler', false )
			&& PigCache_Sql_Profiler::is_learning()
			&& $this->pigcache_is_cacheable_select( $trim )
		) {
			PigCache_Sql_Profiler::record( $query, $this->num_rows );
		}

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
		if ( isset( $pack['table_epochs'] ) && is_array( $pack['table_epochs'] ) && ! empty( $pack['table_epochs'] ) ) {
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
	 */
	private function pigcache_store_select( $key, $return_val, $query = '' ) {
		$ttl = 120;
		if ( class_exists( 'PigCache_Sql_Cache', false ) ) {
			$ttl = PigCache_Sql_Cache::ttl();
		}

		$pack = array(
			'last_result' => $this->last_result,
			'num_rows'    => (int) $this->num_rows,
			'return_val'  => $return_val,
		);

		$table_epochs = $this->pigcache_resolve_table_epochs( $query );

		if ( ! empty( $table_epochs ) ) {
			$pack['table_epochs'] = $table_epochs;
		} else {
			$pack['epoch'] = PigCache_Sql_Cache::get_epoch();
		}

		wp_cache_set( $key, $pack, 'pigcache_sql', $ttl );
	}

	/**
	 * Try to resolve per-table epochs for a query using the compiled profile.
	 *
	 * @param string $query
	 * @return array<string, int> table => epoch, or empty if no profile.
	 */
	private function pigcache_resolve_table_epochs( $query ) {
		if ( ! class_exists( 'PigCache_Sql_Profiler', false ) || ! PigCache_Sql_Profiler::has_profile() ) {
			return array();
		}

		$tables = PigCache_Sql_Profiler::get_tables_for_query( $query );

		if ( false === $tables || empty( $tables ) ) {
			return array();
		}

		if ( class_exists( 'PigCache_Sql_Cache', false ) ) {
			return PigCache_Sql_Cache::get_table_epochs( $tables );
		}

		return array();
	}

	/**
	 * Handle a successful mutation: bump only the mutated table's epoch when
	 * a profile exists, otherwise fall back to the global epoch.
	 *
	 * @param string $trim Left-trimmed SQL of the mutating statement.
	 */
	private function pigcache_on_mutation( $trim ) {
		if ( class_exists( 'PigCache_Sql_Profiler', false )
			&& PigCache_Sql_Profiler::has_profile()
			&& class_exists( 'PigCache_Sql_Cache', false )
		) {
			$table = PigCache_Sql_Profiler::extract_mutation_table( $trim );

			if ( $table !== '' ) {
				PigCache_Sql_Cache::bump_table_epoch( $table );
				return;
			}
		}

		$this->pigcache_bump_epoch();
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

		if ( false === $new || 0 === $new ) {
			$ttl = defined( 'YEAR_IN_SECONDS' ) ? (int) YEAR_IN_SECONDS * 10 : 315360000;
			wp_cache_set( 'pigcache_sql_epoch', 2, 'pigcache', $ttl );
		}
	}
}
