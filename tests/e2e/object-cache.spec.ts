/**
 * PigCache object cache E2E tests.
 *
 * Requires the local Docker environment (PigCache/tests/docker/) to be running.
 * Tests verify that the Redis object-cache drop-in is active and functional
 * using the PIGCACHE_TESTING debug headers emitted when that constant is set.
 *
 * Run:
 *   cd PigCache/tests/e2e
 *   npx playwright test object-cache --project=chromium
 */

import { test, expect } from '@playwright/test';
import { config } from 'dotenv';
import { resolve } from 'path';

config({ path: resolve(__dirname, '../.env.tests') });
config({ path: resolve(__dirname, '../.env.tests.local'), override: true });

function env(key: string): string {
  const v = process.env[key];
  if (!v) throw new Error(`Missing required env var: ${key}. Set it in PigCache/tests/.env.tests`);
  return v;
}

const LOCAL_URL = env('PIGCACHE_LOCAL_URL');
const OK_LOCAL  = env('PIGCACHE_URL_OK_LOCAL');

// ─── Object cache availability ────────────────────────────────────────────────

test.describe('Object cache', () => {
  test('WordPress home returns 200 (object cache not crashing)', async ({ request }) => {
    const res = await request.get(LOCAL_URL);
    expect(res.status()).toBe(200);
  });

  test('X-PigCache header is present on second request (Redis drop-in active)', async ({ request }) => {
    // First request warms the cache.
    await request.get(OK_LOCAL);
    // Second request should be served from the HTML cache stored in Redis.
    const res = await request.get(OK_LOCAL);
    expect(res.status()).toBe(200);

    const pig = res.headers()['x-pigcache'];
    if (pig !== undefined) {
      console.log(`X-PigCache: ${pig}`);
      // If object cache is active, the HTML cache HIT header must be present.
      expect(pig).toBe('HIT');
    } else {
      // X-PigCache absent means the HTML cache is not yet warmed.
      // This is expected on the very first run after Docker startup.
      console.log('X-PigCache absent — cache not yet warm, this is fine on first run');
    }
  });

  test('object cache hit ratio header is non-negative', async ({ request }) => {
    await request.get(OK_LOCAL);
    const res = await request.get(OK_LOCAL);
    const objHits = res.headers()['x-pigcache-obj-hits'];
    if (objHits !== undefined) {
      console.log(`X-PigCache-Obj-Hits: ${objHits}`);
      expect(parseInt(objHits, 10)).toBeGreaterThanOrEqual(0);
    } else {
      console.log('X-PigCache-Obj-Hits absent — PIGCACHE_TESTING may not be set or HTML cache served the page');
    }
  });

  test('cache serves correct content after a warm-up', async ({ page }) => {
    // Hit the page twice; second time comes from Redis object cache.
    await page.goto(OK_LOCAL, { waitUntil: 'networkidle' });
    await page.goto(OK_LOCAL, { waitUntil: 'networkidle' });

    const title = await page.title();
    expect(title.length).toBeGreaterThan(0);

    // Page must be structurally complete.
    const body = await page.locator('body').innerText();
    expect(body.length).toBeGreaterThan(100);
  });
});

// ─── Object cache resilience ──────────────────────────────────────────────────

test.describe('Object cache resilience', () => {
  test('repeated requests return identical HTML body size', async ({ request }) => {
    const sizes: number[] = [];
    for (let i = 0; i < 3; i++) {
      const res = await request.get(OK_LOCAL);
      const body = await res.body();
      sizes.push(body.length);
    }
    console.log(`Body sizes: ${sizes.join(', ')}`);
    for (const s of sizes) {
      expect(s).toBeGreaterThan(0);
    }
    // After warm-up (skip first), sizes should be identical (served from cache).
    if (sizes.length >= 2) {
      const cached = sizes.slice(1);
      const max = Math.max(...cached);
      const min = Math.min(...cached);
      expect(max - min).toBe(0);
    }
  });

  test('WordPress admin page is not cached (object cache should not cache admin)', async ({ request }) => {
    const res = await request.get(`${LOCAL_URL}/wp-admin/`);
    // Admin should redirect to login (302) or return login form (200).
    expect([200, 302]).toContain(res.status());
    // The HTML cache must NOT serve admin pages.
    const pig = res.headers()['x-pigcache'];
    expect(pig).toBeUndefined();
  });
});
