# Feature — Continuous Query Learning (Pro)

## What it is and how it differs from the SQL Profiler

PigCache Pro ships two SQL-related subsystems that are often confused because both
observe queries. They solve entirely different problems.

| | SQL Profiler | Continuous Query Learning |
|---|---|---|
| **Goal** | Build a table→query map for smart cache invalidation | Measure real-world query performance and send telemetry to the API |
| **Question answered** | "When `wp_posts` changes, which cache entries are stale?" | "Which queries are slow? Which run 50,000 times per day?" |
| **Output** | Compiled profile stored in `wp_pigcache_profiles` | Per-period stats rows in `wp_pigcache_query_stats`, uploaded to the API |
| **Benefit** | Fewer unnecessary cache invalidations | Visibility into production query load; basis for adaptive TTL |
| **Active** | During a learning window, then compiled once | Always on in production (sampling) |
| **Data leaves the server** | Yes — fingerprints sent to API for aggregation | Yes — aggregated stats sent to API |

They are complementary. The profiler makes the cache *smarter*. Continuous learning
makes the cache *observable* and feeds the adaptive TTL engine.

---

## Architecture overview

```
Every incoming PHP request (sampled at configured rate, default 10%)
    │
    ├── PigCache_Wpdb::query()          ← db.php drop-in intercepts every query
    │       └── microtime(true) before + after parent::query()
    │       └── PigCache_Continuous_Learner::record(
    │                 $normalized,   // "SELECT … WHERE post_id = ?"
    │                 $tables,       // ['wp_postmeta']
    │                 $exec_ms,      // 4.2
    │                 $mem_kb        // 18
    │             )
    │
    ├── PigCache_Query_Buffer::record()         ← in-request accumulator
    │       APCu backend:  apcu_store( 'pigcache_qbuf:{hash}', $row )
    │       Redis backend: HSET pigcache_qbuf:{window} {hash} {json}
    │       (window = floor(time()/900)*900 — 15-minute bucket)
    │
    │   [request ends — buffer lives in shared memory until cron runs]
    │
    └── (every 15 minutes) pigcache-cron.php   ← standalone CLI script
            │
            ├── TASK 1: Flush buffer → MySQL
            │       _pigcache_cron_read_buffer()
            │           APCu: APCUIterator on /^pigcache_qbuf:/
            │           Redis: HGETALL pigcache_qbuf:{prev_window}; DEL key
            │       _pigcache_cron_upsert_batch()
            │           INSERT INTO wp_pigcache_query_stats … ON DUPLICATE KEY UPDATE
            │               hit_count     = hit_count     + VALUES(hit_count)
            │               total_exec_ms = total_exec_ms + VALUES(total_exec_ms)
            │               max_exec_ms   = GREATEST(max_exec_ms, VALUES(max_exec_ms))
            │               total_mem_kb  = total_mem_kb  + VALUES(total_mem_kb)
            │       UPDATE wp_options SET pigcache_cron_flush_last = NOW()
            │
            ├── TASK 2: Send unsent rows → PigCache API
            │       SELECT * FROM wp_pigcache_query_stats WHERE sent_at IS NULL LIMIT 200
            │       POST https://bluecache.pigworlds.com/api/v1/query-stats
            │            Authorization: Bearer {api_key}
            │            X-Site-Id: {site_id}
            │            Body: { rows: [...] }
            │       UPDATE wp_pigcache_query_stats SET sent_at = NOW() WHERE id IN (…)
            │       DELETE rows older than 30 days where sent_at IS NOT NULL
            │
            └── TASK 3: Cloud sync (fingerprints + profile)
                    3a. POST /sites/{id}/environment
                            reads active_plugins, stylesheet from wp_options
                            reads WP version from wp-includes/version.php (no WordPress boot)
                    3b. SELECT fingerprint, template, tables_json FROM wp_pigcache_sql_fingerprints
                             WHERE synced_at IS NULL ORDER BY hit_count DESC LIMIT 500
                        POST /sites/{id}/fingerprints   (batches of 500)
                        UPDATE wp_pigcache_sql_fingerprints SET synced_at = NOW() WHERE fingerprint IN (…)
                    3c. GET /sites/{id}/profile
                        → writes wp-content/pigcache-sql-profile.php
                        UPDATE wp_options SET pigcache_cloud_profile_source = 'cloud'
                        UPDATE wp_options SET pigcache_cloud_last_sync = NOW()
```

---

## The sampling decision

Recording every query on a high-traffic site would add meaningful overhead.
`PigCache_Continuous_Learner::should_record()` samples each request:

```php
// Memoised once per request — same decision for all queries in a single page load.
private static $record_this_request = null;

public static function should_record(): bool {
    if ( null === self::$record_this_request ) {
        $rate = self::effective_rate();   // float 0.001–1.0
        self::$record_this_request = ( mt_rand( 1, 1000 ) <= (int) floor( $rate * 1000 ) );
    }
    return self::$record_this_request;
}
```

At the default 10% rate, 1 in 10 page loads records query data.
At 100% (dev/debug), every request is recorded.

The effective rate is resolved in priority order:
1. `PIGCACHE_LEARNING_SAMPLE_RATE` constant in `wp-config.php`
2. `sample_rate` from the API config (set per-site from your account dashboard)
3. Default: `0.10` (10%)

---

## The query buffer

Rather than writing to MySQL on every sampled query (which would create a write
bottleneck), query data is accumulated in fast shared memory and flushed in bulk
by the cron.

### APCu backend (preferred)

APCu lives in the PHP process's shared memory segment. Reads and writes are
sub-microsecond. PigCache uses a key per normalized query hash:

```
pigcache_qbuf:{md5_hash} → {
    normalized: "SELECT … WHERE post_id = ?",
    tables:     ["wp_postmeta"],
    count:      17,
    total_ms:   48,
    max_ms:     12,
    total_mem:  306
}
```

Enabled by default when the `apcu` extension is loaded and shared memory is available.
Override: `define( 'PIGCACHE_APCU_BUFFER', false )` disables APCu and forces Redis.

### Redis backend (fallback)

When APCu is unavailable, the buffer uses a Redis HASH:

```
Key:   pigcache_qbuf:{window_timestamp}
       (window = floor(time()/900)*900 — the current 15-min bucket)
Field: {md5_hash}
Value: JSON-encoded row
```

The cron reads the *previous* window's key (`{window} - 900`) to avoid a race where
the cron reads a bucket that is still being written to by live requests.

**Note on key namespaces:** Redis keys for the SQL cache use `pc_sql:*` and
`pc_epochs:*`. The query buffer uses `pigcache_qbuf:*`. There is no collision.

---

## MySQL table: `wp_pigcache_query_stats`

```sql
CREATE TABLE wp_pigcache_query_stats (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    query_hash    VARCHAR(32)     NOT NULL,          -- md5 of normalized SQL
    normalized    TEXT            NOT NULL,           -- "SELECT … WHERE post_id = ?"
    tables        TEXT            NOT NULL,           -- JSON array: ["wp_postmeta"]
    hit_count     BIGINT UNSIGNED NOT NULL DEFAULT 0, -- executions in period
    total_exec_ms BIGINT UNSIGNED NOT NULL DEFAULT 0, -- sum of all exec times
    max_exec_ms   INT UNSIGNED    NOT NULL DEFAULT 0, -- slowest single execution
    total_mem_kb  BIGINT UNSIGNED NOT NULL DEFAULT 0, -- sum of memory used
    period_start  DATETIME        NOT NULL,           -- 15-min bucket start
    sent_at       DATETIME                 DEFAULT NULL, -- NULL = pending API sync
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hash_period (query_hash, period_start),
    KEY idx_period  (period_start),
    KEY idx_sent    (sent_at),
    KEY idx_exec_ms (total_exec_ms)
);
```

The `UNIQUE KEY (query_hash, period_start)` means the cron upserts using
`ON DUPLICATE KEY UPDATE` — two cron runs in the same 15-min window safely
accumulate into the same row rather than creating duplicates.

**Retention:** Rows with `sent_at IS NOT NULL` are pruned after 30 days by the
cron's cleanup step. Unsent rows are kept indefinitely until successfully uploaded.

---

## Adaptive TTL

When `adaptive_ttl` is enabled (set from the API dashboard or via
`define( 'PIGCACHE_ADAPTIVE_TTL', true )`), the SQL cache TTL for each query
is chosen dynamically based on how long the query actually takes to execute:

```
Execution time    → Cache TTL
─────────────────────────────
< 20 ms           → 60 s    (fast query — cheap to re-run on miss)
20 ms – 200 ms    → 300 s   (moderate — worth caching longer)
> 200 ms          → 900 s   (slow query — keep cached as long as possible)
```

**Why this matters:** A query that takes 2 ms costs almost nothing on a cache miss.
A query that takes 400 ms (a large JOIN, a `LIKE '%…%'` scan, or a COUNT on a
million-row table) should stay cached as long as the data can tolerate staleness.
Adaptive TTL automates this without requiring manual per-query configuration.

The TTL is only applied when `PigCache_Continuous_Learner::adaptive_ttl()` returns
a non-null value — i.e., when learning is enabled AND the query has been sampled in
the current request. Unsampled queries use the global SQL cache TTL from Settings.

---

## Standalone cron script

Unlike WP-Cron (which fires on page load and adds latency to a real visitor's
request), `bin/pigcache-cron.php` is a self-contained CLI script:

```
php /path/to/wp-content/plugins/pigcache/bin/pigcache-cron.php
```

**What it does NOT load:**
- WordPress core (`wp-load.php`, `wp-settings.php`)
- Any plugin
- Any theme

**What it DOES:**
1. Parses `wp-config.php` via regex to extract `DB_*`, `WP_REDIS_*`, and
   `PIGCACHE_*` constants (~0 ms — no PHP execution of the config file)
2. Opens a direct `mysqli` connection to MySQL
3. Reads the APCu or Redis buffer
4. Upserts into `wp_pigcache_query_stats`
5. POSTs unsent rows to the PigCache API via `curl`
6. Writes timestamps to `wp_options` so the admin panel shows "Last flush: 3 min ago"

**Overhead:** ~5 ms on an empty run, ~50–300 ms when flushing a full buffer.
Compare to `wp-cron.php`: ~100–300 ms just to boot WordPress before doing any work.

**Cron command (errors only — stdout discarded):**
```
*/15 * * * *  /usr/local/bin/php -q /path/to/pigcache/bin/pigcache-cron.php \
  > /dev/null 2>> /path/to/pigcache/logs/pigcache-cron.log
```

`stderr` (connection failures, missing credentials) is appended to the log.
A healthy run produces no log output.

**Custom interval:** the default 15 minutes can be changed via `wp-config.php`:

```php
define( 'PIGCACHE_FLUSH_INTERVAL', 5 );  // minutes; valid range 1–60
```

Remember to update the crontab expression to match:

```
*/5 * * * *  /usr/local/bin/php -q /path/to/pigcache/bin/pigcache-cron.php \
  > /dev/null 2>> /path/to/pigcache/logs/pigcache-cron.log
```

The admin "confirmed" window scales automatically to `interval + 10` minutes,
so a 5-minute interval is flagged as broken only if no flush ran in the last 15 minutes.

**Flags:**

| Flag | Effect |
|------|--------|
| `--flush-only` | Only Task 1 (buffer → MySQL). Skips API upload and cloud sync. |
| `--send-only` | Only Task 2 (MySQL → API stats). Skips buffer flush and cloud sync. |
| `--no-cloud` | Tasks 1 + 2, skips cloud sync (Task 3). |
| `--cloud-only` | Only Task 3 (cloud sync). Useful for diagnosing profile issues. |
| `--wp-config /path` | Explicit path to `wp-config.php`. |

---

## Example A — Detecting a slow query degrading a product page

A WooCommerce store starts getting reports that product pages occasionally take
3–4 seconds. The Continuous Learning pipeline surfaces the cause:

```
Admin → Settings → Continuous Query Learning → Slowest query patterns

Query pattern                                              Avg     Peak    Runs
─────────────────────────────────────────────────────────────────────────────────
SELECT * FROM wp_postmeta WHERE meta_value LIKE ?          1,240ms  3,810ms  4,820
SELECT p.* FROM wp_posts p WHERE p.post_status = ?…         12ms     89ms  98,400
SELECT option_value FROM wp_options WHERE option_name = ?    0.4ms    2ms 412,000
```

The first row — a `LIKE '%…%'` scan on `wp_postmeta` — runs 4,820 times per day
at an average of 1.24 seconds. This is a table scan with no index on `meta_value`.

**Actions available:**
- Add a MySQL index on `meta_value` (surgical fix)
- Increase the adaptive TTL floor to keep this query cached longer (buys time)
- Use `/*pigcache:no-cache*/` on this query if results must always be fresh

Without the telemetry, this query would be invisible — it doesn't show up in the
WordPress admin, and slow query logs require MySQL access you may not have.

---

## Example B — Identifying a query running 400,000 times per day

```
Most frequent query patterns

Query pattern                                              Runs      Avg
──────────────────────────────────────────────────────────────────────────
SELECT option_value FROM wp_options WHERE option_name = ?  412,000   0.4ms
SELECT * FROM wp_usermeta WHERE user_id = ?                198,000   0.6ms
SELECT ID FROM wp_posts WHERE post_status = ? LIMIT ?       94,000   1.1ms
```

`wp_options` is queried 412,000 times per day — about 4.7 per second.
Each call is fast (0.4 ms) but they sum to ~165 seconds of MySQL time per day.

The object cache should be absorbing these — if the hit rate is low, it means
either the TTL is too short, the object cache isn't enabled, or a plugin is
calling `delete_option()` excessively.

With this data you can:
1. Verify the object cache is active (Settings → Object Cache → Enabled)
2. Check which plugin is calling `delete_option('the_option_name')` repeatedly
3. Use `wp_cache_set()` directly in custom code to cache the value longer

---

## Example C — Verifying adaptive TTL is working

Before adaptive TTL:
```
Global SQL cache TTL: 120s
Slow JOIN query (avg 380ms): cached for 120s → misses every 2 min
Fast lookup (avg 0.8ms):     cached for 120s → wastes Redis memory
```

After enabling adaptive TTL:
```
Slow JOIN (380ms avg) → TTL 900s → rebuilt 4× per hour instead of 30×
Fast lookup (0.8ms)   → TTL  60s → frees Redis memory faster
```

The Continuous Learning pipeline provides the execution times that make adaptive
TTL possible. Without the per-query timing data, the system has no basis for
choosing a TTL other than the global default.

---

## Remote config and local overrides

Learning config is managed from your PigCache account dashboard and fetched by the
plugin via `GET /api/v1/learning-config`. The response is cached locally for 6 hours
to avoid hitting the API on every request.

You can also control learning directly from **Settings → Continuous Query Learning**
in the WordPress admin — clicking Enable/Disable updates the API immediately and
flushes the local cache so the change takes effect on the next page load.

**Priority order for each setting:**

| Setting | Priority 1 (highest) | Priority 2 | Priority 3 (lowest) |
|---|---|---|---|
| Learning on/off | `PIGCACHE_CONTINUOUS_LEARNING` constant | API config | Off |
| Sample rate | `PIGCACHE_LEARNING_SAMPLE_RATE` constant | API config | 10% |
| Adaptive TTL | `PIGCACHE_ADAPTIVE_TTL` constant | API config | Off |

Constants are set in `wp-config.php` and override the API for cases where you need
a hard guarantee (e.g., disabling learning on a staging site that shares an API key
with production, or forcing 100% sampling during a debugging session).

---

## What the admin shows

**Settings page → Continuous Query Learning section:**

- **Learning** — Enabled/Disabled, source (API config or constant), toggle button
- **Sample rate** — current effective rate (e.g., 10%)
- **Adaptive TTL** — On/Off
- **Buffer backend** — APCu (preferred) or Redis
- **Stat rows (MySQL)** — total rows in `wp_pigcache_query_stats`
- **Pending API sync** — rows not yet uploaded (`sent_at IS NULL`)
- **Last buffer flush** — timestamp of last successful cron Task 1 run
- **Last API sync** — timestamp of last successful cron Task 2 run
- **Slowest query patterns** — top 10 by average execution time (accordion)
- **Most frequent query patterns** — top 10 by total hit count (accordion)

**Cache Dashboard card → Query Analytics:**

- Unique query patterns tracked across all periods
- Total executions recorded
- Average query time across all patterns
- Slowest query patterns (accordion)
- Most frequent query patterns (accordion)

**Header status pill:**
- `Learning on` (green) — learning is enabled and active
- `Learning off` (grey) — learning is disabled or not yet configured

---

## Example D — Diagnosing why templates are not reflected after the cron runs

**Symptom:** The server cron runs every 15 minutes (confirmed in cPanel logs), but
the SQL profile on the site never updates and no new templates appear.

**Root cause 1 — Cloud sync was missing from the standalone cron.**

The old `bin/pigcache-cron.php` only flushed query stats. It did not send fingerprints
to the API or download the compiled profile. The profile download was handled by
`PigCache_Cloud_Sync` which ran only via the WP-Cron hook `pigcache_cloud_sync`.
If the server cron calls `pigcache-cron.php` (not `wp-cron.php`), that hook never fired.

**Fix:** Task 3 was added to the standalone cron. A single cron entry now handles
everything: stats flush, stats upload, fingerprint upload, and profile download.

**Root cause 2 — Admin showed "cron not detected" even though the cron was running.**

`is_cron_confirmed()` calls `get_option('pigcache_cron_flush_last')`. On sites with a
persistent object cache (Redis, APCu via WP), the cached value was stale — the
standalone cron wrote to MySQL directly via `mysqli` without invalidating the cache.

**Fix:** `wp_cache_delete('pigcache_cron_flush_last', 'options')` is called before
every `get_option()` in `is_cron_confirmed()` and in the admin timestamp display.

**Diagnostic steps using the verbose API:**

```bash
# Check what profile the backend has and which templates it contains
curl -H "Authorization: Bearer {API_KEY}" \
     -H "X-Site-Id: {SITE_ID}" \
     "https://bluecache.pigworlds.com/api/v1/sites/{SITE_ID}/profile?verbose=1"
```

Key fields in `debug`:

| Field | Meaning |
|-------|---------|
| `fingerprints_stored_in_db` | How many fingerprints the API has for this env |
| `fingerprints_in_profile` | How many made it into the compiled profile |
| `compiled_at_human` | When the profile was last compiled |
| `templates` | Full list ordered by hits — use this to verify your queries are present |

```bash
# Force a cloud-only sync from the server to re-download the profile immediately
php /path/to/pigcache/bin/pigcache-cron.php --cloud-only
```
