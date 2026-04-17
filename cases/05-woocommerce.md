# End-to-end example — WooCommerce store

This walkthrough traces a single product page request through all four PigCache layers on a real WooCommerce store. Numbers are representative of a mid-size store (5 000 products, 500 req/min peak).

---

## Store configuration

- WordPress 6.7, WooCommerce 9.x
- 5 000 products, 200 categories, 80 tags
- Average product page: 48 MySQL queries, 820 ms uncached TTFB
- Redis: 512 MB dedicated instance

---

## Request 1: cold cache (first visitor to `/product/red-sneakers/`)

```
advanced-cache.php loaded (before WordPress boots)
  └── HTML cache key: pc_html:b7e2a1f...
      └── Redis GET → (nil)   ← MISS

WordPress boots, theme renders:

  wp_cache_get(88, 'posts')           ← Object cache: MISS
    └── SELECT * FROM wp_posts WHERE ID = 88
        └── SQL cache: MISS (no epoch key yet)
        └── MySQL executes (12 ms) → stores in SQL cache
        └── stores in object cache

  wp_cache_get('post_meta_88', ...)   ← Object cache: MISS
    └── SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 88
        └── SQL cache: MISS → MySQL (8 ms) → stored

  wc_get_product_terms(88, ...)       ← 3 term queries: MISS each
    └── MySQL (18 ms total) → stored in SQL + object cache

  pigcache_fragment('product_attrs_88', ...)   ← Fragment: MISS
    └── woocommerce_product_attributes() runs → 22 attribute queries
    └── Fragment HTML stored in Redis (pc_frag:product_attrs_88:...)

  [remaining 20 queries for reviews, related, upsells, shipping classes...]
    └── SQL cache populated for all

  Full HTML assembled → ob_end_flush()
    └── HTML cache stores gzipped page (pc_html:b7e2a1f...) → 18 KB

Total TTFB: 820 ms
```

---

## Request 2: warm cache (same URL, second visitor, 30 seconds later)

```
advanced-cache.php loaded
  └── Redis GET pc_html:b7e2a1f... → 18 KB gzip   ← HIT

Headers sent (Content-Encoding: gzip, ETag: "b7e2a1f", Cache-Control: public, max-age=3600)
Response body streamed from Redis.
PHP exits. WordPress never boots. MySQL never queried.

Total TTFB: 3 ms
Improvement: 273× faster
```

---

## 500 concurrent visitors (peak hour)

```
┌──────────────────────────────────────────────────────────────────┐
│  HTML cache HIT rate: 97%                                         │
│  → 485 requests served in 3–5 ms each from Redis                  │
│                                                                   │
│  HTML cache MISS: 15 requests (new URLs or just-invalidated)      │
│  → Object cache hit rate: 94%  → 2–4 DB queries per page         │
│  → SQL cache hit rate:    91%  → ~22 queries bypass MySQL         │
│  → Fragment cache:        100% (product_attrs_* all warm)         │
│                                                                   │
│  MySQL query rate:  ~60 q/s  (vs ~24 000 q/s without any cache)  │
│  Redis GET rate:    ~4 800 ops/s                                  │
│  PHP processes active: 18    (vs 500 without HTML cache)          │
└──────────────────────────────────────────────────────────────────┘
```

---

## An order is placed (invalidation scenario)

A customer purchases product #88 (red sneakers). WooCommerce:
1. Decrements stock: `UPDATE wp_postmeta SET meta_value = 4 WHERE post_id = 88 AND meta_key = '_stock'`
2. Creates order post: `INSERT INTO wp_posts ...`
3. Fires `woocommerce_reduce_order_stock` action

### Free plan behavior

```
PigCache_Invalidation::on_post_meta_updated( 88, '_stock', 4 )
  └── can_use_tag_invalidation() → false
  └── global_flush()
      ├── PigCache_Html_Cache::flush_all()    → wp_cache_flush_group('pc_html')
      └── PigCache_Fragments::flush_all()     → wp_cache_flush_group('pc_frag')

Result: ALL 5 000 product pages dropped from HTML cache.
        ALL fragments dropped.
        Object cache and SQL cache cleared by their own epoch bumps.
        Next 5 000 unique visitors each pay the 820 ms render cost.
```

### Pro plan behavior

```
PigCache_Invalidation::on_post_meta_updated( 88, '_stock', 4 )
  └── can_use_tag_invalidation() → true
  └── purge_by_tags( ['product:88', 'post:88'] )
      └── Redis SMEMBERS pc_tagidx:product:88
          → ['pc_html:b7e2a1f...', 'pc_frag:product_attrs_88:...']
      └── Redis DEL pc_html:b7e2a1f...
      └── Redis DEL pc_frag:product_attrs_88:...

SQL cache for wp_postmeta epoch bumped:
  pc_epoch:wp_postmeta = 8  (was 7)
  → only queries touching wp_postmeta need to re-run

Result: ONLY /product/red-sneakers/ dropped from HTML cache.
        4 999 other product pages remain cached and served in 3 ms.
        Stock meta re-queried on next visit to that product only.
```

---

## SQL Profiler view (Pro) after one week of traffic

The cloud dashboard compiles fingerprints from all contributing sites running the same plugin/theme environment:

```
Top slow fingerprints (avg execution time, all sites):

#1  SELECT p.*, pm.* FROM wp_posts p
    JOIN wp_postmeta pm ON pm.post_id = p.ID
    WHERE p.post_type = ? AND p.post_status = ?
    AND pm.meta_key = ?
    ORDER BY pm.meta_value+0 DESC
    LIMIT ?
    ──────────────────────────────────────────────────────
    avg: 340 ms | total hits: 1 200 000 | sites: 12
    tables: wp_posts, wp_postmeta
    templates: shop.php, archive-product.php
    recommendation: add index on (post_type, post_status) + meta_key lookup

#2  SELECT t.term_id, t.name, t.slug, tt.count
    FROM wp_terms t JOIN wp_term_taxonomy tt ...
    WHERE tt.taxonomy = ? AND tt.parent = ?
    ──────────────────────────────────────────────────────
    avg: 180 ms | total hits: 800 000 | sites: 12
    recommendation: this query is SQL-cached with 91% hit rate; acceptable
```

The profiler surfaces query #1 as a candidate for a MySQL index. Adding the index on the actual server (not PigCache-managed) drops query time from 340 ms to 12 ms and also improves SQL cache miss cost.

---

## Summary: what each layer contributed

| Layer | Requests served | TTFB saved | MySQL load reduction |
|---|---|---|---|
| HTML cache | 97% of all requests | 817 ms / request | 97% |
| Object cache | 94% of WP boot queries | 60–150 ms / page | — |
| SQL cache | 91% of remaining DB queries | 20–80 ms / page | 91% of query time |
| Fragment cache | 100% of attribute tables | 40–80 ms / page | eliminating ~22 queries/page |

**Combined (Pro, peak hour):** average TTFB across all requests = **12 ms** (from 820 ms cold). MySQL query rate = **60 q/s** (from theoretical 24 000 q/s).
