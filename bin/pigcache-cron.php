<?php
/**
 * PigCache standalone cron runner.
 *
 * Executes the query analytics pipeline (flush buffer → MySQL, send → API)
 * WITHOUT bootstrapping WordPress or loading any plugins.
 *
 * Credentials are extracted from wp-config.php automatically.
 * APCu and Redis are accessed directly via native PHP extensions.
 *
 * Usage (cPanel → Cron Jobs, every 15 minutes):
 *   php /path/to/wp-content/plugins/pigcache/bin/pigcache-cron.php
 *
 * Or with an explicit wp-config path:
 *   php /path/to/.../pigcache-cron.php --wp-config /path/to/wp-config.php
 *
 * @package PigCache
 */
// ── PRO_START ─────────────────────────────────────────────────────────────────
/**
 * With log file (errors and verbose output appended to plugin root):
 *   php .../pigcache/bin/pigcache-cron.php > /dev/null 2>> .../pigcache/pigcache-cron.log
 */
// ── PRO_END ───────────────────────────────────────────────────────────────────

// ── Safety ───────────────────────────────────────────────────────────────────

if ( isset( $_SERVER['HTTP_HOST'] ) || isset( $_SERVER['REQUEST_METHOD'] ) ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

define( 'PIGCACHE_CRON_START', microtime( true ) );

// ── Error handlers ───────────────────────────────────────────────────────────
// $pigcache_error_sink is populated after DB credentials are resolved.
// Both handlers reference it via closure so they pick up the value set later.

$pigcache_error_sink = null;

set_exception_handler( static function ( Throwable $e ) use ( &$pigcache_error_sink ): void {
	$msg = get_class( $e ) . ': ' . $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . ']';
	fwrite( STDERR, "[pigcache-cron] EXCEPTION: {$msg}\n" );
	if ( $pigcache_error_sink ) {
		( $pigcache_error_sink )( $msg, 'exception' );
	}
	exit( 1 );
} );

register_shutdown_function( static function () use ( &$pigcache_error_sink ): void {
	$err = error_get_last();
	if ( ! $err || ! in_array( $err['type'], [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ], true ) ) {
		return;
	}
	$msg = 'Fatal error: ' . $err['message'] . ' [' . $err['file'] . ':' . $err['line'] . ']';
	fwrite( STDERR, "[pigcache-cron] FATAL: {$msg}\n" );
	if ( $pigcache_error_sink ) {
		( $pigcache_error_sink )( $msg, 'fatal' );
	}
} );

// ── Find wp-config.php ───────────────────────────────────────────────────────

$wp_config_path = _pigcache_cron_find_wp_config( $argv ?? array() );

if ( ! $wp_config_path ) {
	fwrite( STDERR, "[pigcache-cron] ERROR: wp-config.php not found. Pass --wp-config /path/to/wp-config.php\n" );
	exit( 1 );
}

// ── Extract constants from wp-config.php without executing WordPress ─────────

$cfg = _pigcache_cron_parse_config( $wp_config_path );

// When true, success/info lines go to STDERR so they appear in the cron log file.
// The cron command discards stdout (> /dev/null) but appends stderr to the log,
// so no crontab change is needed — just set the constant and the log fills up.
define( 'PIGCACHE_CRON_VERBOSE', ! empty( $cfg['PIGCACHE_CRON_VERBOSE'] ) );

if ( PIGCACHE_CRON_VERBOSE ) {
	error_reporting( E_ALL );
	ini_set( 'display_errors', '1' );  // PHP CLI sends display_errors to STDERR → lands in the cron log
}

if ( ! isset( $cfg['DB_NAME'], $cfg['DB_USER'], $cfg['DB_PASSWORD'], $cfg['DB_HOST'] ) ) {
	fwrite( STDERR, "[pigcache-cron] ERROR: Could not read DB credentials from wp-config.php\n" );
	exit( 1 );
}

// ── Connect to MySQL ─────────────────────────────────────────────────────────

$db = _pigcache_cron_mysql_connect( $cfg );

if ( ! $db ) {
	fwrite( STDERR, "[pigcache-cron] ERROR: MySQL connection failed\n" );
	exit( 1 );
}

$table_prefix  = $cfg['table_prefix'] ?? 'wp_';
$stats_table   = $table_prefix . 'pigcache_query_stats';
$options_table = $table_prefix . 'options';
$kv_table      = $table_prefix . 'pigcache_kv';
$kv_ready      = _pigcache_cron_table_exists( $db, $kv_table );

// ── PRO_START ─────────────────────────────────────────────────────────────────
// ── Resolve API credentials (reused by tasks + error sink) ───────────────────

$api_url = rtrim( $cfg['PIGCACHE_CLOUD_API_URL'] ?? 'https://bluecache.pigworlds.com/api/v1', '/' );
$api_key = ( $cfg['PIGCACHE_API_KEY'] ?? '' )
	?: _pigcache_cron_get_option( $db, $options_table, 'pigcache_license_key' );
$site_id = _pigcache_cron_get_option( $db, $options_table, 'pigcache_cloud_site_id' );
// ── PRO_END ───────────────────────────────────────────────────────────────────

// ── Error sink — records last error to the KV table ──────────────────────────

$pigcache_error_sink = static function ( string $msg, string $context = '' ) use ( $db, $kv_table, $kv_ready ): void {
	if ( $kv_ready ) {
		_pigcache_cron_kv_set( $db, $kv_table, 'cron_last_error', json_encode( [
			'msg'     => $msg,
			'context' => $context,
			'ts'      => time(),
		] ) );
	}
};
// ── PRO_START ─────────────────────────────────────────────────────────────────
// Augment the error sink to also push errors to the cloud API.
$pigcache_error_sink = static function ( string $msg, string $context = '' ) use ( $db, $kv_table, $kv_ready, $api_url, $api_key, $site_id ): void {
	if ( $kv_ready ) {
		_pigcache_cron_kv_set( $db, $kv_table, 'cron_last_error', json_encode( [
			'msg'     => $msg,
			'context' => $context,
			'ts'      => time(),
		] ) );
	}
	if ( $api_url && $api_key && $site_id ) {
		_pigcache_cron_api_request( 'POST', $api_url . '/cron-error', $api_key, $site_id, [
			'message'     => $msg,
			'context'     => $context,
			'occurred_at' => time(),
		] );
	}
};
// ── PRO_END ───────────────────────────────────────────────────────────────────

// ── Determine which tasks to run ─────────────────────────────────────────────

$run_flush = true;
// ── PRO_START ─────────────────────────────────────────────────────────────────
$run_send             = true;
$run_cloud            = true;
$run_mutation_harvest = true;
$run_traffic_harvest  = true;
$run_html_tier_report = true;

foreach ( $argv ?? array() as $arg ) {
	if ( $arg === '--flush-only'  ) { $run_send = false; $run_cloud = false; $run_mutation_harvest = false; $run_traffic_harvest = false; $run_html_tier_report = false; }
	if ( $arg === '--send-only'   ) { $run_flush = false; $run_cloud = false; $run_mutation_harvest = false; $run_traffic_harvest = false; $run_html_tier_report = false; }
	if ( $arg === '--no-cloud'    ) { $run_cloud = false; }
	if ( $arg === '--cloud-only'  ) { $run_flush = false; $run_send = false; $run_mutation_harvest = false; $run_traffic_harvest = false; $run_html_tier_report = false; }
	if ( $arg === '--no-adaptive' ) { $run_mutation_harvest = false; $run_traffic_harvest = false; $run_html_tier_report = false; }
}
// ── PRO_END ───────────────────────────────────────────────────────────────────

// ── TASK 1: Flush APCu/Redis buffer → MySQL ───────────────────────────────────

if ( $run_flush ) {
	$rows = _pigcache_cron_read_buffer( $cfg );

	if ( ! empty( $rows ) ) {
		$window       = (int) floor( time() / 900 ) * 900;
		$period_start = gmdate( 'Y-m-d H:i:s', $window );
		_pigcache_cron_upsert_batch( $db, $stats_table, $rows, $period_start );
	}

	// Record heartbeat in wp_pigcache_kv (bypasses object cache — always visible to admin).
	if ( $kv_ready ) {
		_pigcache_cron_kv_set( $db, $kv_table, 'cron_flush_last', time() );
	}

	_pigcache_cron_log( 'flush done — ' . count( $rows ) . ' unique queries' );
}

// ── PRO_START ─────────────────────────────────────────────────────────────────
// ── TASK 2: Send unsent rows → API ───────────────────────────────────────────

if ( $run_send ) {
	$unsent = _pigcache_cron_get_unsent( $db, $stats_table, 200 );

	if ( ! empty( $unsent ) ) {
		if ( $api_key && $site_id ) {
			$accepted = _pigcache_cron_push_to_api( $api_url, $api_key, $site_id, $unsent );
			if ( ! empty( $accepted ) ) {
				_pigcache_cron_mark_sent( $db, $stats_table, $accepted );
			}
			_pigcache_cron_log( 'send done — ' . count( $accepted ) . '/' . count( $unsent ) . ' rows accepted' );
		} else {
			_pigcache_cron_log( 'send skipped — no API key or site ID' );
		}
	}

	if ( $kv_ready ) {
		_pigcache_cron_kv_set( $db, $kv_table, 'cron_send_last', time() );
	}

	// Prune sent rows older than 30 days.
	$db->query( "DELETE FROM `{$stats_table}` WHERE sent_at IS NOT NULL AND period_start < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
}

// ── TASK 3: Cloud sync (environment + fingerprints → API, profile download) ───

if ( $run_cloud ) {
	if ( ! $api_key || ! $site_id ) {
		_pigcache_cron_log( 'cloud sync skipped — no API key or site ID (activate license from wp-admin first)' );
	} else {
		// 3a. Send environment details so the API can match the correct profile.
		$env = _pigcache_cron_build_environment( $db, $options_table, $wp_config_path );
		_pigcache_cron_api_request( 'POST', $api_url . "/sites/{$site_id}/environment", $api_key, $site_id, $env );
		_pigcache_cron_log( 'cloud env sent' );

		// 3b. Upload unsynced SQL fingerprints in batches of 500.
		$fp_table     = $table_prefix . 'pigcache_sql_fingerprints';
		$fp_total     = 0;
		$fp_has_table = _pigcache_cron_table_exists( $db, $fp_table );

		if ( $fp_has_table ) {
			do {
				$rows = _pigcache_cron_get_unsynced_fingerprints( $db, $fp_table, 500 );
				if ( empty( $rows ) ) {
					break;
				}

				$payload = array();
				$hashes  = array();

				foreach ( $rows as $row ) {
					$payload[] = array(
						'fingerprint' => $row['fingerprint'],
						'template'    => $row['template'],
						'tables'      => json_decode( $row['tables_json'], true ),
						'hit_count'   => (int) $row['hit_count'],
						'avg_rows'    => round( (float) $row['avg_rows'], 2 ),
					);
					$hashes[] = $row['fingerprint'];
				}

				$resp = _pigcache_cron_api_request(
					'POST',
					$api_url . "/sites/{$site_id}/fingerprints",
					$api_key,
					$site_id,
					array( 'fingerprints' => $payload )
				);

				if ( $resp !== null ) {
					_pigcache_cron_mark_synced_fingerprints( $db, $fp_table, $hashes );
					$fp_total += count( $hashes );
				} else {
					break;
				}
			} while ( count( $rows ) >= 500 );
		}

		_pigcache_cron_log( "cloud fingerprints: {$fp_total} sent" );

		// 3c. Download compiled profile and write it to disk.
		$profile_path = _pigcache_cron_resolve_profile_path( $cfg, $wp_config_path );
		$written      = _pigcache_cron_download_profile( $api_url, $api_key, $site_id, $profile_path );

		if ( $written ) {
			_pigcache_cron_update_option( $db, $options_table, 'pigcache_cloud_profile_source', 'cloud' );
			_pigcache_cron_log( 'cloud profile: downloaded and written to ' . $profile_path );
		} else {
			_pigcache_cron_log( 'cloud profile: no update (no new profile or API error)' );
		}

		_pigcache_cron_update_option( $db, $options_table, 'pigcache_cloud_last_sync', (string) time() );
	}
}

// ── TASK 4: Harvest mutation window → MySQL → stability map ──────────────────

if ( $run_mutation_harvest ) {
	$mut_window_raw = _pigcache_cron_get_option( $db, $options_table, 'pigcache_mut_window' );
	$mut_window     = $mut_window_raw ? json_decode( $mut_window_raw, true ) : array();

	if ( is_array( $mut_window ) && ! empty( $mut_window ) ) {
		$stability_table = $table_prefix . 'pigcache_table_stability';
		$window_start    = gmdate( 'Y-m-d H:i:s', (int) floor( time() / 900 ) * 900 );

		if ( _pigcache_cron_table_exists( $db, $stability_table ) ) {
			foreach ( $mut_window as $table_name => $count ) {
				$stmt = $db->prepare(
					"INSERT INTO `{$stability_table}` (table_name, window_start, mutation_count)
					 VALUES (?, ?, ?)
					 ON DUPLICATE KEY UPDATE mutation_count = mutation_count + VALUES(mutation_count)"
				);
				if ( $stmt ) {
					$stmt->bind_param( 'ssi', $table_name, $window_start, $count );
					$stmt->execute();
					$stmt->close();
				}
			}

			// Reset the window accumulator.
			_pigcache_cron_update_option( $db, $options_table, 'pigcache_mut_window', '{}' );

			// Prune old rows.
			$db->query( "DELETE FROM `{$stability_table}` WHERE window_start < DATE_SUB(NOW(), INTERVAL 30 DAY)" );

			// Build stability map (mutations per table in last 24h) and store as transient.
			$stability_map = _pigcache_cron_build_stability_map( $db, $stability_table );
			_pigcache_cron_set_transient( $db, $options_table, 'pigcache_stability_map', serialize( $stability_map ), 25 * 60 );

			_pigcache_cron_log( 'mutation harvest done — ' . count( $mut_window ) . ' tables' );
		} else {
			_pigcache_cron_log( 'mutation harvest skipped — table not found (run plugin activate)' );
		}
	} else {
		_pigcache_cron_log( 'mutation harvest — no pending mutations' );
	}
}

// ── TASK 5: Harvest traffic data → MySQL → traffic map ───────────────────────

if ( $run_traffic_harvest ) {
	$last_traffic = $kv_ready
		? (int) _pigcache_cron_kv_get( $db, $kv_table, 'cron_traffic_harvest_last' )
		: (int) _pigcache_cron_get_option( $db, $options_table, 'pigcache_traffic_harvest_last' );
	$harvest_due  = ( time() - $last_traffic ) >= ( 23 * 3600 );

	if ( $harvest_due ) {
		$traffic_table = $table_prefix . 'pigcache_url_traffic';

		if ( _pigcache_cron_table_exists( $db, $traffic_table ) ) {
			// Try AWStats first.
			$traffic_data = _pigcache_cron_read_awstats( $cfg, $wp_config_path );
			$source       = 'awstats';

			// Fallback to own hit counters.
			if ( empty( $traffic_data ) ) {
				$hits_raw     = _pigcache_cron_get_option( $db, $options_table, 'pigcache_url_hits_window' );
				$hits_raw_arr = $hits_raw ? json_decode( $hits_raw, true ) : array();

				if ( is_array( $hits_raw_arr ) && ! empty( $hits_raw_arr ) ) {
					foreach ( $hits_raw_arr as $entry ) {
						if ( ! empty( $entry['uri'] ) && isset( $entry['hits'] ) ) {
							$uri                  = $entry['uri'];
							$traffic_data[ $uri ] = ( isset( $traffic_data[ $uri ] ) ? $traffic_data[ $uri ] : 0 ) + (int) $entry['hits'];
						}
					}
					_pigcache_cron_update_option( $db, $options_table, 'pigcache_url_hits_window', '{}' );
				}

				$source = 'self';
			}

			if ( ! empty( $traffic_data ) ) {
				$period_start = gmdate( 'Y-m-d H:i:s', (int) floor( time() / 86400 ) * 86400 );

				foreach ( array_chunk( $traffic_data, 100, true ) as $chunk ) {
					foreach ( $chunk as $uri => $hits ) {
						$url_hash = md5( $uri );
						$uri_str  = substr( (string) $uri, 0, 2083 );
						$stmt     = $db->prepare(
							"INSERT INTO `{$traffic_table}` (url_hash, url, hit_count, period_start)
							 VALUES (?, ?, ?, ?)
							 ON DUPLICATE KEY UPDATE hit_count = hit_count + VALUES(hit_count)"
						);
						if ( $stmt ) {
							$stmt->bind_param( 'ssis', $url_hash, $uri_str, $hits, $period_start );
							$stmt->execute();
							$stmt->close();
						}
					}
				}

				$db->query( "DELETE FROM `{$traffic_table}` WHERE period_start < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
			}

			// Build traffic map (top 1000 URLs by 30-day hits) and store as transient.
			$traffic_map = _pigcache_cron_build_traffic_map( $db, $traffic_table );
			_pigcache_cron_set_transient( $db, $options_table, 'pigcache_traffic_map', serialize( $traffic_map ), 25 * 3600 );

			if ( $kv_ready ) {
				_pigcache_cron_kv_set( $db, $kv_table, 'cron_traffic_harvest_last', time() );
				_pigcache_cron_kv_set( $db, $kv_table, 'cron_traffic_source_used', $source );
			} else {
				_pigcache_cron_update_option( $db, $options_table, 'pigcache_traffic_harvest_last', (string) time() );
				_pigcache_cron_update_option( $db, $options_table, 'pigcache_traffic_source_used', $source );
			}

			_pigcache_cron_log( 'traffic harvest done — ' . count( $traffic_data ) . ' URLs (source: ' . $source . ')' );
		} else {
			_pigcache_cron_log( 'traffic harvest skipped — table not found (run plugin activate)' );
		}
	} else {
		_pigcache_cron_log( 'traffic harvest — skipped (last run ' . round( ( time() - $last_traffic ) / 3600, 1 ) . 'h ago)' );
	}
}
// ── TASK 6: Report HTML cache tier log → API ─────────────────────────────────

if ( $run_html_tier_report && $api_key && $site_id ) {
	$tier_log_raw = _pigcache_cron_get_option( $db, $options_table, 'pigcache_html_tier_log' );
	$tier_log     = $tier_log_raw ? json_decode( $tier_log_raw, true ) : array();

	if ( is_array( $tier_log ) && ! empty( $tier_log ) ) {
		$counts      = array( 'hot_stable' => 0, 'hot_dynamic' => 0, 'cold' => 0 );
		$hot_dynamic = array();
		$hot_stable  = array();

		foreach ( $tier_log as $entry ) {
			$tier            = isset( $entry['tier'] ) ? $entry['tier'] : 'cold';
			$counts[ $tier ] = ( isset( $counts[ $tier ] ) ? $counts[ $tier ] : 0 ) + 1;

			if ( $tier === 'hot_dynamic' && count( $hot_dynamic ) < 20 ) {
				$hot_dynamic[] = array( 'uri' => $entry['uri'], 'ttl' => (int) $entry['ttl'] );
			}
			if ( $tier === 'hot_stable' && count( $hot_stable ) < 20 ) {
				$hot_stable[] = array( 'uri' => $entry['uri'], 'ttl' => (int) $entry['ttl'] );
			}
		}

		_pigcache_cron_api_request( 'POST', $api_url . '/adaptive-ttl-report', $api_key, $site_id, array(
			'tier_counts' => $counts,
			'hot_dynamic' => $hot_dynamic,
			'hot_stable'  => $hot_stable,
			'total'       => count( $tier_log ),
			'reported_at' => gmdate( 'c' ),
		) );

		_pigcache_cron_log( 'html tier report sent — ' . count( $tier_log ) . ' URIs (' . $counts['hot_stable'] . ' stable, ' . $counts['hot_dynamic'] . ' dynamic, ' . $counts['cold'] . ' cold)' );
	} else {
		_pigcache_cron_log( 'html tier report — no tier log yet' );
	}
}

// ── PRO_END ───────────────────────────────────────────────────────────────────

$elapsed = round( ( microtime( true ) - PIGCACHE_CRON_START ) * 1000 );
_pigcache_cron_log( "finished in {$elapsed}ms" );
$db->close();
exit( 0 );

// ── Helpers ──────────────────────────────────────────────────────────────────

function _pigcache_cron_find_wp_config( array $argv ): string {
	// --wp-config explicit override.
	foreach ( $argv as $i => $arg ) {
		if ( $arg === '--wp-config' && isset( $argv[ $i + 1 ] ) ) {
			return $argv[ $i + 1 ];
		}
	}

	// Walk up from the plugin directory to find wp-config.php.
	$dir = dirname( __DIR__ ); // plugin root
	for ( $i = 0; $i < 6; $i++ ) {
		$candidate = $dir . '/wp-config.php';
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}
		$dir = $parent;
	}

	return '';
}

function _pigcache_cron_parse_config( string $path ): array {
	$cfg     = array();
	$content = file_get_contents( $path );

	if ( $content === false ) {
		return $cfg;
	}

	// Extract define( 'CONSTANT', 'value' ) — handles single and double quotes.
	preg_match_all(
		"/define\s*\(\s*['\"]([A-Z0-9_]+)['\"]\s*,\s*(?:'([^']*)'|\"([^\"]*)\"|(true|false|[0-9]+))\s*\)/",
		$content,
		$matches,
		PREG_SET_ORDER
	);

	foreach ( $matches as $m ) {
		$key = $m[1];
		$val = $m[2] !== '' ? $m[2] : ( $m[3] !== '' ? $m[3] : $m[4] );
		if ( $val === 'true' )  { $val = true; }
		if ( $val === 'false' ) { $val = false; }
		$cfg[ $key ] = $val;
	}

	// Extract $table_prefix.
	if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m ) ) {
		$cfg['table_prefix'] = $m[1];
	}

	return $cfg;
}

function _pigcache_cron_mysql_connect( array $cfg ): ?mysqli {
	$host = $cfg['DB_HOST'] ?? '127.0.0.1';
	$port = 3306;

	// cPanel often encodes port as host:port.
	if ( strpos( $host, ':' ) !== false ) {
		[ $host, $p ] = explode( ':', $host, 2 );
		$port = (int) $p ?: 3306;
	}

	$db = new mysqli( $host, $cfg['DB_USER'], $cfg['DB_PASSWORD'], $cfg['DB_NAME'], $port );

	if ( $db->connect_errno ) {
		return null;
	}

	$db->set_charset( 'utf8mb4' );

	return $db;
}

function _pigcache_cron_read_buffer( array $cfg ): array {
	// Try APCu first.
	if ( function_exists( 'apcu_enabled' ) && apcu_enabled() && class_exists( 'APCUIterator', false ) ) {
		$result   = array();
		$iterator = new APCUIterator( '/^pigcache_qbuf:/' );
		foreach ( $iterator as $item ) {
			$hash            = substr( $item['key'], strlen( 'pigcache_qbuf:' ) );
			$result[ $hash ] = $item['value'];
			apcu_delete( $item['key'] );
		}
		if ( ! empty( $result ) ) {
			return $result;
		}
	}

	// Try Redis.
	if ( class_exists( 'Redis', false ) ) {
		$redis = _pigcache_cron_redis_connect( $cfg );
		if ( $redis ) {
			$result      = array();
			$prev_window = (int) floor( time() / 900 ) - 1;
			$key         = 'pigcache_qbuf:' . $prev_window;
			$raw         = $redis->hGetAll( $key );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $hash => $json ) {
					$data = json_decode( $json, true );
					if ( is_array( $data ) ) {
						$result[ $hash ] = $data;
					}
				}
			}
			$redis->del( $key );
			$redis->close();
			return $result;
		}
	}

	return array();
}

function _pigcache_cron_redis_connect( array $cfg ): ?Redis {
	if ( ! class_exists( 'Redis', false ) ) {
		return null;
	}

	$host = $cfg['PIGCACHE_REDIS_HOST'] ?? '127.0.0.1';
	$port = isset( $cfg['PIGCACHE_REDIS_PORT'] ) ? (int) $cfg['PIGCACHE_REDIS_PORT'] : 6379;

	try {
		$redis = new Redis();
		$redis->connect( $host, $port, 2 );
		if ( ! empty( $cfg['PIGCACHE_REDIS_PASSWORD'] ) ) {
			$redis->auth( $cfg['PIGCACHE_REDIS_PASSWORD'] );
		}
		return $redis;
	} catch ( Exception $e ) {
		return null;
	}
}

function _pigcache_cron_upsert_batch( mysqli $db, string $table, array $rows, string $period_start ): void {
	foreach ( array_chunk( $rows, 50, true ) as $chunk ) {
		$values       = array();
		$placeholders = array();

		foreach ( $chunk as $hash => $data ) {
			$placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
			array_push(
				$values,
				(string) $hash,
				(string) ( $data['normalized'] ?? '' ),
				json_encode( $data['tables'] ?? array() ),
				(int) ( $data['count'] ?? 0 ),
				(int) ( $data['total_ms'] ?? 0 ),
				(int) ( $data['max_ms'] ?? 0 ),
				(int) ( $data['total_mem'] ?? 0 ),
				$period_start
			);
		}

		$sql = "INSERT INTO `{$table}`
			(query_hash, normalized, tables, hit_count, total_exec_ms, max_exec_ms, total_mem_kb, period_start)
			VALUES " . implode( ', ', $placeholders ) . "
			ON DUPLICATE KEY UPDATE
				hit_count     = hit_count     + VALUES(hit_count),
				total_exec_ms = total_exec_ms + VALUES(total_exec_ms),
				max_exec_ms   = GREATEST(max_exec_ms, VALUES(max_exec_ms)),
				total_mem_kb  = total_mem_kb  + VALUES(total_mem_kb)";

		$stmt = $db->prepare( $sql );
		if ( ! $stmt ) {
			continue;
		}

		$types = str_repeat( 's', count( $values ) );
		$stmt->bind_param( $types, ...$values );
		$stmt->execute();
		$stmt->close();
	}
}

// ── PRO_START ─────────────────────────────────────────────────────────────────
function _pigcache_cron_get_unsent( mysqli $db, string $table, int $limit ): array {
	$stmt = $db->prepare( "SELECT * FROM `{$table}` WHERE sent_at IS NULL ORDER BY period_start ASC LIMIT ?" );
	if ( ! $stmt ) {
		return array();
	}
	$stmt->bind_param( 'i', $limit );
	$stmt->execute();
	$result = $stmt->get_result();
	$rows   = $result ? $result->fetch_all( MYSQLI_ASSOC ) : array();
	$stmt->close();
	return $rows;
}

function _pigcache_cron_mark_sent( mysqli $db, string $table, array $ids ): void {
	if ( empty( $ids ) ) {
		return;
	}
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '?' ) );
	$stmt = $db->prepare( "UPDATE `{$table}` SET sent_at = NOW() WHERE id IN ({$placeholders})" );
	if ( ! $stmt ) {
		return;
	}
	$types = str_repeat( 'i', count( $ids ) );
	$stmt->bind_param( $types, ...$ids );
	$stmt->execute();
	$stmt->close();
}

function _pigcache_cron_push_to_api( string $api_url, string $api_key, string $site_id, array $rows ): array {
	$ch = curl_init( $api_url . '/query-stats' );
	if ( ! $ch ) {
		return array();
	}

	$body = json_encode( array( 'rows' => $rows ) );

	curl_setopt_array( $ch, array(
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => $body,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_HTTPHEADER     => array(
			'Content-Type: application/json',
			'Accept: application/json',
			'Authorization: Bearer ' . $api_key,
			'X-Site-Id: ' . $site_id,
		),
	) );

	$response = curl_exec( $ch );
	$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	if ( $code !== 200 || ! $response ) {
		return array();
	}

	$data = json_decode( $response, true );

	return array_map( 'intval', (array) ( $data['data']['accepted'] ?? array() ) );
}

function _pigcache_cron_get_option( mysqli $db, string $table, string $option_name ): string {
	$stmt = $db->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = ? LIMIT 1" );
	if ( ! $stmt ) {
		return '';
	}
	$stmt->bind_param( 's', $option_name );
	$stmt->execute();
	$stmt->bind_result( $value );
	$stmt->fetch();
	$stmt->close();
	return (string) ( $value ?? '' );
}

function _pigcache_cron_update_option( mysqli $db, string $table, string $option_name, string $value ): void {
	$stmt = $db->prepare(
		"INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (?, ?, 'no')
		 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
	);
	if ( ! $stmt ) {
		return;
	}
	$stmt->bind_param( 'ss', $option_name, $value );
	$stmt->execute();
	$stmt->close();
}
// ── PRO_END ───────────────────────────────────────────────────────────────────

function _pigcache_cron_log( string $msg ): void {
	$ts   = gmdate( 'Y-m-d H:i:s' );
	$line = "[pigcache-cron {$ts}] {$msg}\n";
	// Verbose mode → STDERR so the line lands in the cron log file.
	// Normal mode  → STDOUT, which the cron command discards (> /dev/null).
	fwrite( defined( 'PIGCACHE_CRON_VERBOSE' ) && PIGCACHE_CRON_VERBOSE ? STDERR : STDOUT, $line );
}

// ── PRO_START ─────────────────────────────────────────────────────────────────
// ── Cloud sync helpers ────────────────────────────────────────────────────────

/**
 * Generic HTTP request to the PigCache API.
 * Returns decoded JSON body on success (2xx), null on failure.
 *
 * @return array|null
 */
function _pigcache_cron_api_request( string $method, string $url, string $api_key, string $site_id, array $body = array() ): ?array {
	$ch = curl_init( $url );
	if ( ! $ch ) {
		return null;
	}

	$json = json_encode( $body );

	curl_setopt_array( $ch, array(
		CURLOPT_CUSTOMREQUEST  => $method,
		CURLOPT_POSTFIELDS     => $json,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 20,
		CURLOPT_HTTPHEADER     => array(
			'Content-Type: application/json',
			'Accept: application/json',
			'Authorization: Bearer ' . $api_key,
			'X-Site-Id: ' . $site_id,
		),
	) );

	$response = curl_exec( $ch );
	$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	if ( $code < 200 || $code >= 300 || ! $response ) {
		return null;
	}

	return json_decode( $response, true ) ?? array();
}

/**
 * Build environment payload by reading active plugins and theme from wp_options.
 *
 * @param mysqli $db
 * @param string $options_table
 * @param string $wp_config_path  Used to derive WP version from wp-includes/version.php.
 * @return array
 */
function _pigcache_cron_build_environment( mysqli $db, string $options_table, string $wp_config_path ): array {
	// Active plugins.
	$raw_plugins = _pigcache_cron_get_option( $db, $options_table, 'active_plugins' );
	$plugins     = array();

	if ( $raw_plugins ) {
		$list = @unserialize( $raw_plugins );
		if ( is_array( $list ) ) {
			foreach ( $list as $file ) {
				$dir       = dirname( $file );
				$plugins[] = ( '.' === $dir ) ? $file : $dir;
			}
			sort( $plugins );
		}
	}

	// Active theme (stylesheet).
	$theme = _pigcache_cron_get_option( $db, $options_table, 'stylesheet' );

	// WP version — read from wp-includes/version.php without executing WordPress.
	$wp_version   = '';
	$version_file = rtrim( dirname( $wp_config_path ), '/\\' ) . '/wp-includes/version.php';
	if ( ! file_exists( $version_file ) ) {
		// wp-config.php may live one level above ABSPATH.
		$version_file = rtrim( dirname( $wp_config_path ), '/\\' ) . '/wp/wp-includes/version.php';
	}
	if ( file_exists( $version_file ) ) {
		$content = file_get_contents( $version_file );
		if ( preg_match( "/\\\$wp_version\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m ) ) {
			$wp_version = $m[1];
		}
	}

	$parts    = explode( '.', $wp_version );
	$wp_major = ( isset( $parts[0], $parts[1] ) ) ? $parts[0] . '.' . $parts[1] : $wp_version;

	return array(
		'plugins'     => $plugins,
		'theme'       => $theme,
		'wp_version'  => $wp_version,
		'wp_major'    => $wp_major,
		'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
	);
}
// ── PRO_END ───────────────────────────────────────────────────────────────────

/**
 * Whether a MySQL table exists.
 */
function _pigcache_cron_table_exists( mysqli $db, string $table ): bool {
	$escaped = $db->real_escape_string( $table );
	$result  = $db->query( "SHOW TABLES LIKE '{$escaped}'" );
	return $result && $result->num_rows > 0;
}

// ── PRO_START ─────────────────────────────────────────────────────────────────
/**
 * Get unsynced fingerprints from pigcache_sql_fingerprints.
 *
 * @return array[]
 */
function _pigcache_cron_get_unsynced_fingerprints( mysqli $db, string $table, int $limit ): array {
	$stmt = $db->prepare(
		"SELECT fingerprint, template, tables_json, hit_count, avg_rows
		   FROM `{$table}`
		  WHERE synced_at IS NULL
		  ORDER BY hit_count DESC
		  LIMIT ?"
	);
	if ( ! $stmt ) {
		return array();
	}
	$stmt->bind_param( 'i', $limit );
	$stmt->execute();
	$result = $stmt->get_result();
	$rows   = $result ? $result->fetch_all( MYSQLI_ASSOC ) : array();
	$stmt->close();
	return $rows;
}

/**
 * Mark fingerprints as synced.
 *
 * @param string[] $hashes
 */
function _pigcache_cron_mark_synced_fingerprints( mysqli $db, string $table, array $hashes ): void {
	if ( empty( $hashes ) ) {
		return;
	}
	$placeholders = implode( ',', array_fill( 0, count( $hashes ), '?' ) );
	$stmt         = $db->prepare(
		"UPDATE `{$table}` SET synced_at = NOW() WHERE fingerprint IN ({$placeholders})"
	);
	if ( ! $stmt ) {
		return;
	}
	$types = str_repeat( 's', count( $hashes ) );
	$stmt->bind_param( $types, ...$hashes );
	$stmt->execute();
	$stmt->close();
}
// ── PRO_END ───────────────────────────────────────────────────────────────────

// ── KV store helpers (mirrors PigCache_KV for standalone cron) ───────────────

/**
 * Write a scalar value to wp_pigcache_kv.
 * Scalar values are stored as plain strings (matching maybe_serialize behaviour).
 *
 * @param mixed $value  Scalar only — arrays not needed from the cron.
 */
function _pigcache_cron_kv_set( mysqli $db, string $table, string $key, $value ): void {
	$val  = (string) $value;
	$now  = gmdate( 'Y-m-d H:i:s' );
	$stmt = $db->prepare(
		"INSERT INTO `{$table}` (cache_key, value, updated_at, expires_at)
		 VALUES (?, ?, ?, NULL)
		 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at), expires_at = NULL"
	);
	if ( $stmt ) {
		$stmt->bind_param( 'sss', $key, $val, $now );
		$stmt->execute();
		$stmt->close();
	}
}

// ── PRO_START ─────────────────────────────────────────────────────────────────
/**
 * Read a scalar value from wp_pigcache_kv.
 * Returns $default when the key is absent or expired.
 *
 * @return string
 */
function _pigcache_cron_kv_get( mysqli $db, string $table, string $key, string $default = '' ): string {
	$stmt = $db->prepare(
		"SELECT value FROM `{$table}`
		  WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW())
		  LIMIT 1"
	);
	if ( ! $stmt ) {
		return $default;
	}
	$stmt->bind_param( 's', $key );
	$stmt->execute();
	$result = $stmt->get_result();
	$row    = $result ? $result->fetch_assoc() : null;
	$stmt->close();
	return $row ? (string) $row['value'] : $default;
}

// ── Adaptive TTL helpers ──────────────────────────────────────────────────────

/**
 * Write a WordPress transient directly to MySQL.
 * Mirrors what set_transient() does internally.
 *
 * @param int $ttl  Seconds until expiry.
 */
function _pigcache_cron_set_transient( mysqli $db, string $options_table, string $key, string $value, int $ttl ): void {
	$timeout_key   = '_transient_timeout_' . $key;
	$transient_key = '_transient_' . $key;
	$expires       = time() + $ttl;

	// Write the timeout first, then the value.
	$stmt = $db->prepare(
		"INSERT INTO `{$options_table}` (option_name, option_value, autoload) VALUES (?, ?, 'no')
		 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
	);
	if ( $stmt ) {
		$exp_str = (string) $expires;
		$stmt->bind_param( 'ss', $timeout_key, $exp_str );
		$stmt->execute();
		$stmt->bind_param( 'ss', $transient_key, $value );
		$stmt->execute();
		$stmt->close();
	}
}

/**
 * Build the stability map: table_name → mutations in last 24h.
 *
 * @return array<string,int>
 */
function _pigcache_cron_build_stability_map( mysqli $db, string $stability_table ): array {
	$result = $db->query(
		"SELECT table_name, SUM(mutation_count) AS total
		   FROM `{$stability_table}`
		  WHERE window_start >= DATE_SUB(NOW(), INTERVAL 1 DAY)
		  GROUP BY table_name"
	);

	$map = array();
	if ( $result ) {
		while ( $row = $result->fetch_assoc() ) {
			$map[ $row['table_name'] ] = (int) $row['total'];
		}
		$result->free();
	}

	return $map;
}

/**
 * Build the traffic map: url → total hits over last 30 days (top 1000).
 *
 * @return array<string,int>
 */
function _pigcache_cron_build_traffic_map( mysqli $db, string $traffic_table ): array {
	$result = $db->query(
		"SELECT url, SUM(hit_count) AS total_hits
		   FROM `{$traffic_table}`
		  WHERE period_start >= DATE_SUB(NOW(), INTERVAL 30 DAY)
		  GROUP BY url
		  ORDER BY total_hits DESC
		  LIMIT 1000"
	);

	$map = array();
	if ( $result ) {
		while ( $row = $result->fetch_assoc() ) {
			$map[ $row['url'] ] = (int) $row['total_hits'];
		}
		$result->free();
	}

	return $map;
}

/**
 * Find and parse AWStats data files, returning uri → hits map.
 * Mirrors PigCache_Traffic_Reader::read_awstats() for standalone use.
 *
 * @return array<string,int>
 */
function _pigcache_cron_read_awstats( array $cfg, string $wp_config_path ): array {
	// Resolve AWStats directory.
	$dir = '';

	if ( ! empty( $cfg['PIGCACHE_AWSTATS_DIR'] ) ) {
		$dir = rtrim( $cfg['PIGCACHE_AWSTATS_DIR'], '/\\' );
	}

	if ( ! $dir || ! is_dir( $dir ) ) {
		$base       = rtrim( dirname( $wp_config_path ), '/\\' );
		$candidates = array(
			dirname( dirname( $base ) ) . '/tmp/awstats',
			dirname( $base ) . '/tmp/awstats',
			$base . '/tmp/awstats',
			'/tmp/awstats',
		);
		foreach ( $candidates as $path ) {
			if ( is_dir( $path ) ) {
				$dir = $path;
				break;
			}
		}
	}

	if ( ! $dir || ! is_dir( $dir ) ) {
		return array();
	}

	$files = glob( $dir . '/awstats*.txt' );
	if ( empty( $files ) ) {
		return array();
	}

	usort( $files, static function ( $a, $b ) {
		return filemtime( $b ) - filemtime( $a );
	} );

	return _pigcache_cron_parse_awstats_file( $files[0] );
}

/**
 * Parse BEGIN_URLS section of an AWStats data file.
 *
 * @return array<string,int>
 */
function _pigcache_cron_parse_awstats_file( string $path ): array {
	$handle = @fopen( $path, 'r' );
	if ( ! $handle ) {
		return array();
	}

	$result     = array();
	$in_section = false;
	$static_ext = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico',
		'css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'otf',
		'mp4', 'mp3', 'pdf', 'zip', 'gz', 'map',
	);

	while ( ( $line = fgets( $handle ) ) !== false ) {
		$line = rtrim( $line );

		if ( 'BEGIN_URLS' === substr( $line, 0, 10 ) ) {
			$in_section = true;
			continue;
		}

		if ( 'END_URLS' === $line ) {
			break;
		}

		if ( ! $in_section || '' === $line || '#' === $line[0] ) {
			continue;
		}

		$parts = preg_split( '/\s+/', $line );
		if ( ! isset( $parts[2] ) ) {
			continue;
		}

		$uri  = $parts[0];
		$hits = (int) $parts[2];

		if ( $hits < 1 || '' === $uri ) {
			continue;
		}

		$ext = strtolower( pathinfo( $uri, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $static_ext, true ) ) {
			continue;
		}

		$result[ $uri ] = isset( $result[ $uri ] ) ? $result[ $uri ] + $hits : $hits;
	}

	fclose( $handle );

	return $result;
}

/**
 * Determine where to write the SQL profile PHP file.
 * Mirrors PigCache_Sql_Profiler::profile_path().
 */
function _pigcache_cron_resolve_profile_path( array $cfg, string $wp_config_path ): string {
	if ( ! empty( $cfg['PIGCACHE_SQL_PROFILE_PATH'] ) ) {
		return $cfg['PIGCACHE_SQL_PROFILE_PATH'];
	}

	// Derive wp-content dir from ABSPATH or from wp-config location.
	if ( ! empty( $cfg['ABSPATH'] ) ) {
		$abspath = rtrim( $cfg['ABSPATH'], '/\\' );
	} else {
		$abspath = rtrim( dirname( $wp_config_path ), '/\\' );
		// If wp-config.php sits above wp-includes, ABSPATH is the directory containing wp-settings.php.
		if ( file_exists( $abspath . '/wp-settings.php' ) ) {
			// Already correct.
		} elseif ( file_exists( $abspath . '/wp/wp-settings.php' ) ) {
			$abspath .= '/wp';
		}
	}

	$content_dir = ! empty( $cfg['WP_CONTENT_DIR'] )
		? rtrim( $cfg['WP_CONTENT_DIR'], '/\\' )
		: $abspath . '/wp-content';

	return $content_dir . '/pigcache-sql-profile.php';
}

/**
 * Fetch the compiled profile from the cloud and write it to disk.
 * Mirrors PigCache_Cloud_Sync::check_profile() + PigCache_Sql_Profiler::write_cloud_profile().
 *
 * @return bool  True if a new profile was written.
 */
function _pigcache_cron_download_profile( string $api_url, string $api_key, string $site_id, string $profile_path ): bool {
	$ch = curl_init( $api_url . "/sites/{$site_id}/profile" );
	if ( ! $ch ) {
		return false;
	}

	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 20,
		CURLOPT_HTTPHEADER     => array(
			'Accept: application/json',
			'Authorization: Bearer ' . $api_key,
			'X-Site-Id: ' . $site_id,
		),
	) );

	$response = curl_exec( $ch );
	$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	if ( $code !== 200 || ! $response ) {
		return false;
	}

	$json = json_decode( $response, true );
	$data = $json['data'] ?? null;

	if ( ! is_array( $data ) || empty( $data['map'] ) ) {
		return false;
	}

	// Build tables reverse index if missing.
	if ( ! isset( $data['tables'] ) ) {
		$index = array();
		foreach ( $data['map'] as $fp => $tables ) {
			foreach ( (array) $tables as $t ) {
				$index[ $t ][] = $fp;
			}
		}
		$data['tables'] = $index;
	}

	if ( ! isset( $data['compiled_at'] ) ) {
		$data['compiled_at'] = time();
	}

	$data['source'] = 'cloud';

	$dir = dirname( $profile_path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}

	$content = "<?php\n"
		. "// Downloaded from PigCache Cloud — do not edit.\n"
		. '// Written: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
		. '// Unique templates: ' . count( $data['map'] ) . "\n"
		. 'return ' . var_export( $data, true ) . ";\n";

	return file_put_contents( $profile_path, $content, LOCK_EX ) !== false;
}
// ── PRO_END ───────────────────────────────────────────────────────────────────
