<?php
/**
 * SQL Query Profiler — learns query templates during an observation window,
 * compiles them into a static PHP file, and provides per-table epoch lookups
 * at runtime with zero MySQL overhead.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Sql_Profiler {

	const OPTION_LEARN_START  = 'pigcache_sql_profile_learn_start';
	const OPTION_LEARN_DAYS   = 'pigcache_sql_profile_learn_days';
	const OPTION_AUTO_RELEARN = 'pigcache_sql_auto_relearn';
	const OPTION_ENV_CHANGE   = 'pigcache_sql_env_last_change';
	const DEFAULT_LEARN_DAYS  = 7;

	/** @var array|null Cached compiled profile. */
	private static $profile = null;

	/** @var bool Whether the profile file has been attempted to load. */
	private static $profile_loaded = false;

	/**
	 * In-memory buffer: fingerprint => { template, tables, total_rows, count }.
	 * Accumulated during the request and flushed once at shutdown.
	 *
	 * @var array<string, array>
	 */
	private static $record_buffer = array();

	/** @var bool Whether the shutdown flush hook has been registered. */
	private static $flush_registered = false;

	/**
	 * Register auto re-learn hooks (plugin/theme changes).
	 */
	public static function init() {
		add_action( 'activated_plugin', array( __CLASS__, 'on_environment_change' ), 99 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_environment_change' ), 99 );
		add_action( 'switch_theme', array( __CLASS__, 'on_environment_change' ), 99 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_environment_change' ), 99 );
	}

	/**
	 * Fired when plugins, themes, or core are changed.
	 *
	 * Stores what changed, then auto-relaunches learning if enabled.
	 */
	public static function on_environment_change( ...$args ) {
		if ( ! PigCache_License::can_use_profiler() ) {
			return;
		}

		$current_filter = current_filter();
		$detail         = self::describe_env_change( $current_filter, $args );
		$auto           = self::auto_relearn_enabled();

		update_option( self::OPTION_ENV_CHANGE, array(
			'time'           => time(),
			'filter'         => $current_filter,
			'detail'         => $detail,
			'auto_relearned' => false,
		), false );

		if ( class_exists( 'PigCache_Environment', false ) ) {
			PigCache_Environment::flush();
		}

		if ( ! $auto || self::is_learning() ) {
			return;
		}

		update_option( self::OPTION_ENV_CHANGE, array(
			'time'           => time(),
			'filter'         => $current_filter,
			'detail'         => $detail,
			'auto_relearned' => true,
		), false );

		self::trigger_relearn();
	}

	/**
	 * @return bool
	 */
	public static function auto_relearn_enabled(): bool {
		if ( defined( 'PIGCACHE_SQL_PROFILE_AUTO_RELEARN' ) ) {
			return (bool) PIGCACHE_SQL_PROFILE_AUTO_RELEARN;
		}

		return (bool) get_option( self::OPTION_AUTO_RELEARN, true );
	}

	/**
	 * @param string $filter
	 * @param array  $args
	 * @return string Human-readable description of what changed.
	 */
	private static function describe_env_change( string $filter, array $args ): string {
		if ( 'activated_plugin' === $filter && ! empty( $args[0] ) ) {
			$slug = dirname( $args[0] );
			$name = '.' === $slug ? $args[0] : $slug;
			return sprintf( 'Plugin activated: %s', $name );
		}

		if ( 'deactivated_plugin' === $filter && ! empty( $args[0] ) ) {
			$slug = dirname( $args[0] );
			$name = '.' === $slug ? $args[0] : $slug;
			return sprintf( 'Plugin deactivated: %s', $name );
		}

		if ( 'switch_theme' === $filter ) {
			$name = ! empty( $args[0] ) ? $args[0] : 'unknown';
			return sprintf( 'Theme switched to: %s', $name );
		}

		if ( 'upgrader_process_complete' === $filter && isset( $args[1]['type'] ) ) {
			$type = $args[1]['type'];

			if ( 'core' === $type ) {
				return sprintf( 'WordPress core updated to %s', get_bloginfo( 'version' ) );
			}

			if ( 'plugin' === $type ) {
				$plugins = isset( $args[1]['plugins'] ) ? (array) $args[1]['plugins'] : array();
				if ( ! empty( $args[1]['plugin'] ) ) {
					$plugins = array( $args[1]['plugin'] );
				}
				$names = array_map( static function ( $p ) {
					$s = dirname( $p );
					return '.' === $s ? $p : $s;
				}, $plugins );
				return sprintf( 'Plugin(s) updated: %s', implode( ', ', $names ) );
			}

			if ( 'theme' === $type ) {
				$themes = isset( $args[1]['themes'] ) ? (array) $args[1]['themes'] : array();
				return sprintf( 'Theme(s) updated: %s', implode( ', ', $themes ) );
			}
		}

		return 'Environment changed';
	}

	// ------------------------------------------------------------------
	// Learning mode
	// ------------------------------------------------------------------

	/**
	 * @return bool
	 */
	public static function is_learning() {
		if ( defined( 'PIGCACHE_SQL_PROFILE_LEARN' ) && PIGCACHE_SQL_PROFILE_LEARN ) {
			return true;
		}

		$start = (int) get_option( self::OPTION_LEARN_START, 0 );
		if ( $start < 1 ) {
			return false;
		}

		$days = (int) get_option( self::OPTION_LEARN_DAYS, self::DEFAULT_LEARN_DAYS );
		if ( $days < 1 ) {
			$days = self::DEFAULT_LEARN_DAYS;
		}

		return ( time() - $start ) < ( $days * DAY_IN_SECONDS );
	}

	/**
	 * @return int Seconds remaining in the learning window, or 0 if not learning.
	 */
	public static function learning_remaining() {
		$start = (int) get_option( self::OPTION_LEARN_START, 0 );
		if ( $start < 1 ) {
			return 0;
		}

		$days = (int) get_option( self::OPTION_LEARN_DAYS, self::DEFAULT_LEARN_DAYS );
		$end  = $start + ( $days * DAY_IN_SECONDS );
		$left = $end - time();

		return $left > 0 ? $left : 0;
	}

	/**
	 * Start a new learning window.
	 *
	 * Requires Pro license or active trial.
	 *
	 * @param int $days Duration in days.
	 * @return bool|WP_Error
	 */
	public static function start_learning( $days = 0 ) {
		if ( ! PigCache_License::can_use_profiler() ) {
			return new WP_Error( 'pigcache_no_license', 'SQL Profiler requires a PigCache Pro license.' );
		}

		if ( $days < 1 ) {
			$days = self::DEFAULT_LEARN_DAYS;
		}

		update_option( self::OPTION_LEARN_START, time(), false );
		update_option( self::OPTION_LEARN_DAYS, $days, false );

		return true;
	}

	/**
	 * Stop learning and clear the window.
	 */
	public static function stop_learning() {
		delete_option( self::OPTION_LEARN_START );
		delete_option( self::OPTION_LEARN_DAYS );
	}

	// ------------------------------------------------------------------
	// Recording (during learning)
	// ------------------------------------------------------------------

	/**
	 * Record a SELECT query during the learning phase.
	 *
	 * Buffers fingerprints in memory and flushes once at shutdown,
	 * so a fingerprint seen 200 times in one request produces a single
	 * MySQL upsert instead of 200.
	 *
	 * @param string $query  Raw SQL.
	 * @param int    $num_rows Rows returned (for stats).
	 */
	public static function record( $query, $num_rows = 0 ) {
		if ( ! self::is_learning() ) {
			return;
		}

		if ( ! PigCache_License::can_use_profiler() ) {
			return;
		}

		$template    = self::normalize( $query );
		$fingerprint = md5( $template );
		$tables      = self::extract_tables( $query );

		if ( empty( $tables ) ) {
			return;
		}

		if ( isset( self::$record_buffer[ $fingerprint ] ) ) {
			self::$record_buffer[ $fingerprint ]['count']++;
			self::$record_buffer[ $fingerprint ]['total_rows'] += (int) $num_rows;
		} else {
			self::$record_buffer[ $fingerprint ] = array(
				'template'   => $template,
				'tables'     => $tables,
				'count'      => 1,
				'total_rows' => (int) $num_rows,
			);
		}

		if ( ! self::$flush_registered ) {
			self::$flush_registered = true;
			register_shutdown_function( array( __CLASS__, 'flush_record_buffer' ) );
		}
	}

	/**
	 * Flush the in-memory buffer to MySQL. Called once at shutdown.
	 *
	 * Each unique fingerprint produces one upsert regardless of how
	 * many times it was seen during the request.
	 */
	public static function flush_record_buffer() {
		if ( empty( self::$record_buffer ) || ! class_exists( 'PigCache_Sql_Profile_Store', false ) ) {
			return;
		}

		foreach ( self::$record_buffer as $fingerprint => $entry ) {
			$avg_rows = $entry['count'] > 0
				? $entry['total_rows'] / $entry['count']
				: 0;

			PigCache_Sql_Profile_Store::upsert(
				$fingerprint,
				$entry['template'],
				$entry['tables'],
				(int) round( $avg_rows )
			);
		}

		self::$record_buffer = array();
	}

	// ------------------------------------------------------------------
	// Normalization & table extraction
	// ------------------------------------------------------------------

	/**
	 * Normalize a SQL query into a stable template by replacing literal values.
	 *
	 * @param string $query
	 * @return string
	 */
	public static function normalize( $query ) {
		$norm = preg_replace( '/\s+/', ' ', trim( $query ) );

		$norm = preg_replace( "/('[^'\\\\]*(?:\\\\.[^'\\\\]*)*')/", '?', $norm );

		$norm = preg_replace( '/\b\d+\b/', '?', $norm );

		$norm = preg_replace( '/IN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/', 'IN (?)', $norm );

		return $norm;
	}

	/**
	 * Extract table names from a SQL query.
	 *
	 * @param string $query
	 * @return string[]
	 */
	public static function extract_tables( $query ) {
		$tables = array();

		if ( preg_match_all(
			'/\b(?:FROM|JOIN)\s+`?(\w+)`?/i',
			$query,
			$matches
		) ) {
			foreach ( $matches[1] as $t ) {
				$t = strtolower( $t );
				if ( self::is_valid_table( $t ) ) {
					$tables[ $t ] = true;
				}
			}
		}

		return array_keys( $tables );
	}

	/**
	 * Extract the target table from a mutating statement (INSERT/UPDATE/DELETE).
	 *
	 * @param string $query
	 * @return string Table name or empty string.
	 */
	public static function extract_mutation_table( $query ) {
		$query = ltrim( $query );

		if ( preg_match( '/^\s*(?:INSERT\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)?(?:IGNORE\s+)?INTO|REPLACE\s+(?:LOW_PRIORITY\s+|DELAYED\s+)?(?:INTO)?)\s+`?(\w+)`?/i', $query, $m ) ) {
			return strtolower( $m[1] );
		}

		if ( preg_match( '/^\s*UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)?`?(\w+)`?/i', $query, $m ) ) {
			return strtolower( $m[1] );
		}

		if ( preg_match( '/^\s*DELETE\s+.*?\bFROM\s+`?(\w+)`?/i', $query, $m ) ) {
			return strtolower( $m[1] );
		}

		if ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $query, $m ) ) {
			return strtolower( $m[1] );
		}

		return '';
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	private static function is_valid_table( $name ) {
		if ( strlen( $name ) < 2 || strlen( $name ) > 128 ) {
			return false;
		}

		$reserved = array( 'select', 'from', 'where', 'join', 'on', 'as', 'set', 'values', 'into', 'null', 'true', 'false' );
		if ( in_array( $name, $reserved, true ) ) {
			return false;
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Compilation
	// ------------------------------------------------------------------

	/**
	 * Compile the learning data into a static PHP file.
	 *
	 * @return bool|WP_Error
	 */
	public static function compile() {
		if ( ! PigCache_License::can_use_profiler() ) {
			return new WP_Error( 'pigcache_no_license', 'SQL Profiler requires a PigCache Pro license.' );
		}

		if ( ! class_exists( 'PigCache_Sql_Profile_Store', false ) ) {
			return new WP_Error( 'pigcache_no_store', 'Profile store class not available.' );
		}

		$rows = PigCache_Sql_Profile_Store::get_all();
		if ( empty( $rows ) ) {
			return new WP_Error( 'pigcache_no_data', 'No fingerprints recorded. Run learning mode first.' );
		}

		$map           = array();
		$tables_index  = array();
		$stats         = array();
		$total_queries = 0;

		foreach ( $rows as $row ) {
			$fp     = $row->fingerprint;
			$tbls   = json_decode( $row->tables_json, true );
			$hits   = (int) $row->hit_count;

			if ( ! is_array( $tbls ) || empty( $tbls ) ) {
				continue;
			}

			$map[ $fp ] = $tbls;
			$total_queries += $hits;

			foreach ( $tbls as $t ) {
				if ( ! isset( $tables_index[ $t ] ) ) {
					$tables_index[ $t ] = array();
				}
				$tables_index[ $t ][] = $fp;
			}

			$stats[ $fp ] = array(
				'hits'     => $hits,
				'avg_rows' => round( (float) $row->avg_rows, 2 ),
				'template' => $row->template,
			);
		}

		$learn_start = (int) get_option( self::OPTION_LEARN_START, 0 );
		$learn_days  = (int) get_option( self::OPTION_LEARN_DAYS, self::DEFAULT_LEARN_DAYS );

		$data = array(
			'compiled_at'      => time(),
			'learn_start'      => $learn_start,
			'learn_days'       => $learn_days,
			'query_count'      => $total_queries,
			'unique_templates' => count( $map ),
			'map'              => $map,
			'tables'           => $tables_index,
			'stats'            => $stats,
		);

		$path    = self::profile_path();
		$content = "<?php\n"
			. "// Auto-generated by PigCache SQL Profiler — do not edit.\n"
			. '// Compiled: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
			. '// Learning period: ' . $learn_days . ' days (' . number_format( $total_queries ) . " queries observed)\n"
			. '// Unique templates: ' . count( $map ) . "\n"
			. 'return ' . var_export( $data, true ) . ";\n";

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$written = file_put_contents( $path, $content, LOCK_EX );

		if ( false === $written ) {
			return new WP_Error( 'pigcache_write_fail', 'Could not write profile to: ' . $path );
		}

		$data['source'] = 'local';

		self::$profile        = $data;
		self::$profile_loaded = true;

		self::stop_learning();

		if ( class_exists( 'PigCache_Cloud_Sync', false ) ) {
			PigCache_Cloud_Sync::mark_local_profile();
			PigCache_Cloud_Sync::push_compile_stats( $data );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Runtime: load and query the compiled profile
	// ------------------------------------------------------------------

	/**
	 * @return string Full path to the compiled profile.
	 */
	public static function profile_path() {
		if ( defined( 'PIGCACHE_SQL_PROFILE_PATH' ) && PIGCACHE_SQL_PROFILE_PATH ) {
			return PIGCACHE_SQL_PROFILE_PATH;
		}

		return ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' )
			. '/pigcache-sql-profile.php';
	}

	/**
	 * @return bool
	 */
	public static function has_profile() {
		return is_readable( self::profile_path() );
	}

	/**
	 * Load the compiled profile (once per request).
	 *
	 * @return array|false
	 */
	public static function load_profile() {
		if ( self::$profile_loaded ) {
			return self::$profile ?: false;
		}

		self::$profile_loaded = true;

		$path = self::profile_path();

		if ( ! is_readable( $path ) ) {
			return false;
		}

		$data = include $path;

		if ( ! is_array( $data ) || ! isset( $data['map'] ) ) {
			return false;
		}

		self::$profile = $data;

		return $data;
	}

	/**
	 * Get the tables a query fingerprint touches (from compiled profile).
	 *
	 * @param string $fingerprint md5 of normalized query.
	 * @return string[]|false Tables array or false if not in profile.
	 */
	public static function get_tables_for_fingerprint( $fingerprint ) {
		$profile = self::load_profile();
		if ( ! $profile ) {
			return false;
		}

		return isset( $profile['map'][ $fingerprint ] ) ? $profile['map'][ $fingerprint ] : false;
	}

	/**
	 * Get the tables a raw query touches: profile lookup with fallback to regex.
	 *
	 * @param string $query Raw SQL.
	 * @return string[]|false Tables or false if no profile and no extraction.
	 */
	public static function get_tables_for_query( $query ) {
		$norm = self::normalize( $query );
		$fp   = md5( $norm );

		$tables = self::get_tables_for_fingerprint( $fp );

		if ( false !== $tables ) {
			return $tables;
		}

		if ( ! self::has_profile() ) {
			return false;
		}

		$extracted = self::extract_tables( $query );

		return ! empty( $extracted ) ? $extracted : false;
	}

	/**
	 * Get compiled profile stats (for admin display).
	 *
	 * @return array|false
	 */
	public static function get_profile_stats() {
		return self::load_profile();
	}

	// ------------------------------------------------------------------
	// Re-learn management
	// ------------------------------------------------------------------

	/**
	 * Trigger a re-learn cycle: backup the old profile, start a new learning window.
	 *
	 * @param int $days
	 */
	public static function trigger_relearn( $days = 0 ) {
		if ( ! PigCache_License::can_use_profiler() ) {
			return;
		}

		$path = self::profile_path();

		if ( is_readable( $path ) ) {
			$bak = $path . '.bak';
			@copy( $path, $bak );
		}

		if ( $days < 1 ) {
			$days = self::DEFAULT_LEARN_DAYS;
		}

		if ( class_exists( 'PigCache_Sql_Profile_Store', false ) ) {
			PigCache_Sql_Profile_Store::truncate();
		}

		self::start_learning( $days );

		self::$profile        = null;
		self::$profile_loaded = false;
	}

	/**
	 * Delete the compiled profile file.
	 */
	public static function delete_profile() {
		$path = self::profile_path();
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}

		self::$profile        = null;
		self::$profile_loaded = false;
	}

	// ------------------------------------------------------------------
	// Cloud profile integration
	// ------------------------------------------------------------------

	/**
	 * Write a profile received from the PigCache Cloud API.
	 *
	 * The cloud profile has the same structure as a locally compiled one
	 * (map, tables, stats) so the runtime lookup code is unchanged.
	 *
	 * @param array $data Profile data from the API response.
	 * @return bool
	 */
	public static function write_cloud_profile( array $data ) {
		if ( empty( $data['map'] ) ) {
			return false;
		}

		if ( ! isset( $data['tables'] ) ) {
			$data['tables'] = self::build_tables_index( $data['map'] );
		}

		if ( ! isset( $data['compiled_at'] ) ) {
			$data['compiled_at'] = time();
		}

		$data['source'] = 'cloud';

		$path    = self::profile_path();
		$content = "<?php\n"
			. "// Downloaded from PigCache Cloud — do not edit.\n"
			. '// Written: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
			. '// Unique templates: ' . count( $data['map'] ) . "\n"
			. 'return ' . var_export( $data, true ) . ";\n";

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$written = file_put_contents( $path, $content, LOCK_EX );

		if ( false === $written ) {
			return false;
		}

		self::$profile        = $data;
		self::$profile_loaded = true;

		return true;
	}

	/**
	 * Build the reverse tables index from a fingerprint->tables map.
	 *
	 * @param array $map fingerprint => string[] tables.
	 * @return array table => string[] fingerprints.
	 */
	private static function build_tables_index( array $map ) {
		$index = array();

		foreach ( $map as $fp => $tables ) {
			foreach ( (array) $tables as $t ) {
				if ( ! isset( $index[ $t ] ) ) {
					$index[ $t ] = array();
				}
				$index[ $t ][] = $fp;
			}
		}

		return $index;
	}

	/**
	 * Whether the loaded profile came from the cloud.
	 *
	 * @return bool
	 */
	public static function is_cloud_profile() {
		$profile = self::load_profile();

		return is_array( $profile ) && isset( $profile['source'] ) && 'cloud' === $profile['source'];
	}
}
