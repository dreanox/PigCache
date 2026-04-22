# Layer 2 — HTML Cache (full-page output buffer)

## What it is and how it loads

The HTML cache captures the **complete rendered HTML of a page**, stores it in Redis, and on subsequent requests serves it directly — bypassing PHP execution, WordPress boot, and MySQL entirely.

This is the **biggest single latency win**: a page that takes 820 ms to render takes 2–5 ms when HTML-cached.

---

## Drop-in management (admin UI)

Unlike the other two drop-ins, `advanced-cache.php` has its own WordPress requirement (`WP_CACHE = true`). PigCache handles both from Settings → PigCache:

```
Settings → PigCache → Full-page HTML cache (advanced-cache.php)
  ├── Status table
  │     ├── Status                  Active / Not installed / Installed (WP_CACHE not set)
  │     ├── Drop-in valid (PigCache) ✓ / ✗
  │     ├── WP_CACHE constant        true / not set
  │     └── wp-content/advanced-cache.php   Present (PigCache) / Not installed
  │
  └── [Install HTML cache drop-in]   copies dropin + injects define('WP_CACHE', true)
      [Remove PigCache advanced-cache.php]   removes file (does not touch wp-config.php)
```

**Install flow** (`PigCache_Dropin_Html_Cache::install()`):
1. Copies `pigcache/includes/dropin/advanced-cache.php` → `wp-content/advanced-cache.php`
2. Calls `maybe_inject_wp_cache_constant()`:
   - Reads wp-config.php (checked at `ABSPATH/wp-config.php` and `dirname(ABSPATH)/wp-config.php`)
   - Inserts `define( 'WP_CACHE', true );` before the "That's all, stop editing!" marker
   - Silently skips if the file is read-only or the constant is already defined

If auto-injection fails, the admin shows a yellow warning with the exact line to add manually.

**Deactivation:** `register_deactivation_hook` removes `advanced-cache.php` when the plugin is deactivated, consistent with the other drop-ins.

---

## WordPress loading order

WordPress loads drop-ins in this exact sequence inside `wp-settings.php`:

```
1. wp-db.php + wp-content/db.php (database layer)
2. wp_start_object_cache()        → wp-content/object-cache.php (Redis)
3. if (WP_CACHE)                  → wp-content/advanced-cache.php  ← HTML cache
4. require all plugins            → pigcache.php, theme functions.php, etc.
```

This means:
- `advanced-cache.php` runs **after** `object-cache.php` — `wp_cache_get` is already available
- `advanced-cache.php` runs **before** plugins — `is_admin()`, `is_user_logged_in()` etc. are NOT available
- `advanced-cache.php` runs **before** `$wpdb` is usable for queries

---

## HIT path — serve from cache before WordPress boots

```
Browser GET /product/red-sneakers/
    │
    ├── wp-settings.php: wp_start_object_cache()
    │       └── $wp_object_cache = new PigCache_Object_Cache()
    │               └── $this->redis = new Redis(); connect(REDIS_HOST)
    │
    ├── wp-settings.php: require wp-content/advanced-cache.php
    │       └── locate_and_require( 'class-pigcache-html-cache.php' )
    │       └── PigCache_Html_Cache::serve_early()
    │               │
    │               ├── $_SERVER['REQUEST_METHOD'] === 'GET'              ✓
    │               ├── No wordpress_logged_in_* cookie                    ✓
    │               ├── No woocommerce_cart_hash cookie                    ✓
    │               ├── URI does not contain /wp-admin/ or /wp-login.php   ✓
    │               │
    │               └── wp_cache_get( 'doc_b7e2a1f...', 'pigcache_html' )
    │                       └── Redis GET wp:{blog}:pigcache_html:doc_b7e2a1f...
    │                               HIT → [ 'html' => '<!DOCTYPE html>...', 'tags' => [...], 'time' => 1713000000 ]
    │
    ├── headers_sent() === false
    ├── header('Content-Type: text/html; charset=UTF-8')
    ├── header('X-PigCache: HIT')
    ├── echo $pack['html']
    └── exit()   ← PHP stops; WordPress never boots

[TTFB: 3–5 ms]
```

---

## MISS path — WordPress renders and stores

```
PigCache_Html_Cache::serve_early()
    └── wp_cache_get( ... ) → false   MISS, return

WordPress continues booting
    ├── db.php loaded earlier → $wpdb = new PigCache_WPDB(...)
    └── all plugins + theme load normally

pigcache.php → PigCache_Plugin::instance()
    └── PigCache_Html_Cache::init()
            └── add_action('template_redirect', [self, 'maybe_start_buffer'], 0)

template_redirect fires
    └── PigCache_Html_Cache::maybe_start_buffer()
            ├── should_cache_request()
            │       ├── is_user_logged_in()                  → false ✓
            │       ├── is_admin() / wp_doing_ajax() / etc.  → false ✓
            │       ├── REQUEST_METHOD === 'GET'              → true  ✓
            │       └── wp_using_ext_object_cache()          → true  ✓
            │
            ├── (Pro) PigCache_Tag_Collector::start()
            │       └── add_action('the_post', ...)
            │           add_action('get_term', ...)
            │
            └── ob_start( [self, 'ob_callback'] )

WordPress renders template (all DB queries, theme loops, widget output...)

ob_callback( $html )
    ├── PigCache_Tag_Collector::stop()  → ['post:88', 'term:14', 'product:88']
    │
    ├── $key  = 'doc_' . md5( HTTP_HOST . REQUEST_URI )
    ├── $pack = [ 'html' => $html, 'tags' => $tags, 'time' => time() ]
    ├── wp_cache_set( $key, $pack, 'pigcache_html', $ttl )
    │       └── Redis SET wp:{blog}:pigcache_html:doc_b7e2a1f... EX 3600
    │
    ├── (Pro) PigCache_Tag_Index::store_tags( $key, 'pigcache_html', $tags )
    │       └── INSERT INTO wp_pigcache_tags (cache_key, grp, tag) VALUES (...)
    │
    └── return $html  ← original HTML sent to browser as normal

[TTFB: 820 ms — first visitor pays the render cost]
```

---

## Early-serve checks vs. template_redirect checks

`serve_early()` and `should_cache_request()` both protect against serving stale or sensitive content, but at different stages with different tools available:

| Check | `serve_early()` (before WP boots) | `should_cache_request()` (template_redirect) |
|---|---|---|
| HTTP method | `$_SERVER['REQUEST_METHOD']` | `$_SERVER['REQUEST_METHOD']` |
| Logged-in user | `wordpress_logged_in_*` cookie name | `is_user_logged_in()` |
| Admin URL | String match on `$_SERVER['REQUEST_URI']` | `is_admin()` |
| WooCommerce cart | `woocommerce_cart_hash` cookie | `is_cart()` / `is_checkout()` |
| AJAX / REST | Not checked (those set `DOING_AJAX` after boot) | `wp_doing_ajax()`, `REST_REQUEST` constant |
| Preview | Not checked | `is_preview()` |
| Custom filter | Not available | `apply_filters('pigcache_skip_html_cache', false)` |

The two checks are complementary: `serve_early()` is intentionally conservative (fewer checks) because it runs before WordPress, and `should_cache_request()` is the authoritative gate for **storing** new entries.

---

## What gets cached (storage gate)

`should_cache_request()` returns `false` (do not store) when:

| Condition | Mechanism |
|---|---|
| Logged-in user | `is_user_logged_in()` |
| POST request | `$_SERVER['REQUEST_METHOD']` |
| Admin / AJAX / cron | `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()` |
| REST API | `defined('REST_REQUEST') && REST_REQUEST` |
| POST body not empty | `$_POST` not empty |
| Preview mode | `is_preview()` |
| WooCommerce cart/checkout/account | `is_cart()`, `is_checkout()`, `is_account_page()` |
| Custom exclusion | `apply_filters('pigcache_skip_html_cache', false)` |
| No external object cache | `wp_using_ext_object_cache()` returns false |

---

## Example A — Blog homepage (first visitor vs. warm cache)

```
Request 1:
  serve_early() → wp_cache_get → MISS → WordPress boots
  Theme renders 34 queries, 780 ms total
  ob_callback stores HTML in Redis (key: doc_a3f8..., TTL 3600 s)
  Browser gets full HTML: 780 ms TTFB

Requests 2 – N (within the hour):
  serve_early() → wp_cache_get → HIT
  header('X-PigCache: HIT')
  echo $pack['html']
  exit()
  TTFB: 4 ms
```

---

## Example B — WooCommerce product page

```
Cold cache:
  wp_cache_get('doc_b7e2a1f...', 'pigcache_html') → nil
  WordPress boots → 48 queries, 820 ms
  ob_callback → wp_cache_set(... EX 3600)

Warm cache:
  serve_early() → HIT → exit()   3 ms TTFB
  MySQL: 0 queries
  PHP: ~2 ms (Redis connection already open from object-cache.php)
```

---

## Example C — Logged-in editor visits a cached page

```
Editor logs in → browser holds wordpress_logged_in_abc123 cookie

GET /product/red-sneakers/
  serve_early():
    foreach array_keys($_COOKIE) as $name:
      strncmp($name, 'wordpress_logged_in_', 20) === 0  → true → return

  WordPress boots normally (no serve_early exit)
  template_redirect → should_cache_request() → is_user_logged_in() → true → return (no ob_start)

  Page renders from scratch every request for logged-in users.
  Cache is neither read nor written.
```

This is correct: logged-in users see live content (draft posts, admin bars, nonces) and their requests never poison the public cache.

---

## Invalidation: Free vs. Pro

### Free — global flush on any content change

```php
// PigCache_Invalidation::on_save_post() → global_flush()
PigCache_Html_Cache::flush_all();
// wp_cache_flush_group('pigcache_html')
// Increments the group version counter in Redis
// All existing doc_* keys become unreachable instantly
```

Every cached page is invalidated when any post/term/menu is saved.

### Pro — tag-based selective invalidation

```
Post #88 saved → PigCache_Invalidation::on_save_post()
  purge_by_tags(['post:88', 'product:88'])
    PigCache_Tag_Index::purge_by_tags(...)
      SELECT cache_key FROM wp_pigcache_tags WHERE tag IN ('post:88','product:88')
      → ['pigcache_html:doc_b7e2a1f...']
      wp_cache_delete('doc_b7e2a1f...', 'pigcache_html')
      DELETE FROM wp_pigcache_tags WHERE cache_key = 'doc_b7e2a1f...'
```

4,999 other product pages remain cached. Only `/product/red-sneakers/` is dropped.

---

## Verifying the drop-in is active

From the browser dev tools:
```
Response headers:
  X-PigCache: HIT        ← served from cache
  X-PigCache: (absent)   ← WordPress rendered the page (miss or excluded)
```

From wp-admin → Settings → PigCache:
- Status row shows **Active**
- WP_CACHE constant row shows **true**

From the CLI:
```bash
# Check that the file is ours
head -3 wp-content/advanced-cache.php
# Should include: PigCache HTML Cache drop-in

# Check WP_CACHE is defined in wp-config.php
grep -i WP_CACHE wp-config.php
# Should show: define( 'WP_CACHE', true );
```
