<?php
/**
 * Activation and deactivation must never produce stray output.
 *
 * WordPress wraps plugin activation in an output buffer specifically to catch
 * this: any byte produced while the plugin's hooks run gets reported back as
 * "The plugin generated N characters of unexpected output during activation",
 * and on a fresh site it can leave the plugin silently deactivated instead.
 *
 * The concrete way this happened here: PigCache_Plugin::__construct() runs on
 * 'plugins_loaded', which fires before 'init'. It used to call
 * wp_schedule_event() directly for its cron hooks whenever they were not yet
 * scheduled — true on every fresh install, and every deactivate/reactivate
 * cycle, since deactivation clears them. wp_schedule_event() triggers the
 * 'cron_schedules' filter, and PigCache's own callback for that filter builds
 * a label with __() — a translation call made before WordPress considers it
 * safe to load translations, which WordPress logs as a doing_it_wrong()
 * notice. Because that notice fires this early, before any other output, it
 * became the first bytes of the entire response, and every later header()
 * call in the same request (redirects, cookies) then failed with "headers
 * already sent".
 *
 * @package PigCache
 */

declare( strict_types=1 );

final class ActivationOutputTest extends PigCache_Integration_TestCase {

	/**
	 * Simulates the exact condition of a fresh site or a deactivate/reactivate
	 * cycle: the plugin's cron hooks are not scheduled, so the next 'init' run
	 * schedules them from scratch. Nothing in that path may print anything.
	 */
	public function test_scheduling_the_plugin_cron_hooks_produces_no_output(): void {
		wp_clear_scheduled_hook( PigCache_Plugin::TAG_CLEANUP_HOOK );
		if ( class_exists( 'PigCache_Continuous_Learner', false ) ) {
			PigCache_Continuous_Learner::unschedule();
		}

		ob_start();
		PigCache_Plugin::maybe_schedule_cron();
		$output = ob_get_clean();

		$this->assertSame(
			'',
			$output,
			"scheduling the plugin's cron hooks must not print anything — this is exactly what breaks activation on a fresh site"
		);

		$this->assertNotFalse(
			wp_next_scheduled( PigCache_Plugin::TAG_CLEANUP_HOOK ),
			'the tag cleanup cron must actually get scheduled'
		);
	}

	/**
	 * The label callback for the 'pigcache_flush' cron schedule is exactly where
	 * the too-early translation call lived. Exercise it directly, before 'init'
	 * has necessarily settled anything, and confirm it is silent.
	 */
	public function test_cron_schedules_filter_produces_no_output(): void {
		ob_start();
		$schedules = apply_filters( 'cron_schedules', array() );
		$output    = ob_get_clean();

		$this->assertSame( '', $output, 'building the cron schedule label must not print anything' );
		$this->assertArrayHasKey( 'pigcache_flush', $schedules );
	}

	/**
	 * The full "does a real fresh-site activation stay silent" check lives
	 * outside PHPUnit, in tests/run.sh's HTTP-based verification: WordPress
	 * decides whether a translation call is "too early" by whether 'init' has
	 * ever fired, tracked globally for the life of the process, so re-firing
	 * 'plugins_loaded'/'init' here — after WordPress's own boot already fired
	 * them once for this test run — cannot reproduce the failure. Re-firing
	 * global one-time hooks like these is also unsafe on its own: WordPress
	 * core keeps state (e.g. icon collection registries) that is only meant to
	 * run once and errors on a second pass, unrelated to anything this plugin
	 * does.
	 */
}
