# PigCache — Cache Layers: Concrete Examples

PigCache provides four independent cache layers that stack on top of each other. Each layer targets a different bottleneck. On a typical WordPress + WooCommerce site all four fire on every request.

| Layer | What it stores | Redis key pattern | Free invalidation | Pro invalidation |
|---|---|---|---|---|
| **Object cache** | Any `wp_cache_*` value | `wp:{blog}:{group}:{key}` | Per-group (WP core) | Per-group (WP core) |
| **HTML cache** | Full rendered page (gzipped) | `pc_html:{url_hash}` | Global flush | Tag-based |
| **SQL cache** | WPDB query result sets | `pc_sql:{fingerprint}:{epoch}` | Global epoch bump | Per-table epoch bump |
| **Fragment cache** | Partial template output | `pc_frag:{key}:{ttl-bucket}` | Global flush | Tag-based |

## Quickstart reading order

1. [01-object-cache.md](01-object-cache.md) — the foundation every WordPress site benefits from immediately
2. [02-html-cache.md](02-html-cache.md) — full-page cache; biggest latency win for public pages
3. [03-sql-cache.md](03-sql-cache.md) — query-level cache with smart per-table invalidation (Pro)
4. [04-fragment-cache.md](04-fragment-cache.md) — partial page caching for logged-in or dynamic sections
5. [05-woocommerce.md](05-woocommerce.md) — end-to-end walkthrough combining all four layers

## How layers interact

```
Browser request
    │
    ▼
[HTML cache hit?] ──YES──▶ Return gzipped page (no PHP, no MySQL)
    │ NO
    ▼
WordPress boots, runs theme
    │
    ├─▶ wp_cache_get('post_42', 'posts')  ←── [Object cache hit?]
    │        │ MISS → MySQL query
    │        │   └─▶ [SQL cache hit?]  ←── fingerprint epoch check
    │        │            │ MISS → real query → store in SQL cache
    │        │            ▼
    │        └─▶ store in object cache
    │
    ├─▶ pigcache_fragment('sidebar', fn() { ... })  ←── [Fragment cache hit?]
    │
    └─▶ Full HTML assembled → store in HTML cache
```
