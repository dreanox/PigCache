=== PigCache ===
Contributors: aixeiger
Tags: cache, redis, object-cache, performance, html-cache
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.0.28
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Redis object-cache drop-in with optional SQL, HTML page, and fragment caching.

== Description ==

PigCache replaces WordPress’s default object cache with **Redis** (official drop-in pattern), using PhpRedis when available or the bundled **Predis**. Configure the connection with `PIGCACHE_REDIS_*` constants in wp-config.php.

Features:

* **Redis object cache** drop-in (PhpRedis or bundled Predis)
* **SQL query cache** (optional `db.php` drop-in) with per-table invalidation, so writing to one table does not discard results that never read it
* **Full-page HTML cache** for anonymous visitors with tag-based selective invalidation on content changes
* **Fragment caching** helpers backed by Redis
* **Tag-based selective invalidation** — only affected pages are purged when a post, term, or comment changes

Every feature listed above is included and fully enabled. The plugin has no license check, no trial period and no usage limit, and it does not contact any external service.

The object cache drop-in is derived from [Redis Object Cache](https://github.com/rhubarbgroup/redis-cache) (GPLv3, Till Krüss / Rhubarb Group).

== External services ==

This plugin does not connect to any external service. All caching, invalidation and metrics happen on your own server and your own Redis instance.

== Installation ==

1. Upload the plugin files to `wp-content/plugins/pigcache`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Settings → PigCache** and use **Enable object cache (copy drop-in)** when you are ready.
4. Optionally install the **SQL cache** drop-in from the same screen.
5. For the full-page HTML cache, install the `advanced-cache.php` drop-in from the same screen and add `define( 'WP_CACHE', true );` to your `wp-config.php`. The plugin never edits `wp-config.php` for you.

Do not run another full Redis object-cache plugin at the same time; PigCache replaces that role.

== Frequently Asked Questions ==

= Does this work without Redis? =

No. A reachable Redis server is required for the object cache (and for HTML/fragment features that use Redis).

= Is this the same as “Redis Object Cache”? =

PigCache includes its own drop-in and must not be used together with the separate “Redis Object Cache” plugin.

== Changelog ==

= 1.0.25 =
* Fixed: activating the plugin on a fresh site — or deactivating and reactivating it — could break the request outright. The constructor scheduled the plugin's WP-Cron events on `plugins_loaded`, before `init`, and building the cron label touched a translation function that early. WordPress logs that as a notice, but because it is the very first byte of output in the entire request, every later `header()` call in that request then fails with "headers already sent" — which shows up as WordPress reporting unexpected output during activation, or the plugin being silently left deactivated. Cron registration now waits for `init`, like it always should have.
* Fixed: with a cold object cache, a query could return another query's rows. Caching a result or invalidating one reaches the database itself, and those internal lookups overwrote the result the caller was about to read. It affected the first query of each kind in a request after a cache flush, a Redis restart or a fresh install, and the wrong rows were then cached for the full TTL. Writes were affected too: a lost `insert_id` could attach new rows to the wrong record.
* Fixed: the first write to a table did not invalidate SELECTs cached before it, because a freshly bumped per-table epoch was indistinguishable from a missing one. The global epoch had the same flaw.
* Fixed: `wp_cache_decr()` could return negative values instead of clamping at zero as WordPress core does.
* Fixed: the query-stats table was never created, because MySQL rejects a `DEFAULT` on a `TEXT` column.
* Fixed: `INSERT`/`REPLACE` statements written without the optional `INTO` keyword were not attributed to their table.
* The URL firewall now learns from 404s WordPress actually returned instead of matching against a list of known permalinks. It no longer needs to be seeded or rebuilt, and it cannot 404 real content.
* Hardened: the URL firewall's `wp pigcache-fw report` command now strips control/escape bytes before printing request data (IP, User-Agent) to the terminal, and the recorded IP is validated as a well-formed address instead of trusted verbatim from the `CF-Connecting-IP` header.
* Hardened: request headers used by the URL firewall for instrumentation (User-Agent, Referer) are explicitly sanitized before being stored.
* Fixed: the tag-index table used `DATETIME ... DEFAULT CURRENT_TIMESTAMP`, which MySQL only allows on `DATETIME` columns since 5.6.5 (older versions only allowed it on `TIMESTAMP`). On an older server the table silently failed to create, and worse, the very next activation queried a column on a table that didn't exist, which WordPress reported as unexpected output during activation on any install with `WP_DEBUG` on. The two Pro-only tables with the same pattern are fixed the same way. None of the three columns had a default anyway — the code that writes to them was already setting the value itself.
* Fixed: an inline SQL comment left inside a `CREATE TABLE` string was silently turned into a bogus column by `dbDelta()`, producing a broken `ALTER TABLE ADD COLUMN` on every activation after the first. Explanatory comments about column definitions now live outside the SQL string.
* Tested against WordPress 7.1.

= 1.0.24 =
* Tag-based selective invalidation and per-table SQL invalidation are now part of the plugin for everyone, with no license check of any kind.
* The plugin no longer writes to `wp-config.php`; the `WP_CACHE` snippet is shown in the admin screen instead.
* The Redis circuit breaker keeps its state in shared memory instead of a file in the system temp directory.
* Drop-ins resolve the plugin directory through `WP_PLUGIN_DIR` / `WPMU_PLUGIN_DIR` when those constants are available.

= 1.0.1 =
* Security: sanitize $_SERVER inputs in early-boot path and admin handlers.
* Security: escape all outputs in object cache stats view.
* HTML and fragment caches use tag-based selective invalidation when available, with global flush fallback.

= 1.0.0 =
* Initial release on WordPress.org.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
