# End-to-end example — WooCommerce store

This walkthrough traces a single product page request through all four PigCache layers. Every step names the exact file, class, and method involved.

---

## Store configuration

- WordPress 6.7, WooCommerce 9.x
- 5 000 products, 200 categories, 80 attribute tags
- Average product page: 48 MySQL queries uncached, 820 ms TTFB
- Redis: 512 MB dedicated instance (`REDIS_HOST=redis`, `REDIS_PORT=6379`)
- PigCache Pro, all layers enabled

---

## Boot sequence (what loads before the first line of theme code)

```
HTTP GET /product/red-sneakers/ HTTP/1.1

index.php
  └── wp-blog-header.php
        └── wp-load.php
              └── wp-config.php  (user's site config — defines DB credentials, WP_CACHE=true)
              └── wp-settings.php
                    │
                    ├── [line ~65] require 'wp-content/db.php'
                    │       └── require 'pigcache/includes/class-pigcache-sql-cache.php'
                    │       └── require 'pigcache/includes/class-pigcache-query-normalizer.php'
                    │       └── $wpdb = new PigCache_Wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST)
                    │
                    ├── [line ~80] require 'wp-content/object-cache.php'
                    │       └── $wp_object_cache = new PigCache_Object_Cache()
                    │               └── $this->redis = new Redis()
                    │               └── $this->redis->connect('redis', 6379)
                    │
                    ├── [line ~98] require 'wp-content/advanced-cache.php'  (WP_CACHE=true)
                    │       └── require 'pigcache/includes/class-pigcache-html-cache.php'
                    │       └── PigCache_Html_Cache::serve()
                    │               └── PigCache_Html_Cache::is_cacheable_request() → true
                    │               └── PigCache_Html_Cache::build_cache_key($_SERVER)
                    │                     → md5('https://shop.example.com/product/red-sneakers/')
                    │                     → 'b7e2a1f3c9d8e6a2'
                    │               └── wp_cache_get('b7e2a1f3...', 'pc_html')
                    │                     → Redis GET wp:1:pc_html:b7e2a1f3c9d8e6a2
                    │                     → (nil)  ← MISS (cold cache)
                    │               └── PigCache_Html_Cache::acquire_stampede_lock('b7e2a1f3...')
                    │                     → Redis SET pc_lock:b7e2a1f3... 1 NX EX 30 → OK
                    │               └── (continues — does not exit)
                    │
                    ├── All plugins load (mu-plugins, then active plugins)
                    │       └── pigcache.php — PigCache::init()
                    │               ├── PigCache_Config::init()
                    │               ├── PigCache_Html_Cache::init()
                    │               │     └── add_action('template_redirect', [self,'start_buffer'], 0)
                    │               ├── PigCache_Invalidation::init()
                    │               │     └── add_action('save_post', ...)
                    │               │     └── add_action('updated_postmeta', ...)
                    │               │     └── add_action('edited_term', ...)
                    │               │     └── ... (all WP write hooks)
                    │               └── PigCache_Tag_Collector::start()  (Pro)
                    │                     └── add_action('the_post',    [self,'collect_post'])
                    │                     └── add_action('get_term',    [self,'collect_term'])
                    │                     └── add_action('get_post',    [self,'collect_post'])
                    │
                    └── Theme loads, WP_Query runs, template renders...
```

---

## Request 1 — Cold cache: full render + cache population

### Step 1: Output buffer starts

```
do_action('template_redirect')
  └── PigCache_Html_Cache::start_buffer()
        └── ob_start( ['PigCache_Html_Cache', 'buffer_callback'] )
            // All output from this point will be captured
```

### Step 2: WordPress resolves the product post

```
WP_Query::get_posts()
  └── PigCache_Wpdb::get_results(
        "SELECT wp_posts.* FROM wp_posts WHERE … post_name = 'red-sneakers'"
      )
        └── PigCache_Sql_Cache::query_type($sql) → 'READ'
        └── PigCache_Query_Normalizer::normalize($sql)
              → "SELECT wp_posts.* FROM wp_posts WHERE … post_name = ?"
        └── PigCache_Sql_Cache::build_cache_key($normalized, ['wp_posts'])
              → "pc_sql:{fp_hash}:wp_posts=14"
        └── wp_cache_get("pc_sql:{fp}:wp_posts=14", 'pc_sqlc') → false  MISS
        └── parent::query($sql)  ← actual MySQL, 8 ms
        └── PigCache_Sql_Cache::set("pc_sql:{fp}:wp_posts=14", $results, TTL=120)
              → wp_cache_set(…) → Redis SET … EX 120

  └── WP_Post::get_instance(88)
        └── wp_cache_get(88, 'posts') → false  MISS
        └── PigCache_Wpdb::get_row("SELECT * FROM wp_posts WHERE ID = 88")
              → PigCache_Sql_Cache: MISS → MySQL (4 ms) → cached
        └── wp_cache_set(88, $post, 'posts')
              → Redis SET wp:1:posts:88 serialize($post)

  └── PigCache_Tag_Collector::collect_post($post)  (Pro)
        // Adds 'post:88' and 'product:88' to the in-memory tag accumulator
        └── $this->tags[] = 'post:88'
        └── $this->tags[] = 'product:88'
```

### Step 3: Product meta loaded (WooCommerce)

```
WC_Product_Data_Store_CPT::read($product)
  └── update_meta_cache('post', [88])  (WordPress core)
        └── wp_cache_get('post_meta_88', 'post_meta') → false  MISS
        └── PigCache_Wpdb::get_results(
              "SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN (88)"
            )
              → PigCache_Sql_Cache: MISS (first request)
              → MySQL: 6 ms, returns 45 meta rows
              → PigCache_Sql_Cache::set(key, $rows, 120)
        └── wp_cache_set('post_meta_88', $meta, 'post_meta')
              → Redis SET wp:1:post_meta:post_meta_88 …

  // Product price, stock, SKU, attributes — all come from the cached meta blob now
  WC_Product::get_price()     → wp_cache_get('post_meta_88', 'post_meta') → HIT
  WC_Product::get_stock()     → HIT
  WC_Product::get_sku()       → HIT
  // 0 additional MySQL queries for product meta after first load
```

### Step 4: Product categories and tags (term queries)

```
WC_Product::get_category_ids()
  └── wp_get_post_terms(88, 'product_cat')
        └── wp_cache_get('get_terms:...hash...', 'terms') → false  MISS
        └── PigCache_Wpdb::get_results("SELECT t.*, tt.* FROM wp_terms t
              JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
              JOIN wp_term_relationships tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
              WHERE tr.object_id IN (88) AND tt.taxonomy = 'product_cat'")
              → PigCache_Sql_Cache: MISS
              → MySQL: 12 ms → cached with key pc_sql:{fp}:wp_terms=3:wp_term_taxonomy=5:wp_term_relationships=9

  └── PigCache_Tag_Collector::collect_term($term)  (Pro)
        // Adds 'term:14' (Sneakers category) to tag accumulator
        └── $this->tags[] = 'term:14'
```

### Step 5: Fragment cache — product attributes table

```
// Template: woocommerce/single-product/tabs/attributes.php (child theme override)
pigcache_fragment(
    'product_attrs_88',
    fn() => woocommerce_product_attributes(),
    3600,
    ['product:88', 'terms']
)

PigCache_Fragments::render('product_attrs_88', $generator, 3600, ['product:88','terms'])
  └── wp_cache_get('product_attrs_88', 'pc_frag') → false  MISS
  └── ob_start()
  └── woocommerce_product_attributes()
        └── WC_Product::get_attributes()
              → Multiple get_terms() calls for each attribute group
              → PigCache_Sql_Cache handles each query (MISSes on first request, cached)
              → 22 attribute queries, ~40 ms total
  └── $html = ob_get_clean()   ← full attributes table HTML
  └── wp_cache_set('product_attrs_88', $html, 'pc_frag', 3600)
        → Redis SET wp:1:pc_frag:product_attrs_88 <html> EX 3600
  └── PigCache_Tag_Index::link_all(['product:88','terms'], 'pc_frag:product_attrs_88')  (Pro)
        → Redis SADD wp:1:pc_tagidx:product:88   "pc_frag:product_attrs_88"
        → Redis SADD wp:1:pc_tagidx:terms        "pc_frag:product_attrs_88"
  └── echo $html
```

### Step 6: Navigation menu (SQL cache saves this)

```
wp_nav_menu(['theme_location' => 'primary'])
  └── wp_get_nav_menu_items($menu_id)
        └── wp_cache_get($menu_id, 'nav_menu_items') → false  MISS
        └── WP_Query for nav_menu_item posts:
              PigCache_Wpdb::get_results("SELECT … FROM wp_posts WHERE post_type='nav_menu_item'…")
              → PigCache_Sql_Cache: MISS → MySQL (18 ms) → cached (wp_posts epoch)
        └── Multiple get_term() calls for menu item metadata:
              → PigCache_Sql_Cache: MISS → MySQL → cached (wp_terms epoch)
        └── wp_cache_set($menu_id, $items, 'nav_menu_items')
```

### Step 7: Output buffer closes — HTML cache stores the page

```
// PHP shutdown / ob_end_flush triggers buffer_callback
PigCache_Html_Cache::buffer_callback($html)
  └── PigCache_Html_Cache::should_cache($html)
        → HTTP 200, Content-Type text/html, length 87 340 bytes, no Set-Cookie → true
  └── gzencode($html, 6) → 22 KB (74% compression)
  └── $entry = ['body' => $compressed, 'headers' => [...], 'created' => time(), 'status' => 200]
  └── wp_cache_set('b7e2a1f3...', $entry, 'pc_html', 3600)
        → Redis SET wp:1:pc_html:b7e2a1f3c9d8e6a2 <22KB> EX 3600
  └── PigCache_Tag_Index::link_all(PigCache_Tag_Collector::get_tags(), 'pc_html:b7e2a1f3...')  (Pro)
        // PigCache_Tag_Collector::get_tags() returns: ['post:88','product:88','term:14','term:7']
        → Redis SADD wp:1:pc_tagidx:post:88      "pc_html:b7e2a1f3..."
        → Redis SADD wp:1:pc_tagidx:product:88   "pc_html:b7e2a1f3..."
        → Redis SADD wp:1:pc_tagidx:term:14      "pc_html:b7e2a1f3..."
        → Redis SADD wp:1:pc_tagidx:term:7       "pc_html:b7e2a1f3..."
  └── PigCache_Html_Cache::release_stampede_lock('b7e2a1f3...')
        → Redis DEL pc_lock:b7e2a1f3...
  └── return $html   ← sent to browser uncompressed (browser handles Content-Encoding)

Total TTFB: 820 ms
Redis memory consumed by this request: ~25 KB (HTML entry) + index sets + SQL cache entries
```

---

## Request 2 — Warm cache: same URL, 30 seconds later

```
advanced-cache.php → PigCache_Html_Cache::serve()
  └── wp_cache_get('b7e2a1f3...', 'pc_html')
        → Redis GET wp:1:pc_html:b7e2a1f3c9d8e6a2 → 22 KB  HIT

  └── PigCache_Html_Cache::send_cached_response($entry)
        status_header(200)
        header('Content-Type: text/html; charset=UTF-8')
        header('Content-Encoding: gzip')
        header('ETag: "b7e2a1f3c9d8e6a2"')
        header('X-PigCache: HIT')
        header('Cache-Control: public, max-age=3600')
        echo $entry['body']   ← 22 KB gzip, streamed from Redis

  └── exit()
        // PHP stops here.
        // $wpdb was never used.
        // No plugin ran past advanced-cache.php.
        // WooCommerce never loaded.
        // MySQL was never contacted.

TTFB: 3 ms
Improvement: 273× faster
```

---

## 500 concurrent visitors during a flash sale

```
Traffic distribution (1 hour of 500 concurrent visitors across all product pages):

 Layer            │ Requests handled  │ Hit rate │ Avg latency │ MySQL queries
──────────────────┼───────────────────┼──────────┼─────────────┼───────────────
 HTML cache       │ 485 / 500 (97%)   │ 97%      │ 3–5 ms      │ 0
 ────── (MISS) ───┼───────────────────┼──────────┼─────────────┤
 Object cache     │ 94% of WP queries │ 94%      │ < 1 ms each │ 0
 SQL cache        │ 91% of remaining  │ 91%      │ < 1 ms each │ ~5 / render
 Fragment cache   │ 100% (all warm)   │ 100%     │ < 1 ms each │ 0

 MySQL query rate:    ~60 q/s  (vs theoretical ~24 000 q/s without any cache)
 Redis GET rate:      ~4 800 ops/s
 PHP-FPM workers:     18 active (vs 500 without HTML cache)
 Redis memory used:   ~380 MB (HTML entries + SQL entries + fragment entries + object cache)
```

---

## An order is placed — invalidation walkthrough

Customer buys product #88 (red sneakers). WooCommerce:

1. Creates order post: `INSERT INTO wp_posts (post_type='shop_order', …)`
2. Creates order items: `INSERT INTO wp_woocommerce_order_items …`
3. Decrements stock: `UPDATE wp_postmeta SET meta_value=4 WHERE post_id=88 AND meta_key='_stock'`
4. Fires action: `do_action('woocommerce_reduce_order_stock', $order)`

### SQL cache invalidation (automatic, epoch-based)

```
PigCache_Wpdb::pigcache_query("INSERT INTO wp_posts …")
  └── PigCache_Sql_Cache::query_type() → 'WRITE'
  └── PigCache_Sql_Cache::bump_epochs("INSERT INTO wp_posts …")
        └── PigCache_Query_Normalizer::extract_tables($sql) → ['wp_posts']
        └── wp_cache_incr('wp_posts', 1, 'pc_epochs')
              → Redis INCR wp:1:pc_epochs:wp_posts   → 15 (was 14)
        // All SQL cache keys containing ':wp_posts=14' are now stale.
        // wp_postmeta, wp_terms epochs unchanged.

PigCache_Wpdb::pigcache_query("UPDATE wp_postmeta SET meta_value=4 WHERE …")
  └── PigCache_Sql_Cache::bump_epochs()
        → INCR wp:1:pc_epochs:wp_postmeta   → 8 (was 7)
        // All product meta queries that had ':wp_postmeta=7' are now stale.
        // Next render re-fetches fresh stock value from MySQL.
```

### HTML + fragment cache invalidation

```
PigCache_Invalidation::on_post_meta_updated(88, '_stock', 4)
  └── self::can_use_tag_invalidation() → true  (Pro license)
  └── self::purge_by_tags(['post:88', 'product:88'])

PigCache_Tag_Index::purge_by_tags(['post:88', 'product:88'])
  └── foreach ['post:88', 'product:88'] as $tag:
        wp_cache_get($tag, 'pc_tagidx')
          'post:88'    → ['pc_html:b7e2a1f3...']
          'product:88' → ['pc_html:b7e2a1f3...', 'pc_frag:product_attrs_88']

        // Deduplicated set of keys to purge:
        // {'pc_html:b7e2a1f3...', 'pc_frag:product_attrs_88'}

        wp_cache_delete('b7e2a1f3...', 'pc_html')
          → Redis DEL wp:1:pc_html:b7e2a1f3c9d8e6a2
        wp_cache_delete('product_attrs_88', 'pc_frag')
          → Redis DEL wp:1:pc_frag:product_attrs_88

        // Tag index entries cleaned up:
        wp_cache_delete('post:88', 'pc_tagidx')
        wp_cache_delete('product:88', 'pc_tagidx')
```

**Net result (Pro):**
- `/product/red-sneakers/` dropped from HTML cache ✓
- Product attributes fragment dropped ✓
- `/product/blue-jeans/`, `/product/green-hat/` … 4 999 other pages: **still cached** ✓
- Next visitor to `/product/red-sneakers/`: pays 820 ms (renders fresh with updated stock)
- All other visitors: still 3 ms

**Net result (Free):**
```
PigCache_Invalidation::on_post_meta_updated(88, '_stock', 4)
  └── can_use_tag_invalidation() → false
  └── global_flush()
        └── PigCache_Html_Cache::flush_all()
              → wp_cache_flush_group('pc_html')
              → INCR wp:1:pc_html:__version__   ← all 5 000 HTML pages stale
        └── PigCache_Fragments::flush_all()
              → wp_cache_flush_group('pc_frag')
              → INCR wp:1:pc_frag:__version__   ← all fragment entries stale
```
Next 5 000 unique product page visitors each pay 820 ms. Server load spikes.

---

## SQL Profiler output (Pro) — one week of traffic

After a week of traffic, `PigCache_Cloud_Sync::push_fingerprints()` has sent normalized query data to the PigCache API. The API's `ProfileAggregator` and `ProfileCompiler` services (Laravel, `app/Services/`) compile a ranked list.

The compiled profile is returned by `GET /api/v1/profile` (authenticated with the site's API key), stored locally by `PigCache_Sql_Profile_Store`, and displayed in the Settings → SQL Profiler tab.

```
Compiled environment profile
Environment hash: a1b2c3d4  (WooCommerce 9.x + Storefront 4.x + WP 6.7)
Contributing sites: 12
Profile generated: 2026-04-17T10:30:00Z

── Top fingerprints by total execution time ─────────────────────────────────────

Rank  #1  ─────────────────────────────────────────────────────────────────────
  Normalized:  SELECT p.*, pm.meta_value FROM wp_posts p
               JOIN wp_postmeta pm ON pm.post_id = p.ID
               WHERE p.post_type = ? AND p.post_status = ?
               AND pm.meta_key = ?
               ORDER BY pm.meta_value+0 DESC LIMIT ?

  Statistics:  avg: 340 ms  |  total hits: 1 200 000  |  sites: 12
  Tables:      wp_posts, wp_postmeta
  Templates:   shop.php, archive-product.php, woocommerce/product-searchresult.php
  SQL cache:   hit rate 76% (miss cost: 340 ms each, ~288 000 MySQL executions/week)
  Recommendation: Add composite index → CREATE INDEX pc_suggested ON wp_posts
                  (post_type, post_status) and review meta_key lookup via wp_postmeta.

Rank  #2  ─────────────────────────────────────────────────────────────────────
  Normalized:  SELECT t.term_id, t.name, t.slug, tt.count
               FROM wp_terms t
               JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
               WHERE tt.taxonomy = ? AND tt.parent = ?
               ORDER BY t.name ASC

  Statistics:  avg: 180 ms  |  total hits: 800 000  |  sites: 12
  Tables:      wp_terms, wp_term_taxonomy
  Templates:   archive-product.php, sidebar.php
  SQL cache:   hit rate 91% (miss cost: 180 ms, ~72 000 MySQL executions/week)
  Recommendation: SQL cache is effective. Consider adding fragment cache around
                  the category listing that fires this query.

Rank  #3  ─────────────────────────────────────────────────────────────────────
  Normalized:  SELECT * FROM wp_options WHERE autoload = ?

  Statistics:  avg: 65 ms   |  total hits: 4 100 000  |  sites: 12
  Tables:      wp_options
  SQL cache:   hit rate 99.8% (object cache handles 'alloptions' key for most hits)
  Recommendation: Already well-cached. Investigate why autoload is being bypassed
                  on 0.2% of requests (search for wp_load_alloptions($force_db=true)).
```

**Acting on Rank #1:** adding the composite index drops the 340 ms query to 12 ms. This reduces:
- SQL cache miss cost: 340 ms → 12 ms
- HTML cache miss cost (when that query is part of a cold page render): significant
- No PigCache configuration needed — it's a MySQL-side fix that PigCache's profiler surfaced

---

## Summary: what each layer saved (warm traffic hour)

| Layer | File / Class | Requests saved | Avg TTFB saved | MySQL queries eliminated |
|---|---|---|---|---|
| HTML cache | `advanced-cache.php` / `PigCache_Html_Cache` | 97% of all requests | 817 ms each | 100% for those requests |
| Object cache | `object-cache.php` / `PigCache_Object_Cache` | 94% of WP data lookups | 60–150 ms / page render | All WP_Post, options, terms |
| SQL cache | `db.php` / `PigCache_Wpdb` + `PigCache_Sql_Cache` | 91% of remaining queries | 20–80 ms / page render | 43 of 48 queries per cold render |
| Fragment cache | `class-pigcache-fragments.php` / `PigCache_Fragments` | 100% of attribute renders | 40 ms / cold render | ~22 term queries per product |

**Combined result (Pro, peak hour):**  
Average TTFB across all requests: **12 ms** (down from 820 ms cold).  
MySQL query rate: **~60 q/s** (down from theoretical ~24 000 q/s without cache).  
PHP workers needed to handle 500 concurrent: **18** (down from 500).
