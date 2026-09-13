Subject: Re: Plugin review "aixeiger" — PigCache 1.0.24 resubmission

Hello,

Thank you for the detailed review. I have addressed every point and uploaded a corrected
version (1.0.24). Below is what changed for each issue, and an explanation for the three
items I believe are false positives.

--------------------------------------------------------------------------------
1. Trialware and Locked Features (Guideline 5)
--------------------------------------------------------------------------------

You are right, and this is now fully resolved.

Tag-based HTML/fragment invalidation and per-table SQL invalidation are no longer gated in
any way. They are ordinary, always-on features of the plugin:

  * includes/class-pigcache-tag-collector.php and includes/class-pigcache-tag-index.php are
    part of the plugin and are loaded unconditionally from pigcache.php.
  * PigCache_Invalidation now runs tag-based purging by default. The global flush is only a
    fallback a site owner can opt into with the `pigcache_use_tag_invalidation` filter.
  * PigCache_WPDB resolves per-table epochs from the query itself, so a write to one table no
    longer discards cached results that never read it.
  * The tag index table is created on activation and on upgrade, and pruned by a daily
    wp-cron event.

The licensing layer is gone from the package entirely. There is no license class, no license
key option, no license check, no trial, no time limit and no usage quota anywhere in the code
you receive. You can verify this by grepping the ZIP: `license`, `trial`, `pro`, `upgrade`
and `unlock` return no functional matches outside the GPL headers.

The plugin also does not connect to any external service. All caching, invalidation and
metrics happen on the site's own server and its own Redis instance. I have added an
"== External services ==" section to readme.txt stating this explicitly.

I do sell a separate commercial plugin with different functionality (SQL query profiling,
adaptive TTL learning, and a hosted analytics service). None of its code, and no mechanism
that would load or activate it, is present in this submission. It is distributed only from
my own site, as a standalone plugin.

To make sure this cannot regress, the release script now refuses to produce the WordPress.org
package if any licensing or commercial-edition identifier survives into the build.

--------------------------------------------------------------------------------
2. Determining files and directories locations
--------------------------------------------------------------------------------

Fixed where a WordPress API was available, and explained below where one genuinely is not.

Changed:
  * includes/dropin/advanced-cache.php, includes/dropin/object-cache.php and
    includes/dropin/db-pigcache.php now resolve the plugin directory from WP_PLUGIN_DIR and
    WPMU_PLUGIN_DIR when those constants are defined, instead of hardcoding
    wp-content/plugins and wp-content/mu-plugins. This makes the drop-ins work with custom
    plugin directory layouts.
  * includes/class-pigcache-dropin-db.php now uses WP_PLUGIN_DIR / WPMU_PLUGIN_DIR directly,
    since it runs inside WordPress where those constants always exist.
  * `dirname( ABSPATH ) . '/wp-config.php'` is gone completely — see section 3.

The main plugin file already followed the pattern you describe:

    define( 'PIGCACHE_FILE', __FILE__ );
    define( 'PIGCACHE_DIR', plugin_dir_path( __FILE__ ) );
    define( 'PIGCACHE_URL', plugin_dir_url( __FILE__ ) );

Every file inside the plugin is loaded through PIGCACHE_DIR.

Remaining WP_CONTENT_DIR references — please note why these are correct:

  a) WP_CONTENT_DIR . '/object-cache.php', '/advanced-cache.php' and '/db.php'.
     These are the WordPress drop-in paths themselves. The drop-in files must be written to
     and read from wp-content/, not from the plugin directory — that is the location
     WordPress core loads them from. plugin_dir_path() would point at the wrong place.
     `is_writable( WP_CONTENT_DIR )` is checked for the same reason: wp-content/ is the
     directory we are copying into.

  b) The WP_PLUGIN_DIR / WPMU_PLUGIN_DIR fallback inside the three drop-ins.
     The drop-ins are included by wp-settings.php before the plugin API exists, so
     plugin_dir_path() and plugins_url() are not defined. WP_PLUGIN_DIR and WPMU_PLUGIN_DIR
     are also usually still undefined at that point, because core only defines them later in
     wp_plugin_directory_constants(), which runs after require_wp_db(),
     wp_start_object_cache() and the advanced-cache.php include. The code therefore prefers
     those constants whenever they are available and falls back to the core default under
     WP_CONTENT_DIR only when they are not. I could not find any WordPress API that is
     available this early in the bootstrap; if there is one I have missed, I will gladly
     switch to it.

  c) WP_LANG_DIR . "/plugins/{$domain}-{$locale}.mo" in includes/dropin/object-cache.php.
     WP_LANG_DIR is the documented WordPress constant for the languages directory, and the
     drop-in cannot call load_plugin_textdomain() because it runs before the l10n and plugin
     APIs are loaded.

--------------------------------------------------------------------------------
3. Saving data in the plugin folder / asking users to edit files
--------------------------------------------------------------------------------

All three examples are resolved.

  * pigcache.php no longer creates a logs directory inside the plugin folder, and no longer
    writes .htaccess or .gitignore anywhere. That code has been removed entirely.

  * The Redis circuit breaker no longer writes a flag file to the system temp directory. It
    now keeps its state in shared memory via APCu, and falls back to per-request state when
    APCu is unavailable. Nothing is written to disk.

  * The plugin no longer writes to wp-config.php. The previous version tried to insert
    `define( 'WP_CACHE', true );` automatically; that method has been removed, along with the
    `dirname( ABSPATH )` lookup it used. The admin screen now simply shows the site owner the
    one line to add, and the installation instructions in readme.txt say the same. The plugin
    remains fully functional without it — only the optional full-page HTML cache needs it, and
    that feature is opt-in from the settings screen.

The only files PigCache writes are the three WordPress drop-ins in wp-content/, and only when
the site owner explicitly clicks the corresponding button on the settings screen. All plugin
state lives in the options table and in the plugin's own MySQL tables.

--------------------------------------------------------------------------------
4. Unclosed ob_start()
--------------------------------------------------------------------------------

Fixed. There is exactly one ob_start() in the plugin, in
PigCache_Html_Cache::maybe_start_buffer(). It is now explicitly paired with
PigCache_Html_Cache::end_buffer(), registered on `shutdown` at priority 0 in the same
function, which calls ob_end_flush(). A `$buffering` flag guards against double-closing, and
ob_get_level() is checked so we never close a buffer that is not ours.

--------------------------------------------------------------------------------
5. Generic function/class/define/namespace/option names
--------------------------------------------------------------------------------

The plugin prefix is `pigcache` (8 characters) and the class prefix is `PigCache_`. All
global functions, constants and options use it: pigcache_fragment(), pigcache_tag(),
PIGCACHE_VERSION, PIGCACHE_DIR, PIGCACHE_URL, pigcache_db_version, and so on.

On the two items flagged in the analysis:

  * `class WP_Object_Cache` in includes/dropin/object-cache.php.
    This class name is mandated by the WordPress object cache drop-in API. WordPress core
    instantiates `WP_Object_Cache` in wp_cache_init() and the global $wp_object_cache is
    expected to be an instance of it. The same applies to the `wp_cache_*` functions in that
    file — wp_cache_get(), wp_cache_set(), wp_cache_flush() and the rest are the drop-in
    contract defined by wp-includes/cache.php. Renaming them would break the drop-in and any
    plugin that calls the standard cache API. Every drop-in in the directory, including
    Redis Object Cache, does exactly this. Note that this file is a drop-in template that
    only ever executes from wp-content/object-cache.php, where it replaces core's own
    WP_Object_Cache rather than conflicting with it.

  * The `redis` prefix on 12 elements.
    These are all class methods, not global symbols: redis_status(), redis_instance() and
    redis_version() are public methods of WP_Object_Cache (part of the de-facto API that
    Redis-based drop-ins expose, inherited from Redis Object Cache), and redis_info_flat(),
    redis_dbsize() and redis_type_label() are private methods of PigCache_Metrics. Being
    class members, they cannot collide with anything in the global namespace.

If you would still prefer the private PigCache_Metrics helpers renamed, I am happy to do so —
please let me know.

--------------------------------------------------------------------------------
Also included in this version
--------------------------------------------------------------------------------

  * readme.txt now documents that the plugin is fully functional with no license check and no
    external service, and includes the WP_CACHE instruction in the installation steps.
  * "Tested up to" corrected to 6.9.
  * Review correspondence and internal development notes are excluded from the package.

Thank you again for the thorough review — the trialware point in particular was fair, and the
plugin is better for it. Please let me know if anything above is still not compliant.

Best regards,
aixeiger
