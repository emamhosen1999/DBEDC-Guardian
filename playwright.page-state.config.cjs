// @ts-check
// Page-state restoration scenarios against a LOCAL instance, in all three
// engines. Run with:
//   E2E_EMAIL=... E2E_PASSWORD=... npx playwright test -c playwright.page-state.config.cjs
const { defineConfig } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';

module.exports = defineConfig({
  testDir: './tests/playwright',
  testMatch: '**/page-state.spec.cjs',
  timeout: 300_000,
  // The local php artisan serve instance takes ~10 s per full page load.
  expect: { timeout: 30_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: BASE,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    actionTimeout: 15_000,
  },
  projects: [
    { name: 'chromium', use: { browserName: 'chromium' } },
    { name: 'firefox', use: { browserName: 'firefox' } },
    { name: 'webkit', use: { browserName: 'webkit' } },
  ],
});
