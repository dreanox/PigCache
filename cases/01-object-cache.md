# Layer 1 — Object Cache (Redis drop-in)

## What it is and how it loads

WordPress core defines a standard object cache API (`wp_cache_get`, `wp_cache_set`, `wp_cache_delete`, `wp_cache_flush_group`, etc.) that by default uses a plain PHP array — data lives only for the duration of the current request and is discarded when PHP exits.

When PigCache copies `includes/object-cache.php` to `wp-content/object-cache.php`, WordPress core detects this file during `wp-settings.php` bootstrap (around line 80: `if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) require WP_CONTENT_DIR . '/object-cache.php';`). From that point all cache API calls are forwarded to PigCache's implementation.

### Drop-in internals

**File:** `wp-content/object-cache.php` (copied from `includes/object-cache.php`)  
**Main class:** `PigCache_Object_Cache`  
**Global instance:** `$wp_object_cache` (WordPress core expects this global)

```php
// How WordPress core bridges the API to the drop-in (wp-includes/cache.php):
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
    global $wp_object_cache;
    return $wp_object_cache->get( $key, $group, $force, $found );
}
```

`PigCache_Object_Cache::get()` translates the `($key, $group)` pair into a Redis key:

```
wp:{blog_id}:{group}:{key}
         │       │      │
         │       │      └── arbitrary string, e.g. "42", "alloptions"
         │       └── WordPress group name, e.g. "posts", "options", "terms"
         └── blog ID for multisite isolation (always 1 on single-site)
```

The Redis connection is established once in `PigCache_Object_Cache::__construct()` using `phpredis` (`new Redis()` → `$this->redis->connect()`). All subsequent calls in the same request reuse the socket.

### Non-persistent groups

Some WordPress groups must never persist across requests (e.g., `counts`, `plugins`, `themes`). `PigCache_Object_Cache` maintains a `$non_persistent_groups` list. Values in these groups are stored in a local PHP array only — `wp_cache_set` for a non-persistent group never touches Redis.

```php
// Registering a group as non-persistent (called by WordPress core and plugins):
wp_cache_add_non_persistent_groups( ['counts', 'plugins', 'themes'] );
// PigCache_Object_Cache::add_non_persistent_groups() stores these in $this->non_persistent_groups
```

---

## How WordPress uses the object cache (automatic — no code changes needed)

### Example A — Post object fetch

`get_post(42)` in WordPress core (`wp-includes/post.php`):

```php
// wp-includes/class-wp-post.php — WP_Post::get_instance()
public static function get_instance( $post_id ) {
    $cached = wp_cache_get( $post_id, 'posts' );  // → PigCache_Object_Cache::get(42, 'posts')
    //                                              //   Redis GET wp:1:posts:42
    if ( false !== $cached ) {
        return $cached;   // ← Returns stdClass directly from Redis. No MySQL.
    }

    $post = $wpdb->get_row(                       // → PigCache_Wpdb::get_row() if SQL cache active
        $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE ID = %d LIMIT 1", $post_id )
    );

    wp_cache_set( $post_id, $post, 'posts' );     // → PigCache_Object_Cache::set(42, $post, 'posts')
    //                                              //   Redis SET wp:1:posts:42 serialize($post)
    return $post;
}
```

**First request:** Redis GET returns `false` → MySQL query → result serialized and stored in Redis with no TTL (persists until invalidated).  
**All subsequent requests:** Redis GET returns the serialized object → `unserialize()` → returned in < 1 ms. MySQL is never reached.

**Real-world impact:** A blog post page that previously ran 12 queries (post, meta, author, terms, options) drops to 0–2 on warm cache. TTFB drops by 60–150 ms.

---

### Example B — Options table (autoloaded)

Every WordPress request calls `wp_load_alloptions()` (`wp-includes/option.php`) to load all auto-loaded options into memory. Without Redis this is a full table scan on every single request.

```php
// wp-includes/option.php — wp_load_alloptions()
function wp_load_alloptions( $force_db = false ) {
    global $wpdb;

    if ( ! $force_db ) {
        $alloptions = wp_cache_get( 'alloptions', 'options' );
        // PigCache_Object_Cache::get('alloptions', 'options')
        // Redis GET wp:1:options:alloptions
        if ( $alloptions ) return $alloptions;
    }

    $alloptions_db = $wpdb->get_results(
        "SELECT option_name, option_value FROM $wpdb->options WHERE autoload = 'yes'"
    );

    $alloptions = [];
    foreach ( $alloptions_db as $o ) {
        $alloptions[ $o->option_name ] = $o->option_value;
    }

    wp_cache_set( 'alloptions', $alloptions, 'options' );
    // Redis SET wp:1:options:alloptions serialize($alloptions)
    return $alloptions;
}
```

A site with 400 auto-loaded options (common after many plugins accumulate) stores a ~120 KB serialized blob in Redis. The table scan that takes 40–120 ms becomes a single Redis GET that takes < 0.5 ms — on every request, every visitor.

**When is this invalidated?**  
`update_option()` calls `wp_cache_delete('alloptions', 'options')` — `PigCache_Object_Cache::delete('alloptions', 'options')` → `Redis DEL wp:1:options:alloptions`. Next request re-fetches from MySQL.

---

### Example C — Term / taxonomy data

`get_terms()` (`wp-includes/class-wp-term-query.php`) caches its result by a hash of the query arguments:

```php
// wp-includes/class-wp-term-query.php — WP_Term_Query::get_terms()
$cache_key   = md5( serialize( $this->query_vars ) );
$cached_terms = wp_cache_get( $cache_key, 'terms' );
// PigCache_Object_Cache::get( $cache_key, 'terms' )
// Redis GET wp:1:terms:{md5}

if ( false !== $cached_terms ) {
    return apply_filters( 'get_terms', $cached_terms, ... );
}

$terms = $wpdb->get_results( $sql );  // cold query — can be 150–300 ms on large taxonomies

foreach ( $terms as $term ) {
    wp_cache_set( $term->term_id, $term, 'terms' );
    // Caches each individual term object too
}
wp_cache_set( $cache_key, $terms, 'terms' );
```

With 10 000 WooCommerce products across 500 categories, listing the category archive fires several term queries totaling 200–400 ms. Cached: < 1 ms total.

**Invalidation:** `clean_term_cache( $term_id )` in `wp-includes/taxonomy.php` calls `wp_cache_delete( $term_id, 'terms' )` and `wp_cache_delete( 'last_changed', 'terms' )`. The `last_changed` pattern is a lightweight version of epoch invalidation — all query-hash keys that encoded `last_changed` are now stale.

---

### Example D — Navigation menus

`wp_nav_menu()` resolves the menu, fetches its term object, then calls `wp_get_nav_menu_items()` which fires a `WP_Query` for `nav_menu_item` posts plus multiple `get_term` calls. On a cold cache this can be 8–15 queries and 30–60 ms.

```php
// wp-includes/nav-menu.php — wp_get_nav_menu_items()
$cached_items = wp_cache_get( $menu->term_id, 'nav_menu_items' );
// PigCache_Object_Cache::get( $menu->term_id, 'nav_menu_items' )
if ( false !== $cached_items ) return $cached_items;

$items = $wpdb->get_results( ... );
wp_cache_set( $menu->term_id, $items, 'nav_menu_items' );
```

This cache entry persists in Redis until `wp_update_nav_menu_item()` calls `clean_term_cache( $menu_id )` → `wp_cache_delete( $menu_id, 'nav_menu_items' )`.

---

### Example E — Custom plugin using the object cache

Any third-party plugin or theme can store arbitrary data. PigCache is transparent — no PigCache-specific API is required:

```php
// my-plugin/includes/featured-authors.php
function get_featured_authors() {
    $key    = 'featured_authors_v2';
    $group  = 'my_plugin';

    $cached = wp_cache_get( $key, $group );
    // PigCache_Object_Cache::get('featured_authors_v2', 'my_plugin')
    // Redis GET wp:1:my_plugin:featured_authors_v2

    if ( false !== $cached ) {
        return $cached;
    }

    // Complex JOIN: users + usermeta + posts count
    global $wpdb;
    $authors = $wpdb->get_results( "
        SELECT u.ID, u.display_name, COUNT(p.ID) AS post_count
        FROM {$wpdb->users} u
        INNER JOIN {$wpdb->posts} p ON p.post_author = u.ID AND p.post_status = 'publish'
        WHERE u.ID IN ( SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'featured' )
        GROUP BY u.ID
        ORDER BY post_count DESC
        LIMIT 5
    " );

    wp_cache_set( $key, $authors, $group, HOUR_IN_SECONDS );
    // Redis SET wp:1:my_plugin:featured_authors_v2 serialize($authors) EX 3600

    return $authors;
}

// When an author is unfeatured:
function unfeatue_author( $user_id ) {
    delete_user_meta( $user_id, 'featured' );
    wp_cache_delete( 'featured_authors_v2', 'my_plugin' );
    // PigCache_Object_Cache::delete → Redis DEL wp:1:my_plugin:featured_authors_v2
}
```

---

## Invalidation reference

All object cache invalidation goes through `PigCache_Object_Cache` methods, which map directly to Redis commands:

| WordPress call | `PigCache_Object_Cache` method | Redis command |
|---|---|---|
| `wp_cache_delete($key, $group)` | `::delete($key, $group)` | `DEL wp:1:{group}:{key}` |
| `wp_cache_flush_group($group)` | `::flush_group($group)` | Increments a group-version counter key; old keys become unreachable |
| `wp_cache_flush()` | `::flush()` | `FLUSHDB` or namespace bump depending on config |

`wp_cache_flush_group()` does **not** scan Redis keys. It increments a version counter stored as `wp:1:{group}:__version__`. All keys for that group encode the version in their name; incrementing makes them unreachable without an O(N) scan.

---

## What you see in PigCache metrics (Settings page)

Metrics are collected by `PigCache_Metrics::render_object_cache_summary()`:

- **Hit ratio** — `$wp_object_cache->cache_hits / ($cache_hits + $cache_misses)`. Aim for > 95 % on established sites.
- **Memory used** — `Redis::info('memory')['used_memory_human']`. A typical WordPress site uses 20–80 MB.
- **Key count** — `Redis::dbsize()`. Can reach 50 000+ on large multisite or WooCommerce installs.
- **Evictions** — `Redis::info('stats')['evicted_keys']`. Any non-zero value means Redis is running out of space. Set `maxmemory` and `maxmemory-policy allkeys-lru` in `redis.conf`.
- **Connected clients** — each PHP-FPM worker holds one persistent socket. `REDIS_PERSISTENT=true` in PigCache settings reuses the same socket across requests via `Redis::pconnect()` instead of `Redis::connect()`.
