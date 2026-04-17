# Layer 3 — SQL Cache (WPDB query cache)

The SQL cache intercepts every query that goes through WPDB and stores the result set in Redis. On subsequent identical (or structurally identical) queries the result is returned from Redis without touching MySQL.

This layer is active **even on pages that cannot be HTML-cached** (logged-in users, AJAX, REST API), making it uniquely valuable for authenticated or dynamic sites.

---

## How queries are matched

Two queries are considered the same cache entry if they produce the same **fingerprint** — a normalized form of the SQL:

```sql
-- Original query (post ID differs each time):
SELECT * FROM wp_posts WHERE ID = 42 AND post_status = 'publish'
SELECT * FROM wp_posts WHERE ID = 99 AND post_status = 'publish'

-- Both normalize to the same fingerprint:
SELECT * FROM wp_posts WHERE ID = ? AND post_status = ?
```

The cache key is: `pc_sql:{fingerprint_hash}:{epoch}` where `epoch` is a Redis counter that is bumped on table writes.

---

## Epoch-based invalidation

Instead of tracking individual query keys, PigCache uses **table epochs** — a counter per table stored in Redis:

```
Redis:
  pc_epoch:wp_posts   = 14
  pc_epoch:wp_postmeta = 7
  pc_epoch:wp_options  = 3

Cache key for a post query:
  pc_sql:{fingerprint}:e14:e7   (epochs of all tables the query touches)
```

When `UPDATE wp_posts SET ...` runs, the epoch for `wp_posts` increments to `15`. All previously cached queries touching `wp_posts` are now unreachable (their key contains `:e14:`). Redis will evict them on TTL expiry — no active deletion needed.

### Free vs Pro epoch granularity

| | Free | Pro |
|---|---|---|
| Epoch scope | **One global epoch** shared by all tables | **Per-table epochs** |
| Effect of `UPDATE wp_posts` | All SQL cache entries drop | Only entries touching `wp_posts` drop |
| Best for | Simple blogs, low write volume | WooCommerce, membership sites, high write rate |

---

## Example A — Post meta query (simple blog)

```sql
SELECT meta_value FROM wp_postmeta
WHERE post_id = 42 AND meta_key = '_thumbnail_id'
```

**Free:** epoch key = `pc_sql:{fp}:global:3`
- A new comment (INSERT into wp_comments) bumps the global epoch to 4.
- The post meta cache is now stale too (even though `wp_postmeta` wasn't touched).

**Pro:** epoch key = `pc_sql:{fp}:wp_postmeta:7`
- A new comment bumps `wp_comments` epoch. `wp_postmeta` epoch stays at 7.
- Post meta cache remains valid. Hit rate stays high.

---

## Example B — WooCommerce product data

A WooCommerce product page fires ~8 meta queries per product:

```sql
SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 88
SELECT * FROM wp_posts WHERE ID IN (101, 102, 103)   -- variations
SELECT * FROM wp_term_relationships WHERE object_id = 88
-- … etc.
```

With Pro SQL cache and a busy store (orders every few minutes):

```
INSERT INTO wp_posts (order) → bumps wp_posts epoch only
→ wp_postmeta, wp_term_relationships epochs unchanged
→ product detail pages stay cached
→ hit rate: ~92% for product queries
→ MySQL load: -85% for read queries
```

Without the SQL cache (or with Free + global epoch on a busy store):
```
Each order → global epoch bump → all SQL cache invalidated
→ hit rate: ~10% (misses on every write)
```

---

## Example C — Complex JOIN that appears on every page

Navigation menus in WordPress run a heavy JOIN on every request:

```sql
SELECT t.*, tt.*, tr.object_id
FROM wp_terms AS t
INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id
INNER JOIN wp_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
WHERE tt.taxonomy IN ('nav_menu')
  AND tr.object_id IN (1)
ORDER BY t.name ASC
```

This query almost never changes (menus are updated infrequently). With the SQL cache:
- First request: ~40 ms (cold MySQL, large JOIN)
- All other requests: 0.4 ms (Redis HASH lookup)
- Per-table epoch (`wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`) only bumps when menus are saved.

---

## Example D — AJAX / REST endpoint (no HTML cache possible)

A membership site shows a "recent activity" feed via AJAX for logged-in users. HTML cache is bypassed (logged-in), but SQL cache is not:

```php
// REST endpoint handler
function get_recent_activity( $request ) {
    // This query runs on every AJAX poll (every 30 seconds per user)
    $results = $wpdb->get_results(
        "SELECT user_id, action, created_at FROM wp_user_activity
         WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         ORDER BY created_at DESC LIMIT 20"
    );
    return $results;
}
```

With SQL cache (TTL 30 s, matching the poll interval):
- 500 concurrent users polling every 30 s = 500 req/30 s = ~17 req/s
- Without cache: 17 MySQL queries/s on a complex table scan
- With cache: 1 MySQL query / 30 s; 499 requests per window served from Redis

---

## Bypassing the SQL cache

Queries that must always be fresh can opt out:

```php
// Write queries (INSERT/UPDATE/DELETE) are never cached automatically.
// For SELECT queries that must bypass cache:
$wpdb->pigcache_no_cache = true;
$result = $wpdb->get_results( '...' );
$wpdb->pigcache_no_cache = false;
```

Or by query comment annotation (Pro):

```php
$wpdb->get_results( 'SELECT /*pigcache:no-cache*/ ...' );
```

---

## SQL Profiler (Pro only)

The SQL profiler aggregates fingerprints across all sites sharing an environment (same plugins + theme + WP version). It answers: "which query patterns are slowest across my entire fleet?"

See [05-woocommerce.md](05-woocommerce.md) for a full profiling walkthrough.
