# PigCache — Cache Layers: Concrete Examples

PigCache provides four independent cache layers that stack on top of each other. Each layer targets a different bottleneck and is implemented as a separate WordPress drop-in or class. On a typical WordPress + WooCommerce site all four fire on every request.

---

## What gets installed on activation

When you activate PigCache, `register_activation_hook` in `pigcache.php` runs `PigCache::activate()`, which copies three WordPress drop-in files into `wp-content/` and sets `WP_CACHE = true` in `wp-config.php`:

### Enabling "Object Cache" (Settings → Object Cache → Enable)

```
PigCache_Admin::save_settings()
  └── PigCache_Config::set('object_cache', true)
  └── copy( PIGCACHE_DIR . 'includes/object-cache.php', WP_CONTENT_DIR . '/object-cache.php' )
```

`wp-content/object-cache.php` is loaded by WordPress core during `wp-settings.php` before any plugin, theme, or `functions.php`. From that point on every `wp_cache_get` / `wp_cache_set` call in WordPress is handled by **`PigCache_Object_Cache`** (backed by phpredis) instead of the built-in in-memory array.

**Layer unlocked:** object cache (all WordPress data, any plugin using `wp_cache_*`).

### Enabling "HTML Cache" (Settings → HTML Cache → Enable)

```
PigCache_Admin::save_settings()
  └── PigCache_Config::set('html_cache', true)
  └── copy( PIGCACHE_DIR . 'includes/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' )
  └── PigCache_Config::inject_wp_cache_constant()   // adds define('WP_CACHE', true) to wp-config.php
```

`wp-content/advanced-cache.php` is loaded by WordPress core at the very top of `wp-settings.php`, before `$wpdb` exists, before plugins load. It runs `PigCache_Html_Cache::serve()` — if the current URL is in Redis it sends the gzipped response and calls `exit()`. WordPress never boots.

**Layer unlocked:** full-page HTML cache.

### Enabling "SQL Cache" (Settings → SQL Cache → Enable)

```
PigCache_Admin::save_settings()
  └── PigCache_Config::set('sql_cache', true)
  └── copy( PIGCACHE_DIR . 'includes/db.php', WP_CONTENT_DIR . '/db.php' )
```

`wp-content/db.php` is loaded by WordPress core during `wp-settings.php` immediately after `wp-db.php`. It replaces the global `$wpdb` object with an instance of **`PigCache_Wpdb`** (extends `wpdb`). Every query that goes through `$wpdb->query()`, `$wpdb->get_results()`, `$wpdb->get_row()`, etc. now passes through the PigCache SQL cache layer.

**Layer unlocked:** SQL query cache + SQL Profiler (Pro).

### Fragment cache (always available once object cache is enabled)

No additional drop-in. The `pigcache_fragment()` global function is registered in `pigcache.php` and delegates to **`PigCache_Fragments`**. It is usable in any theme or plugin file.

**Layer unlocked:** partial page / shortcode fragment cache.

---

## Layer summary

| Layer | Drop-in file | Main class | Redis key pattern | Free invalidation | Pro invalidation |
|---|---|---|---|---|---|
| **Object cache** | `wp-content/object-cache.php` | `PigCache_Object_Cache` | `wp:{blog}:{group}:{key}` | Per-group (WP core) | Per-group (WP core) |
| **HTML cache** | `wp-content/advanced-cache.php` | `PigCache_Html_Cache` | `pc_html:{url_hash}` | Global flush | Tag-based |
| **SQL cache** | `wp-content/db.php` | `PigCache_Wpdb` + `PigCache_Sql_Cache` | `pc_sql:{fp_hash}:{epoch_suffix}` | Global epoch bump | Per-table epoch bump |
| **Fragment cache** | _(none — uses object cache)_ | `PigCache_Fragments` | `pc_frag:{key}` | Global flush | Tag-based |

---

## Quickstart reading order

1. [01-object-cache.md](01-object-cache.md) — the foundation every WordPress site benefits from immediately
2. [02-html-cache.md](02-html-cache.md) — full-page cache; biggest latency win for public pages
3. [03-sql-cache.md](03-sql-cache.md) — query-level cache with smart per-table invalidation (Pro)
4. [04-fragment-cache.md](04-fragment-cache.md) — partial page caching for logged-in or dynamic sections
5. [05-woocommerce.md](05-woocommerce.md) — end-to-end walkthrough combining all four layers

---

## How layers interact (with exact code path)

```
Browser GET /product/red-sneakers/
    │
    ├── wp-settings.php loads wp-content/advanced-cache.php
    │       └── PigCache_Html_Cache::serve()
    │               └── PigCache_Html_Cache::build_cache_key( $_SERVER )  → 'pc_html:b7e2a1f...'
    │               └── wp_cache_get( 'b7e2a1f...', 'pc_html' )           via PigCache_Object_Cache
    │
    ├── HIT ──────────────────────────────────────────────────────────────────────────────────────┐
    │       PigCache_Html_Cache::send_cached_response( $entry )                                   │
    │           header('Content-Encoding: gzip')                                                  │
    │           header('ETag: ...')                                                               │
    │           echo $entry['body']   ← gzipped HTML from Redis                                  │
    │           exit()                ← PHP stops here; WP never boots                            │
    │                                                                                              ▼
    │                                                                                         [3 ms TTFB]
    │
    └── MISS ──────────────────────────────────────────────────────────────────────────────────────┐
            WordPress continues booting                                                            │
            │                                                                                      │
            ├── wp-settings.php loads wp-content/db.php                                           │
            │       └── $wpdb = new PigCache_Wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST )       │
            │                                                                                      │
            ├── wp-settings.php loads wp-content/object-cache.php                                 │
            │       └── $wp_object_cache = new PigCache_Object_Cache()                            │
            │               └── $this->redis = new Redis(); $this->redis->connect( REDIS_HOST )   │
            │                                                                                      │
            ├── Plugin loads: pigcache.php                                                         │
            │       └── PigCache_Html_Cache::init()                                               │
            │               └── add_action('template_redirect', [self, 'start_buffer'], 0)        │
            │       └── PigCache_Invalidation::init()                                             │
            │               └── add_action('save_post',       [self, 'on_save_post'])             │
            │               └── add_action('deleted_post',    [self, 'on_delete_post'])           │
            │               └── add_action('updated_postmeta',[self, 'on_post_meta_updated'])     │
            │               └── add_action('edited_term',     [self, 'on_term_edited'])           │
            │       └── (Pro) PigCache_Tag_Collector::start()                                     │
            │               └── add_action('the_post',   [self, 'collect_post'])                  │
            │               └── add_action('get_term',   [self, 'collect_term'])                  │
            │                                                                                      │
            ├── Theme renders single-product.php                                                   │
            │       │                                                                              │
            │       ├── get_post(88)                                                              │
            │       │     └── WP_Post::get_instance(88)                                           │
            │       │           └── wp_cache_get(88, 'posts')           ← PigCache_Object_Cache   │
            │       │                   MISS → PigCache_Wpdb::get_row('SELECT … WHERE ID = 88')   │
            │       │                             └── PigCache_Sql_Cache::get( $fingerprint )     │
            │       │                                   MISS → real MySQL → 12 ms                 │
            │       │                                   └── PigCache_Sql_Cache::set( $fp, $rows ) │
            │       │                             └── wp_cache_set(88, $post, 'posts')            │
            │       │                                                                              │
            │       ├── get_post_meta(88, '_price', true)                                         │
            │       │     └── update_meta_cache() → wp_cache_get('post_meta_88', 'post_meta')     │
            │       │           MISS → PigCache_Wpdb::get_results('SELECT … wp_postmeta …')       │
            │       │                     └── PigCache_Sql_Cache: MISS → MySQL → stored           │
            │       │                                                                              │
            │       ├── pigcache_fragment('product_attrs_88', fn(){…}, 3600, ['product:88'])       │
            │       │     └── PigCache_Fragments::get('product_attrs_88')                         │
            │       │           MISS → run generator → PigCache_Fragments::set(…)                 │
            │       │           (Pro) PigCache_Tag_Index::link('product:88', 'pc_frag:…')         │
            │       │                                                                              │
            │       └── wp_nav_menu(…) ← cached by SQL cache + object cache (term queries)        │
            │                                                                                      │
            ├── PigCache_Html_Cache::buffer_callback( $html )   ← ob_end_flush trigger            │
            │       └── PigCache_Html_Cache::should_cache( $html )   ← checks exclusions          │
            │       └── gzencode( $html, 6 )                                                      │
            │       └── wp_cache_set( 'b7e2a1f...', $entry, 'pc_html', 3600 )                    │
            │       (Pro) PigCache_Tag_Index::link_all( $collected_tags, 'pc_html:b7e2a1f...' )   │
            │                                                                                      │
            └───────────────────────────────────────────────────────────────────────────── [820 ms]
```
