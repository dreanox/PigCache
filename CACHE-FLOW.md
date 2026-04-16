# PigCache — Cache Flow & Tag System

Human-readable reference for how PigCache caches content, how tags work,
and what gets purged when a post is created, updated or deleted.

---

## 1. What gets cached

| Layer | Redis group | Key pattern | Stored data | Invalidation |
|-------|-------------|-------------|-------------|--------------|
| **HTML** (full page) | `pigcache_html` | `doc_{md5(URI)}` | `{html, tags[], time}` | **Tag-based** — only pages referencing the changed object are deleted. |
| **SQL** (SELECT results) | `pigcache_sql` | `sql_{md5(query)}` | `{last_result, num_rows, return_val, table_epochs/epoch}` | **Per-table epochs** (with profiler) or **global epoch** (fallback). |
| **Fragments** | `pigcache_fragments` | `f_{md5(key)}` | Arbitrary callback output | **Tag-based** when tags are provided; otherwise TTL only. |
| **Object cache** (core WP) | Various (`options`, `posts`, `terms`, …) | `{prefix}{blog}:{group}:{key}` | Whatever WordPress stores | Managed by WordPress core; PigCache does not touch these. |

---

## 2. How a page is cached (HTML layer)

```
Visitor requests GET /my-post/

  1. template_redirect (priority 0)
     PigCache_Html_Cache::maybe_start_buffer()

  2. Redis lookup:
     wp_cache_get( "doc_abc123", "pigcache_html" )

  3a. HIT — pack exists and has HTML
      -> echo HTML, exit.  Zero MySQL, zero render.

  3b. MISS — no pack in Redis
      -> ob_start( ob_callback )
      -> WordPress renders the full page (50-100 MySQL queries)
      -> During render, PigCache_Tag_Collector hooks fire:
           the_post        -> adds "post:123", "post_type:post", "author:5"
           get_the_terms   -> adds "term:10", "taxonomy:category"
           wp_get_nav_menu -> adds "nav_menu:3"
           ...and so on for every object shown on the page.
      -> ob_callback fires:
           a) Store pack in Redis:
              { html: "...", tags: ["post:123","term:10",...], time: 1713300000 }
           b) Store tags in MySQL:
              INSERT INTO wp_pigcache_tags (cache_key, grp, tag) VALUES
                ("doc_abc123", "pigcache_html", "post:123"),
                ("doc_abc123", "pigcache_html", "term:10"),
                ("doc_abc123", "pigcache_html", "home"),
                ...
           c) Release stampede lock.
      -> echo HTML to visitor.
```

---

## 3. What tags are collected and when

Tags are ONLY collected during a **cache MISS** (page regeneration).
On cache HIT the tag collector never runs — zero overhead.

### Automatic tags (hooks)

| WordPress hook | Tag(s) produced | Example |
|----------------|----------------|---------|
| `the_post` | `post:{ID}`, `post_type:{type}`, `author:{ID}` | `post:123`, `post_type:post`, `author:5` |
| `get_the_terms` | `term:{term_id}`, `taxonomy:{taxonomy}` | `term:10`, `taxonomy:category` |
| `wp_get_nav_menu_items` | `nav_menu:{menu_id}` | `nav_menu:3` |
| `dynamic_sidebar_before` | `sidebar:{sidebar_id}` | `sidebar:sidebar-1` |
| `get_header` | `zone:header` | `zone:header` |
| `get_footer` | `zone:footer` | `zone:footer` |

### Implicit tags (added automatically by PigCache)

| Condition | Tag | Why |
|-----------|-----|-----|
| Every cached page | `home` (only on front page) | Front page almost always changes when any post changes. |
| Every cached page | `feed` (only on feed URLs) | RSS feeds list recent posts. |

### Manual tags (theme/plugin API)

```php
// Call this anywhere during a page render to tag the current page:
pigcache_tag( 'widget:recent_posts' );
pigcache_tag( 'custom:my_slider' );
pigcache_tag( 'woo:product_list' );
```

---

## 4. What happens when a post is created, updated or deleted

Step-by-step flow when an editor saves **post 123** (type=post, author=5,
categories=[10], tags=[22]):

```
1. WordPress fires save_post( 123 )

2. PigCache_Invalidation::on_save_post( 123 )
   -> Skip revisions and autosaves.

3. Resolve tags for post 123:
   [
     "post:123",          // the post itself
     "post_type:post",    // post type archive
     "author:5",          // author archive
     "term:10",           // category archive
     "term:22",           // tag archive
     "home",              // front page
     "feed",              // RSS feed
     "date:2026-04",      // monthly archive
   ]

4. MySQL query:
   SELECT DISTINCT cache_key, grp
   FROM wp_pigcache_tags
   WHERE tag IN ( 'post:123', 'post_type:post', 'author:5', ... )

   Result: 12 cache keys
   (home, the post page, category page, tag page, author page, feed, etc.)

5. Redis pipeline:
   DEL doc_aaa, doc_bbb, doc_ccc, ... (the 12 keys)
   via wp_cache_delete() for each key in its group.

6. MySQL cleanup:
   DELETE FROM wp_pigcache_tags
   WHERE cache_key IN ( 'doc_aaa', 'doc_bbb', ... )

7. SQL cache (epoch-based, separate):
   PigCache_Sql_Cache::bump_epoch()
   -> All cached SELECT results become stale.

8. Done.
   The other 10,000 cached pages are completely untouched.
   Next visitor to /my-post/ gets a cache MISS and regenerates.
```

### What about delete and trash?

- `deleted_post` and `trashed_post` follow the same flow as save_post.
- `transition_post_status` also resolves tags for the post when the
  status actually changes (e.g. draft -> publish or publish -> trash).

---

## 5. How to add custom tags from themes and plugins

### Tagging a widget

```php
// In your widget's render method:
function widget( $args, $instance ) {
    pigcache_tag( 'widget:recent_posts' );
    pigcache_tag( 'post_type:post' );  // invalidate when any post changes

    $recent = new WP_Query( [ 'posts_per_page' => 5 ] );
    // render...
}
```

### Tagging a WooCommerce product grid

```php
function my_product_grid() {
    $products = wc_get_products( [ 'limit' => 12 ] );
    foreach ( $products as $p ) {
        pigcache_tag( 'post:' . $p->get_id() );
    }
    pigcache_tag( 'post_type:product' );
    // render grid...
}
```

### Tagging a fragment

```php
pigcache_fragment(
    'sidebar_popular',
    function() { /* render */ },
    300,
    'pigcache_fragments',
    [ 'post_type:post', 'sidebar:primary' ]  // tags for selective invalidation
);
```

### Custom invalidation from your plugin

```php
// Purge everything tagged with 'widget:recent_posts':
add_action( 'publish_post', function() {
    if ( class_exists( 'PigCache_Tag_Index' ) ) {
        PigCache_Tag_Index::purge_by_tags( [ 'widget:recent_posts' ] );
    }
});
```

---

## 6. How to debug

### See which pages reference a post

```sql
SELECT cache_key, tag, created
FROM wp_pigcache_tags
WHERE tag = 'post:123'
ORDER BY created DESC;
```

### See all tags for a cached page

```sql
SELECT tag
FROM wp_pigcache_tags
WHERE cache_key = 'doc_abc123'
  AND grp = 'pigcache_html';
```

### Count tags per page (find overly-tagged pages)

```sql
SELECT cache_key, COUNT(*) AS tag_count
FROM wp_pigcache_tags
GROUP BY cache_key
ORDER BY tag_count DESC
LIMIT 20;
```

---

## 7. SQL cache — per-table epochs with profiler (Premium)

SQL SELECT results are cached by `PigCache_WPDB` (the `db.php` drop-in).

> **The SQL Profiler is a premium feature.** A 14-day trial starts on
> first plugin activation. After the trial, a PigCache Pro license is
> required to start learning, compile, or re-learn. Existing compiled
> profiles continue working (read-only) without a license.

### Without profiler (default / free tier)

Single global epoch — every mutation bumps one counter and all SQL
entries go stale. This is the permanent behavior on the free tier.

### With profiler (trial or Pro, after compilation)

Per-table epochs — each table has its own epoch counter in Redis. When
`wp_posts` is mutated, only queries touching `wp_posts` go stale. Queries
that only read `wp_options` or `wp_terms` remain cached.

```
How it works:

1. Learning phase (configurable, default 7 days):
   - Every SELECT is intercepted
   - Query is normalized: SELECT * FROM wp_posts WHERE ID = 123 → ...WHERE ID = ?
   - Tables extracted: [wp_posts]
   - Fingerprint + tables stored in MySQL (wp_pigcache_sql_fingerprints)

2. Compilation:
   - Admin clicks "Compile Now" or learning window ends
   - All fingerprints are read from MySQL
   - A static PHP file is generated: wp-content/pigcache-sql-profile.php
   - File contains: fingerprint -> tables[] mapping
   - No more MySQL reads at runtime

3. Runtime (zero overhead):
   - SELECT arrives -> normalize -> fingerprint -> lookup in static array
   - Profile says tables = ["wp_posts", "wp_postmeta"]
   - Get per-table epochs from Redis: epoch:wp_posts = 5, epoch:wp_postmeta = 3
   - Cached pack has {table_epochs: {wp_posts: 5, wp_postmeta: 3}} -> FRESH

4. On mutation (INSERT INTO wp_posts):
   - Extract table: "wp_posts"
   - INCR epoch:wp_posts only
   - Queries touching wp_options remain cached
```

### Auto re-learn

When plugins are activated/deactivated, themes are switched, or core
updates complete, the profiler automatically starts a new learning window
(configurable via `PIGCACHE_SQL_PROFILE_AUTO_RELEARN`). The old profile
is backed up and used as fallback during re-learning.

### Configuration

| Constant | Default | Description |
|----------|---------|-------------|
| `PIGCACHE_SQL_PROFILE_LEARN` | `false` | Set `true` to force learning mode |
| `PIGCACHE_SQL_PROFILE_PATH` | `wp-content/pigcache-sql-profile.php` | Override compiled file location |
| `PIGCACHE_SQL_PROFILE_AUTO_RELEARN` | `true` | Auto re-learn on plugin/theme change |

### CLI analyzer (optional)

```bash
# Requires Python 3.6+ and a MySQL driver
pip3 install mysql-connector-python

# Analyze using wp-config.php for credentials
python3 cli/pigcache-analyze.py --wp-config /path/to/wp-config.php

# Output to JSON file
python3 cli/pigcache-analyze.py --wp-config /path/to/wp-config.php -o report.json
```

The analyzer produces:
- Frequency-sorted query templates
- Table dependency graph
- Cache efficiency estimate (% of queries surviving a wp_posts mutation)
- Optimization recommendations

---

## 8. Cloud SQL Profiles (PigCache Pro)

When a Pro license is active, the SQL profiler gains cloud capabilities:

```
Site A (WooCommerce + Astra)     Site B (WooCommerce + Astra)
         |                                |
         |  fingerprints                  |  fingerprints
         └───────────┐      ┌─────────────┘
                     ▼      ▼
              ┌─────────────────┐
              │  PigCache Cloud │
              │  (Backend API)  │
              │                 │
              │  Aggregate      │
              │  fingerprints   │
              │  from both      │
              │  sites          │
              │                 │
              │  Compile a      │
              │  shared profile │
              └───────┬─────────┘
                      │ compiled profile
         ┌────────────┴────────────┐
         ▼                         ▼
    Site A downloads           Site C (new WooCommerce + Astra)
    updated profile            gets instant profile
    (more complete)            (no learning needed)
```

### How it works

1. **Environment detection**: The plugin computes a canonical hash of
   `sorted(plugins) + theme + wp_major`. Example: a site running
   WooCommerce + Jetpack + Astra on WP 6.7 produces hash `abc123...`.

2. **Profile matching**: The backend checks if a compiled profile exists
   for that environment hash. If yes, the site downloads it immediately.

3. **Learning + sync**: If no profile exists, the site runs local learning
   (same as before) and syncs fingerprints to the cloud every 12 hours.

4. **Cross-site aggregation**: The backend merges fingerprints from all
   sites with the same environment hash. A WooCommerce profile gets richer
   every time another WooCommerce site contributes data.

5. **Compilation**: When enough data accumulates, the backend compiles
   a profile. New sites with the same stack get it instantly.

### Data privacy

Templates are normalized (no real values):
```
Sent:    SELECT * FROM wp_posts WHERE post_author = ? AND post_status = ?
Never:   SELECT * FROM wp_posts WHERE post_author = 5 AND post_status = 'publish'
```

Only plugin slugs, theme slug, and WP version are sent. No site content,
users, or credentials.

### Fallback chain

```
1. Cloud profile available?     → use it (per-table epochs)
2. Local compiled profile?      → use it (per-table epochs)
3. Neither?                     → global epoch (all SQL goes stale)
```

The site never fails. Cloud is an optimization, not a dependency.

---

## 9. Glossary

| Term | Meaning |
|------|---------|
| **Tag** | A string like `post:123` or `term:10` that links a cache entry to the object it displays. |
| **Tag collector** | `PigCache_Tag_Collector` — hooks into WordPress during render to detect which objects appear on the page. |
| **Tag index** | MySQL table `wp_pigcache_tags` — maps `(cache_key, group) <-> tag`. |
| **Epoch** | An integer counter in Redis. Incrementing it makes all entries that stored the old epoch stale. Used for SQL cache. |
| **Per-table epoch** | An epoch counter per MySQL table (e.g. `epoch:wp_posts`). Only queries touching the mutated table go stale. |
| **SQL profile** | Static PHP file (`wp-content/pigcache-sql-profile.php`) mapping query fingerprints to their tables. |
| **Fingerprint** | md5 of a normalized query template. Used to look up which tables a query touches. |
| **Pack** | The array stored in Redis for each cached page: `{html, tags, time}`. |
| **Stampede lock** | A short-lived Redis key that prevents multiple processes from regenerating the same page simultaneously. |
| **Grace period** | Seconds during which stale HTML is served to prevent thundering herd (SQL cache fallback only). |
| **Environment hash** | md5 of the site's sorted plugin slugs + theme + WP major version. Used to match sites to shared cloud profiles. |
| **Cloud profile** | A compiled SQL profile downloaded from PigCache Cloud, aggregated from multiple sites with the same environment. |
| **Cloud sync** | Periodic (twice-daily) upload of local fingerprints and download of compiled profiles via the PigCache Cloud API. |
