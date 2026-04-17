# Layer 3 — SQL Cache (WPDB query cache)

## What it is and how it loads

The SQL cache intercepts every database query that goes through WPDB and stores the result set in Redis. On subsequent identical (or structurally identical) queries the result set is returned from Redis without touching MySQL.

This layer is active **even on pages that cannot be HTML-cached** (logged-in users, AJAX, REST API), making it uniquely valuable for authenticated and dynamic sites.

### How the drop-in loads

When "SQL Cache" is enabled, PigCache copies `includes/db.php` to `wp-content/db.php`.

WordPress core in `wp-settings.php` (around line 65):
```php
if ( file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
    require_once WP_CONTENT_DIR . '/db.php';
}
```

This runs **before** `$wpdb` is instantiated. `db.php` defines `PigCache_Wpdb extends wpdb` and replaces the global:

```php
// wp-content/db.php
require_once WP_CONTENT_DIR . '/plugins/pigcache/includes/class-pigcache-sql-cache.php';
require_once WP_CONTENT_DIR . '/plugins/pigcache/includes/class-pigcache-query-normalizer.php';

class PigCache_Wpdb extends wpdb {

    public function query( $query ) {
        // Every SQL query — SELECT, INSERT, UPDATE, DELETE — passes through here.
        return $this->pigcache_query( $query );
    }

    private function pigcache_query( $query ) {
        $type = PigCache_Sql_Cache::query_type( $query );
        // Classifies as READ or WRITE by checking first keyword

        if ( 'READ' === $type && ! $this->pigcache_no_cache ) {
            $result = PigCache_Sql_Cache::get( $query );
            if ( false !== $result ) {
                $this->last_result = $result;
                $this->num_rows    = count( $result );
                return count( $result );   // Cache HIT — parent::query() never called
            }
        }

        $return = parent::query( $query );   // Actual MySQL execution

        if ( 'WRITE' === $type ) {
            PigCache_Sql_Cache::bump_epochs( $query );
            // Parses tables touched by the write and increments their epoch counters
        } elseif ( 'READ' === $type && ! $this->pigcache_no_cache ) {
            PigCache_Sql_Cache::set( $query, $this->last_result );
            // (Pro) PigCache_Sql_Profiler::record( $query, $elapsed_ms )
        }

        return $return;
    }
}

$wpdb = new PigCache_Wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
```

After this file runs, the global `$wpdb` is a `PigCache_Wpdb` instance. Every call to `$wpdb->get_results()`, `$wpdb->get_row()`, `$wpdb->get_var()`, `$wpdb->query()` transparently goes through the cache layer.

---

## How queries are matched: fingerprinting

Two queries are the same cache entry if they share the same **fingerprint** — the structural form of the SQL with all literal values replaced by `?`.

**File:** `includes/class-pigcache-query-normalizer.php`  
**Class:** `PigCache_Query_Normalizer`  
**Method:** `PigCache_Query_Normalizer::normalize( $sql ): string`

```php
// Input:
"SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 42 AND meta_key = '_price'"
"SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 99 AND meta_key = '_stock'"

// After PigCache_Query_Normalizer::normalize():
"SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key = ?"

// Fingerprint hash:
md5("SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key = ?")
→ "a8f3c2e1..."
```

The normalizer:
1. Strips leading/trailing whitespace and collapses internal whitespace
2. Replaces all integer, float, and quoted string literals with `?`
3. Replaces `IN (1, 2, 3)` with `IN (?)`
4. Normalizes `NULL` literals
5. Lowercases SQL keywords, leaves table/column names as-is

This means WordPress's `$wpdb->prepare()` call doesn't matter — the normalized forms of all these are identical:
```sql
WHERE ID = 42          → WHERE ID = ?
WHERE ID = '42'        → WHERE ID = ?
WHERE ID IN (42)       → WHERE ID IN (?)
```

---

## Epoch-based invalidation

Instead of tracking individual cache keys per write, PigCache uses **table epoch counters** stored in Redis. The epoch for a table is an integer that increments every time any write touches that table.

**Class:** `PigCache_Sql_Cache`  
**Method:** `PigCache_Sql_Cache::build_cache_key( $query ): string`

```
Redis epoch counters (example):
  wp:1:pc_epochs:wp_posts      = 14
  wp:1:pc_epochs:wp_postmeta   = 7
  wp:1:pc_epochs:wp_options    = 3
  wp:1:pc_epochs:wp_comments   = 22
```

### Free — global epoch

One single epoch key covers all tables:

```
Redis:
  wp:1:pc_epochs:__global__ = 41

Cache key for any query:
  pc_sql:{fingerprint_hash}:g41

  e.g. pc_sql:a8f3c2e1...:g41
```

`PigCache_Sql_Cache::bump_epochs( $query )` on any write:
```php
// Free path
wp_cache_incr( '__global__', 1, 'pc_epochs' );
// Redis INCR wp:1:pc_epochs:__global__   → 42
```
All existing `pc_sql:*:g41` keys are now unreachable (the new key would be `g42`).

### Pro — per-table epochs

Each table has its own counter. The cache key encodes the epoch of every table the query touches:

```
Query touches wp_postmeta only:
  pc_sql:a8f3c2e1...:wp_postmeta=7

Query touches wp_posts + wp_postmeta:
  pc_sql:d4b7f1a2...:wp_posts=14:wp_postmeta=7
```

`PigCache_Sql_Cache::bump_epochs( $query )` on a write:
```php
// Pro path — PigCache_Query_Normalizer::extract_tables($query) returns ['wp_postmeta']
foreach ( $affected_tables as $table ) {
    wp_cache_incr( $table, 1, 'pc_epochs' );
    // Redis INCR wp:1:pc_epochs:wp_postmeta → 8
}
// Only keys containing ':wp_postmeta=7' are now stale.
// Keys for wp_posts (epoch 14) are untouched.
```

---

## Example A — Post meta query (global vs per-table)

```sql
SELECT meta_value FROM wp_postmeta WHERE post_id = 42 AND meta_key = '_thumbnail_id'
```

**Free (global epoch = 41):**
```
Cache key: pc_sql:fp_hash...:g41
Stored in Redis.

→ A visitor posts a comment (INSERT into wp_comments):
  PigCache_Sql_Cache::bump_epochs() → INCR __global__ → 42
  All SQL cache entries stale. Hit rate drops to 0% until keys rebuild.
```

**Pro (per-table epochs):**
```
Cache key: pc_sql:fp_hash...:wp_postmeta=7
Stored in Redis.

→ A visitor posts a comment (INSERT into wp_comments):
  PigCache_Sql_Cache::bump_epochs() → INCR wp:1:pc_epochs:wp_comments → 23
  wp_postmeta epoch unchanged (still 7).
  Cache key 'pc_sql:fp_hash...:wp_postmeta=7' is still valid.
  Hit rate maintained.
```

---

## Example B — Navigation menu query (every page, rarely changes)

WordPress renders navigation menus with a multi-table JOIN:

```sql
SELECT t.*, tt.*, tr.object_id
FROM   wp_terms AS t
  INNER JOIN wp_term_taxonomy AS tt
    ON t.term_id = tt.term_id
  INNER JOIN wp_term_relationships AS tr
    ON tr.term_taxonomy_id = tt.term_taxonomy_id
WHERE  tt.taxonomy IN ('nav_menu')
  AND  tr.object_id IN (1)
ORDER  BY t.name ASC
```

**Fingerprint (after `PigCache_Query_Normalizer::normalize()`):**
```sql
SELECT t.*, tt.*, tr.object_id
FROM   wp_terms AS t
  INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id
  INNER JOIN wp_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
WHERE  tt.taxonomy IN (?)
  AND  tr.object_id IN (?)
ORDER  BY t.name ASC
```

**Cache key (Pro):** `pc_sql:{fp}:wp_terms=3:wp_term_taxonomy=5:wp_term_relationships=9`

This query fires on every page load (via `wp_nav_menu()`). Cold: 40 ms (JOIN across three tables). Cached: 0.4 ms.

**Invalidation (Pro):** `wp_update_nav_menu()` → triggers `INSERT/UPDATE` to `wp_term_relationships` → `PigCache_Sql_Cache::bump_epochs(['wp_term_relationships'])` → `INCR wp:1:pc_epochs:wp_term_relationships` → key `:wp_term_relationships=9` becomes stale. Rebuilt on next request (40 ms), cached again.

---

## Example C — WooCommerce product page (48 queries → near zero)

A product page fires dozens of queries. With SQL cache (Pro), the epoch structure keeps them valid across order activity:

```
Query type                   | Tables touched          | Invalidated by
─────────────────────────────┼─────────────────────────┼──────────────────────────
Product post data            | wp_posts                | Order creation (bumps wp_posts)
Product meta (_price, _sku)  | wp_postmeta             | Product update only
Product attributes (terms)   | wp_terms, wp_term_tax   | Term edit only
Product images (attachment)  | wp_posts, wp_postmeta   | Media update only
Product reviews (comments)   | wp_comments             | New review only
Related products WP_Query    | wp_posts, wp_postmeta   | Product update only
Navigation menu              | wp_terms, wp_term_*     | Menu update only
Site options                 | wp_options              | Option update only
```

An order is placed (INSERT wp_posts + wp_postmeta + wp_woocommerce_order_items):
- Bumps: `wp_posts` epoch → invalidates product post + related product queries
- Bumps: `wp_postmeta` epoch → invalidates product meta queries
- `wp_term_*` untouched → attribute, category, tag queries remain cached
- `wp_options` untouched → options queries remain cached

**Net result (Pro):** ~30 of the 48 queries remain cached. MySQL sees ~18 queries per render, not 48.

---

## Example D — AJAX/REST endpoint for logged-in users

A membership plugin shows a user's recent course completions via AJAX. HTML cache is bypassed (logged-in), but SQL cache still works:

```php
// REST endpoint — fires every 30 seconds per user
add_action( 'rest_api_init', function () {
    register_rest_route( 'my-plugin/v1', '/progress', [
        'callback' => function ( $request ) {
            global $wpdb;
            // This query runs for every AJAX poll from every logged-in user
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT course_id, completed_at, score
                 FROM {$wpdb->prefix}course_progress
                 WHERE user_id = %d
                 ORDER BY completed_at DESC
                 LIMIT 20",
                get_current_user_id()
            ) );
            // PigCache_Wpdb::get_results() → PigCache_Sql_Cache::get()
            // Fingerprint: SELECT course_id, completed_at, score FROM wp_course_progress
            //              WHERE user_id = ? ORDER BY completed_at DESC LIMIT ?
            // Cache key: pc_sql:{fp}:wp_course_progress=12
        }
    ] );
} );
```

With 500 concurrent members polling every 30 s:
- **Without SQL cache:** 500 queries/30 s = ~17 MySQL queries/s on a table scan
- **With SQL cache (per-user key variant):** 500 individual cached results; each user's record is rebuilt only when `wp_course_progress` epoch bumps (i.e., when a course is completed). 499 of 500 polls are served from Redis.

> **Note on per-user queries:** The query above normalizes to the same fingerprint for all users, but the literal user ID is stripped by the normalizer — meaning all users share the same cache entry. If per-user data must differ, prefix the fragment key with the user ID and use `pigcache_fragment()` instead (see layer 4). The SQL cache is most effective for shared/global result sets.

---

## Bypassing the SQL cache

```php
// For the duration of this query only:
$wpdb->pigcache_no_cache = true;
$fresh = $wpdb->get_results( 'SELECT ... FROM wp_stock WHERE ...' );
$wpdb->pigcache_no_cache = false;

// Using inline SQL comment annotation (Pro):
$wpdb->get_results( 'SELECT /*pigcache:no-cache*/ * FROM wp_stock WHERE ...' );
// PigCache_Query_Normalizer detects the marker and skips cache + storage.
```

Write queries (`INSERT`, `UPDATE`, `DELETE`, `REPLACE`, `TRUNCATE`) are **never** cached automatically — `PigCache_Sql_Cache::query_type()` classifies them as WRITE and skips the cache read path entirely.

---

## SQL Profiler (Pro only)

**Files:** `includes/class-pigcache-sql-profiler.php` (`PigCache_Sql_Profiler`) + `includes/class-pigcache-sql-profile-store.php` (`PigCache_Sql_Profile_Store`)

When profiling is enabled and `PigCache_License::can_use_profiler()` returns `true`, `PigCache_Wpdb::pigcache_query()` records timing data after each query:

```php
// After parent::query($query) returns:
PigCache_Sql_Profiler::record( $query, $elapsed_ms, $num_rows );
// Stores: fingerprint → [total_time, hit_count, avg_rows, last_seen]
// in a local PHP accumulator for the current request.

// On shutdown (add_action('shutdown', [PigCache_Sql_Profiler::class, 'flush'])):
PigCache_Sql_Profiler::flush()
  └── PigCache_Cloud_Sync::push_fingerprints( $accumulated_data )
        // HTTP POST to PigCache API: /api/v1/fingerprints
        // Payload: normalized queries + timings + current environment hash
```

The API (`app/Services/ProfileAggregator.php`) aggregates fingerprints across all contributing sites sharing the same environment hash (same plugin set + WP version + theme). Compiled profiles are returned via `/api/v1/profile` and cached locally by `PigCache_Sql_Profile_Store`.

See [05-woocommerce.md](05-woocommerce.md) for a full profiling output example.
