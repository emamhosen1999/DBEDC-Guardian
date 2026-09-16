// @ts-check
/**
 * Page-state restoration — the acceptance scenarios, driven in a real browser.
 *
 * Runs against a local instance (see playwright.page-state.config.cjs) with a
 * real account supplied through the environment. Nothing here is hardcoded to
 * production and no credential lives in the repo.
 *
 *   E2E_EMAIL=you@example.com E2E_PASSWORD=... npx playwright test -c playwright.page-state.config.cjs
 */
const { test, expect } = require('@playwright/test');

const EMAIL = process.env.E2E_EMAIL;
const PASS = process.env.E2E_PASSWORD;

test.skip(!EMAIL || !PASS, 'Set E2E_EMAIL and E2E_PASSWORD to run the page-state scenarios.');

/** Query params of the current URL as a plain object. */
const params = (page) => Object.fromEntries(new URL(page.url()).searchParams.entries());

async function login(page) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"], input[name="email"]', EMAIL);
  await page.fill('input[type="password"], input[name="password"]', PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 20_000 });
}

/** The Employees list's search box (shared SearchFilterBar, accessible name = placeholder). */
const employeesSearch = (page) => page.getByRole('searchbox').first();

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page }) => {
  await login(page);
});

test('S1/S7 search survives navigating away, Back, and a hard refresh', async ({ page }) => {
  await page.goto('/employees');
  await employeesSearch(page).fill('a');

  // Debounced: the URL follows once typing pauses.
  await expect.poll(() => params(page).search, { timeout: 5000 }).toBe('a');

  await page.goto('/departments');
  await page.goBack();

  await expect(page).toHaveURL(/\/employees\?.*search=a/);
  await expect(employeesSearch(page)).toHaveValue('a');

  await page.reload();
  await expect(page).toHaveURL(/search=a/);
  await expect(employeesSearch(page)).toHaveValue('a');
});

test('S12 typing "rah" leaves ONE history entry, not one per character', async ({ page }) => {
  await page.goto('/departments');
  await page.goto('/employees');

  const box = employeesSearch(page);
  await box.pressSequentially('rah', { delay: 60 });
  await expect.poll(() => params(page).search, { timeout: 5000 }).toBe('rah');

  // Search commits with `replace`, so the typing left no entries of its own:
  // one Back is the previous *page*, not ?search=ra, and one Forward is the
  // settled search.
  await page.goBack();
  await expect(page).toHaveURL(/\/departments$/);

  await page.goForward();
  await expect(page).toHaveURL(/\/employees\?.*search=rah/);
  await expect(employeesSearch(page)).toHaveValue('rah');
});

test('S5 a tab is URL state: survives refresh and Back, and a bad tab falls back', async ({ page }) => {
  await page.goto('/employees');
  await page.getByRole('tab', { name: /departments/i }).click();
  await expect(page).toHaveURL(/tab=departments/);

  await page.reload();
  await expect(page.getByRole('tab', { name: /departments/i })).toHaveAttribute('data-state', 'active');

  await page.goto('/employees?tab=does-not-exist');
  await expect(page.getByRole('tab', { name: /^employees/i })).toHaveAttribute('data-state', 'active');
});

test('S6/S8 two pages keep independent state through Back and Forward', async ({ page }) => {
  await page.goto('/employees?search=alpha');
  await expect(employeesSearch(page)).toHaveValue('alpha');

  await page.goto('/attendance?tab=monthly');
  await expect(page.getByRole('tab', { name: /monthly/i })).toHaveAttribute('data-state', 'active');

  await page.goBack();
  await expect(page).toHaveURL(/\/employees\?.*search=alpha/);
  await expect(employeesSearch(page)).toHaveValue('alpha');

  await page.goForward();
  await expect(page).toHaveURL(/\/attendance\?.*tab=monthly/);
  await expect(page.getByRole('tab', { name: /monthly/i })).toHaveAttribute('data-state', 'active');
});

test('S9 reset clears the query back to a bare path', async ({ page }) => {
  await page.goto('/employees?search=alpha&status=active&page=2');
  await expect(employeesSearch(page)).toHaveValue('alpha');

  const clear = page.getByRole('button', { name: /reset all|clear filters/i }).first();
  await expect(clear).toBeVisible();
  await clear.click();

  await expect(page).toHaveURL(/\/employees(\?tab=employees)?$/);
  await expect(employeesSearch(page)).toHaveValue('');
});

test('P28 a page number beyond the last page snaps to the last page', async ({ page }) => {
  await page.goto('/employees?page=999');
  await expect.poll(() => Number(params(page).page ?? 1), { timeout: 10_000 }).toBeLessThan(999);
});

test('S3 a server-rendered list restores its page after visiting a detail and coming Back', async ({ page }) => {
  await page.goto('/admin/device-sessions');
  const next = page.getByRole('button', { name: /next page/i }).first();

  if (await next.isEnabled()) {
    await next.click();
    await expect(page).toHaveURL(/page=2/);
    await page.goto('/departments');
    await page.goBack();
    await expect(page).toHaveURL(/\/admin\/device-sessions\?.*page=2/);
  } else {
    test.info().annotations.push({ type: 'note', description: 'Only one page of device sessions in this dataset; pagination step skipped.' });
  }
});

test('S10 after logout the remembered pages are not reachable via Back', async ({ page }) => {
  await page.goto('/employees?search=secret');

  // Sign out through the real UI so the CSRF-protected POST is genuine.
  await page.getByRole('button', { name: /user menu/i }).click();
  await page.getByRole('menuitem', { name: /sign out/i }).click();
  await page.waitForURL(/\/login/, { timeout: 20_000 });

  // Back must not resurrect the authenticated page. Responses carry
  // Cache-Control: no-store, which keeps Chromium and Firefox from serving a
  // bfcache snapshot; WebKit may still show one until the page is touched, so
  // reload to prove the session — and the remembered state — are really gone.
  await page.goBack().catch(() => {});
  await page.reload();
  await expect(page).toHaveURL(/\/login/);
});
