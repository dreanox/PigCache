=== PigCache ===
Contributors: pigcache
Tags: cache, redis, object-cache, performance, html-cache
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Redis object-cache drop-in with optional SQL, HTML page, and fragment caching.

== Description ==

PigCache replaces WordPress’s default object cache with **Redis** (official drop-in pattern), using PhpRedis when available or the bundled **Predis**. You can tune Redis with `WP_REDIS_*` constants or your host’s configuration.

This WordPress.org release includes:

* **SQL query cache** (optional `db.php` drop-in) with **global** invalidation
* **Full-page HTML cache** for anonymous visitors with **global** invalidation on content changes
* **Fragment caching** helpers backed by Redis (global invalidation)

**PigCache Pro** (separate commercial package) adds tag-based selective invalidation, the SQL Profiler with per-table epochs, and optional cloud/license features. Those are not part of this download.

The object cache drop-in is derived from [Redis Object Cache](https://github.com/rhubarbgroup/redis-cache) (GPLv3, Till Krüss / Rhubarb Group).

== Installation ==

1. Upload the plugin files to `wp-content/plugins/pigcache`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Settings → PigCache** and use **Enable object cache (copy drop-in)** when you are ready.
4. Optionally install the **SQL cache** drop-in from the same screen.

Do not run another full Redis object-cache plugin at the same time; PigCache replaces that role.

== Frequently Asked Questions ==

= Does this work without Redis? =

No. A reachable Redis server is required for the object cache (and for HTML/fragment features that use Redis).

= Is this the same as “Redis Object Cache”? =

PigCache includes its own drop-in and must not be used together with the separate “Redis Object Cache” plugin.

= What is the difference between this plugin and PigCache Pro? =

This directory listing is the **community** build: full Redis object cache plus optional SQL, HTML, and fragment caching with **global** cache invalidation. **Pro** is a separate install from the vendor site and adds selective (tag) invalidation, the SQL Profiler, and license/cloud integration.

== Changelog ==

= 1.0.0 =
* Initial release on WordPress.org.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
