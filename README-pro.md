# PigCache Pro

WordPress plugin that replaces the object cache with Redis, adds full-page HTML caching with **tag-based selective invalidation**, SQL query caching with **per-table epochs** via the SQL Profiler, fragment caching with tags, and **Cloud Profiles** — all in a single plugin with no external dependencies required on the server.

- **WordPress:** 5.8 or higher
- **PHP:** 7.4 or higher
- **Redis:** accessible server (default `127.0.0.1:6379`)
- **License:** GPLv2 or later — Pro features require an active license

The object cache drop-in is based on [Redis Object Cache](https://github.com/rhubarbgroup/redis-cache) (GPLv3, Till Krüss / Rhubarb Group). Predis is bundled in `vendor/`; no Composer installation is needed on the server.

---

## Installation

1. Copy the `pigcache` folder to `wp-content/plugins/`.
2. Activate the plugin in **Plugins**.
3. Go to **Settings → PigCache** and click **Enable object cache (copy drop-in)**.
4. Enter your **Pro license key** under **Settings → PigCache → License**.
5. **Self-hosted backend:** add this to `wp-config.php` pointing to your own API server:
   ```php
   define( 'PIGCACHE_CLOUD_API_URL', 'https://bluecache.pigworlds.com/api/v1' );
   ```
   Without this constant the plugin points to the default `https://bluecache.pigworlds.com/v1`. This is required for license validation, cloud sync, and automatic plugin updates.
6. **Optional — SQL Profiler:** install the `db.php` drop-in and start learning from the SQL Profiler panel.

> Do not activate **Redis Object Cache** at the same time — PigCache already serves that role.

---

## Features

### Object cache (Redis)

Replaces `wp_cache_*` with Redis. Uses PhpRedis when the extension is available; falls back to the bundled Predis. Automatic per-site key isolation in multisite or shared-hosting setups.

### Full-page HTML cache with tag-based invalidation

Caches complete HTML output for unauthenticated GET requests outside admin and preview. Subsequent requests are served directly from Redis without running WordPress.

**Tag-based selective invalidation (Pro):** when a post is saved, only the pages that reference that post, term, author, or menu are purged. All other cached pages stay intact.

#### Automatic tags

Collected automatically during page render on cache miss:

| Hook | Tags generated |
|------|---------------|
| `the_post` | `post:{ID}`, `post_type:{type}`, `author:{ID}` |
| `get_the_terms` | `term:{term_id}`, `taxonomy:{taxonomy}` |
| `wp_get_nav_menu_items` | `nav_menu:{menu_id}` |
| `dynamic_sidebar_before` | `sidebar:{sidebar_id}` |
| `get_header` / `get_footer` | `zone:header`, `zone:footer` |
| Implicit | `home`, `feed` |

#### Manual tags

```php
// In any template or plugin:
pigcache_tag( 'widget:recent_posts' );
pigcache_tag( 'custom:my_slider' );
pigcache_tag( 'woo:product_list' );
```

### SQL query cache with per-table epochs

Caches `SELECT` results in Redis with two invalidation modes:

- **Global epoch (Free):** any write invalidates all cached queries.
- **Per-table epochs (Pro):** only queries that touch the changed table are invalidated.

#### SQL Profiler

1. **Learning phase:** intercepts SELECTs, normalizes templates, extracts the tables involved.
2. **Compilation:** generates `wp-content/pigcache-sql-profile.php` — an array lookup with zero runtime overhead.
3. **Auto re-learn:** triggers automatically when the plugin/theme stack changes.

**Cloud Profiles:** the backend aggregates fingerprints from sites with the same stack. A new WooCommerce site receives a compiled profile immediately, without waiting days for local learning.

### Fragment cache with tags

```php
pigcache_fragment(
    key: 'sidebar-posts',
    callback: fn() => render_sidebar_posts(),
    ttl: 300,
    group: 'pigcache_fragments',
    tags: [ 'post_type:post', 'sidebar:primary' ]  // Pro: selective invalidation
);
```

### Admin dashboard

- Real-time status of all cache layers with tag index stats.
- Enable / update / disable drop-ins.
- Configure TTL for SQL and HTML cache.
- **SQL Profiler panel:** Start Learning / Compile / Re-learn / Delete Profile / Sync Now.
- **Redis Metrics** (embedded): server INFO, per-request hit/miss stats, and a paginated key browser.

---

## License

Enter your Pro license key under **Settings → PigCache → License**, or define it in `wp-config.php`:

```php
define( 'PIGCACHE_LICENSE_KEY', 'your-key-here' );
```

License status is cached locally for 24 hours to avoid hitting the API on every request.

A **14-day trial** starts automatically on first activation. No license key needed to evaluate the Profiler and tag-based invalidation locally.

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
| `PIGCACHE_LICENSE_KEY` | Pro license key (alternative to admin option) |
| `PIGCACHE_CLOUD_API_URL` | Backend URL (default `https://api.pigcache.com/v1`) |
| `PIGCACHE_CLOUD_SYNC` | Disable cloud sync manually |
| `PIGCACHE_INVALIDATE_THROTTLE` | Minimum seconds between invalidations (default `2`) |
| `PIGCACHE_INVALIDATE_ON_OPTION` | Invalidate on option add/update/delete |
| `PIGCACHE_INVALIDATE_ON_TERM` | Invalidate on term create/edit/delete |
| `PIGCACHE_INVALIDATE_ON_COMMENT` | Invalidate on comment changes |
| `PIGCACHE_INVALIDATE_ON_NAV_MENU` | Invalidate on menu update/delete |
| `PIGCACHE_INVALIDATE_ON_WIDGET` | Invalidate on widget/sidebar changes |
| `PIGCACHE_INVALIDATE_ON_THEME` | Invalidate on theme switch / Customizer save |
| `PIGCACHE_INVALIDATE_ON_USER` | Invalidate on user register/update/delete |
| `PIGCACHE_HTML_GRACE` | Stale-while-revalidate grace period in seconds (default `10`) |
| `PIGCACHE_SQL_PROFILE_LEARN` | Force Profiler into learning mode |
| `PIGCACHE_SQL_PROFILE_PATH` | Custom path to the compiled profile |
| `PIGCACHE_SQL_PROFILE_AUTO_RELEARN` | Auto re-learn on stack changes (default `true`) |

---

## Filters

| Filter | Purpose |
|--------|---------|
| `pigcache_sql_cache_ttl` | Override SQL cache TTL |
| `pigcache_html_ttl` | Override HTML cache TTL per URI |
| `pigcache_sql_cache_enabled` | Disable SQL cache at runtime |
| `pigcache_sql_cache_skip_context` | Force SQL cache skip in a specific context |
| `pigcache_sql_cache_is_cacheable` | Mark a SELECT as non-cacheable |
| `pigcache_skip_html_cache` | Skip HTML cache for a request |
| `pigcache_invalidation_tags` | Modify resolved tags before purging |
| `pigcache_redis_client` | Force a specific Redis client |
