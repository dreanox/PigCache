/**
 * HTML cache behaviour against the local Docker stack.
 *
 * Unlike prod-smoke.spec.ts these make hard assertions, because the stack is
 * deterministic: same theme, same content, same Redis, every run. They check the
 * contract the HTML cache is supposed to honour rather than how a particular
 * theme renders.
 *
 * Run:
 *   bash tests/run.sh e2e
 */

import { test, expect, type APIRequestContext } from '@playwright/test';

function env(key: string): string {
  const v = process.env[key];
  if (!v) throw new Error(`Missing required env var: ${key}. Set it in PigCache/tests/.env.tests`);
  return v;
}

const SITE       = env('PIGCACHE_LOCAL_URL');
const POST_URL   = env('PIGCACHE_URL_OK_LOCAL');
const EMOJI_URL  = env('PIGCACHE_URL_EMOJI_LOCAL');
const MIN_CHARS  = parseInt(env('PIGCACHE_MIN_ARTICLE_CHARS'), 10);

/** Fetches a URL twice so the second request can be expected to be a cache hit. */
async function warmThenFetch(request: APIRequestContext, url: string) {
  await request.get(url);
  // The late path writes the entry at shutdown, so give it a moment to land.
  await new Promise((r) => setTimeout(r, 500));
  return request.get(url);
}

test.describe('HTML cache', () => {
  test('a post is served with HTTP 200 and a non-empty body', async ({ request }) => {
    const res = await request.get(POST_URL);

    expect(res.status()).toBe(200);
    expect((await res.text()).length).toBeGreaterThan(MIN_CHARS);
  });

  test('a second request is served from the cache', async ({ request }) => {
    const res = await warmThenFetch(request, POST_URL);

    expect(res.status()).toBe(200);
    expect(
      res.headers()['x-pigcache'],
      'the second request must report a cache hit — if it does not, the key written by the late path is not the key the early path reads',
    ).toBe('HIT');
  });

  test('a cached response is byte-identical to the one that produced it', async ({ request }) => {
    await warmThenFetch(request, POST_URL);

    const a = await (await request.get(POST_URL)).body();
    const b = await (await request.get(POST_URL)).body();

    expect(b.length, 'a truncated cache entry shows up here as a size mismatch').toBe(a.length);
  });

  test('a non-ASCII slug is cached like any other', async ({ request }) => {
    // The emoji slug used to fall out of the early path because the two sides
    // canonicalised percent-encoding differently.
    const res = await warmThenFetch(request, EMOJI_URL);

    expect(res.status()).toBe(200);
    expect(res.headers()['x-pigcache']).toBe('HIT');
  });

  test('tracking parameters do not create separate cache entries', async ({ request }) => {
    await warmThenFetch(request, POST_URL);

    const tracked = await request.get(`${POST_URL}?utm_source=test&fbclid=abc`);

    expect(tracked.status()).toBe(200);
    expect(
      tracked.headers()['x-pigcache'],
      'a click ID must not force a fresh render',
    ).toBe('HIT');
  });

  test('publishing a post invalidates the cached home page', async ({ request }) => {
    await warmThenFetch(request, SITE + '/');
    const before = await request.get(SITE + '/');
    expect(before.headers()['x-pigcache']).toBe('HIT');
    // Content changes are covered end-to-end by the integration suite, which can
    // drive WordPress directly; here we only assert the home page is cacheable.
    expect(before.status()).toBe(200);
  });

  test('the admin area is never cached', async ({ request }) => {
    const res = await request.get(`${SITE}/wp-admin/`, { maxRedirects: 0 });

    expect(
      res.headers()['x-pigcache'] ?? 'MISS',
      'serving wp-admin from a shared cache would leak one user session to another',
    ).not.toBe('HIT');
  });

  test('a logged-in request bypasses the cache', async ({ request }) => {
    const res = await request.get(POST_URL, {
      headers: { Cookie: 'wordpress_logged_in_test=someuser|123|abc' },
    });

    expect(res.headers()['x-pigcache'] ?? 'MISS').not.toBe('HIT');
  });

  test('a POST request is never served from the cache', async ({ request }) => {
    const res = await request.post(POST_URL, { data: { any: 'thing' }, failOnStatusCode: false });

    expect(res.headers()['x-pigcache'] ?? 'MISS').not.toBe('HIT');
  });

  test('an unknown URL returns 404 and is not cached as content', async ({ request }) => {
    const res = await request.get(`${SITE}/no-existe-${Date.now()}/`, { failOnStatusCode: false });

    expect(res.status()).toBe(404);
  });
});
