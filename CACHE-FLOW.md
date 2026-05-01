# PigCache — Cache Flow Reference

Developer reference for how each cache layer works, how invalidation
differs between Free and Pro, and what happens step by step when content changes.

---

## 1. Cache layers

| Layer | Store | Key pattern | Stored data | Free invalidation | Pro invalidation |
|-------|-------|-------------|-------------|-------------------|------------------|
| **HTML** | Redis `pigcache_html` | `doc_{md5(host+uri)}` | `{html, tags[], time}` | Global flush — entire group deleted | Tag-based — only affected pages deleted |
| **SQL** | Redis `pigcache_sql` | `sql_{md5(query)}` | `{last_result, num_rows, return_val, epoch\|table_epochs}` | Global epoch bump | Global epoch (fallback) or per-table epoch (with profiler) |
| **Fragments** | Redis `pigcache_fragments` | `f_{md5(key)}` | Arbitrary callback output | Global flush — entire group deleted | Tag-based if tags provided; otherwise TTL only |
| **Object cache** | Redis (core WP groups) | `{prefix}{blog}:{group}:{key}` | Whatever WordPress stores | WP core manages — PigCache does not touch | Same |
| **Query buffer** *(Pro)* | APCu or Redis `pigcache_qbuf:*` | `pigcache_qbuf:{hash}` | Per-request query aggregates | — | Drained every 15 min by cron → MySQL |
| **Query stats** *(Pro)* | MySQL `wp_pigcache_query_stats` | `(query_hash, period_start)` | Aggregated analytics per 15-min window | — | Pruned after 30 days |

---

## 2. Free vs Pro — the fundamental difference

### HTML and Fragment cache

```
FREE BUILD
  save_post fires
    └─ global_flush()
         ├─ wp_cache_flush_group('pigcache_html')     ← ALL pages gone
         └─ wp_cache_flush_group('pigcache_fragments') ← ALL fragments gone
    └─ bump_sql_epoch()                                ← all SQL stale

PRO BUILD
  save_post fires
    └─ resolve_post_tags(post_id)
         returns ["post:123","term:10","home","feed",...]
    └─ PigCache_Tag_Index::purge_by_tags(tags)
         SELECT keys FROM wp_pigcache_tags WHERE tag IN (...)
         wp_cache_delete() only those keys
         DELETE FROM wp_pigcache_tags WHERE cache_key IN (...)
    └─ bump_sql_epoch()                                ← all SQL stale
```

`PigCache_Tag_Collector` and `PigCache_Tag_Index` are Pro-only classes.
They are absent from the WordPress.org Free ZIP entirely.

### SQL cache

Both Free and Pro cache SELECT results in Redis. The difference is
how mutations invalidate them:

```
FREE + PRO (no compiled profile)
  Any mutation (INSERT/UPDATE/DELETE/...)
    └─ bump_epoch()  →  global counter in Redis goes from 5 → 6
    └─ All cached SELECTs have epoch=5 → stale on next read

PRO (with compiled SQL profile)
  UPDATE wp_posts SET ...
    └─ extract table: "wp_posts"
    └─ bump_table_epoch('wp_posts')  →  only wp_posts counter goes up
    └─ SELECTs touching wp_options, wp_terms, etc. remain cached
```

---

## 3. When to use Free vs Pro

The Free build is effective when the average time between `save_post`
calls is long enough for the cache to fill before the next flush.

| Mutations per hour | Interval | Free HTML cache | Recommendation |
|--------------------|----------|-----------------|----------------|
| < 10 | > 6 min | Effective | Free is fine |
| 10–30 | 2–6 min | Acceptable | Free works, hit rate drops during bursts |
| 30–60 | 1–2 min | Degraded | Upgrade to Pro |
| > 60 | < 1 min | Near-zero hit rate | Pro required |
| Continuous (imports, live blog) | 2 s (throttle) | Zero | Pro required |

The throttle (`PIGCACHE_INVALIDATE_THROTTLE`, default 2 s) caps how often
invalidation runs, but does not prevent every `save_post` from eventually
triggering a flush once the throttle window expires.

---

## 4. How a page is cached — step by step

### Free build

```
Visitor requests GET /my-post/

1. template_redirect (priority 0)
   PigCache_Html_Cache::maybe_start_buffer()

2. Redis lookup: wp_cache_get("doc_abc123", "pigcache_html")

3a. HIT
    └─ echo $pack['html'], exit.
       Zero MySQL, zero PHP render, zero tag work.

3b. MISS
    └─ Acquire stampede lock (prevents duplicate regeneration for this URI)
    └─ ob_start( ob_callback )
    └─ WordPress renders the full page
    └─ ob_callback fires:
         a) wp_cache_set("doc_abc123", {html, tags:[], time}, "pigcache_html", TTL)
            tags array is always empty — tag collector not present in Free
         b) Release stampede lock
    └─ echo HTML to visitor
```

### Pro build

```
Visitor requests GET /my-post/

1–2. Same Redis lookup.

3a. HIT — same as Free. Zero overhead.

3b. MISS
    └─ Acquire stampede lock
    └─ PigCache_Tag_Collector::start()
         Hooks into: the_post, get_the_terms, wp_get_nav_menu_items,
                     dynamic_sidebar_before, get_header, get_footer
    └─ ob_start( ob_callback )
    └─ WordPress renders the full page
       During render the hooks fire automatically, collecting tags:
         the_post         → post:123, post_type:post, author:5
         get_the_terms    → term:10, taxonomy:category, term:22, taxonomy:post_tag
         wp_get_nav_menu_items → nav_menu:3
         dynamic_sidebar  → sidebar:sidebar-1
         get_header       → zone:header
         get_footer       → zone:footer
         implicit (URI)   → home (front page), feed (feed URLs)
    └─ ob_callback fires:
         a) wp_cache_set("doc_abc123", {html, tags:[...], time}, "pigcache_html", TTL)
         b) PigCache_Tag_Index::store_tags("doc_abc123", "pigcache_html", tags)
              DELETE FROM wp_pigcache_tags WHERE cache_key = 'doc_abc123'
              INSERT INTO wp_pigcache_tags (cache_key, grp, tag) VALUES
                ('doc_abc123', 'pigcache_html', 'post:123'),
                ('doc_abc123', 'pigcache_html', 'term:10'),
                ...
         c) Release stampede lock
    └─ echo HTML to visitor
```

---

## 5. What happens when a post is saved (Pro)

Editor saves post 123 (type=post, author=5, categories=[10], tags=[22]):

```
1. WordPress fires save_post(123)

2. PigCache_Invalidation::on_save_post(123)
   └─ Skip revisions and autosaves

3. Resolve tags for post 123:
   [
     "post:123",        ← the post page itself
     "post_type:post",  ← post type archive
     "author:5",        ← author archive
     "term:10",         ← category archive
     "term:22",         ← tag archive
     "home",            ← front page
     "feed",            ← RSS feed
     "date:2026-04",    ← monthly archive
   ]

4. MySQL:
   SELECT DISTINCT cache_key, grp
   FROM wp_pigcache_tags
   WHERE tag IN ('post:123','post_type:post','author:5','term:10',...)
   → returns 12 rows (home, post page, category page, tag page, author page, feed, ...)

5. Redis:
   wp_cache_delete("doc_aaa", "pigcache_html")
   wp_cache_delete("doc_bbb", "pigcache_html")
   ... (12 deletes total)

6. MySQL cleanup:
   DELETE FROM wp_pigcache_tags WHERE cache_key IN ('doc_aaa','doc_bbb',...)

7. SQL epoch:
   PigCache_Sql_Cache::bump_epoch()  ← all cached SELECTs stale

8. Done.
   The other cached pages (/about/, /contact/, /another-post/, ...) are untouched.
```

### What happens when a post is saved (Free)

```
1. WordPress fires save_post(123)

2. PigCache_Invalidation::on_save_post(123)
   └─ Skip revisions and autosaves

3. global_flush():
   wp_cache_flush_group('pigcache_html')      ← every cached page gone
   wp_cache_flush_group('pigcache_fragments')  ← every fragment gone

4. bump_sql_epoch()  ← all cached SELECTs stale

5. Done. Next visitor to any cached page triggers a full render.
```

---

## 6. Tags collected automatically (Pro)

Tags are collected only on cache MISS. On cache HIT the collector never runs.

### From WordPress hooks

| Hook | Tag(s) produced | Example |
|------|-----------------|---------|
| `the_post` | `post:{ID}`, `post_type:{type}`, `author:{ID}` | `post:123`, `post_type:post`, `author:5` |
| `get_the_terms` | `term:{term_id}`, `taxonomy:{taxonomy}` | `term:10`, `taxonomy:category` |
| `wp_get_nav_menu_items` | `nav_menu:{menu_id}` | `nav_menu:3` |
| `dynamic_sidebar_before` | `sidebar:{sidebar_id}` | `sidebar:sidebar-1` |
| `get_header` | `zone:header` | `zone:header` |
| `get_footer` | `zone:footer` | `zone:footer` |

### Implicit (URI-based)

| Condition | Tag |
|-----------|-----|
| Front page (`/` or `/?p=`) | `home` |
| Any `/feed` URL | `feed` |

### Manual API

```php
// Tag the current page from a theme or plugin during render:
pigcache_tag( 'widget:recent_posts' );
pigcache_tag( 'woo:product_list' );
pigcache_tag( 'custom:my_slider' );
```

---

## 7. Fragment cache

```php
// Free — tags parameter is accepted but ignored:
pigcache_fragment( 'sidebar_recents', function() {
    return render_recent_posts();
}, 300 );
// On save_post: entire pigcache_fragments group flushed.

// Pro — tags enable selective invalidation:
pigcache_fragment( 'sidebar_recents', function() {
    return render_recent_posts();
}, 300, 'pigcache_fragments', [ 'post_type:post', 'home' ] );
// On save_post of type 'post': only this fragment is deleted.
// A save_post of type 'page' does not touch it.
```

---

## 8. Custom invalidation from plugins (Pro)

```php
// Purge all cache entries tagged 'widget:recent_posts':
add_action( 'publish_post', function() {
    if ( class_exists( 'PigCache_Tag_Index', false ) ) {
        PigCache_Tag_Index::purge_by_tags( [ 'widget:recent_posts' ] );
    }
} );

// Tag a WooCommerce product grid:
function my_product_grid() {
    $products = wc_get_products( [ 'limit' => 12 ] );
    foreach ( $products as $p ) {
        pigcache_tag( 'post:' . $p->get_id() );
    }
    pigcache_tag( 'post_type:product' );
    // render...
}
```

---

## 9. SQL cache — epoch invalidation

`PigCache_WPDB` (the `db.php` drop-in) intercepts every SELECT.
SQL caching is skipped in: WP Admin, AJAX, REST, WP-CLI, Cron.

### Global epoch (Free and Pro fallback)

```
Any mutation (INSERT/UPDATE/DELETE/REPLACE/ALTER/...)
  └─ pigcache_on_mutation(sql)
       └─ no compiled profile → pigcache_bump_epoch()
            wp_cache_incr('pigcache_sql_epoch', 1, 'pigcache')
            epoch: 5 → 6

Next SELECT lookup:
  pack['epoch'] = 5  ≠  current epoch 6  →  MISS  →  hit MySQL
```

### Per-table epochs (Pro — requires compiled SQL profile)

```
UPDATE wp_posts SET post_title = '...' WHERE ID = 42
  └─ pigcache_on_mutation(sql)
       └─ profile present → extract_mutation_table() → "wp_posts"
       └─ PigCache_Sql_Cache::bump_table_epoch('wp_posts')
            wp_cache_incr('pigcache_sql_epoch:wp_posts', 1, 'pigcache')

Cached SELECT touching wp_posts:
  pack['table_epochs'] = {wp_posts: 5}  ≠  current {wp_posts: 6}  →  MISS

Cached SELECT touching only wp_options:
  pack['table_epochs'] = {wp_options: 3}  ==  current {wp_options: 3}  →  HIT ✓
```

---

## 10. SQL Profiler (Pro)

Maps query fingerprints to the tables they touch. Required for per-table epochs.

```
1. Learning phase (configurable, default 7 days):
   Every SELECT → normalize → fingerprint → tables extracted
   Stored in MySQL (wp_pigcache_sql_fingerprints)

2. Compilation:
   Admin clicks "Compile" or learning window ends
   → static PHP file generated: wp-content/pigcache-sql-profile.php
   → fingerprint → tables[] mapping, no MySQL at runtime

3. Runtime (zero overhead on hot path):
   SELECT arrives → normalize → lookup in static array
   → tables = ["wp_posts","wp_postmeta"]
   → fetch table epochs from Redis → compare with pack → HIT or MISS

4. Auto re-learn:
   Triggered on plugin activate/deactivate, theme switch, core update
   Old profile used as fallback during re-learning
```

| Constant | Default | Description |
|----------|---------|-------------|
| `PIGCACHE_SQL_PROFILE_LEARN` | `false` | Force learning mode |
| `PIGCACHE_SQL_PROFILE_PATH` | `wp-content/pigcache-sql-profile.php` | Override compiled file path |
| `PIGCACHE_SQL_PROFILE_AUTO_RELEARN` | `true` | Auto re-learn on stack changes |

---

## 11. Continuous Query Learning (Pro)

Always-on analytics pipeline that samples live traffic to identify slow queries.
Separate from the SQL profiler — does not affect cache invalidation.

### Per-request flow

```
Request arrives
  └─ Sampling decision (once per request, memoised)
       Remote config: learning_enabled + sample_rate
       mt_rand(1,1000) ≤ floor(rate × 1000) → sampled or not

On sampled request — every SELECT:
  PigCache_WPDB measures exec_ms around parent::query()
  → PigCache_Continuous_Learner::record(normalized, tables, exec_ms, mem_kb)
  → PigCache_Query_Buffer::record(...)
       Accumulates in static PHP array (max 300 distinct queries per request)

On shutdown:
  PigCache_Query_Buffer::flush_request()
  → APCu: merge into pigcache_qbuf:{hash}
  → Redis: merge into pigcache_qbuf:{15min-window}
  One external write per request.
```

### Cron pipeline

```
Every 15 min — pigcache_flush_query_stats
  → drain APCu or Redis buffer
  → INSERT … ON DUPLICATE KEY UPDATE into wp_pigcache_query_stats
     keyed on (query_hash, period_start — 15-min UTC buckets)

Every hour — pigcache_send_query_stats
  → POST /api/v1/query-stats (max 200 rows)
  → mark sent, prune rows older than 30 days
```

### Adaptive TTL

| Execution time | TTL assigned |
|----------------|--------------|
| < 20 ms | 60 s |
| 20–200 ms | 300 s |
| > 200 ms | 900 s |

Enable with `define('PIGCACHE_ADAPTIVE_TTL', true)` or via remote config.

| Constant | Default | Description |
|----------|---------|-------------|
| `PIGCACHE_CONTINUOUS_LEARNING` | *(from API)* | Force on/off |
| `PIGCACHE_LEARNING_SAMPLE_RATE` | *(from API)* | Float 0.0–1.0 |
| `PIGCACHE_ADAPTIVE_TTL` | *(from API)* | Enable adaptive TTL tiers |
| `PIGCACHE_APCU_BUFFER` | `false` | Use APCu instead of Redis for buffer |

---

## 12. Cloud SQL Profiles (Pro)

Sites with the same plugin/theme stack share compiled SQL profiles via the
PigCache Cloud API.

```
Site A (WooCommerce + Astra)     Site B (WooCommerce + Astra)
         │ fingerprints                   │ fingerprints
         └──────────────┐   ┌────────────┘
                        ▼   ▼
                 PigCache Cloud API
                 aggregates fingerprints
                 compiles shared profile
                        │
            ┌───────────┴────────────┐
            ▼                        ▼
       Site A (updated)         Site C (new install)
       downloads richer         gets profile instantly,
       profile                  no learning phase needed
```

Environment hash = md5( sorted(plugin slugs) + theme + WP major version ).

Fingerprints are normalized — no real values are ever sent:
```
Sent:    SELECT * FROM wp_posts WHERE ID = ?
Never:   SELECT * FROM wp_posts WHERE ID = 42
```

### Fallback chain

```
1. Cloud profile available?  → use it  (per-table epochs)
2. Local compiled profile?   → use it  (per-table epochs)
3. Neither?                  → global epoch (all SQL stale on any mutation)
```

---

## 13. Debugging (Pro)

### Which pages reference a specific post

```sql
SELECT cache_key, tag, created
FROM wp_pigcache_tags
WHERE tag = 'post:123'
ORDER BY created DESC;
```

### All tags for a cached page

```sql
SELECT tag
FROM wp_pigcache_tags
WHERE cache_key = 'doc_abc123'
  AND grp = 'pigcache_html';
```

### Pages with the most tags (find overly broad pages)

```sql
SELECT cache_key, COUNT(*) AS tag_count
FROM wp_pigcache_tags
GROUP BY cache_key
ORDER BY tag_count DESC
LIMIT 20;
```

### Count cached pages

```sql
SELECT COUNT(DISTINCT cache_key)
FROM wp_pigcache_tags
WHERE grp = 'pigcache_html';
```

---

## 14. Glossary

| Term | Meaning |
|------|---------|
| **Tag** | String like `post:123` or `term:10` linking a cache entry to the object it displays. Pro only. |
| **Tag collector** | `PigCache_Tag_Collector` — hooks into WordPress during render to detect which objects appear. Pro only. |
| **Tag index** | MySQL table `wp_pigcache_tags` mapping `(cache_key, grp) <-> tag`. Pro only. |
| **Global flush** | Deleting the entire `pigcache_html` and `pigcache_fragments` Redis groups. Free invalidation strategy. |
| **Epoch** | Integer counter in Redis. Incrementing it makes all SQL entries that stored the old value stale. |
| **Per-table epoch** | One epoch counter per MySQL table. Only queries touching the mutated table go stale. Pro + compiled profile. |
| **SQL profile** | Static PHP file mapping query fingerprints to their tables. Generated by the SQL profiler. |
| **Fingerprint** | md5 of a normalized query template (literals replaced with `?`). |
| **Pack** | Array stored in Redis for each cached page: `{html, tags, time}`. |
| **Stampede lock** | Short-lived Redis key preventing multiple processes from regenerating the same URI simultaneously. |
| **Environment hash** | md5 of sorted plugin slugs + theme slug + WP major. Used to match sites to shared cloud profiles. |
| **Continuous learning** | Always-on sampled pipeline collecting query exec time and memory. Does not affect invalidation. |
| **Adaptive TTL** | TTL assigned to a SQL cache entry based on query execution time. Slow queries cached longer. |
| **Query buffer** | APCu or Redis accumulator aggregating per-request query data so the MySQL write happens in cron. |
| **Period window** | 15-minute UTC bucket (`floor(time/900)*900`) grouping query stats rows. |
