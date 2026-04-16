# Changelog

All notable changes to PigCache are documented in this file.

---

## [1.5.0] — 2026-04-16

### Changed — SQL Profiler is now a premium feature

The SQL Profiler (local learning + per-table epoch invalidation) is now
gated behind PigCache Pro. A **14-day free trial** starts automatically
when the plugin is activated for the first time.

- **`PigCache_License`**: Added trial management — `maybe_start_trial()`,
  `is_trial()`, `is_trial_expired()`, `trial_days_remaining()`,
  `can_use_profiler()`, `profiler_access_label()`.
- **`PigCache_Sql_Profiler`**: `start_learning()`, `record()`, `compile()`,
  `trigger_relearn()`, and `on_environment_change()` now check
  `PigCache_License::can_use_profiler()` before proceeding. Profile reading
  remains ungated so existing profiles keep working after trial expiry.
- **`PigCache_Admin`**: Profiler section now shows access level (Pro/Trial/
  Expired), hides action buttons when access is revoked, and displays
  upgrade prompts.
- **`pigcache.php`**: `PigCache_License::maybe_start_trial()` called on
  plugin activation.
- **Tier model**: Free = global epoch only; Trial = local profiler for 14
  days; Pro = local profiler + cloud profiles.

### Updated — Documentation

- `MANUAL.md`: Section 6 marked as Premium with trial info; Free vs Pro
  table updated to three-column (Free/Trial/Pro).
- `CACHE-FLOW.md`: Section 7 marked as Premium with access notes.
- `backend/README.md`: Added "Business Model — Premium SQL Profiler"
  section with implementation guidelines for backend developers.
- `backend/API-SPEC.md`: Added "Premium Model" section; endpoints 4, 5, 6
  marked as Pro-only with `plan_required` error code.

---

## [1.4.0] — 2026-04-16

### Added — PigCache Pro: Cloud SQL Profiles

Introduced a **freemium model** with cloud-based intelligent SQL profiling.
Free tier keeps global epoch invalidation; Pro tier gets cloud-synced,
cross-site aggregated profiles for instant per-table epoch invalidation.

- **`PigCache_License`** (`includes/class-pigcache-license.php`) — API key
  storage, plan checking via transient-cached remote status, `is_pro()`,
  `activate()`, `deactivate()`. Supports `PIGCACHE_LICENSE_KEY` constant.
- **`PigCache_Environment`** (`includes/class-pigcache-environment.php`) —
  Detects active plugins, theme, WP version. Computes a canonical environment
  hash for profile matching across sites.
- **`PigCache_Cloud_Client`** (`includes/class-pigcache-cloud-client.php`) —
  HTTP wrapper for all backend API endpoints via `wp_remote_*`. Bearer auth,
  retry on 5xx, 10s timeout, graceful `WP_Error` fallback.
- **`PigCache_Cloud_Sync`** (`includes/class-pigcache-cloud-sync.php`) —
  WP-Cron orchestrator (twice-daily): syncs fingerprints to the cloud,
  downloads pre-compiled profiles, registers environment. Supports manual
  "Sync Now" from admin.
- **Admin UI**: "PigCache Pro — License & Cloud" section with API key
  activation, plan status, sync controls, Pro badge, and cloud profile source
  indicator.

### Changed — PigCache_Sql_Profiler: cloud profile support

- Added `write_cloud_profile()` to write profiles downloaded from the cloud
  using the same format as local compilation.
- Added `is_cloud_profile()` to identify the profile source.
- Local `compile()` now marks the source as "local" via `PigCache_Cloud_Sync`.

### Changed — PigCache_Sql_Profile_Store: sync tracking

- Added `synced_at` column to `{prefix}pigcache_sql_fingerprints`.
- Added `get_unsynced()`, `mark_synced()`, `count_unsynced()` for batched
  cloud sync.

### Added — Backend reference folder (`backend/`)

Reference architecture for building the Laravel API backend:

- `backend/README.md` — Architecture overview, tech stack, getting started.
- `backend/API-SPEC.md` — Full REST API specification with JSON examples
  for all 8 endpoints (license, environment, fingerprints, profiles, ping).
- `backend/database/schema.sql` — MySQL schema (8 tables: licenses, sites,
  site_environments, fingerprints, fingerprint_sources, profiles, sync_log,
  known_plugin_tables).
- `backend/services/` — Extracted PHP classes:
  - `ProfileCompiler.php` — Compiles aggregated fingerprints into profiles.
  - `QueryNormalizer.php` — Shared normalization and table extraction.
  - `EnvironmentMatcher.php` — Matches sites to profiles (exact + fuzzy).
  - `ProfileAggregator.php` — Cross-site aggregation (core SaaS intelligence).
- `backend/config/known-plugins.php` — Starter map of 15+ popular plugins
  and their database tables (WooCommerce, Jetpack, Yoast, Elementor, etc.).

### Configuration constants

| Constant | Default | Description |
|----------|---------|-------------|
| `PIGCACHE_LICENSE_KEY` | _(empty)_ | API key (alternative to admin UI) |
| `PIGCACHE_CLOUD_API_URL` | `https://api.pigcache.com/v1` | Backend URL override |
| `PIGCACHE_CLOUD_SYNC` | `true` (when pro) | Enable/disable cloud sync |

---

## [1.3.0] — 2026-04-16

### Added — SQL Query Profiler with per-table epoch invalidation

Replaced the single global SQL epoch with **per-table epochs**. When
`wp_posts` is mutated, only queries touching `wp_posts` go stale — queries
for options, terms, or users remain cached.

- **`PigCache_Sql_Profiler`** (`includes/class-pigcache-sql-profiler.php`) —
  Learns query templates during a configurable observation window (default 7
  days), normalizes queries, extracts tables, and compiles a static PHP file
  for zero-overhead runtime lookups. Auto re-learns on plugin/theme changes.
- **`PigCache_Sql_Profile_Store`** (`includes/class-pigcache-sql-profile-store.php`)
  — MySQL table `{prefix}pigcache_sql_fingerprints` storing fingerprints,
  templates, table lists, and hit statistics during the learning phase.
- **Compiled profile** (`wp-content/pigcache-sql-profile.php`) — Auto-generated
  static PHP array loaded once per request. Maps `fingerprint -> tables[]` and
  reverse `table -> fingerprints[]`. Zero MySQL at runtime.
- **Per-table epochs** in `PigCache_Sql_Cache` — `bump_table_epoch()`,
  `get_table_epoch()`, `get_table_epochs()`. Each table gets its own Redis
  epoch counter (`pigcache_sql_epoch:{table}`).
- **Admin UI** — "SQL Query Profiler" section on the PigCache settings page
  with status, learning progress, Start/Compile/Re-learn/Delete buttons.
- **CLI analyzer** (`cli/pigcache-analyze.py`) — Python 3.6+ script that reads
  the learning data and produces a JSON report with frequency analysis, table
  dependency mapping, cache efficiency estimates, and recommendations.

### Changed — PigCache_WPDB: per-table epoch integration

`PigCache_WPDB` now stores per-table epochs in the cached pack when a compiled
profile exists. On mutation, only the mutated table's epoch is bumped. Falls
back to global epoch when no profile is available (backward compatible).

### Configuration constants

| Constant | Default | Description |
|----------|---------|-------------|
| `PIGCACHE_SQL_PROFILE_LEARN` | `false` | Force learning mode |
| `PIGCACHE_SQL_PROFILE_PATH` | `wp-content/pigcache-sql-profile.php` | Override compiled file path |
| `PIGCACHE_SQL_PROFILE_AUTO_RELEARN` | `true` | Auto re-learn on plugin/theme change |

---

## [1.2.0] — 2026-04-16

### Added — Tag-based selective invalidation

Replaced the global epoch-based invalidation for HTML cache and fragments with
a **tag-based system**. Saving a post now purges only the specific Redis keys
that reference it, instead of making every cached page stale.

- **`PigCache_Tag_Collector`** (`includes/class-pigcache-tag-collector.php`) —
  Hooks into WordPress during page render (cache MISS only) to detect which
  posts, terms, menus, sidebars, and zones appear on the current page.
  Automatic hooks: `the_post`, `get_the_terms`, `wp_get_nav_menu_items`,
  `dynamic_sidebar_before`, `get_header`, `get_footer`.
- **`PigCache_Tag_Index`** (`includes/class-pigcache-tag-index.php`) —
  MySQL table `{prefix}pigcache_tags` mapping `(cache_key, group) <-> tag`.
  Written only on cache MISS. Methods: `store_tags`, `get_keys_for_tags`,
  `remove_keys`, `purge_by_tags`, `cleanup_stale`.
- **`pigcache_tag()` global function** — Public API for themes/plugins to tag
  the current page during render (e.g. `pigcache_tag('widget:recent_posts')`).
- **`CACHE-FLOW.md`** — Human-readable document explaining the full cache
  lifecycle, tag system, invalidation flow, extensibility, and debugging.

### Changed — HTML cache: epoch removed, tags added

`PigCache_Html_Cache` no longer uses epoch-based invalidation. The stored pack
is now `{html, tags[], time}`. Pages are purged via the tag index when a tagged
object changes. Stampede lock is retained.

### Changed — Invalidation: tag-based post purging

`PigCache_Invalidation::on_save_post()` now resolves specific tags for the
post (`post:ID`, `post_type:X`, `author:Y`, `term:Z`, `home`, `feed`,
`date:YYYY-MM`) and deletes only matching Redis keys via MySQL lookup. SQL
cache still uses `PigCache_Sql_Cache::bump_epoch()`.

Additional scopes (`PIGCACHE_INVALIDATE_ON_TERM`, `_COMMENT`, `_NAV_MENU`,
`_USER`) now also use tag-based purging where possible.

### Changed — Fragments: optional tags parameter

`PigCache_Fragments::remember()` and `pigcache_fragment()` now accept an
optional `$tags` array for selective invalidation via the tag index.

### Changed — Plugin activation

`register_activation_hook` now also calls `PigCache_Tag_Index::create_table()`
to create the MySQL tag index via `dbDelta`.

---

## [1.1.0] — 2026-04-16

### Removed — Point 1: object-cache.php cleanup

- **Credis support removed** (`connect_using_credis`, ~130 lines).  Credis was
  already deprecated upstream.  PigCache now ships PhpRedis, Predis, and Relay
  as supported Redis clients.
- Removed the automatic `redis-cache` global group that belonged to the
  standalone Redis Object Cache plugin.
- Removed `colinmollenhour/credis` from `composer.json`; the lock file and
  `vendor/` directory no longer contain Credis.

### Fixed — Point 3: 503 errors on post creation (thundering-herd)

The root cause was that saving a post bumped both the HTML and SQL epoch
atomically, invalidating **every** cached page at once.  On a high-traffic site
(2 TB/month), thousands of concurrent visitors would suddenly hit WordPress and
MySQL simultaneously, overwhelming the server.

Three complementary fixes:

1. **Throttled invalidation** — `PigCache_Invalidation::on_content_change()`
   now respects `PIGCACHE_INVALIDATE_THROTTLE` (default 2 seconds).  Rapid-fire
   saves during the throttle window are silently skipped.
2. **HTML grace period** — `PigCache_Html_Cache` now stores the epoch and
   timestamp inside the cached pack.  When the epoch is stale but the content is
   younger than `PIGCACHE_HTML_GRACE` (default 10 s), the old HTML is served
   instead of regenerating.
3. **Stampede lock** — If the grace period has expired, only the first request
   acquires a 30-second `wp_cache_add` lock to regenerate the page; every other
   concurrent request serves the previous stale version while regeneration is in
   progress.

### Added — Point 3: configurable invalidation hooks

Post save/delete/trash hooks remain always active.  Additional scopes can be
enabled via `wp-config.php` constants:

| Constant | Hooks |
|----------|-------|
| `PIGCACHE_INVALIDATE_THROTTLE` | Min seconds between epoch bumps (default **2**). |
| `PIGCACHE_INVALIDATE_ON_OPTION` | `updated_option`, `added_option`, `deleted_option` |
| `PIGCACHE_INVALIDATE_ON_TERM` | `created_term`, `edited_term`, `delete_term` |
| `PIGCACHE_INVALIDATE_ON_COMMENT` | `wp_insert_comment`, `edit_comment`, `delete_comment`, `transition_comment_status` |
| `PIGCACHE_INVALIDATE_ON_NAV_MENU` | `wp_update_nav_menu`, `wp_delete_nav_menu` |
| `PIGCACHE_INVALIDATE_ON_WIDGET` | `update_option_sidebars_widgets`, `widget_update_callback` |
| `PIGCACHE_INVALIDATE_ON_THEME` | `switch_theme`, `customize_save_after` |
| `PIGCACHE_INVALIDATE_ON_USER` | `profile_update`, `user_register`, `delete_user` |
| `PIGCACHE_HTML_GRACE` | Seconds of grace for stale HTML serving (default **10**). |

### Changed — Point 4: admin assets separated

- Created `assets/css/pigcache-admin.css` — reusable CSS classes for the
  Settings → PigCache page (`.pigcache-table`, `.pigcache-form`, etc.).
- Created `assets/css/pigcache-metrics.css` — CSS for the Metrics sub-page
  (`.pigcache-metrics-guide`, `.pigcache-key-cell`, etc.).
- Created `assets/js/pigcache-admin.js` — confirm-dialog logic via
  `data-confirm` attributes instead of inline `onclick`.
- `PigCache_Admin::enqueue_assets()` now uses `wp_enqueue_style` /
  `wp_enqueue_script` so assets load only on PigCache admin pages.
- Inline `style="…"` attributes throughout `render_page()` and
  `render_*()` methods replaced with CSS classes.

### Fixed — Point 5: vendor directory incomplete

- Ran `composer update --no-dev` which properly installed **Predis v2.4.1** into
  `vendor/predis/predis/`.
- Added `.gitignore` that keeps `vendor/` tracked (WordPress plugin distribution
  convention) and ignores IDE / OS artefacts.

### Fixed — Point 6: race condition in epoch bumps

`PigCache_Sql_Cache::bump_epoch()` and `PigCache_Html_Cache::bump_epoch()` now
use `wp_cache_incr()` (maps to Redis `INCR`) instead of the non-atomic
`wp_cache_get` + `wp_cache_set` pattern.  If the key doesn't exist yet, it is
initialised to 2 with a long TTL.

The same fix was applied to `PigCache_WPDB::pigcache_bump_epoch()`.

### Fixed — Point 7: stale / orphan keys in Redis

Both HTML and SQL caches now use **content-based keys** (URI hash for HTML,
query hash for SQL) instead of including the epoch in the key hash.  The epoch
is stored **inside** the cached value and checked on read.

This means:

- When the epoch changes, the same Redis key is overwritten on the next write —
  no new orphan key is created.
- Old entries naturally expire via their TTL without wasting Redis memory.

### Fixed — Point 8: `wpdb::flush()` called in `pigcache_hydrate_select`

`PigCache_WPDB::pigcache_hydrate_select()` no longer calls `$this->flush()`
(which reset `col_info`, `last_result`, and other shared wpdb state).  It now
sets only the specific properties needed to restore a cached SELECT result:
`last_result`, `last_query`, `last_error`, `num_rows`, `result`,
`rows_affected`, `insert_id`, `func_call`, and `col_info` (nulled).

---

## [1.0.0] — Initial release

- Object cache drop-in derived from Redis Object Cache v2.7.0 (GPLv3).
- SQL result caching via `PigCache_WPDB` (`wp-content/db.php`).
- Full-page HTML cache via output buffering.
- Fragment caching helper (`pigcache_fragment()`).
- Admin settings page and metrics sub-page.
- Redis DB registry for shared instances.
