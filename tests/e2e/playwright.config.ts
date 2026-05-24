import { defineConfig, devices } from '@playwright/test';
import { config } from 'dotenv';
import { resolve } from 'path';

// Load shared test variables. .env.tests is committed; .env.tests.local is gitignored
// and can override any value for local development without touching the committed file.
config({ path: resolve(__dirname, '../.env.tests') });
config({ path: resolve(__dirname, '../.env.tests.local'), override: true });

export default defineConfig({
  testDir: '.',
  timeout: 30_000,
  retries: 1,
  reporter: [['html', { open: 'never' }], ['list']],
  use: {
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'mobile',
      use: { ...devices['Pixel 7'] },
    },
  ],
});
