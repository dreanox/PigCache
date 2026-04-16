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
		if ( 'settings_page_pigcache' !== $hook_suffix && 'settings_page_pigcache-metrics' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'pigcache-admin',
			PIGCACHE_URL . 'assets/css/pigcache-admin.css',
			array(),
			PIGCACHE_VERSION
		);

		wp_enqueue_script(
			'pigcache-admin',
			PIGCACHE_URL . 'assets/js/pigcache-admin.js',
			array(),
			PIGCACHE_VERSION,
			true
		);

		if ( 'settings_page_pigcache-metrics' === $hook_suffix ) {
			wp_enqueue_style(
				'pigcache-metrics',
				PIGCACHE_URL . 'assets/css/pigcache-metrics.css',
				array( 'pigcache-admin' ),
				PIGCACHE_VERSION
			);
		}
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

		if ( ! PigCache_License::can_use_profiler() ) {
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
				if ( PigCache_Cloud_Sync::is_enabled() ) {
					PigCache_Cloud_Sync::schedule();
				}
			} else {
				$msg = isset( $result['message'] ) ? $result['message'] : __( 'Invalid API key.', 'pigcache' );
				$url = add_query_arg( 'pigcache_err', rawurlencode( $msg ), $url );
			}
		} elseif ( 'deactivate' === $action ) {
			PigCache_License::deactivate();
			PigCache_Cloud_Sync::unschedule();
			$url = add_query_arg( 'pigcache_license', 'deactivated', $url );
		} elseif ( 'sync_now' === $action ) {
			$result = PigCache_Cloud_Sync::force_sync();
			$url    = add_query_arg( 'pigcache_license', 'synced', $url );
		} elseif ( 'refresh_status' === $action ) {
			PigCache_License::flush_cache();
			$url = add_query_arg( 'pigcache_license', 'refreshed', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the License & Cloud section on the admin page.
	 */
	public static function render_license_section() {
		$has_key  = PigCache_License::has_key();
		$is_pro   = PigCache_License::is_pro();
		$plan     = PigCache_License::get_plan();
		$site_id  = PigCache_License::get_site_id();
		$key_hint = $has_key ? substr( PigCache_License::get_key(), 0, 8 ) . '...' : '';

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

		if ( $is_pro ) {
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
			echo '<input type="text" name="pigcache_api_key" id="pigcache_api_key" value="" class="regular-text" placeholder="pc_live_..." />';
			echo '</p>';
			echo '<p><button type="submit" name="pigcache_license_action" value="activate" class="button button-primary">';
			echo esc_html__( 'Activate License', 'pigcache' ) . '</button></p>';
		} else {
			echo '<p>';
			if ( $is_pro ) {
				echo '<button type="submit" name="pigcache_license_action" value="sync_now" class="button button-primary">';
				echo esc_html__( 'Sync Now', 'pigcache' ) . '</button> ';
				echo '<button type="submit" name="pigcache_license_action" value="refresh_status" class="button">';
				echo esc_html__( 'Refresh Status', 'pigcache' ) . '</button> ';
			}
			echo '<button type="submit" name="pigcache_license_action" value="deactivate" class="button pigcache-confirm" data-confirm="' . esc_attr__( 'Deactivate and remove your PigCache Pro license from this site?', 'pigcache' ) . '">';
			echo esc_html__( 'Deactivate License', 'pigcache' ) . '</button>';
			echo '</p>';
		}

		echo '</form>';

		if ( isset( $_GET['pigcache_license'] ) ) {
			$msg_key  = sanitize_key( $_GET['pigcache_license'] );
			$messages = array(
				'activated'   => __( 'License activated. Cloud sync is now enabled.', 'pigcache' ),
				'deactivated' => __( 'License deactivated. Cloud features disabled.', 'pigcache' ),
				'synced'      => __( 'Cloud sync completed.', 'pigcache' ),
				'refreshed'   => __( 'License status refreshed.', 'pigcache' ),
			);
			if ( isset( $messages[ $msg_key ] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( $messages[ $msg_key ] ) . '</p></div>';
			}
		}
	}

	/**
	 * Render the SQL Profiler section on the admin page.
	 */
	public static function render_sql_profiler_section() {
		$can_use     = PigCache_License::can_use_profiler();
		$access      = PigCache_License::profiler_access_label();
		$learning    = PigCache_Sql_Profiler::is_learning();
		$has_profile = PigCache_Sql_Profiler::has_profile();
		$remaining   = PigCache_Sql_Profiler::learning_remaining();

		echo '<h2>' . esc_html__( 'SQL Query Profiler', 'pigcache' );
		if ( 'pro' === $access ) {
			echo ' <span class="pigcache-pro-badge">PRO</span>';
		} elseif ( 'trial' === $access ) {
			echo ' <span class="pigcache-pro-badge" style="background:#b36b00">'
				. esc_html( sprintf( __( 'TRIAL — %d days left', 'pigcache' ), PigCache_License::trial_days_remaining() ) )
				. '</span>';
		}
		echo '</h2>';

		if ( ! $can_use && ! $has_profile ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo '<strong>' . esc_html__( 'Premium feature', 'pigcache' ) . '</strong> — ';
			if ( 'expired' === $access ) {
				echo esc_html__( 'Trial expired. Activate a Pro license to use the SQL Profiler.', 'pigcache' );
			} else {
				echo esc_html__( 'Requires Pro license. A 14-day trial starts on first activation.', 'pigcache' );
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
		} else {
			echo '<span style="color:#b32d2e">' . esc_html__( 'Trial expired — Pro license required', 'pigcache' ) . '</span>';
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

		echo '</tbody></table>';

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

			echo '</form>';
		}

		if ( isset( $_GET['pigcache_profiler'] ) ) {
			$msg_key  = sanitize_key( $_GET['pigcache_profiler'] );
			$messages = array(
				'learning'   => __( 'SQL profiler learning started.', 'pigcache' ),
				'stopped'    => __( 'SQL profiler learning stopped.', 'pigcache' ),
				'compiled'   => __( 'SQL profile compiled successfully. Per-table invalidation is now active.', 'pigcache' ),
				'deleted'    => __( 'SQL profile deleted. Reverted to global epoch invalidation.', 'pigcache' ),
				'relearning' => __( 'SQL profiler re-learning started. Old profile is used as fallback.', 'pigcache' ),
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

		echo '<tr><th>' . esc_html__( 'Invalidation', 'pigcache' ) . '</th>';
		echo '<td><strong>' . esc_html__( 'Tag-based (selective)', 'pigcache' ) . '</strong></td></tr>';

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
		echo '<td>' . esc_html__( 'Tag-based (when tags provided) + TTL', 'pigcache' ) . '</td></tr>';

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

		// --- Tag Index Summary card ---
		echo '<div class="pigcache-dashboard-card">';
		echo '<h3>' . esc_html__( 'Tag Index (MySQL)', 'pigcache' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';

		if ( $table_exists ) {
			$total_rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tag_table}" );
			$total_keys  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT cache_key) FROM {$tag_table}" );
			$total_tags  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT tag) FROM {$tag_table}" );
			$total_groups = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT grp) FROM {$tag_table}" );

			echo '<tr><th>' . esc_html__( 'Total rows', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $total_rows ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Cache keys tracked', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $total_keys ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Unique tags', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $total_tags ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Groups', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $total_groups ) ) . '</td></tr>';
		} else {
			echo '<tr><td>' . esc_html__( 'Table not created yet.', 'pigcache' ) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		echo '</div>'; // .pigcache-dashboard
	}

	/**
	 * Live group lists + optional extra groups that skip Redis persistence.
	 */
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
	}

	/**
	 * @return array{removed_labels: string[], errors: string[]}
	 */
	private static function remove_dropins_all() {
		$oc = self::remove_dropin_object_cache_only();
		$db = self::remove_dropin_db_only();

		return array(
			'removed_labels' => array_merge( $oc['removed_labels'], $db['removed_labels'] ),
			'errors'         => array_merge( $oc['errors'], $db['errors'] ),
		);
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

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$meta        = get_option( PigCache_Config::OPTION_SITE_META, array() );
		$registry_db = PigCache_Config::get_assigned_db();
		$display_db  = PigCache_Config::get_display_redis_database_index();
		$oc          = wp_using_ext_object_cache();

		$wp_config_line = '';
		if ( ! defined( 'WP_REDIS_DATABASE' ) && $registry_db >= PigCache_Config::MIN_DB ) {
			$wp_config_line = sprintf( "define( 'WP_REDIS_DATABASE', %d );", $registry_db );
		}

		echo '<div class="wrap"><h1>PigCache</h1>';

		if ( $oc ) {
			echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=pigcache-metrics' ) ) . '">' . esc_html__( 'View metrics', 'pigcache' ) . ' &rarr;</a></p>';
		}

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

		self::render_license_section();

		echo '<h2>' . esc_html__( 'Remove drop-ins', 'pigcache' ) . '</h2>';
		echo '<form method="post" class="pigcache-form">';
		wp_nonce_field( 'pigcache_remove_dropins' );
		$confirm_all = esc_attr__( 'Remove all PigCache drop-ins now (object-cache.php and db.php when both are ours)?', 'pigcache' );
		$confirm_oc  = esc_attr__( 'Remove wp-content/object-cache.php if it is the PigCache drop-in?', 'pigcache' );
		$confirm_db  = esc_attr__( 'Remove wp-content/db.php if it is the PigCache drop-in?', 'pigcache' );
		echo '<p>';
		echo '<button type="submit" name="pigcache_remove_dropins" value="all" class="button pigcache-confirm" data-confirm="' . $confirm_all . '">' . esc_html__( 'Remove all PigCache drop-ins', 'pigcache' ) . '</button> ';
		echo '<button type="submit" name="pigcache_remove_dropins" value="object_cache" class="button pigcache-confirm" data-confirm="' . $confirm_oc . '">' . esc_html__( 'Remove object-cache.php only', 'pigcache' ) . '</button> ';
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

		if ( $wp_config_line ) {
			echo '<h2>' . esc_html__( 'wp-config.php', 'pigcache' ) . '</h2>';
			echo '<pre class="pigcache-code-block">' . esc_html( $wp_config_line ) . '</pre>';
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
		if ( isset( $_GET['pigcache_ttl_saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'TTL saved.', 'pigcache' ) . '</p></div>';
		}
		if ( isset( $_GET['pigcache_groups_saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Groups saved.', 'pigcache' ) . '</p></div>';
		}
		if ( isset( $_GET['pigcache_dropins_removed'] ) && is_string( $_GET['pigcache_dropins_removed'] ) ) {
			$allowed = array( 'object-cache.php' => true, 'db.php' => true );
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
