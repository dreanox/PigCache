# PigCache — Plans & Pending Work

---

## Testing Strategy

> Status: **pending** — documented for future implementation. No tests exist yet.

### Why three layers

The plugin has three distinct runtime contexts, each requiring a different testing approach:

| Context | Examples | Needs |
|---------|----------|-------|
| Pure PHP logic | `Adaptive_Ttl::decide()`, AWStats parser, KV SQL generation | No infrastructure |
| WordPress + MySQL | `PigCache_KV` reads/writes, table install, cron pipeline | Real DB (MySQL or SQLite) |
| Full stack | Redis flush + heartbeat, cron binary, AWStats auto-detect | Docker (Redis + MySQL + WP) |

The bug that exposed the original heartbeat problem (cron writes to wp_options, admin reads stale value from Redis `notoptions` cache) is the canonical example of something only a full-stack E2E test would catch before production.

---

## Layer 1 — Unit Tests

**Framework:** PHPUnit + Brain Monkey  
**Infrastructure required:** none  
**Expected run time:** < 5 s

```
composer require --dev phpunit/phpunit yoast/phpunit-polyfills brain/monkey
```

Brain Monkey stubs WordPress functions (`get_option`, `update_option`, `wp_cache_get`, `$wpdb->prepare`, etc.) without loading WordPress. Every test is pure PHP.

### Priority test cases

| Test class | Method | What it verifies |
|------------|--------|-----------------|
| `AdaptiveTtlTest` | `test_decide_returns_minus_one_when_disabled` | No data → static TTL fallback |
| `AdaptiveTtlTest` | `test_decide_cold_when_hits_below_threshold` | hits < cold_threshold → TTL 0 |
| `AdaptiveTtlTest` | `test_decide_hot_dynamic_when_mutations_high` | hot + high mutations → short TTL |
| `AdaptiveTtlTest` | `test_decide_hot_stable_when_mutations_low` | hot + low mutations → long TTL |
| `AdaptiveTtlTest` | `test_options_override_defaults` | wp_options values are used when no constants |
| `AwstatsParserTest` | `test_parses_begin_urls_section` | fixture file → correct uri→hits map |
| `AwstatsParserTest` | `test_skips_static_asset_extensions` | `.css`, `.js`, `.png` filtered out |
| `MutationTrackerTest` | `test_flush_pending_merges_into_existing_window` | static accumulator + JSON merge |
| `MutationTrackerTest` | `test_flush_pending_noop_when_empty` | no pending → no update_option call |
| `KvTest` | `test_set_generates_insert_on_duplicate_sql` | mock wpdb, verify prepared SQL |
| `KvTest` | `test_get_returns_default_when_row_missing` | empty result → default returned |
| `KvTest` | `test_get_returns_default_when_expired` | expires_at in the past → default |
| `CronHelpersTest` | `test_kv_set_uses_correct_column_order` | mysqli mock, verify bind_param call |
| `CronHelpersTest` | `test_kv_get_returns_default_on_no_result` | no row → empty string |
| `SamplingTest` | `test_is_request_sampled_false_when_disabled` | remote config disabled → false |
| `SamplingTest` | `test_is_request_sampled_respects_constant_override` | PIGCACHE_CONTINUOUS_LEARNING=false |

### Fixture files needed

- `tests/fixtures/awstats-sample.txt` — a minimal valid AWStats data file with a `BEGIN_URLS` section
- `tests/fixtures/awstats-no-urls.txt` — file without a URL section (edge case)

---

## Layer 2 — Integration Tests

**Framework:** wp-phpunit/wp-phpunit + automattic/sqlite-db  
**Infrastructure required:** none (SQLite, no MySQL server)  
**Expected run time:** < 30 s

```
composer require --dev wp-phpunit/wp-phpunit automattic/sqlite-db
```

`automattic/sqlite-db` provides a SQLite drop-in for the WordPress DB, so integration tests run without a MySQL server. The test runner bootstraps a real WordPress and rolls back after each test.

> Note: `dbDelta()` has limited SQLite support. The table creation tests may require a real MySQL. Wrap them in a `@group requires-mysql` annotation and skip in SQLite mode.

### Priority test cases

| Test class | Method | What it verifies |
|------------|--------|-----------------|
| `KvIntegrationTest` | `test_set_and_get_round_trip` | Value written is readable |
| `KvIntegrationTest` | `test_get_after_object_cache_flush` | wp_cache_flush() → KV::get() still works |
| `KvIntegrationTest` | `test_expired_entry_returns_default` | TTL expiry via real datetime comparison |
| `KvIntegrationTest` | `test_purge_expired_removes_old_rows` | purge_expired() deletes the right rows |
| `KvIntegrationTest` | `test_heartbeat_flow` | `KV::set(flush_last, time())` → `is_cron_confirmed()` = true |
| `PluginInstallTest` | `test_install_creates_all_tables` | wp_pigcache_kv, _table_stability, _url_traffic exist |
| `PluginInstallTest` | `test_upgrade_from_version_2_to_3` | existing DB at v2, run maybe_install_tables(), kv table appears |
| `MutationPipelineTest` | `test_record_then_harvest_updates_transient` | record_mutation → flush_pending → harvest → get_stability_map |
| `TrafficPipelineTest` | `test_record_hit_then_harvest_updates_transient` | record_hit → flush_pending → harvest (own counters) → get_uri_hits |
| `TrafficPipelineTest` | `test_harvest_throttle_skips_before_23h` | second harvest call within 23 h is a no-op |
| `CronConfirmTest` | `test_cron_confirmed_false_on_fresh_install` | no row in KV → is_cron_confirmed() = false |
| `CronConfirmTest` | `test_cron_confirmed_true_after_flush` | cron_flush() writes KV → is_cron_confirmed() = true |
| `CronConfirmTest` | `test_cron_confirmed_false_after_ttl_window` | KV row older than confirm_ttl → false |

**The key regression test** (the original bug):

```php
public function test_heartbeat_survives_redis_notoptions_cache() {
    // Simulate what the old code suffered: notoptions cached "missing"
    wp_cache_set( 'notoptions', array( 'pigcache_cron_flush_last' => true ), 'options' );

    // New code uses KV table — not affected by object cache at all
    PigCache_KV::set( PigCache_KV::KEY_CRON_FLUSH_LAST, time() );

    $this->assertTrue( PigCache_Continuous_Learner::is_cron_confirmed() );
}
```

---

## Layer 3 — E2E Tests

**Framework:** Docker Compose + PHPUnit or bash assertions  
**Infrastructure required:** Docker  
**Expected run time:** 2–5 min (image pull cached)

### docker-compose.test.yml (to be created)

```yaml
services:
  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress_test

  redis:
    image: redis:7-alpine

  wordpress:
    image: wordpress:php8.2-cli
    depends_on: [db, redis]
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_NAME: wordpress_test
      # WP object cache → Redis
      PIGCACHE_REDIS_HOST: redis

  cron:
    build: .
    command: php bin/pigcache-cron.php
    depends_on: [db, wordpress]
```

### Priority test scenarios

| Scenario | Steps | Asserts |
|----------|-------|---------|
| **Heartbeat end-to-end** | 1. Run cron. 2. FLUSHALL Redis. 3. Read admin page. | `is_cron_confirmed()` returns true — KV table survives Redis flush |
| **Full flush pipeline** | Run cron with APCu buffer populated | `wp_pigcache_query_stats` has rows, KV `cron_flush_last` is recent |
| **Mutation harvest** | Write posts via WP-CLI, run cron, check KV transient | stability map has `wp_posts` with mutation count > 0 |
| **AWStats harvest** | Place sample awstats file in `tmp/awstats/`, run cron | traffic map has expected URI hits, KV source = 'awstats' |
| **Own-counter fallback** | No AWStats dir, warm HTML cache, run cron | traffic map built from own hit counters |
| **DB upgrade v2→v3** | Install plugin at v2, update to v3, load admin | `wp_pigcache_kv` table exists, no PHP errors |
| **Adaptive TTL cold tier** | Low-traffic URL, Adaptive TTL enabled | ob_callback skips caching (returns without wp_cache_set) |

---

## Implementation Order

When ready to implement:

1. **Unit tests first** — zero infrastructure, immediate value, run in CI on every push
2. **Integration tests** — add after the first unit suite passes; use SQLite for speed, fall back to MySQL for `dbDelta` tests
3. **E2E tests** — before any public Pro release; run in CI on `main` only (slow)

### Directory layout (to be created)

```
tests/
  bootstrap.php          # Brain Monkey / wp-phpunit bootstrap
  Unit/
    AdaptiveTtlTest.php
    AwstatsParserTest.php
    MutationTrackerTest.php
    KvTest.php
    CronHelpersTest.php
    SamplingTest.php
  Integration/
    KvIntegrationTest.php
    PluginInstallTest.php
    MutationPipelineTest.php
    TrafficPipelineTest.php
    CronConfirmTest.php
  E2E/
    HeartbeatTest.php
    CronPipelineTest.php
    AdaptiveTtlE2ETest.php
  fixtures/
    awstats-sample.txt
    awstats-no-urls.txt
  docker/
    docker-compose.test.yml
    Dockerfile.test
phpunit.xml.dist         # points to tests/Unit/ by default; --testsuite integration|e2e for others
```

### phpunit.xml.dist skeleton

```xml
<?xml version="1.0"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="integration">
      <directory>tests/Integration</directory>
    </testsuite>
    <testsuite name="e2e">
      <directory>tests/E2E</directory>
    </testsuite>
  </testsuites>
  <coverage>
    <include>
      <directory>includes</directory>
    </include>
  </coverage>
</phpunit>
```

---

## Build exclusion

All test infrastructure is already excluded from distribution ZIPs via `.distignore`:
- `tests/` — test directory
- `phpunit.xml` / `phpunit.xml.dist` — test runner config
- `plans.md` — this file
- `.phpunit.result.cache` — PHPUnit runtime cache
- `Makefile` — convenience runner

The `build.sh` script uses `.distignore` with `rsync --exclude-from`, so no manual step is needed when tests are added.
