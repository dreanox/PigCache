<?php
/**
 * wp-admin: PigCache settings (object cache drop-in, SQL drop-in, site hints).
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Admin {

	/**
	 * Hook admin UI.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_profiler_post' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_license_post' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_learning_post' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_wp_config' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_db_dropin' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_db_dropin_inactive' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_object_cache_foreign' ) );
	}

	/**
	 * Enqueue CSS and JS on PigCache admin pages only.
	 *
	 * @param string $hook_suffix
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_pigcache' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'pigcache-admin',
			PIGCACHE_URL . 'assets/css/pigcache-admin.css',
			array(),
			PIGCACHE_VERSION
		);

		wp_enqueue_style(
			'pigcache-metrics',
			PIGCACHE_URL . 'assets/css/pigcache-metrics.css',
			array( 'pigcache-admin' ),
			PIGCACHE_VERSION
		);

		wp_enqueue_script(
			'pigcache-admin',
			PIGCACHE_URL . 'assets/js/pigcache-admin.js',
			array(),
			PIGCACHE_VERSION,
			true
		);
	}

	/**
	 * Form actions: object-cache drop-in and db.php drop-in.
	 */
	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['pigcache_save_ttl'] ) ) {
			check_admin_referer( 'pigcache_cache_ttl' );

			$sql  = isset( $_POST['pigcache_sql_ttl'] ) ? (int) wp_unslash( $_POST['pigcache_sql_ttl'] ) : PigCache_Config::DEFAULT_SQL_TTL;
			$html = isset( $_POST['pigcache_html_ttl'] ) ? (int) wp_unslash( $_POST['pigcache_html_ttl'] ) : PigCache_Config::DEFAULT_HTML_TTL;

			PigCache_Config::set_cache_ttls( $sql, $html );

			$url = add_query_arg( 'pigcache_ttl_saved', '1', admin_url( 'options-general.php?page=pigcache' ) );
			wp_safe_redirect( $url );
			exit;
		}

		if ( isset( $_POST['pigcache_save_extra_groups'] ) ) {
			check_admin_referer( 'pigcache_extra_groups' );

			$raw = isset( $_POST['pigcache_extra_non_persistent'] ) ? wp_unslash( $_POST['pigcache_extra_non_persistent'] ) : '';
			$raw = is_string( $raw ) ? $raw : '';
			PigCache_Config::set_extra_non_persistent_groups_from_text( $raw );

			$url = add_query_arg( 'pigcache_groups_saved', '1', admin_url( 'options-general.php?page=pigcache' ) );
			wp_safe_redirect( $url );
			exit;
		}

		if ( isset( $_POST['pigcache_remove_dropins'] ) ) {
			check_admin_referer( 'pigcache_remove_dropins' );

			$scope = sanitize_key( wp_unslash( $_POST['pigcache_remove_dropins'] ) );
			$url   = admin_url( 'options-general.php?page=pigcache' );

			if ( 'all' === $scope ) {
				$result = self::remove_dropins_all();
			} elseif ( 'object_cache' === $scope ) {
				$result = self::remove_dropin_object_cache_only();
			} elseif ( 'db' === $scope ) {
				$result = self::remove_dropin_db_only();
			} elseif ( 'html_cache' === $scope ) {
				$result = self::remove_dropin_html_cache_only();
			} else {
				wp_safe_redirect( $url );
				exit;
			}

			if ( ! empty( $result['errors'] ) ) {
				$url = add_query_arg( 'pigcache_err', rawurlencode( implode( ' ', $result['errors'] ) ), $url );
			}

			if ( ! empty( $result['removed_labels'] ) ) {
				$url = add_query_arg( 'pigcache_dropins_removed', rawurlencode( implode( ',', $result['removed_labels'] ) ), $url );
			} elseif ( empty( $result['errors'] ) ) {
				$url = add_query_arg( 'pigcache_dropins_none', '1', $url );
			}

			wp_safe_redirect( $url );
			exit;
		}

		if ( isset( $_POST['pigcache_oc_action'] ) ) {
			check_admin_referer( 'pigcache_object_cache' );

			$action = sanitize_key( wp_unslash( $_POST['pigcache_oc_action'] ) );
			if ( 'enable' === $action ) {
				$result = PigCache_Dropin_Object_Cache::install();
			} elseif ( 'disable' === $action ) {
				$result = PigCache_Dropin_Object_Cache::remove();
			} elseif ( 'update' === $action ) {
				$result = PigCache_Dropin_Object_Cache::update_dropin();
			} elseif ( 'flush' === $action ) {
				$result = wp_cache_flush() ? true : new WP_Error( 'pigcache_flush', __( 'Object cache flush failed.', 'pigcache' ) );
			} else {
				return;
			}

			$url = admin_url( 'options-general.php?page=pigcache' );
			if ( is_wp_error( $result ) ) {
				$url = add_query_arg( 'pigcache_err', rawurlencode( $result->get_error_message() ), $url );
			} else {
				$url = add_query_arg( 'pigcache_oc', '1', $url );
			}

			wp_safe_redirect( $url );
			exit;
		}

		if ( isset( $_POST['pigcache_html_action'] ) ) {
			check_admin_referer( 'pigcache_html_dropin' );

			$action = sanitize_key( wp_unslash( $_POST['pigcache_html_action'] ) );
			if ( 'install' === $action ) {
				$result = PigCache_Dropin_Html_Cache::install();
			} elseif ( 'remove' === $action ) {
				$result = PigCache_Dropin_Html_Cache::remove();
			} else {
				return;
			}

			$url = admin_url( 'options-general.php?page=pigcache' );
			if ( is_wp_error( $result ) ) {
				$url = add_query_arg( 'pigcache_err', rawurlencode( $result->get_error_message() ), $url );
			} else {
				$url = add_query_arg( 'pigcache_html', '1', $url );
			}

			wp_safe_redirect( $url );
			exit;
		}

		if ( ! isset( $_POST['pigcache_db_action'] ) ) {
			return;
		}

		check_admin_referer( 'pigcache_db_dropin' );

		$action = sanitize_key( wp_unslash( $_POST['pigcache_db_action'] ) );
		if ( 'install' === $action ) {
			$result = PigCache_Dropin_DB::install();
		} elseif ( 'remove' === $action ) {
			$result = PigCache_Dropin_DB::remove();
		} else {
			return;
		}

		$url = admin_url( 'options-general.php?page=pigcache' );
		if ( is_wp_error( $result ) ) {
			$url = add_query_arg( 'pigcache_err', rawurlencode( $result->get_error_message() ), $url );
		} else {
			$url = add_query_arg( 'pigcache_db', '1', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle SQL Profiler form actions.
	 */
	public static function handle_profiler_post() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['pigcache_profiler_action'] ) ) {
			return;
		}

		check_admin_referer( 'pigcache_sql_profiler' );

		if ( ! class_exists( 'PigCache_Sql_Profiler', false ) || ! PigCache_License::can_use_profiler() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['pigcache_profiler_action'] ) );
		$url    = admin_url( 'options-general.php?page=pigcache' );

		if ( 'start_learning' === $action ) {
			$days = isset( $_POST['pigcache_learn_days'] ) ? (int) $_POST['pigcache_learn_days'] : 7;
			PigCache_Sql_Profiler::start_learning( max( 1, min( 30, $days ) ) );
			$url = add_query_arg( 'pigcache_profiler', 'learning', $url );
		} elseif ( 'stop_learning' === $action ) {
			PigCache_Sql_Profiler::stop_learning();
			$url = add_query_arg( 'pigcache_profiler', 'stopped', $url );
		} elseif ( 'compile' === $action ) {
			$result = PigCache_Sql_Profiler::compile();
			if ( is_wp_error( $result ) ) {
				$url = add_query_arg( 'pigcache_err', rawurlencode( $result->get_error_message() ), $url );
			} else {
				$url = add_query_arg( 'pigcache_profiler', 'compiled', $url );
			}
		} elseif ( 'delete_profile' === $action ) {
			PigCache_Sql_Profiler::delete_profile();
			$url = add_query_arg( 'pigcache_profiler', 'deleted', $url );
		} elseif ( 'relearn' === $action ) {
			$days = isset( $_POST['pigcache_learn_days'] ) ? (int) $_POST['pigcache_learn_days'] : 7;
			PigCache_Sql_Profiler::trigger_relearn( max( 1, min( 30, $days ) ) );
			$url = add_query_arg( 'pigcache_profiler', 'relearning', $url );
		} elseif ( 'save_auto_relearn' === $action ) {
			$enabled = ! empty( $_POST['pigcache_auto_relearn'] );
			update_option( PigCache_Sql_Profiler::OPTION_AUTO_RELEARN, $enabled, false );
			$url = add_query_arg( 'pigcache_profiler', $enabled ? 'auto_relearn_on' : 'auto_relearn_off', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle License & Cloud form actions.
	 */
	public static function handle_license_post() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['pigcache_license_action'] ) ) {
			return;
		}

		check_admin_referer( 'pigcache_license' );

		$action = sanitize_key( wp_unslash( $_POST['pigcache_license_action'] ) );
		$url    = admin_url( 'options-general.php?page=pigcache' );

		if ( 'activate' === $action ) {
			$key    = isset( $_POST['pigcache_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pigcache_api_key'] ) ) : '';
			$result = PigCache_License::activate( $key );

			if ( is_wp_error( $result ) ) {
				$url = add_query_arg( 'pigcache_err', rawurlencode( $result->get_error_message() ), $url );
			} elseif ( ! empty( $result['valid'] ) ) {
				$url = add_query_arg( 'pigcache_license', 'activated', $url );
				if ( class_exists( 'PigCache_Cloud_Sync', false ) && PigCache_Cloud_Sync::is_enabled() ) {
					PigCache_Cloud_Sync::schedule();
				}
			} else {
				$msg = isset( $result['message'] ) ? $result['message'] : __( 'Invalid API key.', 'pigcache' );
				$url = add_query_arg( 'pigcache_err', rawurlencode( $msg ), $url );
			}
		} elseif ( 'deactivate' === $action ) {
			PigCache_License::deactivate();
			if ( class_exists( 'PigCache_Cloud_Sync', false ) ) {
				PigCache_Cloud_Sync::unschedule();
			}
			$url = add_query_arg( 'pigcache_license', 'deactivated', $url );
		} elseif ( 'sync_now' === $action ) {
			if ( class_exists( 'PigCache_Cloud_Sync', false ) ) {
				PigCache_Cloud_Sync::force_sync();
				$url = add_query_arg( 'pigcache_license', 'synced', $url );
			} else {
				$url = add_query_arg( 'pigcache_err', rawurlencode( __( 'Cloud sync is not available in this build.', 'pigcache' ) ), $url );
			}
		} elseif ( 'refresh_status' === $action ) {
			PigCache_License::flush_cache();
			$url = add_query_arg( 'pigcache_license', 'refreshed', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle Continuous Learning toggle from the admin UI.
	 */
	public static function handle_learning_post() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['pigcache_learning_action'] ) ) {
			return;
		}

		check_admin_referer( 'pigcache_learning' );

		if ( ! class_exists( 'PigCache_Cloud_Client', false ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['pigcache_learning_action'] ) );
		$url    = admin_url( 'options-general.php?page=pigcache' );

		if ( 'enable' === $action || 'disable' === $action ) {
			$enabled  = 'enable' === $action;
			$payload  = array( 'learning_enabled' => $enabled );

			if ( isset( $_POST['pigcache_sample_rate'] ) ) {
				$rate = (float) $_POST['pigcache_sample_rate'] / 100;
				$payload['sample_rate'] = max( 0.001, min( 1.0, $rate ) );
			}

			$result = PigCache_Cloud_Client::patch( 'learning-config', $payload );

			if ( is_wp_error( $result ) ) {
				$url = add_query_arg( 'pigcache_err', rawurlencode( $result->get_error_message() ), $url );
			} else {
				PigCache_Continuous_Learner::flush_config_cache();
				$url = add_query_arg( 'pigcache_learning', $enabled ? 'enabled' : 'disabled', $url );
			}
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the Continuous Query Learning section (Pro only).
	 */
	public static function render_continuous_learning_section() {
		global $wpdb;

		echo '<h2>' . esc_html__( 'Continuous Query Learning', 'pigcache' );
		echo ' <span class="pigcache-pro-badge">PRO</span>';
		echo '</h2>';

		// ── Flash notices ────────────────────────────────────────────────
		if ( isset( $_GET['pigcache_learning'] ) ) {
			$lmsg = sanitize_key( $_GET['pigcache_learning'] );
			$lmsgs = array(
				'enabled'  => __( 'Continuous learning enabled and synced to the API.', 'pigcache' ),
				'disabled' => __( 'Continuous learning disabled and synced to the API.', 'pigcache' ),
			);
			if ( isset( $lmsgs[ $lmsg ] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( $lmsgs[ $lmsg ] ) . '</p></div>';
			}
		}

		// ── Remote config status ─────────────────────────────────────────
		$cfg          = PigCache_Continuous_Learner::get_remote_config();
		$enabled      = ! empty( $cfg['learning_enabled'] );
		$sample_rate  = (float) ( $cfg['sample_rate'] ?? 0.10 );
		$adaptive_ttl = ! empty( $cfg['adaptive_ttl'] );
		$const_override = defined( 'PIGCACHE_CONTINUOUS_LEARNING' );

		// Constant overrides
		if ( $const_override ) {
			$enabled = (bool) PIGCACHE_CONTINUOUS_LEARNING;
		}
		if ( defined( 'PIGCACHE_LEARNING_SAMPLE_RATE' ) ) {
			$sample_rate = (float) PIGCACHE_LEARNING_SAMPLE_RATE;
		}
		if ( defined( 'PIGCACHE_ADAPTIVE_TTL' ) ) {
			$adaptive_ttl = (bool) PIGCACHE_ADAPTIVE_TTL;
		}

		$apcu_on    = PigCache_Query_Buffer::apcu_enabled();
		$has_client = class_exists( 'PigCache_Cloud_Client', false );

		echo '<table class="widefat striped pigcache-table"><tbody>';

		echo '<tr><th>' . esc_html__( 'Learning', 'pigcache' ) . '</th><td>';
		echo $enabled
			? '<span style="color:green">&#10003; ' . esc_html__( 'Enabled', 'pigcache' ) . '</span>'
			: '<span style="color:#b32d2e">&#10007; ' . esc_html__( 'Disabled', 'pigcache' ) . '</span>';
		if ( $const_override ) {
			echo ' <span class="description">(' . esc_html__( 'constant override — remove PIGCACHE_CONTINUOUS_LEARNING to use API config', 'pigcache' ) . ')</span>';
		} else {
			echo ' <span class="description">(' . esc_html__( 'from API config', 'pigcache' ) . ')</span>';
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Sample rate', 'pigcache' ) . '</th><td>';
		echo esc_html( round( $sample_rate * 100 ) . '%' );
		if ( defined( 'PIGCACHE_LEARNING_SAMPLE_RATE' ) ) {
			echo ' <span class="description">(' . esc_html__( 'constant override', 'pigcache' ) . ')</span>';
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Adaptive TTL', 'pigcache' ) . '</th><td>';
		echo $adaptive_ttl
			? '<span style="color:green">&#10003; ' . esc_html__( 'On', 'pigcache' ) . '</span>'
			: esc_html__( 'Off', 'pigcache' );
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Buffer backend', 'pigcache' ) . '</th><td>';
		if ( $apcu_on ) {
			echo '<strong>APCu</strong>';
		} elseif ( PigCache_Query_Buffer::redis_available() ) {
			echo '<strong>Redis</strong>';
		} else {
			echo '<span style="color:#b32d2e">' . esc_html__( 'None — stats not collected', 'pigcache' ) . '</span>';
		}
		echo '</td></tr>';

		// ── MySQL stats ──────────────────────────────────────────────────
		$stats_table = $wpdb->prefix . 'pigcache_query_stats';
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
			DB_NAME,
			$stats_table
		) );

		if ( $table_exists ) {
			$total_rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$stats_table}" ); // phpcs:ignore
			$unsent_rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$stats_table} WHERE sent_at IS NULL" ); // phpcs:ignore
			echo '<tr><th>' . esc_html__( 'Stat rows (MySQL)', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $total_rows ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Pending API sync', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $unsent_rows ) ) . '</td></tr>';
		}

		// ── Cron last-run timestamps ─────────────────────────────────────
		// Bypass object cache: standalone cron writes directly to MySQL, so a persistent
		// cache (Redis/APCu) may hold stale values until explicitly invalidated.
		wp_cache_delete( PigCache_Continuous_Learner::OPT_FLUSH_LAST, 'options' );
		wp_cache_delete( PigCache_Continuous_Learner::OPT_SEND_LAST,  'options' );
		$flush_ts = (int) get_option( PigCache_Continuous_Learner::OPT_FLUSH_LAST, 0 );
		$send_ts  = (int) get_option( PigCache_Continuous_Learner::OPT_SEND_LAST,  0 );

		echo '<tr><th>' . esc_html__( 'Last buffer flush', 'pigcache' ) . '</th><td>';
		echo $flush_ts
			? esc_html( human_time_diff( $flush_ts ) . ' ' . __( 'ago', 'pigcache' ) )
			: '<span style="color:#b32d2e">' . esc_html__( 'Never — cron has not run yet', 'pigcache' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Last API sync', 'pigcache' ) . '</th><td>';
		echo $send_ts
			? esc_html( human_time_diff( $send_ts ) . ' ' . __( 'ago', 'pigcache' ) )
			: '<span style="color:#646970">' . esc_html__( 'Never', 'pigcache' ) . '</span>';
		echo '</td></tr>';

		echo '</tbody></table>';

		// ── Toggle form ──────────────────────────────────────────────────
		if ( $has_client && ! $const_override ) {
			echo '<form method="post" style="margin:12px 0">';
			wp_nonce_field( 'pigcache_learning' );

			if ( $enabled ) {
				echo '<button type="submit" name="pigcache_learning_action" value="disable" class="button pigcache-confirm"'
					. ' data-confirm="' . esc_attr__( 'Disable continuous learning? This will sync to the API.', 'pigcache' ) . '">';
				echo esc_html__( 'Disable Learning', 'pigcache' );
				echo '</button>';
			} else {
				$rate_pct = (int) round( $sample_rate * 100 );
				echo '<label for="pigcache_sample_rate" style="margin-right:6px">' . esc_html__( 'Sample rate:', 'pigcache' ) . '</label>';
				echo '<input type="number" id="pigcache_sample_rate" name="pigcache_sample_rate" value="' . esc_attr( (string) $rate_pct ) . '" min="1" max="100" step="1" style="width:70px;margin-right:10px"> %';
				echo '&nbsp;&nbsp;';
				echo '<button type="submit" name="pigcache_learning_action" value="enable" class="button button-primary">';
				echo esc_html__( 'Enable Learning', 'pigcache' );
				echo '</button>';
			}

			echo '</form>';
		} elseif ( $const_override ) {
			echo '<p class="description">' . esc_html__( 'The PIGCACHE_CONTINUOUS_LEARNING constant is set — remove it from wp-config.php to control learning from here.', 'pigcache' ) . '</p>';
		}

		// ── Top slow queries ─────────────────────────────────────────────
		if ( $table_exists ) {
			$slow = PigCache_Query_Stats::get_top_slow( 10 );
			if ( ! empty( $slow ) ) {
				echo '<details class="pigcache-dashboard-details" open><summary><strong>';
				echo esc_html__( 'Top 10 slowest queries (avg ms)', 'pigcache' );
				echo '</strong></summary>';
				echo '<table class="widefat striped"><thead><tr>';
				echo '<th>' . esc_html__( 'Avg ms', 'pigcache' ) . '</th>';
				echo '<th>' . esc_html__( 'Peak ms', 'pigcache' ) . '</th>';
				echo '<th>' . esc_html__( 'Hits', 'pigcache' ) . '</th>';
				echo '<th>' . esc_html__( 'Tables', 'pigcache' ) . '</th>';
				echo '<th>' . esc_html__( 'Query (normalized)', 'pigcache' ) . '</th>';
				echo '</tr></thead><tbody>';
				foreach ( $slow as $row ) {
					$tables = is_string( $row['tables'] ) ? json_decode( $row['tables'], true ) : array();
					echo '<tr>';
					echo '<td><strong>' . esc_html( $row['avg_ms'] ) . '</strong></td>';
					echo '<td>' . esc_html( $row['peak_ms'] ) . '</td>';
					echo '<td>' . esc_html( number_format( (int) $row['total_hits'] ) ) . '</td>';
					echo '<td><code>' . esc_html( implode( ', ', (array) $tables ) ) . '</code></td>';
					echo '<td><code style="font-size:11px;white-space:pre-wrap;word-break:break-all">'
						. esc_html( mb_substr( $row['normalized'], 0, 200 ) )
						. ( strlen( $row['normalized'] ) > 200 ? '…' : '' )
						. '</code></td>';
					echo '</tr>';
				}
				echo '</tbody></table></details>';
			}

			$frequent = PigCache_Query_Stats::get_top_frequent( 10 );
			if ( ! empty( $frequent ) ) {
				echo '<details class="pigcache-dashboard-details"><summary><strong>';
				echo esc_html__( 'Top 10 most frequent queries', 'pigcache' );
				echo '</strong></summary>';
				echo '<table class="widefat striped"><thead><tr>';
				echo '<th>' . esc_html__( 'Hits', 'pigcache' ) . '</th>';
				echo '<th>' . esc_html__( 'Avg ms', 'pigcache' ) . '</th>';
				echo '<th>' . esc_html__( 'Tables', 'pigcache' ) . '</th>';
				echo '<th>' . esc_html__( 'Query (normalized)', 'pigcache' ) . '</th>';
				echo '</tr></thead><tbody>';
				foreach ( $frequent as $row ) {
					$tables = is_string( $row['tables'] ) ? json_decode( $row['tables'], true ) : array();
					echo '<tr>';
					echo '<td><strong>' . esc_html( number_format( (int) $row['total_hits'] ) ) . '</strong></td>';
					echo '<td>' . esc_html( $row['avg_ms'] ) . '</td>';
					echo '<td><code>' . esc_html( implode( ', ', (array) $tables ) ) . '</code></td>';
					echo '<td><code style="font-size:11px;white-space:pre-wrap;word-break:break-all">'
						. esc_html( mb_substr( $row['normalized'], 0, 200 ) )
						. ( strlen( $row['normalized'] ) > 200 ? '…' : '' )
						. '</code></td>';
					echo '</tr>';
				}
				echo '</tbody></table></details>';
			}
		}

		// ── Cron health banner ───────────────────────────────────────────
		$cron_confirmed  = PigCache_Continuous_Learner::is_cron_confirmed();
		$using_wp_cron   = PigCache_Continuous_Learner::using_wp_cron_fallback();
		$flush_last      = (int) get_option( PigCache_Continuous_Learner::OPT_FLUSH_LAST, 0 );

		if ( $cron_confirmed ) {
			echo '<div class="notice notice-success inline" style="margin:12px 0">';
			echo '<p>&#10003; <strong>' . esc_html__( 'Cron pipeline active.', 'pigcache' ) . '</strong> ';
			echo esc_html( sprintf(
				__( 'Last buffer flush: %s ago.', 'pigcache' ),
				human_time_diff( $flush_last )
			) );
			if ( $using_wp_cron ) {
				echo ' <span style="color:#b36b00">' . esc_html__( '(WP-Cron fallback — PIGCACHE_USE_WP_CRON is set)', 'pigcache' ) . '</span>';
			}
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-warning inline" style="margin:12px 0;border-left-color:#d63638">';
			echo '<p>';
			echo '<strong style="color:#d63638">&#9888; ' . esc_html__( 'System not fully active.', 'pigcache' ) . '</strong> ';
			echo esc_html__( 'The query analytics pipeline requires a real server cron job. Until one is configured and confirmed, no stats will be collected or sent.', 'pigcache' );
			echo '</p></div>';
		}

		// ── Cron setup instructions ──────────────────────────────────────
		$cron_script = PIGCACHE_DIR . 'bin/pigcache-cron.php';

		if ( $cron_confirmed ) {
			$details_class = 'pigcache-cron-details pigcache-cron-details--ok';
			$summary_html  = '&#10003; ' . esc_html__( 'Server cron active', 'pigcache' )
				. ' <span class="pigcache-cron-details__hint">'
				. esc_html__( 'View setup instructions', 'pigcache' )
				. '</span>';
		} else {
			$details_class = 'pigcache-cron-details pigcache-cron-details--required';
			$summary_html  = '&#9888; ' . esc_html__( 'Server cron not detected — setup instructions below', 'pigcache' );
		}

		echo '<details class="' . esc_attr( $details_class ) . '"' . ( $cron_confirmed ? '' : ' open' ) . '>';
		echo '<summary>' . $summary_html . '</summary>'; // phpcs:ignore
		echo '<div class="pigcache-cron-body">';

		echo '<h4 style="margin:0 0 6px">' . esc_html__( 'Why a real cron job — not WP-Cron', 'pigcache' ) . '</h4>';
		echo '<table class="widefat" style="max-width:640px;margin-bottom:14px"><thead><tr>';
		echo '<th></th><th>' . esc_html__( 'Server cron', 'pigcache' ) . '</th><th>' . esc_html__( 'WP-Cron', 'pigcache' ) . '</th>';
		echo '</tr></thead><tbody>';
		$rows_why = array(
			array( __( 'Timing', 'pigcache' ),        __( 'Exact (every 15 min)', 'pigcache' ),          __( 'On next page load after interval', 'pigcache' ) ),
			array( __( 'High-traffic sites', 'pigcache' ), __( 'Safe — own process', 'pigcache' ),        __( 'Runs inside a visitor request, adds latency', 'pigcache' ) ),
			array( __( 'Low-traffic sites', 'pigcache' ),  __( 'Always fires', 'pigcache' ),              __( 'May not fire for hours if no visits', 'pigcache' ) ),
			array( __( 'Analytics accuracy', 'pigcache' ), __( 'Consistent 15-min windows', 'pigcache' ), __( 'Irregular windows, data gaps possible', 'pigcache' ) ),
		);
		foreach ( $rows_why as $r ) {
			echo '<tr><th style="width:30%">' . esc_html( $r[0] ) . '</th>';
			echo '<td style="color:green">&#10003; ' . esc_html( $r[1] ) . '</td>';
			echo '<td style="color:#b32d2e">&#10007; ' . esc_html( $r[2] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$php_bin = PHP_BINARY;
		$php_version_label = '';
		if ( $php_bin && $php_bin !== 'php' ) {
			$php_version_label = ' <span class="description">(' . esc_html( 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' — detected from this server' ) . ')</span>';
		} else {
			$php_bin = '/usr/local/bin/php';
		}

		$log_path = rtrim( PIGCACHE_DIR, '/\\' ) . '/logs/pigcache-cron.log';

		echo '<p><strong>' . esc_html__( 'Add this job in cPanel → Cron Jobs (every 15 minutes):', 'pigcache' ) . '</strong>';
		echo $php_version_label . '</p>';
		echo '<pre class="pigcache-code-block">*/15 * * * *  ' . esc_html( $php_bin ) . ' -q ' . esc_html( $cron_script ) . ' &gt; /dev/null 2&gt;&gt; ' . esc_html( $log_path ) . '</pre>';
		echo '<p class="description">';
		echo esc_html__( 'stdout (normal output) is discarded. stderr (errors only) is appended to the log file above — it stays empty on healthy runs.', 'pigcache' );
		if ( ! $php_bin || $php_bin === '/usr/local/bin/php' ) {
			echo ' ' . esc_html__( 'If the PHP path does not work, check the correct one under cPanel → MultiPHP Manager.', 'pigcache' );
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'To check for errors:', 'pigcache' ) . ' <code>tail -50 ' . esc_html( $log_path ) . '</code></p>';

		echo '<hr style="border:none;border-top:1px solid #e0e0e0;margin:14px 0">';
		echo '<p class="description">';
		echo '<strong>' . esc_html__( 'WP-Cron fallback (not recommended):', 'pigcache' ) . '</strong> ';
		echo esc_html__( 'If you have no server access to add a cron job, define this constant to fall back to WP-Cron. Stats will be collected but with less timing precision — WP-Cron fires on the next page load after the interval, not at an exact time, and adds a small delay to that visitor request.', 'pigcache' );
		echo '</p>';
		echo '<pre class="pigcache-code-block">define( \'PIGCACHE_USE_WP_CRON\', true );</pre>';

		echo '</div></details>';
	}

	/**
	 * Render the License & Cloud section on the admin page.
	 */
	public static function render_license_section() {
		$has_key   = PigCache_License::has_key();
		$is_pro    = PigCache_License::is_pro();
		$plan      = PigCache_License::get_plan();
		$site_id   = PigCache_License::get_site_id();
		$key_hint  = $has_key ? substr( PigCache_License::get_key(), 0, 8 ) . '...' : '';
		$full_pkg  = PigCache_License::has_pro_distribution();

		if ( ! $full_pkg ) {
			echo '<h2>';
			echo esc_html__( 'PigCache Pro (full package)', 'pigcache' );
			echo ' <span class="pigcache-pro-badge" style="background:#646970">' . esc_html__( 'Community build', 'pigcache' ) . '</span>';
			echo '</h2>';
			echo '<div class="notice notice-info inline"><p>';
			echo esc_html__( 'This WordPress.org release does not include the license client, cloud sync, or the SQL profiler. Those ship only in the paid Pro ZIP.', 'pigcache' );
			echo ' ';
			echo esc_html__( 'Entering a key here cannot unlock or download Pro code — replace this plugin with the Pro package from your purchase, then activate your license in that install.', 'pigcache' );
			echo '</p></div>';

			if ( $has_key ) {
				echo '<div class="notice notice-warning inline"><p>';
				echo esc_html__( 'A license key is still stored from a previous Pro install. You can clear it below; it will not enable features in this build.', 'pigcache' );
				echo '</p></div>';
				echo '<table class="widefat striped pigcache-table"><tbody>';
				echo '<tr><th>' . esc_html__( 'API key', 'pigcache' ) . '</th>';
				echo '<td><code>' . esc_html( $key_hint ) . '</code></td></tr>';
				if ( $site_id ) {
					echo '<tr><th>' . esc_html__( 'Site ID', 'pigcache' ) . '</th>';
					echo '<td><code>' . esc_html( $site_id ) . '</code></td></tr>';
				}
				echo '</tbody></table>';
				echo '<form method="post" class="pigcache-form">';
				wp_nonce_field( 'pigcache_license' );
				echo '<p><button type="submit" name="pigcache_license_action" value="deactivate" class="button pigcache-confirm" data-confirm="' . esc_attr__( 'Clear the stored license data from this site?', 'pigcache' ) . '">';
				echo esc_html__( 'Clear stored license', 'pigcache' ) . '</button></p>';
				echo '</form>';
			}

			self::render_license_flash_notices();

			return;
		}

		echo '<h2>';
		echo esc_html__( 'PigCache Pro — License & Cloud', 'pigcache' );
		if ( $is_pro ) {
			echo ' <span class="pigcache-pro-badge">PRO</span>';
		}
		echo '</h2>';

		echo '<table class="widefat striped pigcache-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Plan', 'pigcache' ) . '</th>';
		echo '<td><strong>' . esc_html( ucfirst( $plan ) ) . '</strong></td></tr>';

		if ( $has_key ) {
			echo '<tr><th>' . esc_html__( 'API key', 'pigcache' ) . '</th>';
			echo '<td><code>' . esc_html( $key_hint ) . '</code></td></tr>';
		}

		if ( $site_id ) {
			echo '<tr><th>' . esc_html__( 'Site ID', 'pigcache' ) . '</th>';
			echo '<td><code>' . esc_html( $site_id ) . '</code></td></tr>';
		}

		if ( $is_pro && class_exists( 'PigCache_Cloud_Sync', false ) ) {
			$last_sync = PigCache_Cloud_Sync::last_sync();
			$is_cloud  = PigCache_Cloud_Sync::is_cloud_profile();
			$env_hash  = class_exists( 'PigCache_Environment', false ) ? PigCache_Environment::get_signature() : '-';

			echo '<tr><th>' . esc_html__( 'Cloud sync', 'pigcache' ) . '</th>';
			echo '<td>' . ( PigCache_Cloud_Sync::is_enabled()
				? '<span style="color:green">&#10003; ' . esc_html__( 'Enabled', 'pigcache' ) . '</span>'
				: '<span style="color:#b32d2e">&#10007; ' . esc_html__( 'Disabled', 'pigcache' ) . '</span>'
			) . '</td></tr>';

			echo '<tr><th>' . esc_html__( 'Last sync', 'pigcache' ) . '</th>';
			echo '<td>' . ( $last_sync
				? esc_html( gmdate( 'Y-m-d H:i:s', $last_sync ) . ' UTC' )
				: esc_html__( 'Never', 'pigcache' )
			) . '</td></tr>';

			echo '<tr><th>' . esc_html__( 'Profile source', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( $is_cloud ? __( 'Cloud', 'pigcache' ) : __( 'Local', 'pigcache' ) ) . '</td></tr>';

			echo '<tr><th>' . esc_html__( 'Environment hash', 'pigcache' ) . '</th>';
			echo '<td><code>' . esc_html( $env_hash ) . '</code></td></tr>';

			if ( class_exists( 'PigCache_Sql_Profile_Store', false ) ) {
				$unsynced = PigCache_Sql_Profile_Store::count_unsynced();
				echo '<tr><th>' . esc_html__( 'Pending sync', 'pigcache' ) . '</th>';
				echo '<td>' . esc_html( number_format( $unsynced ) . ' ' . __( 'fingerprints', 'pigcache' ) ) . '</td></tr>';
			}
		}

		echo '</tbody></table>';

		echo '<form method="post" class="pigcache-form">';
		wp_nonce_field( 'pigcache_license' );

		if ( ! $has_key ) {
			echo '<p>';
			echo '<label for="pigcache_api_key">' . esc_html__( 'API key:', 'pigcache' ) . ' </label>';
			echo '<input type="text" name="pigcache_api_key" id="pigcache_api_key" value="" class="regular-text" placeholder="' . esc_attr__( '64-character hex key from your account', 'pigcache' ) . '" />';
			echo '</p>';
			echo '<p><button type="submit" name="pigcache_license_action" value="activate" class="button button-primary">';
			echo esc_html__( 'Activate License', 'pigcache' ) . '</button></p>';
		} else {
			echo '<p>';
			if ( $is_pro && class_exists( 'PigCache_Cloud_Sync', false ) ) {
				echo '<button type="submit" name="pigcache_license_action" value="sync_now" class="button button-primary">';
				echo esc_html__( 'Sync Now', 'pigcache' ) . '</button> ';
			}
			echo '<button type="submit" name="pigcache_license_action" value="refresh_status" class="button">';
			echo esc_html__( 'Refresh Status', 'pigcache' ) . '</button> ';
			echo '<button type="submit" name="pigcache_license_action" value="deactivate" class="button pigcache-confirm" data-confirm="' . esc_attr__( 'Deactivate and remove your PigCache Pro license from this site?', 'pigcache' ) . '">';
			echo esc_html__( 'Deactivate License', 'pigcache' ) . '</button>';
			echo '</p>';
		}

		echo '</form>';

		self::render_license_flash_notices();
	}

	/**
	 * Success notices after license actions (shared by Pro UI and edge cases).
	 */
	private static function render_license_flash_notices() {
		if ( ! isset( $_GET['pigcache_license'] ) ) {
			return;
		}

		$msg_key = sanitize_key( $_GET['pigcache_license'] );

		$activated_msg = class_exists( 'PigCache_Cloud_Sync', false )
			? __( 'License activated. Cloud sync is available when enabled.', 'pigcache' )
			: __( 'License activated.', 'pigcache' );

		$messages = array(
			'activated'   => $activated_msg,
			'deactivated' => __( 'License removed from this site.', 'pigcache' ),
			'synced'      => __( 'Cloud sync completed.', 'pigcache' ),
			'refreshed'   => __( 'License status refreshed.', 'pigcache' ),
		);

		if ( isset( $messages[ $msg_key ] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( $messages[ $msg_key ] ) . '</p></div>';
		}
	}

	/**
	 * Render the SQL Profiler section on the admin page.
	 */
	public static function render_sql_profiler_section() {
		$access  = PigCache_License::profiler_access_label();
		$can_use = PigCache_License::can_use_profiler();

		echo '<h2>' . esc_html__( 'SQL Query Profiler', 'pigcache' );
		if ( 'pro' === $access ) {
			echo ' <span class="pigcache-pro-badge">PRO</span>';
		} elseif ( 'trial' === $access ) {
			echo ' <span class="pigcache-pro-badge" style="background:#b36b00">'
				. esc_html( sprintf( __( 'TRIAL — %d days left', 'pigcache' ), PigCache_License::trial_days_remaining() ) )
				. '</span>';
		}
		echo '</h2>';

		if ( ! class_exists( 'PigCache_Sql_Profiler', false ) ) {
			echo '<div class="notice notice-info inline"><p>';
			if ( 'community' === $access ) {
				echo esc_html__( 'Smarter per-table SQL invalidation and the query profiler are only in the full PigCache Pro plugin (the paid ZIP). This WordPress.org build still caches SQL, but uses one global invalidation epoch.', 'pigcache' );
			} else {
				echo esc_html__( 'The SQL Profiler module is missing from this install. Reinstall the complete PigCache Pro package.', 'pigcache' );
			}
			echo '</p></div>';
			return;
		}

		$learning    = PigCache_Sql_Profiler::is_learning();
		$has_profile = PigCache_Sql_Profiler::has_profile();
		$remaining   = PigCache_Sql_Profiler::learning_remaining();

		if ( ! $can_use && ! $has_profile ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo '<strong>' . esc_html__( 'Pro feature', 'pigcache' ) . '</strong> — ';
			if ( 'expired' === $access ) {
				echo esc_html__( 'Your trial has expired. Upgrade to a Pro license to use the SQL Profiler.', 'pigcache' );
			} elseif ( 'free' === $access ) {
				echo esc_html__( 'Your license is active but on the Free plan. Upgrade to Pro to unlock the SQL Profiler.', 'pigcache' );
			} else {
				echo esc_html__( 'Activate a Pro license key from your PigCache account to unlock the SQL Profiler.', 'pigcache' );
			}
			echo '</p></div>';
		}

		if ( $learning ) {
			$status = __( 'Learning', 'pigcache' );
			$color  = '#b36b00';
		} elseif ( $has_profile ) {
			$status = __( 'Compiled (active)', 'pigcache' );
			$color  = 'green';
		} else {
			$status = __( 'Inactive', 'pigcache' );
			$color  = '#b32d2e';
		}

		echo '<table class="widefat striped pigcache-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Status', 'pigcache' ) . '</th><td><span style="color:' . esc_attr( $color ) . '">' . esc_html( $status ) . '</span></td></tr>';

		echo '<tr><th>' . esc_html__( 'Access', 'pigcache' ) . '</th><td>';
		if ( 'pro' === $access ) {
			echo '<span style="color:green">' . esc_html__( 'Pro license active', 'pigcache' ) . '</span>';
		} elseif ( 'trial' === $access ) {
			echo '<span style="color:#b36b00">' . esc_html( sprintf( __( 'Trial — %d day(s) remaining', 'pigcache' ), PigCache_License::trial_days_remaining() ) ) . '</span>';
		} elseif ( 'expired' === $access ) {
			echo '<span style="color:#b32d2e">' . esc_html__( 'Trial expired — upgrade to Pro', 'pigcache' ) . '</span>';
		} else {
			echo '<span style="color:#646970">' . esc_html__( 'Free plan — upgrade to Pro to unlock', 'pigcache' ) . '</span>';
		}
		echo '</td></tr>';

		if ( $learning ) {
			$fp_count   = class_exists( 'PigCache_Sql_Profile_Store', false ) ? PigCache_Sql_Profile_Store::count() : 0;
			$total_hits = class_exists( 'PigCache_Sql_Profile_Store', false ) ? PigCache_Sql_Profile_Store::total_hits() : 0;
			$days_left  = ceil( $remaining / DAY_IN_SECONDS );

			echo '<tr><th>' . esc_html__( 'Time remaining', 'pigcache' ) . '</th><td>';
			echo esc_html( sprintf( __( '%d day(s)', 'pigcache' ), $days_left ) );
			echo '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Unique templates found', 'pigcache' ) . '</th><td>' . esc_html( number_format( $fp_count ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Total queries recorded', 'pigcache' ) . '</th><td>' . esc_html( number_format( $total_hits ) ) . '</td></tr>';
		}

		if ( $has_profile ) {
			$profile = PigCache_Sql_Profiler::get_profile_stats();
			if ( is_array( $profile ) ) {
				echo '<tr><th>' . esc_html__( 'Compiled at', 'pigcache' ) . '</th><td>';
				echo esc_html( gmdate( 'Y-m-d H:i:s', $profile['compiled_at'] ) . ' UTC' );
				echo '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Unique templates', 'pigcache' ) . '</th><td>';
				echo esc_html( number_format( $profile['unique_templates'] ) );
				echo '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Tables tracked', 'pigcache' ) . '</th><td>';
				echo esc_html( implode( ', ', array_keys( $profile['tables'] ) ) );
				echo '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Queries observed', 'pigcache' ) . '</th><td>';
				echo esc_html( number_format( $profile['query_count'] ) );
				echo '</td></tr>';
			}
		}

		// ── Auto re-learn row ────────────────────────────────────────────
		if ( $can_use ) {
			$auto_relearn = PigCache_Sql_Profiler::auto_relearn_enabled();
			echo '<tr><th>' . esc_html__( 'Auto re-learn', 'pigcache' ) . '</th><td>';
			echo $auto_relearn
				? '<span style="color:green">&#10003; ' . esc_html__( 'On — restarts automatically when plugins/themes change', 'pigcache' ) . '</span>'
				: '<span style="color:#646970">' . esc_html__( 'Off — manual restart required after environment changes', 'pigcache' ) . '</span>';
			if ( defined( 'PIGCACHE_SQL_PROFILE_AUTO_RELEARN' ) ) {
				echo ' <span class="description">(' . esc_html__( 'constant override', 'pigcache' ) . ')</span>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		// ── Environment change notice ─────────────────────────────────────
		if ( $can_use ) {
			$last_change = get_option( PigCache_Sql_Profiler::OPTION_ENV_CHANGE );
			if ( is_array( $last_change ) && ! empty( $last_change['time'] ) ) {
				$age     = human_time_diff( (int) $last_change['time'] );
				$detail  = esc_html( (string) ( $last_change['detail'] ?? 'Environment changed' ) );
				$was_auto = ! empty( $last_change['auto_relearned'] );

				if ( $was_auto ) {
					echo '<div class="notice notice-info inline" style="margin:12px 0">';
					echo '<p><strong>' . esc_html__( 'Environment change detected', 'pigcache' ) . '</strong> ';
					echo esc_html( sprintf( __( '%s ago', 'pigcache' ), $age ) ) . ' — ';
					echo $detail . '. ';
					echo esc_html__( 'Learning was automatically restarted.', 'pigcache' );
					echo '</p></div>';
				} elseif ( ! $learning ) {
					echo '<div class="notice notice-warning inline" style="margin:12px 0">';
					echo '<p><strong>' . esc_html__( 'Environment change detected', 'pigcache' ) . '</strong> ';
					echo esc_html( sprintf( __( '%s ago', 'pigcache' ), $age ) ) . ' — ';
					echo $detail . '. ';
					echo esc_html__( 'Consider restarting learning so the SQL profile reflects your current plugin stack.', 'pigcache' );
					echo '</p></div>';
				}
			}
		}

		if ( $can_use ) {
			echo '<form method="post" class="pigcache-form">';
			wp_nonce_field( 'pigcache_sql_profiler' );

			if ( ! $learning && ! $has_profile ) {
				echo '<p>';
				echo '<label for="pigcache_learn_days">' . esc_html__( 'Learning duration:', 'pigcache' ) . ' </label>';
				echo '<input type="number" name="pigcache_learn_days" id="pigcache_learn_days" value="7" min="1" max="30" class="small-text" /> ';
				echo '<span class="description">' . esc_html__( 'days', 'pigcache' ) . '</span>';
				echo '</p>';
				echo '<p><button type="submit" name="pigcache_profiler_action" value="start_learning" class="button button-primary">';
				echo esc_html__( 'Start Learning', 'pigcache' ) . '</button></p>';
			}

			if ( $learning ) {
				echo '<p>';
				echo '<button type="submit" name="pigcache_profiler_action" value="compile" class="button button-primary">';
				echo esc_html__( 'Compile Now', 'pigcache' ) . '</button> ';
				echo '<button type="submit" name="pigcache_profiler_action" value="stop_learning" class="button">';
				echo esc_html__( 'Stop Learning', 'pigcache' ) . '</button>';
				echo '</p>';
			}

			if ( $has_profile && ! $learning ) {
				echo '<p>';
				echo '<label for="pigcache_learn_days_re">' . esc_html__( 'Re-learn duration:', 'pigcache' ) . ' </label>';
				echo '<input type="number" name="pigcache_learn_days" id="pigcache_learn_days_re" value="7" min="1" max="30" class="small-text" /> ';
				echo '<span class="description">' . esc_html__( 'days', 'pigcache' ) . '</span>';
				echo '</p>';
				echo '<p>';
				echo '<button type="submit" name="pigcache_profiler_action" value="relearn" class="button button-primary">';
				echo esc_html__( 'Re-learn', 'pigcache' ) . '</button> ';
				echo '<button type="submit" name="pigcache_profiler_action" value="delete_profile" class="button pigcache-confirm" data-confirm="' . esc_attr__( 'Delete the compiled SQL profile? Per-table invalidation will revert to global epoch.', 'pigcache' ) . '">';
				echo esc_html__( 'Delete Profile', 'pigcache' ) . '</button>';
				echo '</p>';
			}

			// Auto re-learn toggle (only shown when not overridden by constant).
			if ( ! defined( 'PIGCACHE_SQL_PROFILE_AUTO_RELEARN' ) ) {
				$auto_relearn = PigCache_Sql_Profiler::auto_relearn_enabled();
				echo '<hr style="border:none;border-top:1px solid #e0e0e0;margin:14px 0">';
				echo '<p>';
				echo '<label><input type="checkbox" name="pigcache_auto_relearn" value="1"' . checked( $auto_relearn, true, false ) . '> ';
				echo esc_html__( 'Automatically restart learning when a plugin or theme is installed, updated, or removed', 'pigcache' );
				echo '</label>';
				echo '</p>';
				echo '<p><button type="submit" name="pigcache_profiler_action" value="save_auto_relearn" class="button">';
				echo esc_html__( 'Save', 'pigcache' ) . '</button></p>';
			}

			echo '</form>';
		}

		if ( isset( $_GET['pigcache_profiler'] ) ) {
			$msg_key  = sanitize_key( $_GET['pigcache_profiler'] );
			$messages = array(
				'learning'        => __( 'SQL profiler learning started.', 'pigcache' ),
				'stopped'         => __( 'SQL profiler learning stopped.', 'pigcache' ),
				'compiled'        => __( 'SQL profile compiled successfully. Per-table invalidation is now active.', 'pigcache' ),
				'deleted'         => __( 'SQL profile deleted. Reverted to global epoch invalidation.', 'pigcache' ),
				'relearning'      => __( 'SQL profiler re-learning started. Old profile is used as fallback.', 'pigcache' ),
				'auto_relearn_on'  => __( 'Auto re-learn enabled. Learning will restart automatically on environment changes.', 'pigcache' ),
				'auto_relearn_off' => __( 'Auto re-learn disabled. You will be notified but learning will not restart automatically.', 'pigcache' ),
			);
			if ( isset( $messages[ $msg_key ] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( $messages[ $msg_key ] ) . '</p></div>';
			}
		}
	}

	/**
	 * Read-only dashboard showing what is cached and how across all layers.
	 */
	public static function render_cache_dashboard() {
		global $wpdb;

		echo '<h2>' . esc_html__( 'Cache Dashboard', 'pigcache' ) . '</h2>';

		echo '<div class="pigcache-dashboard">';

		// --- HTML Cache card ---
		echo '<div class="pigcache-dashboard-card">';
		echo '<h3>' . esc_html__( 'HTML Cache', 'pigcache' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';

		$tag_inv_active = class_exists( 'PigCache_License', false ) && PigCache_License::can_use_tag_invalidation();

		echo '<tr><th>' . esc_html__( 'Invalidation', 'pigcache' ) . '</th>';
		echo '<td><strong>' . esc_html(
			$tag_inv_active
				? __( 'Tag-based (selective)', 'pigcache' )
				: __( 'Global flush (upgrade to Pro for tag-based)', 'pigcache' )
		) . '</strong></td></tr>';

		$tag_table = $wpdb->prefix . 'pigcache_tags';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s', DB_NAME, $tag_table ) );

		if ( $table_exists ) {
			$html_keys = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT cache_key) FROM {$tag_table} WHERE grp = 'pigcache_html'" );
			$html_tags = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tag_table} WHERE grp = 'pigcache_html'" );
			$unique_tags = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT tag) FROM {$tag_table} WHERE grp = 'pigcache_html'" );

			echo '<tr><th>' . esc_html__( 'Pages in tag index', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $html_keys ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Tag associations', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $html_tags ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Unique tags', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $unique_tags ) ) . '</td></tr>';
		} else {
			echo '<tr><th>' . esc_html__( 'Tag index', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html__( 'Table not created yet. Deactivate and reactivate the plugin.', 'pigcache' ) . '</td></tr>';
		}

		$html_ttl = class_exists( 'PigCache_Config', false ) ? PigCache_Config::get_html_cache_ttl() : 60;
		echo '<tr><th>' . esc_html__( 'TTL', 'pigcache' ) . '</th>';
		echo '<td>' . esc_html( $html_ttl . 's' ) . '</td></tr>';

		echo '</tbody></table>';

		if ( $table_exists ) {
			echo '<details class="pigcache-dashboard-details"><summary>' . esc_html__( 'Top tags by usage', 'pigcache' ) . '</summary>';
			$top_tags = $wpdb->get_results( "SELECT tag, COUNT(*) AS cnt FROM {$tag_table} WHERE grp = 'pigcache_html' GROUP BY tag ORDER BY cnt DESC LIMIT 15" );
			if ( $top_tags ) {
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Tag', 'pigcache' ) . '</th><th>' . esc_html__( 'Pages', 'pigcache' ) . '</th></tr></thead><tbody>';
				foreach ( $top_tags as $row ) {
					echo '<tr><td><code>' . esc_html( $row->tag ) . '</code></td><td>' . esc_html( $row->cnt ) . '</td></tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p>' . esc_html__( 'No tags recorded yet.', 'pigcache' ) . '</p>';
			}
			echo '</details>';
		}

		echo '</div>';

		// --- SQL Cache card ---
		echo '<div class="pigcache-dashboard-card">';
		echo '<h3>' . esc_html__( 'SQL Cache', 'pigcache' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';

		$sql_active = PigCache_Dropin_DB::is_active();
		echo '<tr><th>' . esc_html__( 'db.php active', 'pigcache' ) . '</th>';
		echo '<td>' . ( $sql_active ? '<span style="color:green">&#10003;</span>' : '<span style="color:#b32d2e">&#10007;</span>' ) . '</td></tr>';

		$has_profile = class_exists( 'PigCache_Sql_Profiler', false ) && PigCache_Sql_Profiler::has_profile();
		$is_learning = class_exists( 'PigCache_Sql_Profiler', false ) && PigCache_Sql_Profiler::is_learning();

		if ( $has_profile ) {
			$mode_label = __( 'Per-table epochs (profiler active)', 'pigcache' );
			$mode_color = 'green';
		} elseif ( $is_learning ) {
			$mode_label = __( 'Global epoch (profiler learning)', 'pigcache' );
			$mode_color = '#b36b00';
		} else {
			$mode_label = __( 'Global epoch (default)', 'pigcache' );
			$mode_color = '#666';
		}

		echo '<tr><th>' . esc_html__( 'Invalidation mode', 'pigcache' ) . '</th>';
		echo '<td><span style="color:' . esc_attr( $mode_color ) . '"><strong>' . esc_html( $mode_label ) . '</strong></span></td></tr>';

		if ( class_exists( 'PigCache_Sql_Cache', false ) ) {
			$global_epoch = PigCache_Sql_Cache::get_epoch();
			echo '<tr><th>' . esc_html__( 'Global epoch', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( (string) $global_epoch ) . '</td></tr>';
		}

		if ( $has_profile ) {
			$profile = PigCache_Sql_Profiler::get_profile_stats();
			if ( is_array( $profile ) && isset( $profile['tables'] ) ) {
				$table_names = array_keys( $profile['tables'] );
				$table_epochs = PigCache_Sql_Cache::get_table_epochs( $table_names );

				echo '<tr><th>' . esc_html__( 'Tables tracked', 'pigcache' ) . '</th>';
				echo '<td>' . esc_html( (string) count( $table_names ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Query templates', 'pigcache' ) . '</th>';
				echo '<td>' . esc_html( number_format( $profile['unique_templates'] ) ) . '</td></tr>';
			}
		}

		$sql_ttl = class_exists( 'PigCache_Sql_Cache', false ) ? PigCache_Sql_Cache::ttl() : 120;
		echo '<tr><th>' . esc_html__( 'TTL', 'pigcache' ) . '</th>';
		echo '<td>' . esc_html( $sql_ttl . 's' ) . '</td></tr>';

		echo '</tbody></table>';

		if ( $has_profile && is_array( $profile ) && isset( $profile['tables'] ) && ! empty( $table_epochs ) ) {
			echo '<details class="pigcache-dashboard-details"><summary>' . esc_html__( 'Per-table epochs', 'pigcache' ) . '</summary>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Table', 'pigcache' ) . '</th>';
			echo '<th>' . esc_html__( 'Epoch', 'pigcache' ) . '</th>';
			echo '<th>' . esc_html__( 'Query templates', 'pigcache' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $table_names as $t ) {
				$ep  = isset( $table_epochs[ $t ] ) ? $table_epochs[ $t ] : 1;
				$cnt = isset( $profile['tables'][ $t ] ) ? count( $profile['tables'][ $t ] ) : 0;
				echo '<tr><td><code>' . esc_html( $t ) . '</code></td>';
				echo '<td>' . esc_html( (string) $ep ) . '</td>';
				echo '<td>' . esc_html( (string) $cnt ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '</details>';
		}

		echo '</div>';

		// --- Fragments card ---
		echo '<div class="pigcache-dashboard-card">';
		echo '<h3>' . esc_html__( 'Fragment Cache', 'pigcache' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';

		echo '<tr><th>' . esc_html__( 'Invalidation', 'pigcache' ) . '</th>';
		echo '<td>' . esc_html(
			$tag_inv_active
				? __( 'Tag-based (when tags provided) + TTL', 'pigcache' )
				: __( 'Global flush + TTL (tag-based requires Pro)', 'pigcache' )
		) . '</td></tr>';

		if ( $table_exists ) {
			$frag_keys = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT cache_key) FROM {$tag_table} WHERE grp = 'pigcache_fragments'" );
			$frag_tags = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tag_table} WHERE grp = 'pigcache_fragments'" );

			echo '<tr><th>' . esc_html__( 'Fragments in tag index', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $frag_keys ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Tag associations', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $frag_tags ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		// --- Query Analytics card (Pro) ---
		if ( class_exists( 'PigCache_Query_Stats', false ) ) {
			$stats_table = PigCache_Query_Stats::table_name();
			$qs_exists   = $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$stats_table
			) );

			if ( $qs_exists ) {
				echo '<div class="pigcache-dashboard-card">';
				echo '<h3>' . esc_html__( 'Query Analytics', 'pigcache' ) . '</h3>';

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$summary = $wpdb->get_row( "SELECT
					COUNT(DISTINCT query_hash) AS unique_patterns,
					SUM(hit_count)             AS total_executions,
					ROUND(SUM(total_exec_ms) / NULLIF(SUM(hit_count), 0), 1) AS avg_ms,
					MIN(period_start) AS earliest,
					MAX(period_start) AS latest
				FROM {$stats_table}" );

				echo '<table class="widefat striped"><tbody>';

				if ( $summary && (int) $summary->total_executions > 0 ) {
					echo '<tr><th>' . esc_html__( 'Unique query patterns', 'pigcache' ) . '</th>';
					echo '<td>' . esc_html( number_format( (int) $summary->unique_patterns ) ) . '</td></tr>';

					echo '<tr><th>' . esc_html__( 'Total executions tracked', 'pigcache' ) . '</th>';
					echo '<td>' . esc_html( number_format( (int) $summary->total_executions ) ) . '</td></tr>';

					echo '<tr><th>' . esc_html__( 'Average query time', 'pigcache' ) . '</th>';
					echo '<td>' . esc_html( self::format_ms( (float) $summary->avg_ms ) ) . '</td></tr>';

					if ( $summary->earliest ) {
						$since = human_time_diff( (int) strtotime( $summary->earliest ) );
						echo '<tr><th>' . esc_html__( 'Data collected since', 'pigcache' ) . '</th>';
						echo '<td>' . esc_html( $since . ' ago' ) . '</td></tr>';
					}
				} else {
					echo '<tr><td colspan="2"><em>' . esc_html__( 'No data yet — the cron collects query stats every 15 min.', 'pigcache' ) . '</em></td></tr>';
				}

				echo '</tbody></table>';

				// Top slow queries accordion.
				$slow = PigCache_Query_Stats::get_top_slow( 10 );
				if ( ! empty( $slow ) ) {
					echo '<details class="pigcache-dashboard-details">';
					echo '<summary>' . esc_html__( 'Slowest query patterns', 'pigcache' ) . '</summary>';
					echo '<table class="widefat striped"><thead><tr>';
					echo '<th>' . esc_html__( 'Query pattern', 'pigcache' ) . '</th>';
					echo '<th style="white-space:nowrap">' . esc_html__( 'Avg', 'pigcache' ) . '</th>';
					echo '<th style="white-space:nowrap">' . esc_html__( 'Peak', 'pigcache' ) . '</th>';
					echo '<th>' . esc_html__( 'Runs', 'pigcache' ) . '</th>';
					echo '</tr></thead><tbody>';
					foreach ( $slow as $row ) {
						$tables     = json_decode( (string) $row['tables'], true );
						$tables_str = is_array( $tables ) && ! empty( $tables ) ? implode( ', ', $tables ) : '';
						$full       = (string) $row['normalized'];
						$short      = mb_strlen( $full ) > 90 ? mb_substr( $full, 0, 90 ) . '…' : $full;
						echo '<tr>';
						echo '<td><code title="' . esc_attr( $full ) . '">' . esc_html( $short ) . '</code>';
						if ( $tables_str ) {
							echo '<br><span style="color:#646970;font-size:11px">' . esc_html( $tables_str ) . '</span>';
						}
						echo '</td>';
						echo '<td>' . esc_html( self::format_ms( (float) $row['avg_ms'] ) ) . '</td>';
						echo '<td>' . esc_html( self::format_ms( (float) $row['peak_ms'] ) ) . '</td>';
						echo '<td>' . esc_html( number_format( (int) $row['total_hits'] ) ) . '</td>';
						echo '</tr>';
					}
					echo '</tbody></table></details>';
				}

				// Most frequent queries accordion.
				$freq = PigCache_Query_Stats::get_top_frequent( 10 );
				if ( ! empty( $freq ) ) {
					echo '<details class="pigcache-dashboard-details">';
					echo '<summary>' . esc_html__( 'Most frequent query patterns', 'pigcache' ) . '</summary>';
					echo '<table class="widefat striped"><thead><tr>';
					echo '<th>' . esc_html__( 'Query pattern', 'pigcache' ) . '</th>';
					echo '<th>' . esc_html__( 'Runs', 'pigcache' ) . '</th>';
					echo '<th style="white-space:nowrap">' . esc_html__( 'Avg', 'pigcache' ) . '</th>';
					echo '</tr></thead><tbody>';
					foreach ( $freq as $row ) {
						$tables     = json_decode( (string) $row['tables'], true );
						$tables_str = is_array( $tables ) && ! empty( $tables ) ? implode( ', ', $tables ) : '';
						$full       = (string) $row['normalized'];
						$short      = mb_strlen( $full ) > 90 ? mb_substr( $full, 0, 90 ) . '…' : $full;
						echo '<tr>';
						echo '<td><code title="' . esc_attr( $full ) . '">' . esc_html( $short ) . '</code>';
						if ( $tables_str ) {
							echo '<br><span style="color:#646970;font-size:11px">' . esc_html( $tables_str ) . '</span>';
						}
						echo '</td>';
						echo '<td><strong>' . esc_html( number_format( (int) $row['total_hits'] ) ) . '</strong></td>';
						echo '<td>' . esc_html( self::format_ms( (float) $row['avg_ms'] ) ) . '</td>';
						echo '</tr>';
					}
					echo '</tbody></table></details>';
				}

				echo '</div>';
			}
		}

		echo '</div>'; // .pigcache-dashboard
	}

	/**
	 * Human-friendly millisecond formatter.
	 *
	 * @param float $ms
	 * @return string
	 */
	private static function format_ms( float $ms ): string {
		if ( $ms <= 0 ) {
			return '—';
		}
		if ( $ms < 1 ) {
			return '< 1ms';
		}
		if ( $ms >= 1000 ) {
			return round( $ms / 1000, 2 ) . 's';
		}
		return round( $ms, 1 ) . 'ms';
	}

	/**
	 * Live group lists + optional extra groups that skip Redis persistence.
	 */
	public static function render_html_cache_dropin_section() {
		$ac_file   = PigCache_Dropin_Html_Cache::file_exists();
		$ac_ours   = PigCache_Dropin_Html_Cache::is_our_file();
		$ac_active = PigCache_Dropin_Html_Cache::is_active();
		$wp_cache  = PigCache_Dropin_Html_Cache::wp_cache_constant_active();

		echo '<h2>' . esc_html__( 'Full-page HTML cache (advanced-cache.php)', 'pigcache' ) . '</h2>';

		if ( $ac_ours && ! $wp_cache ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'advanced-cache.php is installed but WP_CACHE is not defined/true in wp-config.php. WordPress will not load the drop-in. Add ', 'pigcache' );
			echo '<code>define( \'WP_CACHE\', true );</code>';
			echo ' ' . esc_html__( 'to wp-config.php before the "That\'s all, stop editing" line.', 'pigcache' );
			echo '</p></div>';
		}

		echo '<table class="widefat striped pigcache-table"><tbody>';

		echo '<tr><th>' . esc_html__( 'Status', 'pigcache' ) . '</th>';
		echo '<td>' . esc_html( PigCache_Dropin_Html_Cache::status_label() ) . '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Drop-in valid (PigCache)', 'pigcache' ) . '</th>';
		echo '<td>' . ( $ac_ours ? '<span style="color:green">&#10003;</span>' : '<span style="color:#b32d2e">&#10007;</span>' ) . '</td></tr>';

		echo '<tr><th>' . esc_html__( 'WP_CACHE constant', 'pigcache' ) . '</th>';
		echo '<td>' . ( $wp_cache ? '<span style="color:green">true</span>' : '<span style="color:#b32d2e">' . esc_html__( 'not set', 'pigcache' ) . '</span>' ) . '</td></tr>';

		echo '<tr><th>' . esc_html__( 'wp-content/advanced-cache.php', 'pigcache' ) . '</th><td>';
		if ( $ac_file ) {
			echo esc_html( $ac_ours ? __( 'Present (PigCache)', 'pigcache' ) : __( 'Present (other plugin)', 'pigcache' ) );
		} else {
			echo esc_html__( 'Not installed', 'pigcache' );
		}
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<form method="post" class="pigcache-form">';
		wp_nonce_field( 'pigcache_html_dropin' );

		if ( ! $ac_active && ( ! $ac_file || $ac_ours ) ) {
			echo '<p><button type="submit" name="pigcache_html_action" value="install" class="button button-primary">';
			echo esc_html__( 'Install HTML cache drop-in (advanced-cache.php)', 'pigcache' );
			echo '</button></p>';
		}

		if ( $ac_ours ) {
			echo '<p><button type="submit" name="pigcache_html_action" value="remove" class="button">';
			echo esc_html__( 'Remove PigCache advanced-cache.php', 'pigcache' );
			echo '</button></p>';
		}

		echo '</form>';
	}

	public static function render_object_cache_groups_section() {
		echo '<h2>' . esc_html__( 'Object cache groups (Redis)', 'pigcache' ) . '</h2>';

		if ( wp_using_ext_object_cache() ) {
			global $wp_object_cache;
			if ( is_object( $wp_object_cache ) ) {
				$globals = isset( $wp_object_cache->global_groups ) && is_array( $wp_object_cache->global_groups )
					? $wp_object_cache->global_groups
					: array();
				$ignored = isset( $wp_object_cache->ignored_groups ) && is_array( $wp_object_cache->ignored_groups )
					? $wp_object_cache->ignored_groups
					: array();
				sort( $globals );
				sort( $ignored );

				echo '<table class="widefat striped pigcache-table-wide"><tbody>';
				echo '<tr><th style="width:200px">' . esc_html__( 'Global groups (live)', 'pigcache' ) . '</th><td>';
				echo '<details><summary>' . esc_html( sprintf(
					__( 'Show %d registered names', 'pigcache' ),
					count( $globals )
				) ) . '</summary>';
				echo '<pre class="pigcache-group-list">' . esc_html( implode( "\n", $globals ) ) . '</pre>';
				echo '</details></td></tr>';
				echo '<tr><th>' . esc_html__( 'Non-persistent / ignored (live)', 'pigcache' ) . '</th><td>';
				echo '<details><summary>' . esc_html( sprintf(
					__( 'Show %d registered names', 'pigcache' ),
					count( $ignored )
				) ) . '</summary>';
				echo '<pre class="pigcache-group-list">' . esc_html( implode( "\n", $ignored ) ) . '</pre>';
				echo '</details></td></tr>';
				echo '</tbody></table>';
			}
		} else {
			echo '<p><em>' . esc_html__( 'Enable the object cache drop-in to see live group lists.', 'pigcache' ) . '</em></p>';
		}

		echo '<p><strong>' . esc_html__( 'Additional non-persistent groups', 'pigcache' ) . '</strong></p>';

		echo '<form method="post" class="pigcache-form-wide">';
		wp_nonce_field( 'pigcache_extra_groups' );
		echo '<textarea name="pigcache_extra_non_persistent" rows="8" class="large-text code" placeholder="my_custom_group">' . esc_textarea( PigCache_Config::get_extra_non_persistent_textarea_value() ) . '</textarea>';
		echo '<p><button type="submit" name="pigcache_save_extra_groups" value="1" class="button button-primary">' . esc_html__( 'Save extra non-persistent groups', 'pigcache' ) . '</button></p>';
		echo '</form>';
	}

	public static function register_menu() {
		add_options_page(
			__( 'PigCache', 'pigcache' ),
			__( 'PigCache', 'pigcache' ),
			'manage_options',
			'pigcache',
			array( __CLASS__, 'render_page' )
		);
		// Metrics are now embedded in the main page — no separate submenu.
	}

	/**
	 * @return array{removed_labels: string[], errors: string[]}
	 */
	private static function remove_dropins_all() {
		$oc   = self::remove_dropin_object_cache_only();
		$db   = self::remove_dropin_db_only();
		$html = self::remove_dropin_html_cache_only();

		return array(
			'removed_labels' => array_merge( $oc['removed_labels'], $db['removed_labels'], $html['removed_labels'] ),
			'errors'         => array_merge( $oc['errors'], $db['errors'], $html['errors'] ),
		);
	}

	/**
	 * @return array{removed_labels: string[], errors: string[]}
	 */
	private static function remove_dropin_html_cache_only() {
		$removed = array();
		$errors  = array();

		if ( ! PigCache_Dropin_Html_Cache::file_exists() ) {
			return array( 'removed_labels' => $removed, 'errors' => $errors );
		}

		$r = PigCache_Dropin_Html_Cache::remove();
		if ( is_wp_error( $r ) ) {
			$errors[] = $r->get_error_message();
		} else {
			$removed[] = 'advanced-cache.php';
		}

		return array( 'removed_labels' => $removed, 'errors' => $errors );
	}

	/**
	 * @return array{removed_labels: string[], errors: string[]}
	 */
	private static function remove_dropin_object_cache_only() {
		$removed = array();
		$errors  = array();

		if ( ! PigCache_Dropin_Object_Cache::dropin_exists() ) {
			return array( 'removed_labels' => $removed, 'errors' => $errors );
		}

		if ( ! PigCache_Dropin_Object_Cache::validate() ) {
			$errors[] = __( 'wp-content/object-cache.php is not the PigCache drop-in; not removed.', 'pigcache' );
			return array( 'removed_labels' => $removed, 'errors' => $errors );
		}

		$r = PigCache_Dropin_Object_Cache::remove();
		if ( is_wp_error( $r ) ) {
			$errors[] = $r->get_error_message();
		} else {
			$removed[] = 'object-cache.php';
		}

		return array( 'removed_labels' => $removed, 'errors' => $errors );
	}

	/**
	 * @return array{removed_labels: string[], errors: string[]}
	 */
	private static function remove_dropin_db_only() {
		$removed = array();
		$errors  = array();

		if ( ! PigCache_Dropin_DB::file_exists() ) {
			return array( 'removed_labels' => $removed, 'errors' => $errors );
		}

		$r = PigCache_Dropin_DB::remove();
		if ( is_wp_error( $r ) ) {
			$errors[] = $r->get_error_message();
		} else {
			$removed[] = 'db.php';
		}

		return array( 'removed_labels' => $removed, 'errors' => $errors );
	}

	/**
	 * Render the page header — plain on Free, branded Pro banner when licensed.
	 */
	private static function render_page_header() {
		$is_pro   = class_exists( 'PigCache_License', false ) && PigCache_License::is_pro();
		$plan     = $is_pro && class_exists( 'PigCache_License', false ) ? ucfirst( PigCache_License::get_plan() ) : '';
		$has_dist = class_exists( 'PigCache_License', false ) && PigCache_License::has_pro_distribution();

		if ( $is_pro && $has_dist ) {
			$email   = class_exists( 'PigCache_License', false ) ? PigCache_License::get_key() : '';
			$site_id = class_exists( 'PigCache_License', false ) ? PigCache_License::get_site_id() : '';

			echo '<div class="pigcache-header pigcache-header--pro">';
			echo '<div class="pigcache-header__left">';
			echo '<span class="pigcache-header__logo">🐷</span>';
			echo '<div class="pigcache-header__titles">';
			echo '<h1 class="pigcache-header__name">PigCache <span class="pigcache-header__plan-badge">' . esc_html( $plan ) . '</span></h1>';
			echo '<p class="pigcache-header__tagline">' . esc_html__( 'Redis cache · SQL profiler · Continuous learning · Cloud sync', 'pigcache' ) . '</p>';
			echo '</div>';
			echo '</div>';
			echo '<div class="pigcache-header__right">';
			if ( $site_id ) {
				echo '<div class="pigcache-header__meta">';
				echo '<span class="pigcache-header__meta-label">' . esc_html__( 'Site ID', 'pigcache' ) . '</span>';
				echo '<code class="pigcache-header__meta-value">' . esc_html( substr( $site_id, 0, 8 ) . '…' ) . '</code>';
				echo '</div>';
			}
			$learning_on = class_exists( 'PigCache_Continuous_Learner', false )
				&& ! empty( PigCache_Continuous_Learner::get_remote_config()['learning_enabled'] );
			echo '<div class="pigcache-header__pills" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:flex-end">';

			$pill_base = 'display:inline-flex;align-items:center;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;letter-spacing:0.3px;white-space:nowrap;';
			$pill_on   = $pill_base . 'background:rgba(40,193,112,.18);color:#4ade80;border:1px solid rgba(74,222,128,.3);';
			$pill_off  = $pill_base . 'background:rgba(220,53,69,.15);color:#f87171;border:1px solid rgba(248,113,113,.25);';
			$pill_idle = $pill_base . 'background:rgba(255,255,255,.07);color:rgba(255,255,255,.45);border:1px solid rgba(255,255,255,.12);';

			$redis_on = wp_using_ext_object_cache();
			echo '<span style="' . esc_attr( $redis_on ? $pill_on : $pill_off ) . '">';
			echo esc_html( $redis_on ? __( 'Redis connected', 'pigcache' ) : __( 'Redis off', 'pigcache' ) );
			echo '</span>';

			if ( class_exists( 'PigCache_Sql_Profiler', false ) ) {
				$profiler_on = PigCache_Sql_Profiler::has_profile();
				echo '<span style="' . esc_attr( $profiler_on ? $pill_on : $pill_idle ) . '">';
				echo esc_html( $profiler_on ? __( 'SQL profiler active', 'pigcache' ) : __( 'SQL profiler learning', 'pigcache' ) );
				echo '</span>';
			}

			if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
				echo '<span style="' . esc_attr( $learning_on ? $pill_on : $pill_idle ) . '">';
				echo esc_html( $learning_on ? __( 'Learning on', 'pigcache' ) : __( 'Learning off', 'pigcache' ) );
				echo '</span>';
			}

			echo '</div>';
			echo '</div>';
			echo '</div>';
		} else {
			echo '<h1>PigCache</h1>';
		}
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$meta        = get_option( PigCache_Config::OPTION_SITE_META, array() );
		$registry_db = PigCache_Config::get_assigned_db();
		$display_db  = PigCache_Config::get_display_redis_database_index();
		$oc          = wp_using_ext_object_cache();

		echo '<div class="wrap">';

		self::render_page_header();

		self::render_flash_notices();

		if ( $oc ) {
			self::render_cache_dashboard();
		}

		$oc_valid    = PigCache_Dropin_Object_Cache::validate();
		$oc_exists   = PigCache_Dropin_Object_Cache::dropin_exists();
		$oc_outdated = PigCache_Dropin_Object_Cache::is_outdated();

		echo '<h2>' . esc_html__( 'Redis object cache (wp-content/object-cache.php)', 'pigcache' ) . '</h2>';
		echo '<table class="widefat striped pigcache-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Status', 'pigcache' ) . '</th><td>' . esc_html( PigCache_Dropin_Object_Cache::status_label() ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'External object cache', 'pigcache' ) . '</th><td>' . ( $oc ? '<span style="color:green">&#10003;</span>' : '<span style="color:#b32d2e">&#10007;</span>' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Drop-in valid (PigCache)', 'pigcache' ) . '</th><td>' . ( $oc_valid ? '<span style="color:green">&#10003;</span>' : '<span style="color:#b32d2e">&#10007;</span>' ) . '</td></tr>';
		if ( $oc_valid && $oc_outdated ) {
			echo '<tr><th>' . esc_html__( 'Version', 'pigcache' ) . '</th><td>' . esc_html__( 'Plugin ships a newer drop-in — use Update.', 'pigcache' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" class="pigcache-form">';
		wp_nonce_field( 'pigcache_object_cache' );
		if ( ! $oc_exists || ! $oc_valid ) {
			echo '<p><button type="submit" name="pigcache_oc_action" value="enable" class="button button-primary">' . esc_html__( 'Enable object cache (copy drop-in)', 'pigcache' ) . '</button></p>';
		}
		if ( $oc_valid ) {
			echo '<p>';
			echo '<button type="submit" name="pigcache_oc_action" value="flush" class="button">' . esc_html__( 'Flush object cache', 'pigcache' ) . '</button> ';
			if ( $oc_outdated ) {
				echo '<button type="submit" name="pigcache_oc_action" value="update" class="button">' . esc_html__( 'Update drop-in', 'pigcache' ) . '</button> ';
			}
			echo '<button type="submit" name="pigcache_oc_action" value="disable" class="button">' . esc_html__( 'Disable (remove drop-in)', 'pigcache' ) . '</button>';
			echo '</p>';
		}
		echo '</form>';

		$sql_ttl_default  = PigCache_Config::get_sql_cache_ttl();
		$html_ttl_default = PigCache_Config::get_html_cache_ttl();

		echo '<h2>' . esc_html__( 'Cache TTL', 'pigcache' ) . '</h2>';
		echo '<form method="post" class="pigcache-form-narrow">';
		wp_nonce_field( 'pigcache_cache_ttl' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pigcache_sql_ttl">' . esc_html__( 'SQL SELECT cache', 'pigcache' ) . '</label></th><td>';
		echo '<input name="pigcache_sql_ttl" id="pigcache_sql_ttl" type="number" min="' . esc_attr( (string) PigCache_Config::TTL_MIN_SECONDS ) . '" max="' . esc_attr( (string) PigCache_Config::TTL_MAX_SECONDS ) . '" step="1" value="' . esc_attr( (string) $sql_ttl_default ) . '" class="small-text" /> ';
		echo '<span class="description">' . esc_html__( 'seconds (default 120)', 'pigcache' ) . '</span></td></tr>';
		echo '<tr><th scope="row"><label for="pigcache_html_ttl">' . esc_html__( 'Full-page HTML cache', 'pigcache' ) . '</label></th><td>';
		echo '<input name="pigcache_html_ttl" id="pigcache_html_ttl" type="number" min="' . esc_attr( (string) PigCache_Config::TTL_MIN_SECONDS ) . '" max="' . esc_attr( (string) PigCache_Config::TTL_MAX_SECONDS ) . '" step="1" value="' . esc_attr( (string) $html_ttl_default ) . '" class="small-text" /> ';
		echo '<span class="description">' . esc_html__( 'seconds (default 60)', 'pigcache' ) . '</span></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" name="pigcache_save_ttl" value="1" class="button button-primary">' . esc_html__( 'Save TTL settings', 'pigcache' ) . '</button></p>';
		echo '</form>';

		self::render_object_cache_groups_section();

		self::render_html_cache_dropin_section();

		$sql_active = PigCache_Dropin_DB::is_active();
		$db_file    = PigCache_Dropin_DB::file_exists();
		$db_ours    = PigCache_Dropin_DB::is_our_file();

		echo '<h2>' . esc_html__( 'SQL result cache (db.php)', 'pigcache' ) . '</h2>';

		$db_inactive_msg = PigCache_Dropin_DB::get_dropin_inactive_message();
		if ( $db_inactive_msg !== '' ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $db_inactive_msg ) . '</p></div>';
		}

		echo '<table class="widefat striped pigcache-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'PigCache wpdb active', 'pigcache' ) . '</th><td>' . ( $sql_active ? '<span style="color:green">&#10003;</span>' : '<span style="color:#b32d2e">&#10007;</span>' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'wp-content/db.php', 'pigcache' ) . '</th><td>';
		if ( $db_file ) {
			echo esc_html( $db_ours ? __( 'Present (PigCache)', 'pigcache' ) : __( 'Present (other)', 'pigcache' ) );
		} else {
			echo esc_html__( 'Not installed', 'pigcache' );
		}
		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<form method="post" class="pigcache-form">';
		wp_nonce_field( 'pigcache_db_dropin' );
		if ( $db_ours && $db_inactive_msg !== '' ) {
			echo '<p><button type="submit" name="pigcache_db_action" value="install" class="button button-primary">' . esc_html__( 'Reinstall db.php drop-in (refresh from plugin)', 'pigcache' ) . '</button></p>';
		} elseif ( ! $sql_active && ( ! $db_file || $db_ours ) ) {
			echo '<p><button type="submit" name="pigcache_db_action" value="install" class="button button-primary">' . esc_html__( 'Install db.php drop-in', 'pigcache' ) . '</button></p>';
		}
		if ( $db_ours ) {
			echo '<p><button type="submit" name="pigcache_db_action" value="remove" class="button">' . esc_html__( 'Remove PigCache db.php', 'pigcache' ) . '</button></p>';
		}
		echo '</form>';

		self::render_sql_profiler_section();

		if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
			self::render_continuous_learning_section();
		}

		self::render_license_section();

		echo '<h2>' . esc_html__( 'Remove drop-ins', 'pigcache' ) . '</h2>';
		echo '<form method="post" class="pigcache-form">';
		wp_nonce_field( 'pigcache_remove_dropins' );
		$confirm_all  = esc_attr__( 'Remove all PigCache drop-ins now (object-cache.php, advanced-cache.php and db.php when ours)?', 'pigcache' );
		$confirm_oc   = esc_attr__( 'Remove wp-content/object-cache.php if it is the PigCache drop-in?', 'pigcache' );
		$confirm_html = esc_attr__( 'Remove wp-content/advanced-cache.php if it is the PigCache drop-in?', 'pigcache' );
		$confirm_db   = esc_attr__( 'Remove wp-content/db.php if it is the PigCache drop-in?', 'pigcache' );
		echo '<p>';
		echo '<button type="submit" name="pigcache_remove_dropins" value="all" class="button pigcache-confirm" data-confirm="' . $confirm_all . '">' . esc_html__( 'Remove all PigCache drop-ins', 'pigcache' ) . '</button> ';
		echo '<button type="submit" name="pigcache_remove_dropins" value="object_cache" class="button pigcache-confirm" data-confirm="' . $confirm_oc . '">' . esc_html__( 'Remove object-cache.php only', 'pigcache' ) . '</button> ';
		echo '<button type="submit" name="pigcache_remove_dropins" value="html_cache" class="button pigcache-confirm" data-confirm="' . $confirm_html . '">' . esc_html__( 'Remove advanced-cache.php only', 'pigcache' ) . '</button> ';
		echo '<button type="submit" name="pigcache_remove_dropins" value="db" class="button pigcache-confirm" data-confirm="' . $confirm_db . '">' . esc_html__( 'Remove db.php only', 'pigcache' ) . '</button>';
		echo '</p>';
		echo '</form>';

		echo '<h2>' . esc_html__( 'Site / Redis DB hint', 'pigcache' ) . '</h2>';
		echo '<table class="widefat striped pigcache-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Redis logical database', 'pigcache' ) . '</th><td>';
		echo '<strong>' . esc_html( (string) $display_db ) . '</strong>';
		if ( defined( 'WP_REDIS_DATABASE' ) ) {
			echo ' <span class="description">(WP_REDIS_DATABASE)</span>';
		}
		echo '</td></tr>';
		if ( is_array( $meta ) && isset( $meta['site_url'] ) ) {
			echo '<tr><th>' . esc_html__( 'Site fingerprint', 'pigcache' ) . '</th><td>' . esc_html( (string) $meta['site_url'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $oc && class_exists( 'PigCache_Metrics', false ) ) {
			PigCache_Metrics::render_sections();
		}

		echo '</div>';
	}

	/**
	 * Render flash notices from query string parameters.
	 */
	private static function render_flash_notices() {
		if ( isset( $_GET['pigcache_err'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( wp_unslash( rawurldecode( (string) $_GET['pigcache_err'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['pigcache_oc'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Object cache updated.', 'pigcache' ) . '</p></div>';
		}
		if ( isset( $_GET['pigcache_db'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'db.php updated.', 'pigcache' ) . '</p></div>';
		}
		if ( isset( $_GET['pigcache_html'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'HTML cache drop-in updated.', 'pigcache' ) . '</p></div>';
		}
		if ( isset( $_GET['pigcache_ttl_saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'TTL saved.', 'pigcache' ) . '</p></div>';
		}
		if ( isset( $_GET['pigcache_groups_saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Groups saved.', 'pigcache' ) . '</p></div>';
		}
		if ( isset( $_GET['pigcache_dropins_removed'] ) && is_string( $_GET['pigcache_dropins_removed'] ) ) {
			$allowed = array( 'object-cache.php' => true, 'db.php' => true, 'advanced-cache.php' => true );
			$parts   = array_filter( array_map( 'trim', explode( ',', wp_unslash( $_GET['pigcache_dropins_removed'] ) ) ) );
			$labels  = array();
			foreach ( $parts as $p ) {
				if ( isset( $allowed[ $p ] ) ) {
					$labels[] = $p;
				}
			}
			$labels = array_unique( $labels );
			if ( ! empty( $labels ) ) {
				echo '<div class="notice notice-success"><p>';
				echo esc_html( sprintf(
					__( 'Removed PigCache drop-in file(s): %s', 'pigcache' ),
					implode( ', ', $labels )
				) );
				echo '</p></div>';
			}
		}
		if ( isset( $_GET['pigcache_dropins_none'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No PigCache drop-ins were removed (none installed or already absent).', 'pigcache' ) . '</p></div>';
		}
	}

	public static function maybe_notice_object_cache_foreign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'settings_page_pigcache' ) {
			return;
		}

		if ( PigCache_Dropin_Object_Cache::dropin_exists() && ! PigCache_Dropin_Object_Cache::validate() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'wp-content/object-cache.php is present but is not the PigCache drop-in. Remove or replace it before enabling PigCache.', 'pigcache' );
			echo '</p></div>';
		}
	}

	public static function maybe_notice_db_dropin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'settings_page_pigcache' ) {
			return;
		}

		if ( PigCache_Dropin_DB::file_exists() && ! PigCache_Dropin_DB::is_our_file() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Another wp-content/db.php is installed. PigCache cannot install its SQL cache drop-in until that is resolved.', 'pigcache' );
			echo '</p></div>';
		}
	}

	public static function maybe_notice_db_dropin_inactive() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'settings_page_pigcache' ) {
			return;
		}

		$msg = PigCache_Dropin_DB::get_dropin_inactive_message();
		if ( $msg === '' ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'PigCache SQL cache', 'pigcache' ) . '</strong> — ' . esc_html( $msg ) . '</p></div>';
	}

	public static function maybe_notice_wp_config() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'plugins' ) {
			return;
		}

		if ( defined( 'WP_REDIS_DATABASE' ) ) {
			return;
		}

		$db = PigCache_Config::get_assigned_db();
		if ( $db < PigCache_Config::MIN_DB ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html( sprintf(
			__( 'PigCache assigned Redis database %d. Set WP_REDIS_DATABASE in wp-config.php to match for shared Redis setups.', 'pigcache' ),
			$db
		) );
		echo '</p></div>';
	}
}
