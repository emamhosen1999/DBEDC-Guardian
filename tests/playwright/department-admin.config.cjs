// Playwright config for the isolated Department Admin scenario (docker container `guardian-e2e` on :8123).
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: '.',
    testMatch: 'department-admin.spec.cjs',
    timeout: 90_000,
    expect: { timeout: 15_000 },
    workers: 1,
    fullyParallel: false,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: 'http://localhost:8123',
        headless: true,
        viewport: { width: 1440, height: 900 },
        trace: 'off',
        // The pinned browser build on this machine (Playwright's expected revision is not installable here).
        launchOptions: { executablePath: process.env.E2E_CHROMIUM || '/home/emam-hosen/.cache/ms-playwright/chromium_headless_shell-1234/chrome-headless-shell-linux64/chrome-headless-shell' },
        screenshot: 'only-on-failure',
    },
    outputDir: '/tmp/claude-1000/-home-emam-hosen-Git-Repositories-DBEDC-Guardian/487f08e7-435b-4209-880c-3ae0d5678933/scratchpad/pw-results',
});
