# PigCache

WordPress plugin that replaces the object cache with Redis, adds full-page HTML caching, SQL query caching, and fragment caching — all in a single plugin with no external dependencies required on the server.

**This package (WordPress.org / “Free” build)** ships the runtime above: Redis object cache, optional `db.php` SQL cache, HTML page cache, fragments, metrics, and global invalidation. It does **not** ship the Pro-only PHP modules (SQL Profiler, cloud client/sync, environment helpers). There is **no** license activation or remote cloud in this build; those exist only in the **full PigCache Pro** ZIP from [pigcache.com](https://pigcache.com).

- **WordPress:** 5.8 or higher
- **PHP:** 7.4 or higher
- **Redis:** accessible server (default `127.0.0.1:6379`)
- **License:** GPLv2 or later (same family as WordPress; see `readme.txt` and `pigcache.php` headers)

The object cache drop-in is based on [Redis Object Cache](https://github.com/rhubarbgroup/redis-cache) (GPLv3, Till Krüss / Rhubarb Group). Predis is bundled in `vendor/`; no Composer installation is needed on the server.

---

## Installation

1. Copy the `pigcache` folder to `wp-content/plugins/`.
2. Activate the plugin in **Plugins**.
3. Go to **Settings → PigCache** and click **Enable object cache (copy drop-in)**.
4. Verify Redis is running and accessible.
5. **Optional — SQL cache:** install the `db.php` drop-in from the same settings page.

> Do not activate **Redis Object Cache** at the same time — PigCache already serves that role and will warn you if it detects the other plugin.

---

## Features

### Object cache (Redis)

Replaces `wp_cache_*` with Redis. Uses PhpRedis when the extension is available; falls back to the bundled Predis. Provides automatic per-site key isolation in multisite or shared-hosting setups.

### Full-page HTML cache

Caches complete HTML output for unauthenticated GET requests outside admin and preview. Subsequent requests are served directly from Redis without running WordPress.

**Invalidation (Free): global.** When a post is saved, a term changes, or a menu is updated, the entire HTML cache is flushed. Simple and predictable.

> **PigCache Pro** offers tag-based selective invalidation: only the pages that reference the changed object are purged — the rest stay cached.

### SQL query cache

Caches `SELECT` results in Redis. Invalidation uses a **global epoch**: any write operation invalidates all cached queries.

> **PigCache Pro** includes the SQL Profiler, which enables per-table epochs — only queries touching the changed table are invalidated.

### Fragment cache

```php
pigcache_fragment( $key, $callback, $ttl, $group );
```

Cache arbitrary PHP output in Redis.

> Tag-based invalidation for fragments is a **Pro** feature.

### Admin dashboard

- Real-time status of object cache, HTML cache, and SQL cache.
- Enable / update / disable drop-ins.
- Configure TTL for SQL and HTML cache.
- **PigCache Metrics** (embedded): Redis server INFO, per-request hit/miss stats, and a paginated key browser.

---

## Multiple sites, one Redis

PigCache automatically isolates each site using a prefix derived from `DB_NAME` and `$table_prefix`. For manual control:

```php
// wp-config.php
define( 'PIGCACHE_REDIS_PREFIX', 'my-site:' );
define( 'PIGCACHE_REDIS_DATABASE', 1 ); // optional logical database
```

---

## Constants (wp-config.php)

| Constant | Effect |
|----------|--------|
| `PIGCACHE_REDIS_HOST` | Redis host (default `127.0.0.1`) |
| `PIGCACHE_REDIS_PORT` | Redis port (default `6379`) |
| `PIGCACHE_REDIS_PASSWORD` | Redis password (optional) |
| `PIGCACHE_REDIS_DATABASE` | Logical database index (default `0`) |
| `PIGCACHE_REDIS_PREFIX` | Manual key prefix |
| `PIGCACHE_INVALIDATE_THROTTLE` | Minimum seconds between invalidations (default `2`) |
| `PIGCACHE_INVALIDATE_ON_OPTION` | Invalidate on option add/update/delete |
| `PIGCACHE_INVALIDATE_ON_TERM` | Invalidate on term create/edit/delete |
| `PIGCACHE_INVALIDATE_ON_COMMENT` | Invalidate on comment changes |
| `PIGCACHE_INVALIDATE_ON_NAV_MENU` | Invalidate on menu update/delete |
| `PIGCACHE_INVALIDATE_ON_WIDGET` | Invalidate on widget/sidebar changes |
| `PIGCACHE_INVALIDATE_ON_THEME` | Invalidate on theme switch / Customizer save |
| `PIGCACHE_INVALIDATE_ON_USER` | Invalidate on user register/update/delete |
| `PIGCACHE_HTML_GRACE` | Stale-while-revalidate grace period in seconds (default `10`) |

---

## Filters

| Filter | Purpose |
|--------|---------|
| `pigcache_sql_cache_ttl` | Override SQL cache TTL |
| `pigcache_html_ttl` | Override HTML cache TTL per URI |
| `pigcache_sql_cache_enabled` | Disable SQL cache at runtime |
| `pigcache_skip_html_cache` | Skip HTML cache for a request |
| `pigcache_redis_client` | Force a specific Redis client |

---

## PigCache Pro

The **Pro** product is a **separate plugin package** (not this WordPress.org download). Install that ZIP from your purchase, then activate your API key there. It adds:

- **Tag-based selective invalidation** for HTML and fragments — only affected pages are purged on content changes.
- **SQL Profiler** — per-table epochs invalidate only the queries touching the changed table.
- **Cloud sync & profiles** — optional remote profile/environment workflows tied to your license.

**Trials** (length and eligibility) are defined **on the PigCache server** when you register or receive a trial license; they are not a hidden timer inside this community build.

More at [pigcache.com](https://pigcache.com).
