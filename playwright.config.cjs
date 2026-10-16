// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/playwright',
  testMatch: '**/*.spec.cjs',
  timeout: 120_000,         // 2 min per test
  expect: { timeout: 10000 },
  fullyParallel: false,      // sequential — login first, then audit
  retries: 0,
  reporter: [
    ['list'],
    ['html', { outputFolder: 'tests/playwright/report', open: 'never' }],
  ],
  use: {
    baseURL: 'https://erp.dhakabypass.com',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    actionTimeout: 15000,
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    extraHTTPHeaders: {
      'Accept-Language': 'en-US,en;q=0.9',
      'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
    },
  },
  projects: [
    {
      name: 'chromium',
      use: {
        browserName: 'chromium',
        launchOptions: {
          executablePath: process.env.CHROME_BIN || (require('fs').existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined),
          args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-blink-features=AutomationControlled',
          ],
        },
      },
    },
    // ─── Replay.io: Time-travel debugging for recorded test runs ───
    // Run with:  npx playwright test --project=replay-chromium
    // Upload:    npx @replayio/replay upload --all
    // Degrades gracefully if @replayio/replay is not installed.
    ...((() => {
      try {
        const { getExecutablePath } = require('@replayio/replay');
        return [{
          name: 'replay-chromium',
          use: {
            browserName: 'chromium',
            launchOptions: {
              executablePath: getExecutablePath('chromium'),
              env: { ...process.env, RECORD_ALL_CONTENT: '1' },
              args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
              ],
            },
          },
        }];
      } catch {
        return []; // @replayio/replay not installed — skip this project
      }
    })()),
  ],
});
