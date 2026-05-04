<?php
/**
 * Admin metrics: object-cache stats, Redis INFO, paginated key list (SCAN).
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Metrics {

	const PER_PAGE      = 100;
	const MAX_INDEX     = 8000;
	const TRANSIENT     = 'pigcache_metrics_key_index';
	const TRANSIENT_TTL = 120;

	/**
	 * No separate menu — sections are rendered inline on the main PigCache settings page.
	 */
	public static function init() {
	}

	/**
	 * Render all metrics sections (object cache stats, Redis INFO, key browser).
	 * Called from PigCache_Admin::render_page() when Redis is connected.
	 *
	 * @return void
	 */
	public static function render_sections() {
		if ( ! wp_using_ext_object_cache() ) {
			return;
		}

		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) || ! $wp_object_cache->redis_status() ) {
			return;
		}

		self::maybe_bust_key_cache();

		$redis = $wp_object_cache->redis_instance();

		if ( self::is_cluster( $redis ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Redis Cluster mode: server INFO and key listing are limited; only request-level object cache stats are shown.', 'pigcache' ) . '</p></div>';
		}

		self::render_object_cache_summary( $wp_object_cache );
		self::render_redis_info_section( $redis );
		self::render_keys_table( $wp_object_cache, $redis );
	}

	/**
	 * @return void
	 */
	private static function maybe_bust_key_cache() {
		if ( ! isset( $_GET['pigcache_refresh_keys'], $_GET['_wpnonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'pigcache_refresh_keys' ) ) {
			return;
		}

		delete_transient( self::TRANSIENT );
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Key list cache cleared. Rebuilding on this load.', 'pigcache' ) . '</p></div>';
			}
		);
	}

	/**
	 * @param object $wp_object_cache
	 */
	public static function render_object_cache_summary( $wp_object_cache ) {
		echo '<h2>' . esc_html__( 'Object cache (this request)', 'pigcache' ) . '</h2>';

		if ( ! method_exists( $wp_object_cache, 'info' ) ) {
			echo '<p>' . esc_html__( 'info() is not available on this object cache.', 'pigcache' ) . '</p>';
			return;
		}

		$info = $wp_object_cache->info();
		if ( ! is_object( $info ) ) {
			return;
		}

		$hits   = isset( $info->hits ) ? (int) $info->hits : 0;
		$misses = isset( $info->misses ) ? (int) $info->misses : 0;
		$ratio  = isset( $info->ratio ) ? (float) $info->ratio : 0.0;
		$bytes  = isset( $info->bytes ) ? (int) $info->bytes : 0;
		$time   = isset( $info->time ) ? (float) $info->time : 0.0;
		$calls  = isset( $info->calls ) ? (int) $info->calls : 0;

		echo '<table class="widefat striped pigcache-oc-summary"><tbody>';
		echo '<tr><th>' . esc_html__( 'Hits', 'pigcache' ) . '</th><td>' . esc_html( (string) $hits ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Misses', 'pigcache' ) . '</th><td>' . esc_html( (string) $misses ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Hit ratio %', 'pigcache' ) . '</th><td>' . esc_html( (string) $ratio ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Bytes (runtime cache)', 'pigcache' ) . '</th><td>' . esc_html( (string) $bytes ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Cache time (s)', 'pigcache' ) . '</th><td>' . esc_html( (string) $time ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Redis calls', 'pigcache' ) . '</th><td>' . esc_html( (string) $calls ) . '</td></tr>';

		if ( ! empty( $info->meta ) && is_array( $info->meta ) ) {
			foreach ( $info->meta as $k => $v ) {
				echo '<tr><th>' . esc_html( (string) $k ) . '</th><td>' . esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ) . '</td></tr>';
			}
		}

		if ( ! empty( $info->groups ) && is_object( $info->groups ) ) {
			$g = $info->groups;
			if ( ! empty( $g->global ) && is_array( $g->global ) ) {
				$list = $g->global;
				sort( $list );
				echo '<tr><th>' . esc_html__( 'Global groups', 'pigcache' ) . '</th><td>';
				echo '<p class="description" style="margin-top:0;">' . esc_html__( 'Key prefix scope (multisite/network). Still stored in Redis unless the group is also non-persistent.', 'pigcache' ) . '</p>';
				echo '<details><summary>' . esc_html( sprintf(
					__( 'Full list (%d)', 'pigcache' ),
					count( $list )
				) ) . '</summary>';
				echo '<pre class="pigcache-group-list">' . esc_html( implode( ', ', $list ) ) . '</pre>';
				echo '</details></td></tr>';
			}
			if ( ! empty( $g->non_persistent ) && is_array( $g->non_persistent ) ) {
				$list = $g->non_persistent;
				sort( $list );
				echo '<tr><th>' . esc_html__( 'Non-persistent groups', 'pigcache' ) . '</th><td>';
				echo '<p class="description" style="margin-top:0;">' . esc_html__( 'Ignored by Redis: values live only in PHP for this request (wp_cache_add_non_persistent_groups). Configure more on Settings → PigCache.', 'pigcache' ) . '</p>';
				echo '<details><summary>' . esc_html( sprintf(
					__( 'Full list (%d)', 'pigcache' ),
					count( $list )
				) ) . '</summary>';
				echo '<pre class="pigcache-group-list">' . esc_html( implode( ', ', $list ) ) . '</pre>';
				echo '</details></td></tr>';
			}
			if ( ! empty( $g->unflushable ) && is_array( $g->unflushable ) ) {
				echo '<tr><th>' . esc_html__( 'Unflushable groups', 'pigcache' ) . '</th><td>' . esc_html( implode( ', ', $g->unflushable ) ) . '</td></tr>';
			}
		}

		if ( ! empty( $info->errors ) ) {
			echo '<tr><th>' . esc_html__( 'Errors', 'pigcache' ) . '</th><td>' . esc_html( wp_json_encode( $info->errors ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param mixed $redis
	 */
	public static function render_redis_info_section( $redis ) {
		if ( self::is_cluster( $redis ) ) {
			return;
		}

		$flat = self::redis_info_flat( $redis );
		if ( empty( $flat ) ) {
			echo '<h2>' . esc_html__( 'Redis server', 'pigcache' ) . '</h2><p>' . esc_html__( 'Could not read Redis INFO.', 'pigcache' ) . '</p>';
			return;
		}

		echo '<h2>' . esc_html__( 'Redis INFO', 'pigcache' ) . '</h2>';

		$priority_keys = array(
			'redis_version',
			'used_memory_human',
			'used_memory',
			'total_connections_received',
			'total_commands_processed',
			'instantaneous_ops_per_sec',
			'keyspace_hits',
			'keyspace_misses',
			'expired_keys',
			'evicted_keys',
			'connected_clients',
			'blocked_clients',
			'uptime_in_seconds',
		);

		echo '<table class="widefat striped pigcache-redis-info"><tbody>';
		foreach ( $priority_keys as $pk ) {
			if ( isset( $flat[ $pk ] ) ) {
				echo '<tr><th>' . esc_html( $pk ) . '</th><td>' . esc_html( (string) $flat[ $pk ] ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';

		$dbsize = self::redis_dbsize( $redis );
		if ( null !== $dbsize ) {
			echo '<p><strong>' . esc_html__( 'Keys in this logical database (DBSIZE)', 'pigcache' ) . ':</strong> ' . esc_html( (string) $dbsize ) . '</p>';
		}

		$ks = self::keyspace_line( $flat );
		if ( $ks !== '' ) {
			echo '<p><strong>' . esc_html__( 'Keyspace', 'pigcache' ) . ':</strong> ' . esc_html( $ks ) . '</p>';
		}
	}

	/**
	 * @param mixed $redis
	 * @return array<string, string>
	 */
	private static function redis_info_flat( $redis ) {
		try {
			if ( $redis instanceof \Redis ) {
				$raw = $redis->info();
			} elseif ( $redis instanceof \Predis\ClientInterface ) {
				$raw = $redis->info();
			} else {
				return array();
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $k => $v ) {
			if ( is_array( $v ) ) {
				foreach ( $v as $k2 => $v2 ) {
					$out[ $k . '.' . $k2 ] = is_scalar( $v2 ) ? (string) $v2 : wp_json_encode( $v2 );
				}
			} else {
				$out[ (string) $k ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
			}
		}

		return $out;
	}

	/**
	 * @param array<string, string> $flat
	 */
	private static function keyspace_line( array $flat ) {
		$parts = array();
		foreach ( $flat as $k => $v ) {
			if ( strpos( $k, 'Keyspace.db' ) === 0 || preg_match( '/^db\d+$/', $k ) ) {
				$parts[] = $k . '=' . $v;
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * @param mixed $redis
	 * @return int|null
	 */
	private static function redis_dbsize( $redis ) {
		try {
			if ( $redis instanceof \Redis ) {
				return (int) $redis->dbSize();
			}
			if ( $redis instanceof \Predis\ClientInterface ) {
				return (int) $redis->dbsize();
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * @param mixed $redis
	 */
	private static function is_cluster( $redis ) {
		if ( $redis instanceof \RedisCluster ) {
			return true;
		}

		if ( $redis instanceof \Predis\ClientInterface && class_exists( '\Predis\Connection\Cluster\ClusterInterface' ) && method_exists( $redis, 'getConnection' ) ) {
			return $redis->getConnection() instanceof \Predis\Connection\Cluster\ClusterInterface;
		}

		return false;
	}

	/**
	 * @param mixed $t
	 * @return string
	 */
	private static function redis_type_label( $t ) {
		if ( is_string( $t ) && $t !== '' ) {
			return $t;
		}

		$n   = (int) $t;
		$map = array(
			0 => 'none',
			1 => 'string',
			2 => 'set',
			3 => 'list',
			4 => 'zset',
			5 => 'hash',
			6 => 'stream',
		);

		return isset( $map[ $n ] ) ? $map[ $n ] : (string) $t;
	}

	/**
	 * @param object $wp_object_cache
	 * @param mixed  $redis
	 */
	public static function render_keys_table( $wp_object_cache, $redis ) {
		echo '<h2>' . esc_html__( 'Cached keys', 'pigcache' ) . '</h2>';

		if ( self::is_cluster( $redis ) ) {
			echo '<p>' . esc_html__( 'Key scan is disabled in cluster mode.', 'pigcache' ) . '</p>';
			return;
		}

		$pattern = self::scan_pattern( $wp_object_cache );
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$data = get_transient( self::TRANSIENT );
		if ( ! is_array( $data ) || empty( $data['keys'] ) || ( isset( $data['pattern'] ) && $data['pattern'] !== $pattern ) ) {
			$keys = self::scan_keys( $redis, $pattern, self::MAX_INDEX );
			$data = array(
				'pattern'   => $pattern,
				'keys'      => $keys,
				'truncated' => count( $keys ) >= self::MAX_INDEX,
				'built_at'  => time(),
			);
			set_transient( self::TRANSIENT, $data, self::TRANSIENT_TTL );
		}

		$keys      = $data['keys'];
		$total     = count( $keys );
		$truncated = ! empty( $data['truncated'] );
		$pages     = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged     = min( $paged, $pages );

		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$slice  = array_slice( $keys, $offset, self::PER_PAGE );

		$refresh_url = wp_nonce_url(
			add_query_arg( 'pigcache_refresh_keys', '1', admin_url( 'options-general.php?page=pigcache' ) ),
			'pigcache_refresh_keys'
		);

		echo '<p><strong>' . esc_html__( 'SCAN pattern', 'pigcache' ) . ':</strong> <code>' . esc_html( $pattern ) . '</code> · ';
		echo '<a href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Refresh key list', 'pigcache' ) . '</a>';
		if ( $truncated ) {
			echo ' — <em>' . esc_html__( 'List capped; refine PIGCACHE_REDIS_PREFIX or flush old keys.', 'pigcache' ) . '</em>';
		}
		echo '</p>';

		echo '<p>' . sprintf(
			esc_html__( 'Total keys indexed: %1$s (pages: %2$s)', 'pigcache' ),
			esc_html( (string) $total ),
			esc_html( (string) $pages )
		) . '</p>';

		$meta = self::pipeline_ttl_type( $redis, $slice );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Redis key', 'pigcache' ) . '</th>';
		echo '<th style="width:160px">' . esc_html__( 'TTL / expiry', 'pigcache' ) . '</th>';
		echo '<th style="width:80px">' . esc_html__( 'Type', 'pigcache' ) . '</th>';
		echo '<th>' . esc_html__( 'Likely content', 'pigcache' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $slice as $i => $key ) {
			$ttl  = isset( $meta['ttls'][ $i ] ) ? $meta['ttls'][ $i ] : null;
			$type = isset( $meta['types'][ $i ] ) ? $meta['types'][ $i ] : null;
			$ttl_html = self::format_ttl_table_cell( $ttl );
			$type_s   = ( null === $type || false === $type ) ? '—' : self::redis_type_label( $type );

			echo '<tr>';
			echo '<td><code class="pigcache-key-cell">' . esc_html( $key ) . '</code></td>';
			echo '<td>' . $ttl_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( $type_s ) . '</td>';
			echo '<td>' . esc_html( self::infer_label( $key ) ) . '</td>';
			echo '</tr>';
		}

		if ( empty( $slice ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No keys matched this pattern.', 'pigcache' ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav" style="margin-top:12px"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'base'      => add_query_arg( 'paged', '%#%', admin_url( 'options-general.php?page=pigcache' ) ),
					'format'    => '',
					'prev_text' => __( '&laquo;', 'pigcache' ),
					'next_text' => __( '&raquo;', 'pigcache' ),
					'total'     => $pages,
					'current'   => $paged,
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * @param object $wp_object_cache
	 * @return string
	 */
	private static function scan_pattern( $wp_object_cache ) {
		if ( method_exists( $wp_object_cache, 'fast_build_key' ) ) {
			$sample = $wp_object_cache->fast_build_key( '*', '*' );
			if ( is_string( $sample ) && $sample !== '' ) {
				return $sample;
			}
		}

		if ( defined( 'PIGCACHE_REDIS_PREFIX' ) && PIGCACHE_REDIS_PREFIX !== '' ) {
			return trim( (string) PIGCACHE_REDIS_PREFIX ) . '*';
		}

		return '*';
	}

	/**
	 * @param mixed  $redis
	 * @param string $pattern
	 * @param int    $max_keys
	 * @return string[]
	 */
	private static function scan_keys( $redis, $pattern, $max_keys ) {
		$keys = array();

		try {
			if ( $redis instanceof \Redis ) {
				$iterator = null;
				while ( count( $keys ) < $max_keys ) {
					$batch = $redis->scan( $iterator, $pattern, 250 );
					if ( false === $batch ) {
						if ( (int) $iterator === 0 ) {
							break;
						}
						continue;
					}
					foreach ( $batch as $k ) {
						$keys[] = $k;
						if ( count( $keys ) >= $max_keys ) {
							break 2;
						}
					}
					if ( (int) $iterator === 0 ) {
						break;
					}
				}
			} elseif ( $redis instanceof \Predis\ClientInterface ) {
				$iterator = new \Predis\Collection\Iterator\Keyspace( $redis, $pattern, 250 );
				foreach ( $iterator as $k ) {
					$keys[] = $k;
					if ( count( $keys ) >= $max_keys ) {
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		natcasesort( $keys );

		return array_values( $keys );
	}

	/**
	 * @param mixed $redis
	 * @param array $keys
	 * @return array{ttls: array, types: array}
	 */
	private static function pipeline_ttl_type( $redis, array $keys ) {
		$ttls  = array();
		$types = array();
		$n     = count( $keys );

		if ( 0 === $n ) {
			return array( 'ttls' => $ttls, 'types' => $types );
		}

		try {
			if ( $redis instanceof \Redis ) {
				$redis->multi( \Redis::PIPELINE );
				foreach ( $keys as $k ) {
					$redis->ttl( $k );
				}
				$ttls = $redis->exec();
				$redis->multi( \Redis::PIPELINE );
				foreach ( $keys as $k ) {
					$redis->type( $k );
				}
				$types = $redis->exec();
			} elseif ( $redis instanceof \Predis\ClientInterface ) {
				$ttls = $redis->pipeline(
					static function ( $pipe ) use ( $keys ) {
						foreach ( $keys as $k ) {
							$pipe->ttl( $k );
						}
					}
				);
				$types = $redis->pipeline(
					static function ( $pipe ) use ( $keys ) {
						foreach ( $keys as $k ) {
							$pipe->type( $k );
						}
					}
				);
			}
		} catch ( \Throwable $e ) {
			return array(
				'ttls'  => array_fill( 0, $n, null ),
				'types' => array_fill( 0, $n, null ),
			);
		}

		if ( ! is_array( $ttls ) ) {
			$ttls = array_fill( 0, $n, null );
		}
		if ( ! is_array( $types ) ) {
			$types = array_fill( 0, $n, null );
		}

		return array( 'ttls' => $ttls, 'types' => $types );
	}

	/**
	 * @param mixed $ttl
	 * @return string Safe HTML.
	 */
	private static function format_ttl_table_cell( $ttl ) {
		if ( null === $ttl || false === $ttl ) {
			return esc_html( '—' );
		}

		$t = (int) $ttl;

		if ( -1 === $t ) {
			$label = __( 'No expiry', 'pigcache' );
			$hint  = __( 'Redis will not auto-delete this key. It remains until WordPress invalidates it, you flush object cache, or you delete the key in Redis.', 'pigcache' );

			return '<span title="' . esc_attr( $hint ) . '"><strong>' . esc_html( $label ) . '</strong></span>';
		}

		if ( -2 === $t ) {
			return esc_html( __( 'Key missing', 'pigcache' ) );
		}

		if ( $t < 1 ) {
			return esc_html( (string) $t );
		}

		$human   = self::seconds_to_human_duration( $t );
		$seconds = sprintf(
			__( '%d s in Redis', 'pigcache' ),
			$t
		);

		return '<strong>' . esc_html( $human ) . '</strong><br /><span class="description">' . esc_html( $seconds ) . '</span>';
	}

	/**
	 * @param int $seconds
	 * @return string
	 */
	private static function seconds_to_human_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return sprintf(
				_n( '%d second', '%d seconds', $seconds, 'pigcache' ),
				$seconds
			);
		}

		$parts = array();
		$d     = intdiv( $seconds, DAY_IN_SECONDS );
		if ( $d > 0 ) {
			$parts[] = sprintf(
				_n( '%d day', '%d days', $d, 'pigcache' ),
				$d
			);
			$seconds %= DAY_IN_SECONDS;
		}
		$h = intdiv( $seconds, HOUR_IN_SECONDS );
		if ( $h > 0 ) {
			$parts[] = sprintf(
				_n( '%d hour', '%d hours', $h, 'pigcache' ),
				$h
			);
			$seconds %= HOUR_IN_SECONDS;
		}
		$m = intdiv( $seconds, MINUTE_IN_SECONDS );
		if ( $m > 0 ) {
			$parts[] = sprintf(
				_n( '%d min', '%d mins', $m, 'pigcache' ),
				$m
			);
			$seconds %= MINUTE_IN_SECONDS;
		}
		if ( $seconds > 0 ) {
			$parts[] = sprintf(
				_n( '%d sec', '%d sec', $seconds, 'pigcache' ),
				$seconds
			);
		}

		return implode( ' ', $parts );
	}

	private static function infer_label( $redis_key ) {
		$rules = array(
			'/:posts:(\d+)$/'              => __( 'Post object', 'pigcache' ),
			'/:post_meta:(\d+)/'           => __( 'Post meta', 'pigcache' ),
			'/:post-queries:/'             => __( 'WP_Query cache', 'pigcache' ),
			'/:term-queries:/'             => __( 'Term query cache', 'pigcache' ),
			'/:comment-queries:/'          => __( 'Comment query cache', 'pigcache' ),
			'/:translation_files:/'        => __( 'Translation file cache', 'pigcache' ),
			'/:category_relationships:/'   => __( 'Category term relationships', 'pigcache' ),
			'/:post_tag_relationships:/'   => __( 'Tag term relationships', 'pigcache' ),
			'/:post_format_relationships:/' => __( 'Post format relationships', 'pigcache' ),
			'/:terms:(\d+)/'               => __( 'Term / taxonomy', 'pigcache' ),
			'/:term_meta:(\d+)/'          => __( 'Term meta', 'pigcache' ),
			'/:users:(\d+)/'               => __( 'User', 'pigcache' ),
			'/:user_meta:(\d+)/'           => __( 'User meta', 'pigcache' ),
			'/:comments:(\d+)/'            => __( 'Comment', 'pigcache' ),
			'/:comment_meta:(\d+)/'        => __( 'Comment meta', 'pigcache' ),
			'/:options:/'                   => __( 'Option', 'pigcache' ),
			'/:site-options:/'              => __( 'Site option', 'pigcache' ),
			'/:site-transient:/'            => __( 'Site transient', 'pigcache' ),
			'/:transient:/'                => __( 'Transient', 'pigcache' ),
			'/:pigcache_html/'              => __( 'PigCache HTML', 'pigcache' ),
			'/:pigcache_sql/'              => __( 'PigCache SQL', 'pigcache' ),
			'/:pigcache_fragments/'         => __( 'PigCache fragment', 'pigcache' ),
		);

		foreach ( $rules as $rx => $label ) {
			if ( preg_match( $rx, $redis_key, $m ) ) {
				return isset( $m[1] ) ? $label . ' #' . $m[1] : $label;
			}
		}

		return '—';
	}
}
