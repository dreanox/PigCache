# PigCache Cloud — Backend Reference

This folder contains the **reference architecture**, API contracts, database
schema, and extracted core logic for building the PigCache Cloud API backend.

This is NOT the running application. Use these files to scaffold your Laravel
project.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Framework** | Laravel 11+ (PHP 8.2+) |
| **Database** | MySQL 8.0+ |
| **Queue** | Laravel Queue (Redis or database driver) |
| **Analysis** | Python 3.8+ (optional, for deep profile analysis) |
| **Cache** | Redis (for rate limiting, sessions, queue) |

---

## Folder Structure

```
backend/
├── README.md               ← You are here
├── API-SPEC.md             ← Full REST API spec with JSON examples
├── database/
│   └── schema.sql          ← MySQL schema for the backend
├── services/
│   ├── ProfileCompiler.php     ← Compiles aggregated fingerprints into profiles
│   ├── QueryNormalizer.php     ← Normalizes SQL + extracts tables (shared with plugin)
│   ├── EnvironmentMatcher.php  ← Matches site environments to existing profiles
│   └── ProfileAggregator.php   ← Aggregates fingerprints across sites (core SaaS value)
└── config/
    └── known-plugins.php   ← Starter map of known plugin table signatures
```

---

## Architecture Overview

```
┌────────────────────┐         ┌──────────────────────────┐
│  WordPress Site     │         │  PigCache Cloud API      │
│  (PigCache Plugin)  │  HTTPS  │  (Laravel)               │
│                     │◄───────►│                          │
│  - License check    │         │  - License management    │
│  - Env detection    │         │  - Environment matching  │
│  - Fingerprint sync │         │  - Fingerprint storage   │
│  - Profile download │         │  - Profile compilation   │
│                     │         │  - Profile aggregation   │
└────────────────────┘         └────────────┬─────────────┘
                                            │
                                    ┌───────▼───────┐
                                    │   MySQL       │
                                    │  - licenses   │
                                    │  - sites      │
                                    │  - fingerprints│
                                    │  - profiles   │
                                    └───────────────┘
```

---

## Business Model — Premium SQL Profiler

The SQL Profiler (local learning + per-table epochs) is a **premium feature**.
The cloud profiles are an additional Pro-only capability.

| Tier | SQL Cache Behavior | Profiler | Cloud |
|------|--------------------|----------|-------|
| **Free** | Global epoch (all queries stale on any mutation) | No | No |
| **Trial** (14 days, auto-starts on activation) | Per-table epochs via local profiler | Yes (local) | No |
| **Pro** (paid license) | Per-table epochs via local or cloud profiles | Yes (local + cloud) | Yes |

### Implementation in the plugin

- `PigCache_License::can_use_profiler()` returns `true` only during trial or with active Pro.
- `PigCache_License::maybe_start_trial()` is called on plugin activation.
- All profiler write operations (`start_learning`, `record`, `compile`, `trigger_relearn`) check `can_use_profiler()`.
- Profile **reading** (`load_profile`, `get_tables_for_query`) is NOT gated — already-compiled profiles keep working after trial expiration. This is intentional: it lets users experience the benefit and creates incentive to upgrade.

### Implementation required in the backend

When building the API, the license management system **must** enforce:

1. **License validation**: `POST /v1/license/activate` must verify the key, check the plan, and return `plan: "pro"` (or `"trial"`, `"free"`).
2. **Status endpoint**: `GET /v1/license/status` returns current plan status. The plugin caches this for 24 hours.
3. **Trial tracking**: The trial is tracked **locally** in the plugin (`pigcache_trial_started` option). The backend does not need to track trial state, but should return `plan: "free"` for sites without a paid license so the plugin can distinguish between trial (local) and Pro (paid).
4. **Feature gating at API level**: Endpoints like `POST /v1/sites/{id}/fingerprints` and `GET /v1/sites/{id}/profile` should reject requests from non-Pro sites (`403 Forbidden`).
5. **Grace period on Pro expiry**: Consider allowing a 7-day grace after Pro license expires before stopping cloud sync, to avoid disruption during payment issues.

---

## How it Works

### 1. License Activation

The plugin sends the API key + site URL. The backend validates the key,
registers the site, and returns a `site_id`. All subsequent calls include
this `site_id` in the `X-Site-Id` header.

### 2. Environment Registration

The plugin sends its active plugins, theme, and WP version. The backend
computes a canonical environment hash and checks if a compiled profile
already exists for that stack.

### 3. Fingerprint Sync

During local learning, the plugin periodically uploads fingerprint batches.
The backend stores them and associates them with the site's environment.

### 4. Profile Compilation

When enough sites with the same environment have contributed fingerprints,
the backend compiles an aggregated profile. The compilation weights
fingerprints by frequency and cross-site occurrence.

### 5. Profile Download

The plugin checks for available profiles. If the backend has one for the
site's environment, it downloads and writes it locally. The plugin's runtime
uses it identically to a locally compiled profile.

---

## What the Plugin Sends (Privacy)

The plugin sends **normalized query templates** and metadata. Templates have
all literal values replaced with `?` placeholders. Example:

```
Original:  SELECT * FROM wp_posts WHERE post_author = 5 AND post_status = 'publish'
Sent:      SELECT * FROM wp_posts WHERE post_author = ? AND post_status = ?
```

Additionally sent:
- Table names extracted from the query
- Hit count (how often this query pattern runs)
- Average row count
- Active plugin slugs (directory names only, e.g. "woocommerce")
- Theme slug
- WP major version

**Never sent**: actual data values, user information, site content, passwords,
or connection credentials.

---

## Key Concepts

### Environment Hash

A deterministic md5 of sorted plugin slugs + theme + WP major version.
Two sites running the exact same stack produce the same hash. This is
the primary key for profile sharing.

### Fingerprint

An md5 of a normalized SQL template. The normalization replaces all
literal values with `?` so `WHERE ID = 5` and `WHERE ID = 42` produce
the same fingerprint.

### Profile

A compiled PHP array mapping `fingerprint => tables[]`. At runtime, the
plugin looks up a query's fingerprint in this map to know which tables
to check for epoch freshness.

### Aggregation

The core business logic: collecting fingerprints from many sites with the
same environment, merging them, filtering noise, and compiling a profile
that works for any site in that group.

---

## Getting Started

1. Create a new Laravel project:
   ```bash
   composer create-project laravel/laravel pigcache-cloud
   ```

2. Copy `database/schema.sql` and create a migration from it.

3. Implement routes from `API-SPEC.md` as controllers.

4. Copy `services/*.php` into `app/Services/` and adapt to Laravel conventions
   (dependency injection, Eloquent models, etc.).

5. Set up queue workers for profile compilation jobs.

6. Deploy and update the plugin's `PIGCACHE_CLOUD_API_URL` constant to point
   to your API.
