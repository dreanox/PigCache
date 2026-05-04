=== PigCache ===
Contributors: aixeiger
Tags: cache, redis, object-cache, performance, html-cache
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.5
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Redis object-cache drop-in with optional SQL, HTML page, and fragment caching.

== Description ==

PigCache replaces WordPress’s default object cache with **Redis** (official drop-in pattern), using PhpRedis when available or the bundled **Predis**. Configure the connection with `PIGCACHE_REDIS_*` constants in wp-config.php.

Features:

* **Redis object cache** drop-in (PhpRedis or bundled Predis)
* **SQL query cache** (optional `db.php` drop-in)
* **Full-page HTML cache** for anonymous visitors with tag-based selective invalidation on content changes
* **Fragment caching** helpers backed by Redis
* **Tag-based selective invalidation** — only affected pages are purged when a post, term, or comment changes

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

== Changelog ==

= 1.0.1 =
* Security: sanitize $_SERVER inputs in early-boot path and admin handlers.
* Security: escape all outputs in object cache stats view.
* HTML and fragment caches use tag-based selective invalidation when available, with global flush fallback.

= 1.0.0 =
* Initial release on WordPress.org.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
