<?php
/**
 * Pro-only admin UI for PigCache.
 *
 * This file is present only in the Pro distribution and is never included in
 * the WordPress.org community build. It hooks into PigCache_Admin via named
 * actions so the free build contains zero Pro-specific rendering code.
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Admin_Pro {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_license_post' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_profiler_post' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_learning_post' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_adaptive_ttl_post' ) );
		add_action( 'pigcache_admin_pro_header',            array( __CLASS__, 'render_page_header_pro' ) );
		add_action( 'pigcache_admin_pro_sections',          array( __CLASS__, 'render_sql_profiler_section' ) );
		add_action( 'pigcache_admin_pro_sections',          array( __CLASS__, 'maybe_render_continuous_learning' ) );
		add_action( 'pigcache_admin_pro_sections',          array( __CLASS__, 'render_adaptive_ttl_section' ) );
		add_action( 'pigcache_admin_pro_license_section',   array( __CLASS__, 'render_license_section_pro' ) );
		add_action( 'pigcache_admin_cache_dashboard_extra', array( __CLASS__, 'render_query_analytics_card' ) );
	}

	// ------------------------------------------------------------------
	// Form handlers
	// ------------------------------------------------------------------

	/**
	 * Handle license form actions: activate, deactivate, sync_now, refresh_status.
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
			} else {
				$url = add_query_arg( 'pigcache_license', 'activated', $url );
			}
		} elseif ( 'deactivate' === $action ) {
			$result = PigCache_License::deactivate();
			if ( is_wp_error( $result ) ) {
				$url = add_query_arg( 'pigcache_err', rawurlencode( $result->get_error_message() ), $url );
			} else {
				$url = add_query_arg( 'pigcache_license', 'deactivated', $url );
			}
		} elseif ( 'sync_now' === $action ) {
			if ( class_exists( 'PigCache_Cloud_Sync', false ) ) {
				PigCache_Cloud_Sync::run();
			}
			$url = add_query_arg( 'pigcache_license', 'synced', $url );
		} elseif ( 'refresh_status' === $action ) {
			PigCache_License::flush_cache();
			$url = add_query_arg( 'pigcache_license', 'refreshed', $url );
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
			$enabled = 'enable' === $action;
			$payload = array( 'learning_enabled' => $enabled );

			if ( isset( $_POST['pigcache_sample_rate'] ) ) {
				$rate                   = (float) $_POST['pigcache_sample_rate'] / 100;
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

	// ------------------------------------------------------------------
	// Render: Pro page header
	// ------------------------------------------------------------------

	/**
	 * Renders the Pro branded page header (hooked onto pigcache_admin_pro_header).
	 * If no Pro license is active, falls back silently so the free default renders.
	 */
	public static function render_page_header_pro() {
		if ( ! PigCache_License::is_pro() ) {
			return;
		}

		$plan    = ucfirst( PigCache_License::get_plan() );
		$site_id = PigCache_License::get_site_id();

		$learning_on = class_exists( 'PigCache_Continuous_Learner', false )
			&& ! empty( PigCache_Continuous_Learner::get_remote_config()['learning_enabled'] );

		echo '<div class="pigcache-header pigcache-header--pro">';
		echo '<div class="pigcache-header__left">';
		echo '<img src="' . esc_url( PIGCACHE_URL . 'assets/pig.svg' ) . '" class="pigcache-header__logo" width="48" height="48" alt="" aria-hidden="true">';
		echo '<div class="pigcache-header__titles">';
		echo '<p class="pigcache-header__name">PigCache <span class="pigcache-header__plan-badge">' . esc_html( $plan ) . '</span></p>';
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

		$pill_base = 'display:inline-flex;align-items:center;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;letter-spacing:0.3px;white-space:nowrap;';
		$pill_on   = $pill_base . 'background:rgba(40,193,112,.18);color:#4ade80;border:1px solid rgba(74,222,128,.3);';
		$pill_off  = $pill_base . 'background:rgba(220,53,69,.15);color:#f87171;border:1px solid rgba(248,113,113,.25);';
		$pill_idle = $pill_base . 'background:rgba(255,255,255,.07);color:rgba(255,255,255,.45);border:1px solid rgba(255,255,255,.12);';

		echo '<div class="pigcache-header__pills" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:flex-end">';

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
	}

	// ------------------------------------------------------------------
	// Render: Pro license section
	// ------------------------------------------------------------------

	/**
	 * Renders the Pro License & Cloud section (hooked onto pigcache_admin_pro_license_section).
	 */
	public static function render_license_section_pro() {
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
	 * Flash notices shown inside the license section after a form submit.
	 */
	public static function render_license_flash_notices() {
		if ( ! isset( $_GET['pigcache_license'] ) ) {
			return;
		}

		$lmsg  = sanitize_key( $_GET['pigcache_license'] );
		$lmsgs = array(
			'activated'   => __( 'License activated. Welcome to PigCache Pro!', 'pigcache' ),
			'deactivated' => __( 'License deactivated and removed from this site.', 'pigcache' ),
			'synced'      => __( 'Cloud sync completed.', 'pigcache' ),
			'refreshed'   => __( 'License status refreshed.', 'pigcache' ),
		);

		if ( isset( $lmsgs[ $lmsg ] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( $lmsgs[ $lmsg ] ) . '</p></div>';
		}
	}

	// ------------------------------------------------------------------
	// Render: SQL Profiler section
	// ------------------------------------------------------------------

	/**
	 * Renders the SQL Profiler section (hooked onto pigcache_admin_pro_sections).
	 */
	public static function render_sql_profiler_section() {
		if ( ! class_exists( 'PigCache_Sql_Profiler', false ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'SQL Profiler', 'pigcache' );
		echo ' <span class="pigcache-pro-badge">PRO</span>';
		echo '</h2>';

		if ( isset( $_GET['pigcache_profiler'] ) ) {
			$pmsg  = sanitize_key( $_GET['pigcache_profiler'] );
			$pmsgs = array(
				'learning'         => __( 'Learning mode started. PigCache will observe SQL queries for the configured window.', 'pigcache' ),
				'stopped'          => __( 'Learning mode stopped.', 'pigcache' ),
				'compiled'         => __( 'Profile compiled successfully. Per-table epoch invalidation is now active.', 'pigcache' ),
				'deleted'          => __( 'Profile deleted. Invalidation falls back to global epoch.', 'pigcache' ),
				'relearning'       => __( 'Re-learn cycle triggered. The old profile was backed up.', 'pigcache' ),
				'auto_relearn_on'  => __( 'Auto re-learn enabled.', 'pigcache' ),
				'auto_relearn_off' => __( 'Auto re-learn disabled.', 'pigcache' ),
			);
			if ( isset( $pmsgs[ $pmsg ] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( $pmsgs[ $pmsg ] ) . '</p></div>';
			}
		}

		$is_learning  = PigCache_Sql_Profiler::is_learning();
		$has_profile  = PigCache_Sql_Profiler::has_profile();
		$can_use      = PigCache_License::can_use_profiler();
		$learn_start  = (int) get_option( PigCache_Sql_Profiler::OPTION_LEARN_START, 0 );
		$learn_days   = (int) get_option( PigCache_Sql_Profiler::OPTION_LEARN_DAYS, PigCache_Sql_Profiler::DEFAULT_LEARN_DAYS );
		$auto_relearn = PigCache_Sql_Profiler::auto_relearn_enabled();
		$env_change   = get_option( PigCache_Sql_Profiler::OPTION_ENV_CHANGE, array() );
		$profile      = $has_profile ? PigCache_Sql_Profiler::get_profile_stats() : null;

		if ( ! empty( $env_change['time'] ) && $has_profile ) {
			$changed_at = (int) $env_change['time'];
			$detail     = ! empty( $env_change['detail'] ) ? (string) $env_change['detail'] : __( 'Environment changed', 'pigcache' );
			$auto_rel   = ! empty( $env_change['auto_relearned'] );
			echo '<div class="notice notice-warning inline" style="margin:8px 0"><p>';
			echo '<strong>' . esc_html__( 'Environment change detected:', 'pigcache' ) . '</strong> ';
			echo esc_html( $detail );
			echo ' — <em>' . esc_html( human_time_diff( $changed_at ) . ' ' . __( 'ago', 'pigcache' ) ) . '</em>';
			if ( $auto_rel ) {
				echo ' <span style="color:green">' . esc_html__( '(auto re-learn triggered)', 'pigcache' ) . '</span>';
			} else {
				echo ' <span style="color:#b36b00">' . esc_html__( '(consider re-learning)', 'pigcache' ) . '</span>';
			}
			echo '</p></div>';
		}

		echo '<table class="widefat striped pigcache-table"><tbody>';

		echo '<tr><th>' . esc_html__( 'Learning mode', 'pigcache' ) . '</th><td>';
		if ( $is_learning && $learn_start ) {
			$remaining = PigCache_Sql_Profiler::learning_remaining();
			echo '<span style="color:#b36b00"><strong>' . esc_html__( 'Active', 'pigcache' ) . '</strong></span>';
			echo ' — ' . esc_html( sprintf( __( 'started %s ago', 'pigcache' ), human_time_diff( $learn_start ) ) );
			if ( $remaining > 0 ) {
				echo ', ' . esc_html( sprintf( __( '%s remaining', 'pigcache' ), human_time_diff( time() + $remaining ) ) );
			}
		} else {
			echo esc_html__( 'Inactive', 'pigcache' );
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Compiled profile', 'pigcache' ) . '</th><td>';
		if ( $has_profile && is_array( $profile ) ) {
			echo '<span style="color:green"><strong>' . esc_html__( 'Active', 'pigcache' ) . '</strong></span>';
			if ( ! empty( $profile['compiled_at'] ) ) {
				echo ' — ' . esc_html( sprintf( __( 'compiled %s ago', 'pigcache' ), human_time_diff( (int) $profile['compiled_at'] ) ) );
			}
			if ( isset( $profile['source'] ) && 'cloud' === $profile['source'] ) {
				echo ' <span class="description">(' . esc_html__( 'from cloud', 'pigcache' ) . ')</span>';
			}
		} else {
			echo esc_html__( 'None — using global epoch invalidation', 'pigcache' );
		}
		echo '</td></tr>';

		if ( $has_profile && is_array( $profile ) ) {
			if ( isset( $profile['unique_templates'] ) ) {
				echo '<tr><th>' . esc_html__( 'Query templates', 'pigcache' ) . '</th>';
				echo '<td>' . esc_html( number_format( (int) $profile['unique_templates'] ) ) . '</td></tr>';
			}
			if ( isset( $profile['tables'] ) ) {
				echo '<tr><th>' . esc_html__( 'Tables tracked', 'pigcache' ) . '</th>';
				echo '<td>' . esc_html( number_format( count( $profile['tables'] ) ) ) . '</td></tr>';
			}
			if ( isset( $profile['learn_days'] ) ) {
				echo '<tr><th>' . esc_html__( 'Learning window', 'pigcache' ) . '</th>';
				echo '<td>' . esc_html( sprintf( _n( '%d day', '%d days', (int) $profile['learn_days'], 'pigcache' ), (int) $profile['learn_days'] ) ) . '</td></tr>';
			}
		}

		echo '<tr><th>' . esc_html__( 'Auto re-learn', 'pigcache' ) . '</th><td>';
		echo $auto_relearn
			? '<span style="color:green">&#10003; ' . esc_html__( 'Enabled', 'pigcache' ) . '</span>'
			: esc_html__( 'Disabled', 'pigcache' );
		echo '</td></tr>';

		echo '</tbody></table>';

		if ( $can_use ) {
			echo '<form method="post" class="pigcache-form" style="margin-top:12px">';
			wp_nonce_field( 'pigcache_sql_profiler' );

			$days_val = $learn_days > 0 ? $learn_days : PigCache_Sql_Profiler::DEFAULT_LEARN_DAYS;

			if ( $is_learning ) {
				echo '<p>';
				echo '<button type="submit" name="pigcache_profiler_action" value="stop_learning" class="button">';
				echo esc_html__( 'Stop Learning', 'pigcache' );
				echo '</button>';
				if ( class_exists( 'PigCache_Sql_Profile_Store', false ) ) {
					echo ' <button type="submit" name="pigcache_profiler_action" value="compile" class="button button-primary">';
					echo esc_html__( 'Compile Profile Now', 'pigcache' );
					echo '</button>';
				}
				echo '</p>';
			} else {
				echo '<p>';
				echo '<label for="pigcache_learn_days" style="margin-right:6px">' . esc_html__( 'Learning window:', 'pigcache' ) . '</label>';
				echo '<input type="number" id="pigcache_learn_days" name="pigcache_learn_days" value="' . esc_attr( (string) $days_val ) . '" min="1" max="30" step="1" style="width:60px;margin-right:6px"> ';
				echo esc_html__( 'days', 'pigcache' );
				echo '</p><p>';
				if ( $has_profile ) {
					echo '<button type="submit" name="pigcache_profiler_action" value="relearn" class="button button-primary">';
					echo esc_html__( 'Start Re-learn', 'pigcache' );
					echo '</button> ';
					echo '<button type="submit" name="pigcache_profiler_action" value="delete_profile" class="button pigcache-confirm" data-confirm="' . esc_attr__( 'Delete the compiled SQL profile? The profiler will fall back to global epoch invalidation.', 'pigcache' ) . '">';
					echo esc_html__( 'Delete Profile', 'pigcache' );
					echo '</button>';
				} else {
					echo '<button type="submit" name="pigcache_profiler_action" value="start_learning" class="button button-primary">';
					echo esc_html__( 'Start Learning', 'pigcache' );
					echo '</button>';
				}
				echo '</p>';
			}

			echo '<p>';
			echo '<label>';
			echo '<input type="checkbox" name="pigcache_auto_relearn" value="1"' . checked( $auto_relearn, true, false ) . '> ';
			echo esc_html__( 'Automatically re-learn when plugins/themes change', 'pigcache' );
			echo '</label>';
			echo ' &nbsp;<button type="submit" name="pigcache_profiler_action" value="save_auto_relearn" class="button">';
			echo esc_html__( 'Save', 'pigcache' );
			echo '</button>';
			echo '</p>';

			echo '</form>';
		}
	}

	// ------------------------------------------------------------------
	// Render: Continuous Query Learning
	// ------------------------------------------------------------------

	/**
	 * Gate wrapper hooked onto pigcache_admin_pro_sections.
	 */
	public static function maybe_render_continuous_learning() {
		if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
			self::render_continuous_learning_section();
		}
	}

	/**
	 * Render the Continuous Query Learning section (Pro only).
	 */
	public static function render_continuous_learning_section() {
		global $wpdb;

		echo '<h2>' . esc_html__( 'Continuous Query Learning', 'pigcache' );
		echo ' <span class="pigcache-pro-badge">PRO</span>';
		echo '</h2>';

		if ( isset( $_GET['pigcache_learning'] ) ) {
			$lmsg  = sanitize_key( $_GET['pigcache_learning'] );
			$lmsgs = array(
				'enabled'  => __( 'Continuous learning enabled and synced to the API.', 'pigcache' ),
				'disabled' => __( 'Continuous learning disabled and synced to the API.', 'pigcache' ),
			);
			if ( isset( $lmsgs[ $lmsg ] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( $lmsgs[ $lmsg ] ) . '</p></div>';
			}
		}

		$cfg          = PigCache_Continuous_Learner::get_remote_config();
		$enabled      = ! empty( $cfg['learning_enabled'] );
		$sample_rate  = (float) ( $cfg['sample_rate'] ?? 0.10 );
		$adaptive_ttl = ! empty( $cfg['adaptive_ttl'] );
		$const_override = defined( 'PIGCACHE_CONTINUOUS_LEARNING' );

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

		$stats_table  = $wpdb->prefix . 'pigcache_query_stats';
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
			DB_NAME,
			$stats_table
		) );

		if ( $table_exists ) {
			$total_rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$stats_table}" ); // phpcs:ignore
			$unsent_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$stats_table} WHERE sent_at IS NULL" ); // phpcs:ignore
			echo '<tr><th>' . esc_html__( 'Stat rows (MySQL)', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $total_rows ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Pending API sync', 'pigcache' ) . '</th>';
			echo '<td>' . esc_html( number_format( $unsent_rows ) ) . '</td></tr>';
		}

		$flush_ts = (int) PigCache_KV::get( PigCache_KV::KEY_CRON_FLUSH_LAST, 0 );
		$send_ts  = (int) PigCache_KV::get( PigCache_KV::KEY_CRON_SEND_LAST, 0 );

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

		$cron_confirmed = PigCache_Continuous_Learner::is_cron_confirmed();
		$using_wp_cron  = PigCache_Continuous_Learner::using_wp_cron_fallback();
		$flush_last     = (int) PigCache_KV::get( PigCache_KV::KEY_CRON_FLUSH_LAST, 0 );

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
		echo '<summary>' . $summary_html . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

		$php_bin           = PHP_BINARY;
		$php_version_label = '';
		if ( $php_bin && $php_bin !== 'php' ) {
			$php_version_label = ' <span class="description">(' . esc_html( 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' — detected from this server' ) . ')</span>';
		} else {
			$php_bin = '/usr/local/bin/php';
		}

		$log_path = rtrim( PIGCACHE_DIR, '/\\' ) . '/logs/pigcache-cron.log';

		echo '<p><strong>' . esc_html__( 'Add this job in cPanel → Cron Jobs (every 15 minutes):', 'pigcache' ) . '</strong>';
		echo $php_version_label . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

	// ------------------------------------------------------------------
	// Adaptive TTL v2
	// ------------------------------------------------------------------

	/**
	 * Handle the Adaptive TTL settings form.
	 */
	public static function handle_adaptive_ttl_post() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['pigcache_adaptive_ttl_action'] ) ) {
			return;
		}

		check_admin_referer( 'pigcache_adaptive_ttl' );

		$action = sanitize_key( wp_unslash( $_POST['pigcache_adaptive_ttl_action'] ) );
		$url    = admin_url( 'options-general.php?page=pigcache' );

		if ( 'save' === $action ) {
			$enabled = ! empty( $_POST['pigcache_adaptive_ttl_enabled'] );
			update_option( 'pigcache_adaptive_ttl_enabled', $enabled, false );

			if ( isset( $_POST['pigcache_ttl_hot_stable'] ) ) {
				update_option( 'pigcache_ttl_hot_stable', max( 1, (int) $_POST['pigcache_ttl_hot_stable'] ), false );
			}
			if ( isset( $_POST['pigcache_ttl_hot_dynamic'] ) ) {
				update_option( 'pigcache_ttl_hot_dynamic', max( 1, (int) $_POST['pigcache_ttl_hot_dynamic'] ), false );
			}
			if ( isset( $_POST['pigcache_traffic_cold_threshold'] ) ) {
				update_option( 'pigcache_traffic_cold_threshold', max( 0, (int) $_POST['pigcache_traffic_cold_threshold'] ), false );
			}
			if ( isset( $_POST['pigcache_mutation_dynamic_threshold'] ) ) {
				update_option( 'pigcache_mutation_dynamic_threshold', max( 0, (int) $_POST['pigcache_mutation_dynamic_threshold'] ), false );
			}

			$url = add_query_arg( 'pigcache_adaptive', 'saved', $url );
		} elseif ( 'force_traffic_harvest' === $action ) {
			// Reset last harvest timestamp so it runs on next cron tick.
			PigCache_KV::delete( PigCache_KV::KEY_CRON_TRAFFIC_HARVEST_LAST );
			$url = add_query_arg( 'pigcache_adaptive', 'harvest_reset', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the Adaptive TTL v2 section (hooked onto pigcache_admin_pro_sections).
	 */
	public static function render_adaptive_ttl_section() {
		if (
			! class_exists( 'PigCache_Adaptive_Ttl', false ) ||
			! class_exists( 'PigCache_Traffic_Reader', false ) ||
			! class_exists( 'PigCache_Mutation_Tracker', false )
		) {
			return;
		}

		// Resolve effective values: constant overrides wp_options.
		$enabled = PigCache_Adaptive_Ttl::is_enabled();

		$ttl_stable  = defined( 'PIGCACHE_TTL_HOT_STABLE' )
			? (int) PIGCACHE_TTL_HOT_STABLE
			: (int) get_option( 'pigcache_ttl_hot_stable', PigCache_Adaptive_Ttl::DEFAULT_TTL_HOT_STABLE );

		$ttl_dynamic = defined( 'PIGCACHE_TTL_HOT_DYNAMIC' )
			? (int) PIGCACHE_TTL_HOT_DYNAMIC
			: (int) get_option( 'pigcache_ttl_hot_dynamic', PigCache_Adaptive_Ttl::DEFAULT_TTL_HOT_DYNAMIC );

		$cold_threshold = defined( 'PIGCACHE_TRAFFIC_COLD_THRESHOLD' )
			? (int) PIGCACHE_TRAFFIC_COLD_THRESHOLD
			: (int) get_option( 'pigcache_traffic_cold_threshold', PigCache_Adaptive_Ttl::DEFAULT_COLD_THRESHOLD );

		$dyn_threshold = defined( 'PIGCACHE_MUTATION_DYNAMIC_THRESHOLD' )
			? (int) PIGCACHE_MUTATION_DYNAMIC_THRESHOLD
			: (int) get_option( 'pigcache_mutation_dynamic_threshold', PigCache_Adaptive_Ttl::DEFAULT_DYN_THRESHOLD );

		$const_override = defined( 'PIGCACHE_ADAPTIVE_TTL' );
		$traffic_source = PigCache_Traffic_Reader::detect_source();
		$awstats_dir    = PigCache_Traffic_Reader::awstats_dir();
		$source_label   = PigCache_Traffic_Reader::last_source_label();
		$last_harvest   = (int) PigCache_KV::get( PigCache_KV::KEY_CRON_TRAFFIC_HARVEST_LAST, 0 );
		$stability_map  = PigCache_Mutation_Tracker::get_stability_map();
		$traffic_map    = PigCache_Traffic_Reader::get_traffic_map();

		echo '<h2>' . esc_html__( 'Adaptive TTL', 'pigcache' );
		echo ' <span class="pigcache-pro-badge">PRO</span>';
		echo '</h2>';

		// Flash notices.
		if ( isset( $_GET['pigcache_adaptive'] ) ) {
			$msg_key = sanitize_key( $_GET['pigcache_adaptive'] );
			$msgs    = array(
				'saved'         => __( 'Adaptive TTL settings saved.', 'pigcache' ),
				'harvest_reset' => __( 'Traffic harvest will run on the next cron tick.', 'pigcache' ),
			);
			if ( isset( $msgs[ $msg_key ] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( $msgs[ $msg_key ] ) . '</p></div>';
			}
		}

		// Status table.
		echo '<table class="widefat striped pigcache-table"><tbody>';

		echo '<tr><th>' . esc_html__( 'Status', 'pigcache' ) . '</th><td>';
		if ( $enabled ) {
			echo '<span style="color:green">&#10003; <strong>' . esc_html__( 'Enabled', 'pigcache' ) . '</strong></span>';
		} else {
			echo '<span style="color:#b32d2e">&#10007; ' . esc_html__( 'Disabled', 'pigcache' ) . '</span>';
		}
		if ( $const_override ) {
			echo ' <span class="description">(' . esc_html__( 'constant override', 'pigcache' ) . ')</span>';
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Traffic source', 'pigcache' ) . '</th><td>';
		echo esc_html( $source_label );
		if ( 'awstats' === $traffic_source && $awstats_dir ) {
			echo ' <span class="description">(<code>' . esc_html( $awstats_dir ) . '</code>)</span>';
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Last traffic harvest', 'pigcache' ) . '</th><td>';
		echo $last_harvest
			? esc_html( human_time_diff( $last_harvest ) . ' ' . __( 'ago', 'pigcache' ) )
			: '<span style="color:#b32d2e">' . esc_html__( 'Never', 'pigcache' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Tables in stability map', 'pigcache' ) . '</th>';
		echo '<td>' . esc_html( (string) count( $stability_map ) ) . '</td></tr>';

		echo '<tr><th>' . esc_html__( 'URLs in traffic map', 'pigcache' ) . '</th>';
		echo '<td>' . esc_html( number_format( count( $traffic_map ) ) ) . '</td></tr>';

		echo '<tr><th>' . esc_html__( 'TTL — Hot + Stable', 'pigcache' ) . '</th>';
		echo '<td><strong>' . esc_html( human_time_diff( 0, $ttl_stable ) ) . '</strong>';
		if ( defined( 'PIGCACHE_TTL_HOT_STABLE' ) ) {
			echo ' <span class="description">(' . esc_html__( 'constant', 'pigcache' ) . ')</span>';
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'TTL — Hot + Dynamic', 'pigcache' ) . '</th>';
		echo '<td><strong>' . esc_html( human_time_diff( 0, $ttl_dynamic ) ) . '</strong>';
		if ( defined( 'PIGCACHE_TTL_HOT_DYNAMIC' ) ) {
			echo ' <span class="description">(' . esc_html__( 'constant', 'pigcache' ) . ')</span>';
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Cold threshold', 'pigcache' ) . '</th>';
		echo '<td>' . esc_html( number_format( $cold_threshold ) . ' ' . __( 'hits/30 days', 'pigcache' ) ) . '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Dynamic threshold', 'pigcache' ) . '</th>';
		echo '<td>' . esc_html( number_format( $dyn_threshold ) . ' ' . __( 'mutations/day', 'pigcache' ) ) . '</td></tr>';

		echo '</tbody></table>';

		// Settings form (hidden when all overridden by constants).
		if ( ! $const_override ) {
			echo '<form method="post" class="pigcache-form" style="margin-top:12px">';
			wp_nonce_field( 'pigcache_adaptive_ttl' );

			echo '<p>';
			echo '<label>';
			echo '<input type="checkbox" name="pigcache_adaptive_ttl_enabled" value="1"' . checked( $enabled, true, false ) . '> ';
			echo esc_html__( 'Enable Adaptive TTL', 'pigcache' );
			echo '</label>';
			echo '</p>';

			echo '<table class="form-table" style="max-width:500px"><tbody>';

			echo '<tr><th style="width:220px"><label for="pigcache_ttl_hot_stable">' . esc_html__( 'Hot + Stable TTL (seconds)', 'pigcache' ) . '</label></th>';
			echo '<td><input type="number" id="pigcache_ttl_hot_stable" name="pigcache_ttl_hot_stable" value="' . esc_attr( (string) $ttl_stable ) . '" min="1" step="1" style="width:100px"></td></tr>';

			echo '<tr><th><label for="pigcache_ttl_hot_dynamic">' . esc_html__( 'Hot + Dynamic TTL (seconds)', 'pigcache' ) . '</label></th>';
			echo '<td><input type="number" id="pigcache_ttl_hot_dynamic" name="pigcache_ttl_hot_dynamic" value="' . esc_attr( (string) $ttl_dynamic ) . '" min="1" step="1" style="width:100px"></td></tr>';

			echo '<tr><th><label for="pigcache_traffic_cold_threshold">' . esc_html__( 'Cold threshold (hits/30d)', 'pigcache' ) . '</label></th>';
			echo '<td><input type="number" id="pigcache_traffic_cold_threshold" name="pigcache_traffic_cold_threshold" value="' . esc_attr( (string) $cold_threshold ) . '" min="0" step="1" style="width:100px"></td></tr>';

			echo '<tr><th><label for="pigcache_mutation_dynamic_threshold">' . esc_html__( 'Dynamic threshold (mutations/day)', 'pigcache' ) . '</label></th>';
			echo '<td><input type="number" id="pigcache_mutation_dynamic_threshold" name="pigcache_mutation_dynamic_threshold" value="' . esc_attr( (string) $dyn_threshold ) . '" min="0" step="1" style="width:100px"></td></tr>';

			echo '</tbody></table>';

			echo '<p>';
			echo '<button type="submit" name="pigcache_adaptive_ttl_action" value="save" class="button button-primary">';
			echo esc_html__( 'Save', 'pigcache' );
			echo '</button> ';
			echo '<button type="submit" name="pigcache_adaptive_ttl_action" value="force_traffic_harvest" class="button">';
			echo esc_html__( 'Force Traffic Harvest Now', 'pigcache' );
			echo '</button>';
			echo '</p>';

			echo '</form>';
		} else {
			echo '<p class="description">';
			echo esc_html__( 'Settings are controlled via wp-config.php constants. Remove PIGCACHE_ADAPTIVE_TTL to manage from here.', 'pigcache' );
			echo '</p>';
		}

		// Top URLs classification table.
		if ( ! empty( $traffic_map ) && $enabled ) {
			$tier_colors = array(
				'hot_stable'  => '#1a7f1a',
				'hot_dynamic' => '#b36b00',
				'cold'        => '#2c5f8a',
				'unknown'     => '#646970',
			);

			arsort( $traffic_map );
			$top_urls = array_slice( $traffic_map, 0, 30, true );

			echo '<details class="pigcache-dashboard-details" open><summary><strong>';
			echo esc_html__( 'URL tier classification (top 30 by traffic)', 'pigcache' );
			echo '</strong></summary>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'URL', 'pigcache' ) . '</th>';
			echo '<th style="white-space:nowrap">' . esc_html__( 'Hits/30d', 'pigcache' ) . '</th>';
			echo '<th style="white-space:nowrap">' . esc_html__( 'Mutations/day', 'pigcache' ) . '</th>';
			echo '<th>' . esc_html__( 'Tier', 'pigcache' ) . '</th>';
			echo '<th style="white-space:nowrap">' . esc_html__( 'TTL', 'pigcache' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $top_urls as $uri => $hits ) {
				$info  = PigCache_Adaptive_Ttl::classify( $uri );
				$color = isset( $tier_colors[ $info['tier'] ] ) ? $tier_colors[ $info['tier'] ] : '#646970';
				$ttl_display = $info['ttl'] > 0
					? human_time_diff( 0, $info['ttl'] )
					: ( 0 === $info['ttl'] ? __( 'Not cached', 'pigcache' ) : '—' );

				echo '<tr>';
				echo '<td><code style="font-size:11px">' . esc_html( mb_strimwidth( (string) $uri, 0, 80, '…' ) ) . '</code></td>';
				echo '<td>' . esc_html( number_format( $hits ) ) . '</td>';
				echo '<td>' . esc_html( (string) $info['max_mutations'] ) . '</td>';
				echo '<td><strong style="color:' . esc_attr( $color ) . '">'
					. esc_html( PigCache_Adaptive_Ttl::tier_label( $info['tier'] ) )
					. '</strong></td>';
				echo '<td>' . esc_html( $ttl_display ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table></details>';
		}

		// Stability map — table mutation rates.
		if ( ! empty( $stability_map ) ) {
			arsort( $stability_map );
			echo '<details class="pigcache-dashboard-details"><summary><strong>';
			echo esc_html__( 'Table mutation rates (last 24h)', 'pigcache' );
			echo '</strong></summary>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Table', 'pigcache' ) . '</th>';
			echo '<th>' . esc_html__( 'Mutations / 24h', 'pigcache' ) . '</th>';
			echo '<th>' . esc_html__( 'Stability', 'pigcache' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $stability_map as $table => $muts ) {
				$is_dynamic = $muts > $dyn_threshold;
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $table ) . '</code></td>';
				echo '<td>' . esc_html( number_format( (int) $muts ) ) . '</td>';
				echo '<td>';
				if ( $is_dynamic ) {
					echo '<span style="color:#b36b00">⚡ ' . esc_html__( 'Dynamic', 'pigcache' ) . '</span>';
				} else {
					echo '<span style="color:#1a7f1a">🔥 ' . esc_html__( 'Stable', 'pigcache' ) . '</span>';
				}
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table></details>';
		}
	}

	// ------------------------------------------------------------------
	// Render: Query Analytics card (injected into the cache dashboard)
	// ------------------------------------------------------------------

	/**
	 * Renders the Query Analytics card (hooked onto pigcache_admin_cache_dashboard_extra).
	 */
	public static function render_query_analytics_card() {
		global $wpdb;

		if ( ! class_exists( 'PigCache_Query_Stats', false ) ) {
			return;
		}

		$stats_table = PigCache_Query_Stats::table_name();
		$qs_exists   = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
			DB_NAME,
			$stats_table
		) );

		if ( ! $qs_exists ) {
			return;
		}

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
			echo '<td>' . esc_html( PigCache_Admin::format_ms( (float) $summary->avg_ms ) ) . '</td></tr>';

			if ( $summary->earliest ) {
				$since = human_time_diff( (int) strtotime( $summary->earliest ) );
				echo '<tr><th>' . esc_html__( 'Data collected since', 'pigcache' ) . '</th>';
				echo '<td>' . esc_html( $since . ' ago' ) . '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="2"><em>' . esc_html__( 'No data yet — the cron collects query stats every 15 min.', 'pigcache' ) . '</em></td></tr>';
		}

		echo '</tbody></table>';

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
				echo '<td>' . esc_html( PigCache_Admin::format_ms( (float) $row['avg_ms'] ) ) . '</td>';
				echo '<td>' . esc_html( PigCache_Admin::format_ms( (float) $row['peak_ms'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (int) $row['total_hits'] ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></details>';
		}

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
				echo '<td>' . esc_html( PigCache_Admin::format_ms( (float) $row['avg_ms'] ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></details>';
		}

		echo '</div>';
	}
}
