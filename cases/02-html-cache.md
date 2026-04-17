# Layer 2 — HTML Cache (full-page output buffer)

The HTML cache captures the complete rendered HTML of a page — before it is sent to the browser — and stores it gzipped in Redis. On subsequent requests PigCache serves the gzipped response directly, bypassing PHP, WordPress, and MySQL entirely.

This is the **biggest single latency win**: a page that takes 800 ms to render warm (object cache hits) takes 2–5 ms when HTML-cached.

---

## How it works

```
┌──────────────────────────────────────────────────────────────────────────┐
│ WordPress request lifecycle                                               │
│                                                                           │
│  wp-config.php                                                            │
│      └── advanced-cache.php  ◄── PigCache checks Redis before WP boots   │
│              │                                                            │
│              ├── HIT  → send gzipped HTML + headers → exit()             │
│              │          (PHP ends here, nothing else runs)                │
│              │                                                            │
│              └── MISS → continue loading WordPress                        │
│                            │                                              │
│                            └── ob_start() captures all output             │
│                                    │                                      │
│                                    └── shutdown: store gzip in Redis      │
└──────────────────────────────────────────────────────────────────────────┘
```

The cache key is derived from:
- Request URL (normalized — trailing slash, query strings stripped by config)
- Accept-Encoding header (separate key for gzip vs identity)
- Site ID (multisite)

---

## What gets cached (and what doesn't)

### Cached by default
- Public pages: homepage, blog archive, single posts, static pages
- Public WooCommerce pages: shop, category, product detail (when user is not logged in)
- Search results (optional, disabled by default)

### Never cached
- Any request with an active WordPress session cookie (`wordpress_logged_in_*`)
- WooCommerce cart / checkout / account pages (detected via cookie `woocommerce_items_in_cart`)
- Pages with `Cache-Control: no-store` or `private` headers set by other plugins
- POST requests, XML-RPC, REST API (handled by object cache only)
- Admin (`/wp-admin/`)
- Previews (`?preview=true`)

---

## Example A — Blog homepage

**Before PigCache HTML cache:**
```
GET / → PHP 8.3 → WordPress → 34 DB queries → render 6 post excerpts → 780 ms TTFB
```

**After (MISS — first request):**
```
GET / → advanced-cache.php → Redis MISS → full WordPress boot → 780 ms → store gzip
```

**After (HIT — all subsequent requests until invalidation):**
```
GET / → advanced-cache.php → Redis GET pc_html:a3f8... → send 18 KB gzip → exit() → 4 ms TTFB
```

The Redis key holds: gzipped HTML body + stored response headers (Content-Type, Last-Modified, ETag).

---

## Example B — WooCommerce product page

Product URL: `https://shop.example.com/product/red-sneakers/`

```
First visitor  → 650 ms (12 queries: product, meta, attributes, reviews, related…)
Second visitor → 3 ms   (Redis HIT, 0 queries, 0 PHP execution past advanced-cache.php)
```

When the product is updated in WooCommerce admin:

**Free:** `wp_cache_flush()` → **all** HTML pages are dropped (global flush). Next request for every URL re-renders.

**Pro:** PigCache stored the tag `product:88` when rendering this page. On update it calls `PigCache_Html_Cache::purge_by_tags(['product:88'])` — only the red sneakers page is dropped. All other product pages remain cached.

---

## Example C — Stampede protection

If 500 concurrent visitors hit a cold URL simultaneously, without protection all 500 spawn a PHP process that runs the full query set in parallel — "thundering herd".

PigCache uses a Redis lock:

```
Visitor 1: MISS → acquires lock → renders page → stores in Redis → releases lock
Visitors 2–500: MISS → lock held by V1 → sleep 50 ms → retry → HIT → serve from Redis
```

The lock TTL matches the page render timeout. If rendering fails the lock auto-expires.

---

## Invalidation triggers

| Event | Free | Pro |
|---|---|---|
| Post published / updated | Flush all HTML | Purge by `post:{ID}` tag |
| Post deleted | Flush all HTML | Purge by `post:{ID}` tag |
| Term saved | Flush all HTML | Purge by `term:{ID}` tag |
| Comment approved | Flush all HTML | Purge `post:{comment->post_ID}` tag |
| Plugin/theme update | Flush all HTML | Flush all HTML |
| Manual admin button | Flush all HTML | Flush all HTML |

---

## Useful constants

```php
// In wp-config.php

// Do not cache this URL pattern
define( 'PIGCACHE_HTML_EXCLUDE', [
    '/checkout/',
    '/account/',
    '/api/',
] );

// Cache TTL in seconds (default: 3600)
define( 'PIGCACHE_HTML_TTL', 7200 );

// Cache query strings (default: false — strip them)
define( 'PIGCACHE_HTML_CACHE_QUERY_STRINGS', false );
```
