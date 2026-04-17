# Layer 1 — Object Cache (Redis drop-in)

The object cache drop-in (`object-cache.php`) replaces WordPress's built-in in-memory cache with Redis. Every `wp_cache_get` / `wp_cache_set` call persists across requests instead of being discarded at the end of each PHP process.

This is the only cache layer that is **always active** — it needs no configuration beyond enabling Redis in PigCache settings.

---

## How WordPress uses the object cache

WordPress core calls `wp_cache_*` throughout its own code. You get Redis persistence for free without changing any plugin or theme code.

### Example A — Post object

```php
// First request: cache MISS
// WordPress does: SELECT * FROM wp_posts WHERE ID = 42
// Then immediately stores it:
wp_cache_set( 42, $post_object, 'posts', 0 );

// Second request: cache HIT — no SQL at all
$post = wp_cache_get( 42, 'posts' );
// Returns the stdClass object directly from Redis.
```

**Real impact:** A blog post page that previously ran 12 queries (options, post, meta, terms, author…) drops to 0–2 queries on warm cache.

---

### Example B — Options table

WordPress loads `wp_options` rows marked `autoload = yes` on every single request. With Redis the whole set is loaded once and cached:

```php
// wp_cache_get( 'alloptions', 'options' )
// Stores all auto-loaded options as one serialized blob.
// Saves a full table scan on every page load.
```

On a site with 400 auto-loaded options (common after installing several plugins), this alone cuts 80–150 ms off TTFB.

---

### Example C — Term / taxonomy data

Listing category archives or showing tag clouds hits term tables repeatedly:

```php
// WordPress core — simplified
$terms = wp_cache_get( $cache_key, 'terms' );
if ( false === $terms ) {
    $terms = $wpdb->get_results( $query ); // slow on large taxonomies
    wp_cache_set( $cache_key, $terms, 'terms', DAY_IN_SECONDS );
}
```

With 10 000 products and 500 categories (WooCommerce), the term query can take 200 ms. Cached: 0.3 ms.

---

### Example D — Custom plugin code

Any plugin or theme can opt into the object cache without any PigCache-specific API:

```php
function get_featured_authors() {
    $cache_key = 'featured_authors_v1';
    $authors    = wp_cache_get( $cache_key, 'my_plugin' );

    if ( false === $authors ) {
        // Expensive query joining users, meta, posts
        $authors = $wpdb->get_results( '...' );
        wp_cache_set( $cache_key, $authors, 'my_plugin', HOUR_IN_SECONDS );
    }

    return $authors;
}
```

PigCache stores `my_plugin` in its own Redis group. When the plugin calls `wp_cache_delete( $cache_key, 'my_plugin' )` on save, only that key is removed.

---

## Invalidation

| Scope | Free | Pro |
|---|---|---|
| Single key | `wp_cache_delete( $key, $group )` | same |
| Entire group | `wp_cache_flush_group( 'posts' )` | same |
| All object cache | `wp_cache_flush()` | same |

Object cache invalidation is handled entirely by WordPress core group semantics — it is the same in Free and Pro.

---

## What you see in PigCache metrics

- **Object cache hit ratio** — aim for > 95 % on established sites.
- **Memory usage** — Redis `MEMORY USAGE` per key; large serialized objects (product meta, nav menus) show up here.
- **Evictions** — if `maxmemory-policy` is `allkeys-lru` and Redis runs out of memory it starts evicting. Tune `maxmemory` in `redis.conf`.
