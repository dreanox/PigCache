# Feature — Adaptive TTL for HTML Cache (Pro)

## What it is and how it differs from SQL adaptive TTL

PigCache Pro has two systems called "adaptive TTL". They solve different problems.

| | SQL Adaptive TTL | HTML Adaptive TTL |
|---|---|---|
| **Controls** | TTL of a SQL query result in Redis | TTL of a full HTML page in Redis |
| **Signal** | Query execution time (ms) | Traffic volume × content mutation rate |
| **Tiers** | 3 fixed bands (fast/moderate/slow query) | Hot+Stable / Hot+Dynamic / Cold |
| **Documented in** | Case 06 — Continuous Query Learning | This document |
| **Class** | `PigCache_Continuous_Learner` | `PigCache_Adaptive_Ttl` |

This document covers **HTML Adaptive TTL** only.

---

## The problem it solves

A static HTML cache TTL (e.g. 3600 s) is a blunt instrument:

- A homepage with 50,000 visits/day and posts that change once a week → should be cached for **days**. Rebuilding it every hour wastes resources and serves identical HTML from scratch on every miss.
- A product listing page that gets 20 visits/day and whose inventory changes every few minutes → **shouldn't be cached at all**. A stale cache here causes real user-visible errors.
- A blog archive page with moderate traffic that never changes → should be cached for **a long time**.

Setting one global TTL forces a compromise between these cases. HTML Adaptive TTL removes the compromise by classifying each URL automatically.

---

## The two signals

### Signal 1 — Traffic (Hot vs Cold)

Source: `PigCache_Traffic_Reader::get_uri_hits( $uri )`

Returns the number of times a URI was accessed in the last 30 days. The source of this data is either AWStats log files (parsed by the cron) or PigCache's own Redis hit counters.

A URI with hits below `PIGCACHE_TRAFFIC_COLD_THRESHOLD` (default: 100 hits/30d) is classified as **Cold**.

### Signal 2 — Stability (Stable vs Dynamic)

Source: `PigCache_Mutation_Tracker::get_stability_map()`

Returns a map of `table → mutations_per_day` based on tracked INSERTs/UPDATEs/DELETEs. Each page's tags (e.g. `post:42`, `term:7`) are mapped to the MySQL tables they depend on:

```
Tag prefix   → MySQL table suffixes
──────────────────────────────────────────────────────────────
post         → wp_posts, wp_postmeta
post_type    → wp_posts
author       → wp_users, wp_posts
term         → wp_terms, wp_termmeta, wp_term_relationships
taxonomy     → wp_terms, wp_term_relationships
nav_menu     → wp_posts, wp_term_relationships
sidebar      → wp_posts
home         → wp_posts, wp_postmeta, wp_options
feed         → wp_posts
zone         → (no tables — structural tag only)
```

The worst-case mutation rate across all of a page's dependent tables is taken. If that rate exceeds `PIGCACHE_MUTATION_DYNAMIC_THRESHOLD` (default: 10 mutations/day), the page is classified as **Dynamic**.

---

## The three tiers

```
                      Traffic
                  Cold (<100 hits)    Hot (≥100 hits)
               ┌──────────────────┬──────────────────────┐
Stability      │                  │  Stable               │
Dynamic        │   ❄️ Cold        │  (≤10 mutations/day) │
(>10 mut/day)  │   TTL = 0        ├──────────────────────┤
               │   skip cache     │  ⚡ Hot + Dynamic     │
               │                  │  TTL = 60 s           │
               │                  ├──────────────────────┤
               │                  │  🔥 Hot + Stable      │
               │                  │  TTL = 86400 s (1 day)│
               └──────────────────┴──────────────────────┘
```

| Tier | Default TTL | Meaning |
|---|---|---|
| 🔥 Hot + Stable | 86400 s (1 day) | High traffic, data changes rarely — cache aggressively |
| ⚡ Hot + Dynamic | 60 s | High traffic, data changes often — cache briefly to absorb traffic spikes |
| ❄️ Cold | 0 (skip) | Low traffic — not worth the Redis memory; serve fresh every time |

**TTL = 0 (Cold)** means the page is never stored in the HTML cache. The request flows through to WordPress normally. This is intentional: caching a page that gets 3 visits per month wastes Redis memory and produces stale results on the rare occasions when it is accessed.

---

## Decision flow

```php
PigCache_Adaptive_Ttl::decide( $uri, $tags )

1. is_enabled()? → No → return -1 (caller uses static TTL)
2. Traffic data available? → No → return -1
3. hits < cold_threshold? → Yes → return ttl_cold()         // ❄️ Cold
4. tags_to_tables($tags) → tables
5. get_stability_map() → mutations per table
6. max_mutations > dyn_threshold? → Yes → return ttl_hot_dynamic()  // ⚡
7. → return ttl_hot_stable()                                 // 🔥
```

`-1` means "I have no opinion — use the configured static TTL". This happens when:
- Adaptive TTL is disabled
- The traffic map hasn't been built yet (cron has not run since the feature was enabled)
- Either `PigCache_Traffic_Reader` or `PigCache_Mutation_Tracker` is unavailable

The fallback to static TTL ensures zero disruption during the initial data collection period.

---

## Where it hooks into the HTML cache

`PigCache_Html_Cache::ob_callback()` (the output buffer callback that runs when a page finishes rendering) calls `decide()` to get the final TTL before storing the HTML in Redis:

```
Page renders → tags collected → ob_callback fires
    → PigCache_Adaptive_Ttl::decide( $_SERVER['REQUEST_URI'], $collected_tags )
        → returns TTL (or -1 for static fallback)
    → wp_cache_set( $key, $pack, 'pigcache_html', $ttl )
```

The tags passed to `decide()` are the same tags collected by `PigCache_Tag_Collector` during the render (posts, terms, menus, sidebars — see Case 02).

---

## Configuration

All settings have three levels of precedence (highest wins):

| Setting | Constant (wp-config.php) | wp_options (admin/API) | Default |
|---|---|---|---|
| Enable/disable | `PIGCACHE_ADAPTIVE_TTL` | `pigcache_adaptive_ttl_enabled` | Off |
| Hot + Stable TTL | `PIGCACHE_TTL_HOT_STABLE` | `pigcache_ttl_hot_stable` | 86400 s |
| Hot + Dynamic TTL | `PIGCACHE_TTL_HOT_DYNAMIC` | `pigcache_ttl_hot_dynamic` | 60 s |
| Cold TTL | `PIGCACHE_TTL_COLD` | — (always 0) | 0 |
| Cold threshold | `PIGCACHE_TRAFFIC_COLD_THRESHOLD` | `pigcache_traffic_cold_threshold` | 100 hits/30d |
| Dynamic threshold | `PIGCACHE_MUTATION_DYNAMIC_THRESHOLD` | `pigcache_mutation_dynamic_threshold` | 10 mutations/day |

---

## Example A — Static blog with occasional updates

**Site:** Personal blog, 3 posts per week, ~5,000 visits/day concentrated on the homepage and last 5 posts.

```
Homepage:       80,000 hits/30d, wp_posts mutations: 3/day  → 🔥 Hot + Stable → 86400s
Latest post:    12,000 hits/30d, wp_posts mutations: 3/day  → 🔥 Hot + Stable → 86400s
Archive /2023/:  1,200 hits/30d, wp_posts mutations: 0/day  → 🔥 Hot + Stable → 86400s
Tag /php/:         80 hits/30d,  wp_posts mutations: 3/day  → ❄️ Cold → skip cache
```

Result: the high-traffic pages cache for a full day. Low-traffic tag archives don't consume Redis memory. After a post is published, tag-based invalidation purges only the affected pages regardless of TTL.

---

## Example B — WooCommerce store with frequent stock updates

**Site:** 200 products, inventory updated by warehouse integration every hour.

```
Homepage:             45,000 hits/30d, wp_postmeta mutations: 240/day  → ⚡ Hot+Dynamic → 60s
/shop/ (listing):     38,000 hits/30d, wp_postmeta mutations: 240/day  → ⚡ Hot+Dynamic → 60s
/product/blue-widget:  2,400 hits/30d, wp_postmeta mutations: 240/day  → ⚡ Hot+Dynamic → 60s
/product/old-item:        40 hits/30d, wp_postmeta mutations: 240/day  → ❄️ Cold → skip cache
```

Result: popular pages get a 60-second buffer that absorbs traffic spikes without serving content more than 1 minute stale. Obsolete products are never cached. Without adaptive TTL, a 3600 s static TTL here would serve stale prices and stock levels for up to an hour.

---

## Example C — Tuning thresholds for a news site

**Default problem:** news homepage gets republished 40× per day (hot + dynamic) but editors want it cached for at least 5 minutes because it gets 200,000 visits/day.

```php
// wp-config.php
define( 'PIGCACHE_TTL_HOT_DYNAMIC', 300 );          // 5 minutes instead of 60s
define( 'PIGCACHE_MUTATION_DYNAMIC_THRESHOLD', 50 ); // only flag if > 50 mutations/day
```

Now pages that change up to 50 times per day are still classified as Stable (1-day TTL), and only pages with more than 50 mutations/day get the 5-minute TTL.

---

## Admin UI

Settings page → HTML Cache → Adaptive TTL section:

- **Status** — Enabled/Disabled, source (constant or admin toggle)
- **Tier breakdown** — table of all cached URLs with their current tier, hit count, mutation rate, and active TTL
- **Threshold sliders** — Cold threshold (hits/30d) and Dynamic threshold (mutations/day)
- **TTL inputs** — Hot+Stable TTL, Hot+Dynamic TTL

Cache Dashboard card → HTML Cache:

- Per-URL tier badges (🔥 / ⚡ / ❄️) visible in the cached pages list
- Tier distribution summary (e.g. "42 Hot+Stable, 8 Hot+Dynamic, 156 Cold")
