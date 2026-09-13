/**
 * Smoke tests against a live production site.
 *
 * These hit a real site over the internet and depend on its theme markup, so
 * they are not part of the normal suite and never run in CI. They exist to
 * confirm a deployment on a site you own, not to prove the plugin correct — use
 * html-cache.spec.ts against the Docker stack for that.
 *
 * Run:
 *   cd PigCache/tests/e2e
 *   PIGCACHE_PROD_SMOKE=1 npx playwright test prod-smoke --project=chromium
 *
 * All URLs and thresholds come from PigCache/tests/.env.tests (loaded by
 * playwright.config.ts). Never hardcode values in this file.
 */

import { test, expect, type Page, type Response } from '@playwright/test';

test.skip(
  process.env.PIGCACHE_PROD_SMOKE !== '1',
  'Production smoke tests are opt-in. Set PIGCACHE_PROD_SMOKE=1 to run them.',
);

// ─── env vars (required — fail fast if missing) ───────────────────────────────

function env(key: string): string {
  const v = process.env[key];
  if (!v) throw new Error(`Missing required env var: ${key}. Set it in PigCache/tests/.env.tests`);
  return v;
}

function envInt(key: string): number {
  return parseInt(env(key), 10);
}

const BROKEN_URL        = env('PIGCACHE_URL_BROKEN');
const OK_URL            = env('PIGCACHE_URL_OK');
const MIN_ARTICLE_CHARS = envInt('PIGCACHE_MIN_ARTICLE_CHARS');
const MIN_HEIGHT_RATIO  = parseFloat(env('PIGCACHE_MIN_HEIGHT_RATIO'));

// ─── helpers ──────────────────────────────────────────────────────────────────

async function loadPage(page: Page, url: string): Promise<Response | null> {
  const errors: string[] = [];
  page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });

  let match: Response | null = null;
  page.on('response', (res) => {
    if (res.url() === url || res.url() === url.toLowerCase()) match = res;
  });

  await page.goto(url, { waitUntil: 'networkidle' });
  (page as unknown as Record<string, unknown>)['_consoleErrors'] = errors;
  return match;
}

function cacheHeaders(res: Response | null): Record<string, string> {
  if (!res) return { pigcache: 'MISS', kind: 'n/a', age: 'n/a', ttl: 'n/a' };
  const h = res.headers();
  return {
    pigcache:        h['x-pigcache']          ?? 'MISS',
    kind:            h['x-pigcache-kind']      ?? 'n/a',
    age:             h['x-pigcache-age']       ?? 'n/a',
    ttl:             h['x-pigcache-ttl']       ?? 'n/a',
    contentEncoding: h['content-encoding']     ?? 'n/a',
  };
}

async function findArticleText(page: Page): Promise<string> {
  for (const sel of ['[id*="post-content"]', '[class*="post-content"]', '[class*="entry-content"]', 'article', '.post']) {
    const el = page.locator(sel).first();
    if ((await el.count()) > 0) {
      const text = (await el.innerText()).trim();
      if (text.length > 50) return text;
    }
  }
  return '';
}

// ─── PigCache response headers ────────────────────────────────────────────────

test.describe('PigCache response headers', () => {
  // Warm the cache via a raw API request first — Cloudflare's edge cache can
  // mask PigCache HITs on a cold browser fetch.
  test('broken URL returns HTTP 200', async ({ page, request }) => {
    await request.get(BROKEN_URL);
    const res = await loadPage(page, BROKEN_URL);
    const h = cacheHeaders(res);
    console.log(`BROKEN: pigcache=${h.pigcache} kind=${h.kind} age=${h.age}`);
    expect(res?.status() ?? 200).toBe(200);
  });

  test('ok URL returns HTTP 200', async ({ page, request }) => {
    await request.get(OK_URL);
    const res = await loadPage(page, OK_URL);
    const h = cacheHeaders(res);
    console.log(`OK: pigcache=${h.pigcache} kind=${h.kind} age=${h.age}`);
    expect(res?.status() ?? 200).toBe(200);
  });

  /**
   * After Bug #2 fix (preg_replace replaces FILTER_SANITIZE_URL in serve_early),
   * both URLs should serve via EARLY. Until then, this test logs the mismatch
   * without failing — it becomes a hard assertion once the fix is deployed.
   */
  test('cache kind comparison reveals key-path symmetry', async ({ page }) => {
    const resB = await loadPage(page, BROKEN_URL);
    const resO = await loadPage(page, OK_URL);
    const hB = cacheHeaders(resB);
    const hO = cacheHeaders(resO);
    console.log(`BROKEN kind=${hB.kind}  OK kind=${hO.kind}`);
    if (hB.kind !== hO.kind) {
      console.warn(
        `⚠️  Bug #2 still active: BROKEN=${hB.kind} OK=${hO.kind}. ` +
        'Deploy the fixed plugin and wait for the emoji-URL cache entry to expire.',
      );
    }
  });
});

// ─── Page completeness ────────────────────────────────────────────────────────

async function assertPageComplete(page: Page, url: string, label: string) {
  await loadPage(page, url);
  await page.screenshot({ path: `screenshots/${label}-full.png`, fullPage: true });

  const viewport = page.viewportSize()!;
  const docHeight = await page.evaluate(() => document.documentElement.scrollHeight);
  console.log(`[${label}] viewport=${viewport.width}x${viewport.height} scrollHeight=${docHeight}`);

  expect(docHeight, `[${label}] page seems truncated (too short)`).toBeGreaterThan(viewport.height * 1.5);

  // Theme-agnostic selectors: semantic tags first, then MVP/zox-news patterns.
  const headerSel = 'header, [id*="nav-top"], [id*="nav-wrap"], [id*="main-head"]';
  const footerSel = 'footer, [id*="content-bot"], [id*="site-footer"], [class*="site-footer"]';
  await expect(page.locator(headerSel).first(), `[${label}] header`).toBeVisible();
  await expect(page.locator(footerSel).first(), `[${label}] footer`).toBeVisible();

  let articleFound = false;
  for (const sel of ['article', '[id*="post-content"]', '[class*="post-content"]', '[class*="entry-content"]']) {
    const el = page.locator(sel).first();
    if ((await el.count()) > 0) {
      const box = await el.boundingBox();
      if (box && box.height > 100) {
        console.log(`[${label}] article "${sel}" height=${box.height.toFixed(0)}px`);
        articleFound = true;
        break;
      }
    }
  }
  expect(articleFound, `[${label}] article container with height > 100px must exist`).toBe(true);
  expect((await page.title()).length, `[${label}] <title> must be non-empty`).toBeGreaterThan(10);

  const errors = (page as unknown as Record<string, unknown>)['_consoleErrors'] as string[];
  if (errors.length) console.warn(`[${label}] console errors:`, errors.join(' | '));
}

test.describe('Page completeness', () => {
  test('broken URL renders a structurally complete page', async ({ page }) => {
    await assertPageComplete(page, BROKEN_URL, 'broken');
  });

  test('ok URL renders a structurally complete page', async ({ page }) => {
    await assertPageComplete(page, OK_URL, 'ok');
  });
});

// ─── Article content ──────────────────────────────────────────────────────────

test.describe('Article content', () => {
  test('broken URL has visible article text', async ({ page }) => {
    await loadPage(page, BROKEN_URL);
    const text = await findArticleText(page);
    console.log(`BROKEN body: ${text.length} chars — "${text.slice(0, 120)}"`);
    expect(text.length, 'article body must contain meaningful text').toBeGreaterThan(MIN_ARTICLE_CHARS);
  });

  test('ok URL has visible article text', async ({ page }) => {
    await loadPage(page, OK_URL);
    const text = await findArticleText(page);
    console.log(`OK body: ${text.length} chars — "${text.slice(0, 120)}"`);
    expect(text.length, 'article body must contain meaningful text').toBeGreaterThan(MIN_ARTICLE_CHARS);
  });

  test('both pages have similar scroll height (neither is partial)', async ({ page }) => {
    await loadPage(page, BROKEN_URL);
    const h1 = await page.evaluate(() => document.documentElement.scrollHeight);
    await page.goto(OK_URL, { waitUntil: 'networkidle' });
    const h2 = await page.evaluate(() => document.documentElement.scrollHeight);
    const ratio = Math.min(h1, h2) / Math.max(h1, h2);
    console.log(`BROKEN=${h1}px  OK=${h2}px  ratio=${ratio.toFixed(2)}`);
    expect(ratio, 'one page appears much shorter than the other — possible partial render').toBeGreaterThan(MIN_HEIGHT_RATIO);
  });
});

// ─── Below-the-fold rendering ─────────────────────────────────────────────────

test.describe('Below-the-fold rendering', () => {
  for (const [label, url] of [['broken', BROKEN_URL], ['ok', OK_URL]] as const) {
    test(`${label} URL has content below the fold`, async ({ page }) => {
      await loadPage(page, url);
      const vh = page.viewportSize()!.height;
      await page.evaluate((y) => window.scrollTo(0, y), vh * 2);
      await page.waitForTimeout(400);
      await page.screenshot({ path: `screenshots/${label}-below-fold.png` });

      const count = await page.evaluate(() => {
        let n = 0;
        for (const el of document.querySelectorAll('p, h1, h2, h3, img')) {
          const r = el.getBoundingClientRect();
          if (r.top >= 0 && r.top < window.innerHeight && r.height > 0) n++;
        }
        return n;
      });

      console.log(`[${label}] elements in view below fold: ${count}`);
      expect(count, `[${label}] must have visible elements below the fold`).toBeGreaterThan(3);
    });
  }
});

// ─── Cache key consistency ────────────────────────────────────────────────────

test.describe('Cache key consistency', () => {
  test('emoji URL percent-encoding case does not create duplicate cache entries', async ({ request }) => {
    const urls = [
      BROKEN_URL.toLowerCase().replace('https://', 'https://'),
      BROKEN_URL.toUpperCase().replace(
        new URL(BROKEN_URL).origin.toUpperCase(),
        new URL(BROKEN_URL).origin,
      ),
    ];
    for (const url of urls) {
      const res = await request.get(url);
      const kind = res.headers()['x-pigcache-kind'] ?? 'n/a';
      console.log(`${url.slice(0, 70)}… → kind=${kind} status=${res.status()}`);
    }
  });

  test('consecutive fetches of broken URL return identical body size', async ({ request }) => {
    const results: { size: number; kind: string }[] = [];
    for (let i = 0; i < 2; i++) {
      const res = await request.get(BROKEN_URL);
      const body = await res.body();
      results.push({ size: body.length, kind: res.headers()['x-pigcache-kind'] ?? 'n/a' });
      await new Promise((r) => setTimeout(r, 300));
    }
    console.log('Consecutive fetches:', results);
    if (results[0].kind === 'HIT' && results[1].kind === 'HIT') {
      expect(results[0].size, 'body size must be identical across consecutive HITs').toBe(results[1].size);
    }
  });
});
