# PigCache Cloud — REST API Specification

Base URL: `https://api.pigcache.com/v1`

All requests/responses use `Content-Type: application/json`.

---

## Premium Model

The SQL Profiler is a premium feature. The backend must enforce:

| Feature | Free | Trial | Pro |
|---------|------|-------|-----|
| License activate/deactivate | Yes | Yes | Yes |
| License status | Yes | Yes | Yes |
| Environment sync | No | No | **Yes** |
| Fingerprint upload | No | No | **Yes** |
| Profile download | No | No | **Yes** |
| Ping | Yes | Yes | Yes |

- **Trial** is managed **entirely in the plugin** (14-day timer). The backend
  does not track trial state. It simply returns `plan: "free"` for unlicensed
  sites. The plugin decides locally whether the trial is still active.
- **Pro** is a paid license. The backend gates cloud endpoints (environment,
  fingerprints, profiles) behind `plan != "free"`.
- When implementing, return **403 Forbidden** with `code: "plan_required"` for
  non-Pro requests to gated endpoints.

---

## Authentication

| Method | Header | When |
|--------|--------|------|
| API key in body | — | Only `POST /license/activate` |
| Bearer token | `Authorization: Bearer {api_key}` | All other endpoints |
| Site identifier | `X-Site-Id: {site_id}` | All endpoints after activation |

---

## Endpoints

### 1. POST /v1/license/activate

Activate a license key for a site. Returns a `site_id` used in all
subsequent requests.

**Request:**

```json
{
  "api_key": "pc_live_abc123def456...",
  "site_url": "https://example.com",
  "site_name": "My WordPress Site"
}
```

**Response (200):**

```json
{
  "valid": true,
  "plan": "pro",
  "site_id": "site_a1b2c3d4",
  "expires_at": "2027-04-16T00:00:00Z",
  "sites_used": 1,
  "sites_max": 5
}
```

**Response (401 — invalid key):**

```json
{
  "valid": false,
  "message": "Invalid or expired API key."
}
```

**Response (403 — seat limit):**

```json
{
  "valid": false,
  "message": "License seat limit reached (5/5). Deactivate another site first."
}
```

---

### 2. POST /v1/license/deactivate

Release a site seat from the license.

**Headers:** `Authorization: Bearer {api_key}`

**Request:**

```json
{
  "site_id": "site_a1b2c3d4"
}
```

**Response (200):**

```json
{
  "deactivated": true,
  "sites_used": 0,
  "sites_max": 5
}
```

---

### 3. GET /v1/license/status

Check the current license status (cached by the plugin for 24h).

**Headers:** `Authorization: Bearer {api_key}`

**Response (200):**

```json
{
  "valid": true,
  "plan": "pro",
  "expires_at": "2027-04-16T00:00:00Z",
  "sites_used": 2,
  "sites_max": 5,
  "features": ["cloud_profiles", "priority_support"]
}
```

---

### 4. POST /v1/sites/{site_id}/environment

Register or update the site's plugin/theme environment. The backend uses
this to match the site against existing compiled profiles.

> **Pro only.** Return 403 `plan_required` if the license plan is `free`.

**Headers:** `Authorization: Bearer {api_key}`, `X-Site-Id: {site_id}`

**Request:**

```json
{
  "plugins": [
    "woocommerce",
    "jetpack",
    "contact-form-7",
    "yoast-seo"
  ],
  "theme": "astra",
  "wp_version": "6.7.2",
  "php_version": "8.2"
}
```

**Response (200 — profile available):**

```json
{
  "environment_hash": "a1b2c3d4e5f6...",
  "has_profile": true,
  "profile_hash": "prof_xyz789",
  "profile_compiled_at": "2026-04-10T14:30:00Z",
  "profile_templates": 347,
  "sites_contributing": 42
}
```

**Response (200 — no profile yet):**

```json
{
  "environment_hash": "a1b2c3d4e5f6...",
  "has_profile": false,
  "profile_hash": null,
  "message": "No profile available yet. Start local learning and sync fingerprints."
}
```

---

### 5. POST /v1/sites/{site_id}/fingerprints

Upload a batch of learned SQL fingerprints. Called periodically by
WP-Cron (twice daily) or manually via "Sync Now".

> **Pro only.** Return 403 `plan_required` if the license plan is `free`.

**Headers:** `Authorization: Bearer {api_key}`, `X-Site-Id: {site_id}`

**Request:**

```json
{
  "fingerprints": [
    {
      "fingerprint": "abc123def456...",
      "template": "SELECT wp_posts.* FROM wp_posts WHERE wp_posts.post_type = ? AND wp_posts.post_status = ? ORDER BY wp_posts.post_date DESC LIMIT ?",
      "tables": ["wp_posts"],
      "hit_count": 1523,
      "avg_rows": 10.5
    },
    {
      "fingerprint": "789ghi012jkl...",
      "template": "SELECT wp_options.option_value FROM wp_options WHERE wp_options.option_name = ?",
      "tables": ["wp_options"],
      "hit_count": 8901,
      "avg_rows": 1.0
    }
  ]
}
```

**Response (200):**

```json
{
  "received": 2,
  "new_count": 1,
  "updated_count": 1,
  "total_fingerprints_for_environment": 234
}
```

**Validation rules:**
- `fingerprint`: required, 32 char hex string
- `template`: required, non-empty string, max 4096 chars
- `tables`: required, non-empty array of strings
- `hit_count`: required, integer >= 1
- `avg_rows`: optional, float >= 0
- Max 500 fingerprints per request

---

### 6. GET /v1/sites/{site_id}/profile

Download the compiled profile for this site's environment. The profile is
the same data structure the plugin uses locally.

> **Pro only.** Return 403 `plan_required` if the license plan is `free`.

**Headers:** `Authorization: Bearer {api_key}`, `X-Site-Id: {site_id}`

**Response (200 — profile available):**

```json
{
  "profile_hash": "prof_xyz789",
  "compiled_at": 1713300000,
  "environment_hash": "a1b2c3d4e5f6...",
  "sites_contributing": 42,
  "data": {
    "compiled_at": 1713300000,
    "unique_templates": 347,
    "query_count": 125000,
    "source": "cloud",
    "map": {
      "abc123def456...": ["wp_posts"],
      "789ghi012jkl...": ["wp_options"],
      "mno345pqr678...": ["wp_posts", "wp_postmeta"],
      "stu901vwx234...": ["wp_terms", "wp_term_taxonomy", "wp_term_relationships"]
    },
    "tables": {
      "wp_posts": ["abc123def456...", "mno345pqr678..."],
      "wp_postmeta": ["mno345pqr678..."],
      "wp_options": ["789ghi012jkl..."],
      "wp_terms": ["stu901vwx234..."],
      "wp_term_taxonomy": ["stu901vwx234..."],
      "wp_term_relationships": ["stu901vwx234..."]
    },
    "stats": {
      "abc123def456...": {
        "hits": 45000,
        "avg_rows": 10.5,
        "template": "SELECT wp_posts.* FROM wp_posts WHERE ..."
      }
    }
  }
}
```

**Response (404 — no profile):**

```json
{
  "message": "No compiled profile available for this environment yet."
}
```

---

### 7. GET /v1/profiles/{profile_hash}

Download a profile by its hash (shared across sites with the same
environment). Identical response format to endpoint 6.

**Headers:** `Authorization: Bearer {api_key}`

---

### 8. GET /v1/ping

Health check endpoint. No authentication required.

**Response (200):**

```json
{
  "status": "ok",
  "version": "1.0.0"
}
```

---

## Error Responses

All errors follow this format:

```json
{
  "message": "Human-readable error description.",
  "code": "error_code"
}
```

| HTTP Status | Code | Meaning |
|-------------|------|---------|
| 400 | `validation_error` | Request body failed validation |
| 401 | `unauthorized` | Missing or invalid API key |
| 403 | `forbidden` | Valid key but insufficient permissions |
| 403 | `plan_required` | Endpoint requires Pro plan; site is on free plan |
| 404 | `not_found` | Resource not found |
| 429 | `rate_limited` | Too many requests (include `Retry-After` header) |
| 500 | `server_error` | Internal server error |

---

## Rate Limits

| Plan | Requests/hour | Fingerprints/day |
|------|--------------|-----------------|
| Pro | 120 | 10,000 |
| Agency | 600 | 50,000 |

Rate limit headers are included in every response:

```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 118
X-RateLimit-Reset: 1713303600
```

---

## Webhook (Future)

The backend can optionally call a webhook on the WordPress site when a
new profile is compiled:

```
POST {site_url}/wp-json/pigcache/v1/profile-ready

{
  "profile_hash": "prof_xyz789",
  "compiled_at": 1713300000,
  "templates": 347
}
```

The plugin would register this REST route and trigger an immediate
profile download.

---

## Plugin Constants Reference

These constants control how the plugin communicates with this API:

| Constant | Default | Description |
|----------|---------|-------------|
| `PIGCACHE_LICENSE_KEY` | _(empty)_ | API key (alternative to admin UI) |
| `PIGCACHE_CLOUD_API_URL` | `https://api.pigcache.com/v1` | Backend URL override |
| `PIGCACHE_CLOUD_SYNC` | `true` (when pro) | Enable/disable cloud sync |
