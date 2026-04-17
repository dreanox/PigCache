# Layer 2 — HTML Cache (full-page output buffer)

## What it is and how it loads

The HTML cache captures the **complete rendered HTML of a page** before it is sent to the browser, stores it gzipped in Redis, and on subsequent requests serves it directly — bypassing PHP execution, WordPress boot, and MySQL entirely.

This is the **biggest single latency win**: a page that takes 820 ms to render (even with object cache warm) takes 2–5 ms when HTML-cached.

### How the drop-in loads

When "HTML Cache" is enabled, PigCache:

1. Copies `includes/advanced-cache.php` → `wp-content/advanced-cache.php`
2. Adds `define('WP_CACHE', true)` to `wp-config.php` (via `PigCache_Config::inject_wp_cache_constant()`)

WordPress core in `wp-settings.php` (line ~98):
```php
if ( WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
    require WP_CONTENT_DIR . '/advanced-cache.php';
}
```

This executes **before** `$wpdb` is initialized, before plugins load, before `functions.php` runs. It is the earliest possible hook into WordPress execution.

`advanced-cache.php` bootstraps the minimum it needs:
```php
// wp-content/advanced-cache.php
require_once WP_CONTENT_DIR . '/plugins/pigcache/includes/class-pigcache-config.php';
require_once WP_CONTENT_DIR . '/plugins/pigcache/includes/class-pigcache-html-cache.php';
require_once WP_CONTENT_DIR . '/plugins/pigcache/includes/object-cache.php'; // Redis connection

PigCache_Html_Cache::serve();  // Either exits (HIT) or registers ob_start (MISS)
```

---

## Full request lifecycle

### HIT path (fast path)

```
WordPress core: wp-settings.php
  └── require 'wp-content/advanced-cache.php'
        └── PigCache_Html_Cache::serve()
              └── PigCache_Html_Cache::is_cacheable_request()
                    // Checks: not POST, not admin, no session cookie, not excluded URL
                    → true

              └── PigCache_Html_Cache::build_cache_key()
                    // Normalizes URL: strips tracking params (?utm_*), trailing slash
                    // Key: md5( site_url + normalized_path + accept_encoding )
                    // e.g. 'pc_html:b7e2a1f3c9d8e6a2'
                    → 'b7e2a1f3c9d8e6a2'

              └── wp_cache_get( 'b7e2a1f3c9d8e6a2', 'pc_html' )
                    // PigCache_Object_Cache::get() → Redis GET wp:1:pc_html:b7e2a1f3c9d8e6a2
                    → [ 'body' => <gzipped_html>, 'headers' => [...], 'status' => 200 ]

              └── PigCache_Html_Cache::send_cached_response( $entry )
                    status_header( $entry['status'] )
                    header( 'Content-Encoding: gzip' )
                    header( 'Content-Type: text/html; charset=UTF-8' )
                    header( 'ETag: "b7e2a1f3c9d8e6a2"' )
                    header( 'X-PigCache: HIT' )
                    echo $entry['body']    ← raw bytes from Redis

              └── exit()   ← PHP process ends here
                            WordPress never boots
                            $wpdb never instantiated
                            No plugins load
                            No theme runs
```

**TTFB: 2–5 ms** (Redis socket roundtrip + network)

---

### MISS path (first request, cache population)

```
PigCache_Html_Cache::serve()
  └── wp_cache_get( 'b7e2a1f...', 'pc_html' ) → false

  └── PigCache_Html_Cache::acquire_stampede_lock( 'b7e2a1f...' )
        // Redis SET pc_lock:b7e2a1f... 1 NX EX 30
        // NX = only set if not exists → only first visitor wins the lock
        → true (lock acquired) or false (another process is rendering)

        If false (concurrent request, lock held):
          PigCache_Html_Cache::wait_for_cache( 'b7e2a1f...', $retries = 6, $sleep_ms = 100 )
          // Polls wp_cache_get every 100 ms up to 600 ms
          // If another process populates cache → send_cached_response()
          // If timeout → continue without lock (renders the page, does not store)

// WordPress continues booting normally...
// All plugins load, theme renders, $wpdb runs queries...

add_action( 'template_redirect', [ 'PigCache_Html_Cache', 'start_buffer' ], 0 );
// Priority 0 = before any other plugin's output buffering

PigCache_Html_Cache::start_buffer()
  └── ob_start( [ 'PigCache_Html_Cache', 'buffer_callback' ] )
        // Captures ALL output from this point: HTML, whitespace, errors

// ... WordPress renders the full page ...

PigCache_Html_Cache::buffer_callback( $html )    ← called by PHP on ob_end_flush / exit
  └── PigCache_Html_Cache::should_cache( $html )
        // Checks: HTTP status = 200, Content-Type = text/html,
        //         HTML length > 100 bytes, no 'pigcache:no-cache' marker,
        //         no Set-Cookie header with session data
        → true

  └── $compressed = gzencode( $html, 6 )
        // Level 6 = good ratio, fast enough (< 2 ms for a 50 KB page)

  └── $entry = [
        'body'    => $compressed,
        'headers' => PigCache_Html_Cache::collect_headers(),
        //           Captures Content-Type, Last-Modified, Link (preload hints)
        'created' => time(),
        'status'  => http_response_code(),
      ]

  └── wp_cache_set( 'b7e2a1f...', $entry, 'pc_html', PIGCACHE_HTML_TTL )
        // PigCache_Object_Cache::set() → Redis SET wp:1:pc_html:b7e2a1f... EX 3600

  └── (Pro) PigCache_Tag_Index::link_all( PigCache_Tag_Collector::get_tags(), 'pc_html:b7e2a1f...' )
        // Stores: Redis SADD pc_tagidx:post:88      'pc_html:b7e2a1f...'
        //         Redis SADD pc_tagidx:term:14      'pc_html:b7e2a1f...'
        //         Redis SADD pc_tagidx:product:88   'pc_html:b7e2a1f...'

  └── PigCache_Html_Cache::release_stampede_lock( 'b7e2a1f...' )
        // Redis DEL pc_lock:b7e2a1f...

  └── return $html  ← original uncompressed HTML sent to browser as normal
```

---

## What gets cached (and what doesn't)

`PigCache_Html_Cache::is_cacheable_request()` runs on every request before checking Redis. It returns `false` (skip cache entirely) when:

| Condition | How detected |
|---|---|
| POST request | `$_SERVER['REQUEST_METHOD'] === 'POST'` |
| WordPress admin | `is_admin()` or `/wp-admin/` in path |
| Logged-in user | Cookie named `wordpress_logged_in_*` exists |
| WooCommerce cart | Cookie `woocommerce_items_in_cart` exists with value > 0 |
| REST API | Path starts with `/wp-json/` |
| XML-RPC | Path is `/xmlrpc.php` |
| Excluded URL pattern | Matches any pattern in `PIGCACHE_HTML_EXCLUDE` constant |
| Query string (by default) | `$_SERVER['QUERY_STRING']` is non-empty (configurable) |

`PigCache_Html_Cache::should_cache( $html )` runs after rendering to decide whether to **store**. It returns `false` when:
- HTTP response code is not 200 (404, 301, 500, etc.)
- Response contains `Set-Cookie` with a session or nonce value
- HTML body is shorter than a minimum length (prevents caching error pages)
- Another plugin set `header('Cache-Control: no-store')` or `header('Cache-Control: private')`
- HTML body contains the marker comment `<!-- pigcache:no-cache -->`

---

## Example A — Blog homepage

**Site:** news blog, 6 posts per page, sidebar with recent posts widget.

```
First visitor:
  GET / HTTP/1.1
  advanced-cache.php → PigCache_Html_Cache::serve() → cache MISS
  WordPress boots → 34 DB queries (options, posts, meta, author, terms, widgets)
  Theme renders → 780 ms total
  PigCache_Html_Cache::buffer_callback() → gzencode(html, 6) → 18 KB
  Redis SET wp:1:pc_html:a3f8... <18KB> EX 3600
  Response sent to browser.

Visitor 2, 3, 4 … N (within the hour):
  GET / HTTP/1.1
  advanced-cache.php → Redis GET wp:1:pc_html:a3f8... → 18 KB gzip
  header('X-PigCache: HIT')
  echo <18 KB>
  exit()
  TTFB: 4 ms
```

The Redis key holds the entire entry serialized: body (gzipped HTML) + saved headers + timestamp. When the browser sends `Accept-Encoding: gzip`, PigCache serves the compressed bytes directly. No decompression/recompression cycle.

---

## Example B — WooCommerce product page

**Site:** shop with 5 000 products. Product URL: `/product/red-sneakers/`

```
Cold cache:
  PigCache_Html_Cache::build_cache_key()
    → md5('https://shop.example.com/product/red-sneakers/') = 'b7e2a1f...'
  Redis GET → (nil)  MISS

  WordPress renders: 48 queries, 820 ms
    - WC_Product::get() → 12 meta queries
    - get_terms() for attributes → 8 term queries
    - wp_nav_menu() → 6 join queries
    - Related products WP_Query → 10 queries
    - ...

  gzencode(~85KB HTML, 6) → 22 KB
  Redis SET EX 3600

Warm cache (all subsequent visitors):
  Redis GET → 22 KB gzip → exit()
  TTFB: 3 ms (vs 820 ms)
  Saving: 817 ms / request / visitor
```

---

## Example C — Stampede protection on a viral post

A link goes viral. 2 000 visitors hit `/article/breaking-news/` simultaneously on a cold cache.

**Without stampede lock:** 2 000 PHP-FPM workers spawn, each runs 34 queries, MySQL is overwhelmed, server OOMs.

**With `PigCache_Html_Cache::acquire_stampede_lock()`:**

```
Redis SET pc_lock:f4a9... 1 NX EX 30
  → Worker #1: NX succeeds → lock acquired → renders page → stores in Redis → releases lock
  → Workers #2–2000: NX fails → enters PigCache_Html_Cache::wait_for_cache()
        for ($i = 0; $i < 6; $i++) {
            usleep(100_000);  // 100 ms
            $entry = wp_cache_get('f4a9...', 'pc_html');
            if ($entry) { self::send_cached_response($entry); exit(); }
        }
```

Worker #1 finishes in ~600 ms. Workers #2–2000 poll every 100 ms; by the 6th poll they all get the cached entry and exit in 3 ms. MySQL handles 1 render instead of 2 000.

---

## Invalidation: Free vs Pro

Invalidation is handled by `PigCache_Invalidation` (`includes/class-pigcache-invalidation.php`), which hooks into WordPress core events:

```php
// PigCache_Invalidation::init()
add_action( 'save_post',        [ __CLASS__, 'on_save_post' ] );
add_action( 'deleted_post',     [ __CLASS__, 'on_delete_post' ] );
add_action( 'updated_postmeta', [ __CLASS__, 'on_post_meta_updated' ], 10, 3 );
add_action( 'edited_term',      [ __CLASS__, 'on_term_edited' ], 10, 3 );
add_action( 'comment_approved_to_spam', [ __CLASS__, 'on_comment_status' ], 10, 2 );
add_action( 'wp_update_nav_menu', [ __CLASS__, 'on_nav_menu_updated' ] );
```

### Free — `PigCache_Invalidation::global_flush()`

```php
private static function global_flush() {
    PigCache_Html_Cache::flush_all();    // wp_cache_flush_group('pc_html')
    PigCache_Fragments::flush_all();     // wp_cache_flush_group('pc_frag')
}
```

`wp_cache_flush_group('pc_html')` increments the `pc_html` group version counter in Redis. All existing HTML cache keys become unreachable. They expire naturally (TTL). No `SCAN` or `DEL` loop needed.

**Effect:** Every cached page (potentially thousands) is invalidated on any content save.

### Pro — `PigCache_Invalidation::purge_by_tags()`

```php
// PigCache_Invalidation::on_save_post( $post_id, $post )
private static function purge_by_tags( array $tags ) {
    // PigCache_Tag_Index::purge_by_tags()
    foreach ( $tags as $tag ) {
        $members = wp_cache_get( $tag, 'pc_tagidx' );
        // Redis GET wp:1:pc_tagidx:{tag}  → ['pc_html:b7e2a1f...', 'pc_frag:product_attrs_88:...']
        if ( ! $members ) continue;
        foreach ( $members as $cache_key ) {
            // Parse group and key from the stored string, then:
            wp_cache_delete( $parsed_key, $parsed_group );
            // Redis DEL wp:1:{group}:{key}
        }
        wp_cache_delete( $tag, 'pc_tagidx' );  // Clean up the tag index entry
    }
}
```

**Effect:** Only pages that contained `post:88` in their tag set are dropped. 4 999 other product pages remain cached.

| WordPress event | Tag purged (Pro) | Result |
|---|---|---|
| Post #88 saved | `post:88`, `product:88` | Only /product/red-sneakers/ dropped |
| Term #14 edited | `term:14` | Only pages showing category #14 dropped |
| Comment on post #88 approved | `post:88` | Only post #88 page dropped |
| Menu updated | `nav_menu` | Only pages with cached nav menu fragment dropped |

---

## Useful constants (in `wp-config.php`)

```php
// Exclude these URL patterns from HTML caching
define( 'PIGCACHE_HTML_EXCLUDE', [
    '/checkout/',
    '/my-account/',
    '/cart/',
    '/api/',
    '/wp-json/',
] );

// Cache TTL in seconds (default: 3600)
define( 'PIGCACHE_HTML_TTL', 7200 );

// Cache pages with query strings (default: false)
// Useful for pages where ?lang= or ?currency= changes content
define( 'PIGCACHE_HTML_CACHE_QUERY_STRINGS', false );

// Vary cache by cookie (e.g. currency selector)
define( 'PIGCACHE_HTML_VARY_COOKIES', ['store_currency'] );
```

When `PIGCACHE_HTML_VARY_COOKIES` is set, `PigCache_Html_Cache::build_cache_key()` appends the cookie value to the hash input — each currency gets its own cache entry.
