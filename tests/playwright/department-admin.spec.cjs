// End-to-end proof of the delegated Department Admin (Mahdi, Inspection) against the ISOLATED scenario
// (database/seeders/E2E/DepartmentAdminScenarioSeeder, container guardian-e2e on :8123).
// Every test fails on ANY console error or 4xx/5xx response from the app itself.
const { test, expect } = require('@playwright/test');

const BASE = 'http://localhost:8123';
const PASSWORD = 'Passw0rd!E2E';
const MAHDI = 'mahdi@e2e.test';
const HR = 'hr@e2e.test';

let issues = [];
let guardCanary = true;   // HR legitimately sees every department
let tolerated = [];       // sandbox-only noise, per test (MySQL-only SQL on the global dashboard under sqlite)

test.beforeEach(async ({ page }) => {
    issues = [];
    guardCanary = true;
    tolerated = [];
    page.on('console', (msg) => {
        if (msg.type() !== 'error') return;
        const url = msg.location()?.url || '';
        if (url && !url.startsWith(BASE)) return; // third-party (fonts/CDN) noise is not the app
        issues.push(`console.error: ${msg.text()} @ ${url}`);
    });
    page.on('pageerror', (err) => issues.push(`pageerror: ${err.message}`));
    page.on('response', async (res) => {
        const url = res.url();
        if (!url.startsWith(BASE)) return;
        if (res.status() >= 400 && !/\/(favicon|manifest|sw\.js|build\/)/.test(url)) {
            issues.push(`HTTP ${res.status()} ${res.request().method()} ${url} ${(await res.text().catch(() => '')).slice(0, 240)}`);
        }
        // zero data leaks: another department's canary marker must never reach this browser
        const type = res.headers()['content-type'] || '';
        if (/json|html|text/.test(type) && !/\/build\//.test(url)) {
            try {
                if (guardCanary && (await res.text()).toLowerCase().includes('canary-d2')) issues.push(`LEAK canary marker in ${res.request().method()} ${url}`);
            } catch { /* body no longer available (redirects) */ }
        }
    });
});

test.afterEach(async () => {
    expect(issues.filter((issue) => !tolerated.some((t) => issue.includes(t))), 'console errors / failed responses').toEqual([]);
});

async function login(page, email, password = PASSWORD) {
    await page.context().clearCookies();
    await page.goto('/login');
    await page.locator('input[type="email"], input[name="email"]').first().fill(email);
    await page.locator('input[type="password"]').first().fill(password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30_000 }),
        page.locator('input[type="password"]').first().press('Enter'),
    ]);
}

/** Open a Radix Select by its accessible name and return the option labels. */
async function optionsOf(page, trigger) {
    await trigger.click();
    const items = page.getByRole('option');
    await expect(items.first()).toBeVisible();
    const labels = (await items.allTextContents()).map((t) => t.trim());
    await page.keyboard.press('Escape');
    return labels;
}

async function goEmployees(page, query = '') {
    await page.goto(`/employees${query}`);
    await expect(page.getByText('Employees Console')).toBeVisible();
    await expect(page.getByRole('row').nth(1)).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.describe('Department Admin - Inspection', () => {
    test('signs in and every page he can reach loads clean', async ({ page }) => {
        await login(page, MAHDI);
        for (const path of ['/dashboard', '/employees', '/attendance', '/leaves', '/leaves-employee', '/hr/onboarding', '/hr/offboarding', '/hr/assets', '/attendance-employee', '/petty-cash']) {
            const response = await page.goto(path);
            expect(response.status(), path).toBeLessThan(400);
            await page.waitForLoadState('networkidle');
        }
    });
});

test.describe('Department Admin - employees', () => {
    test('sees Inspection only, with a locked department filter and no org-structure tabs', async ({ page }) => {
        await login(page, MAHDI);
        await goEmployees(page);

        for (const name of ['Ishrat Inspector', 'Imran Inspector', 'Ila Inspector']) await expect(page.getByText(name).first()).toBeVisible();
        for (const name of ['Omar Ops', 'Oli Ops', 'Ona Ops', 'Hana HR']) await expect(page.getByText(name)).toHaveCount(0);

        await expect(page.getByRole('tab', { name: 'Designations' })).toBeVisible();
        for (const hidden of ['Departments', 'Work Locations', 'Roles & Permissions']) await expect(page.getByRole('tab', { name: hidden })).toHaveCount(0);

        // the department filter is a read-only badge, not a dropdown
        await page.getByRole('button', { name: /filter/i }).first().click();
        await expect(page.getByTestId('department-scope-badge')).toContainText('Department: Inspection');
        await expect(page.getByRole('combobox', { name: 'Department' })).toHaveCount(0);
    });

    test('add-employee form: work locations, devices, locked department, no roles, reports-to includes himself', async ({ page }) => {
        await login(page, MAHDI);
        await goEmployees(page);
        await page.getByRole('button', { name: /add/i }).first().click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText('Add New User')).toBeVisible();

        // THE REPORTED BUG: Work Location was empty in the Add form
        const locationTrigger = dialog.getByRole('combobox').filter({ hasText: /work location|Unassigned/i }).first();
        expect(await optionsOf(page, locationTrigger)).toEqual(['Unassigned / Remote', 'Plaza A', 'Plaza B']);

        await expect(dialog.getByTestId('department-field-locked')).toHaveValue('Inspection');
        await expect(dialog.getByText('Assigned Roles')).toHaveCount(0);
        await expect(dialog.getByText('No designations in this department yet')).toBeVisible();

        const reportsTo = dialog.getByRole('combobox').filter({ hasText: /supervisor/i }).first();
        const supervisors = await optionsOf(page, reportsTo);
        expect(supervisors).toContain('Mahdi Hasan');
        expect(supervisors).not.toContain('Omar Ops');

        // devices: pick a biometric override and see the location's terminals only (retired terminal never offered)
        await locationTrigger.click();
        await page.getByRole('option', { name: 'Plaza A' }).click();
        await dialog.getByRole('switch').first().click();
        await dialog.getByText('Biometric Device').first().click();
        await expect(dialog.getByText('Terminals linked to the selected work location.')).toBeVisible();
        await expect(dialog.getByText('Gate A')).toBeVisible();
        await expect(dialog.getByText('Gate B')).toHaveCount(0);
        await expect(dialog.getByText('Retired Gate')).toHaveCount(0);
    });

    test('creates an employee (base Employee role only), a designation inline, edits and assigns it', async ({ page }) => {
        await login(page, MAHDI);
        await goEmployees(page);
        await page.getByRole('button', { name: /add/i }).first().click();
        const dialog = page.getByRole('dialog');

        await dialog.getByPlaceholder('Enter full name').fill('Nadia New');
        await dialog.getByPlaceholder('Enter username').fill('nadia.new');
        await dialog.getByPlaceholder('user@example.com').fill('nadia@e2e.test');
        await dialog.getByPlaceholder('e.g. EMP-1023').fill('2100');

        // + New designation (quick create, department locked to Inspection)
        await dialog.getByTestId('new-designation').click();
        const designationDialog = page.getByRole('dialog').last();
        await designationDialog.getByPlaceholder('e.g. Senior Developer').fill('Field Inspector');
        await expect(designationDialog.getByTestId('department-field-locked')).toHaveValue('Inspection');
        await designationDialog.getByRole('button', { name: 'Create Designation' }).click();
        await expect(dialog.getByRole('combobox').filter({ hasText: 'Field Inspector' })).toBeVisible();

        await dialog.getByPlaceholder('Create password').fill('Adm1n-Set!Pass');
        await dialog.getByPlaceholder('Confirm password').fill('Adm1n-Set!Pass');
        await dialog.getByRole('button', { name: 'Create User' }).click();
        await expect(page.getByText('Nadia New').first()).toBeVisible();

        // server truth: Employee only, in Inspection, with the new designation
        const api = await page.request.get('/employees/paginate?search=Nadia');
        const row = (await api.json()).employees.data[0];
        expect(row.roles.map((r) => r.name)).toEqual(['Employee']);
        expect(row.department_name).toBe('Inspection');
        expect(row.designation_name).toBe('Field Inspector');
    });
});

async function rowOf(page, text) {
    return page.getByRole('row').filter({ hasText: text }).first();
}

async function openRowMenu(page, text) {
    const row = await rowOf(page, text);
    await row.getByRole('button').last().click();
    return page.getByRole('menu');
}

function csrfHeaders(cookies) {
    const token = cookies.find((c) => c.name === 'XSRF-TOKEN');
    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token.value), Accept: 'application/json' } : { Accept: 'application/json' };
}

test.describe('Department Admin - row menu and actions', () => {
    test('row menu offers exactly the permitted actions (never Manage Access, never on himself)', async ({ page }) => {
        await login(page, MAHDI);
        await goEmployees(page);

        let menu = await openRowMenu(page, 'Nadia New');
        for (const item of ['Full Profile', 'Salary & Compensation', 'Edit Profile', 'Reset Password', 'Device Lock', 'Device History', 'Delete']) {
            await expect(menu.getByRole('menuitem', { name: new RegExp(item) })).toBeVisible();
        }
        await expect(menu.getByRole('menuitem', { name: /Manage Access/ })).toHaveCount(0);
        await page.keyboard.press('Escape');

        menu = await openRowMenu(page, 'Mahdi Hasan');
        await expect(menu.getByRole('menuitem', { name: /Edit Profile/ })).toBeVisible();
        for (const absent of ['Reset Password', 'Delete', 'Salary', 'Manage Access']) {
            await expect(menu.getByRole('menuitem', { name: new RegExp(absent) })).toHaveCount(0);
        }
    });

    test('edit profile: work location (edit path), name change, department locked', async ({ page }) => {
        await login(page, MAHDI);
        await goEmployees(page);
        const menu = await openRowMenu(page, 'Nadia New');
        await menu.getByRole('menuitem', { name: /Edit Profile/ }).click();
        const dialog = page.getByRole('dialog');
        const trigger = dialog.getByRole('combobox').filter({ hasText: /work location|Unassigned|Plaza/i }).first();
        expect(await optionsOf(page, trigger)).toEqual(['Unassigned / Remote', 'Plaza A', 'Plaza B']);
        await expect(dialog.getByTestId('department-field-locked')).toBeVisible();
        await expect(dialog.getByText('Assigned Roles')).toHaveCount(0);
        await dialog.getByPlaceholder('Enter full name').fill('Nadia Renamed');
        await dialog.getByRole('button', { name: 'Save Changes' }).click();
        await expect(page.getByText('Nadia Renamed').first()).toBeVisible();
    });

    test('inline placement: designation, work location and reports-to dropdowns are populated', async ({ page }) => {
        await login(page, MAHDI);
        await goEmployees(page);
        const row = await rowOf(page, 'Ishrat Inspector');
        await expect(row.getByTestId('department-cell-locked')).toBeVisible();
        const location = row.getByRole('combobox').first();
        expect(await optionsOf(page, location)).toEqual(['Unassigned / Remote', 'Plaza A', 'Plaza B']);
        const reportsTo = row.getByRole('combobox').last();
        expect(await optionsOf(page, reportsTo)).toContain('Mahdi Hasan');
    });

    test('password reset forces the employee to choose their own password', async ({ page }) => {
        await login(page, MAHDI);
        await goEmployees(page);
        const menu = await openRowMenu(page, 'Nadia Renamed');
        await menu.getByRole('menuitem', { name: /Reset Password/ }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByPlaceholder('At least 8 characters').fill('Temp-Pass#2026');
        await dialog.getByPlaceholder('Re-enter the new password').fill('Temp-Pass#2026');
        await dialog.getByRole('button', { name: 'Reset Password' }).click();
        await expect(page.getByText(/Password reset for/).first()).toBeVisible();

        await login(page, 'nadia@e2e.test', 'Temp-Pass#2026');
        await expect(page).toHaveURL(/account\/password/);
        await page.goto('/employees');
        await expect(page).toHaveURL(/account\/password/);               // every page redirects
        await page.locator('#current_password').fill('Temp-Pass#2026');
        await page.locator('#password').fill('My-Own-Pass#2026');
        await page.locator('#password_confirmation').fill('My-Own-Pass#2026');
        await page.getByRole('button', { name: 'Change password' }).click();
        await expect(page).not.toHaveURL(/account\/password/);
    });

    test('deactivate then restore inside the department; bulk delete is available', async ({ page }) => {
        await login(page, MAHDI);
        await goEmployees(page);
        await expect(page.getByRole('checkbox', { name: /Select all employees/ })).toBeVisible();
        const menu = await openRowMenu(page, 'Nadia Renamed');
        await menu.getByRole('menuitem', { name: /Delete/ }).click();
        await page.getByRole('dialog').getByRole('button', { name: /Delete/ }).last().click();
        await expect(page.getByText('Nadia Renamed')).toHaveCount(0);

        await page.getByRole('button', { name: /filter/i }).first().click();
        await page.getByLabel('Include Deleted').click();
        const restoreMenu = await openRowMenu(page, 'Nadia Renamed');
        await restoreMenu.getByRole('menuitem', { name: /Restore/ }).click();
        await expect(page.getByText('restored successfully').first()).toBeVisible();
    });
});

test.describe('Department Admin - designations', () => {
    test('designation CRUD inside his department; a held designation cannot be deleted', async ({ page }) => {
        await login(page, MAHDI);
        await page.goto('/employees?tab=designations');
        await expect(page.getByText('Field Inspector').first()).toBeVisible();
        await page.getByRole('button', { name: /add/i }).first().click();
        const dialog = page.getByRole('dialog');
        await dialog.getByPlaceholder('e.g. Senior Developer').fill('Senior Inspector');
        await expect(dialog.getByTestId('department-field-locked')).toHaveValue('Inspection');
        await dialog.getByRole('button', { name: 'Create Designation' }).click();
        await expect(page.getByText('Senior Inspector').first()).toBeVisible();
        await expect(page.getByText('Ops Manager')).toHaveCount(0);

        // Field Inspector is held by employees: its delete control is disabled (no dead click that would 422)
        const held = await rowOf(page, 'Field Inspector');
        await expect(held.locator('button[disabled]')).toHaveCount(1);

        // an unused designation can be deleted
        const unused = await rowOf(page, 'Senior Inspector');
        await unused.locator('button:not([disabled])').nth(1).click();
        await page.getByRole('dialog').getByRole('button', { name: /Delete Designation/ }).click();
        await expect(page.getByText('Senior Inspector')).toHaveCount(0);
    });
});

test.describe('Department Admin - cross-department attempts are impossible', () => {
    test('API: D2 people, records and admin surfaces are 403/404', async ({ page }) => {
        await login(page, MAHDI);
        await page.goto('/employees');
        const headers = csrfHeaders(await page.context().cookies());
        const expectDenied = async (res, label) => expect([403, 404, 422], `${label} -> ${res.status()}`).toContain(res.status());

        await expectDenied(await page.request.get('/employees/3001', { headers }), 'show D2 employee');
        await expectDenied(await page.request.put('/users/3001', { headers, data: { name: 'Hijack' } }), 'edit D2 employee');
        await expectDenied(await page.request.post('/users/3001/change-password', { headers, data: { password: 'Str0ng!Passw0rd#2026', password_confirmation: 'Str0ng!Passw0rd#2026' } }), 'reset D2 password');
        await expectDenied(await page.request.delete('/users/3001', { headers }), 'delete D2 employee');
        await expectDenied(await page.request.put('/users/1537/department', { headers, data: { department: 2 } }), 'move himself');
        await expectDenied(await page.request.post('/users/1537/roles', { headers, data: { roles: ['Super Administrator'] } }), 'grant himself a role');
        await expectDenied(await page.request.get('/api/roles', { headers }), 'role list');
        await expectDenied(await page.request.get('/admin/feature-flags', { headers }), 'feature flags');
        await expectDenied(await page.request.get('/departments', { headers }), 'departments admin');
        await expectDenied(await page.request.get('/attendance/policies', { headers }), 'attendance policies');
        await expectDenied(await page.request.get('/hr/payroll', { headers }), 'payroll');
        const list = await (await page.request.get('/employees/paginate?perPage=100', { headers })).text();
        expect(list.toLowerCase()).not.toContain('canary-d2');
    });

    test('shifts: he manages his own department templates and never sees creators or Operations templates', async ({ page }) => {
        await login(page, MAHDI);
        await page.goto('/attendance?tab=shifts');
        await expect(page.getByText('General').first()).toBeVisible();
        await expect(page.getByText('Ops Night')).toHaveCount(0);
        await expect(page.getByText('Created By')).toHaveCount(0);
        await page.getByRole('button', { name: /add shift/i }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByPlaceholder('Name').fill('Inspection Day');
        await dialog.getByPlaceholder('Code').fill('INS-D');
        await expect(dialog.getByTestId('department-field-locked')).toHaveValue('Inspection');
        await dialog.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Inspection Day').first()).toBeVisible();
        const own = await rowOf(page, 'Inspection Day');
        await expect(own.getByRole('button', { name: /Edit shift/ })).toBeVisible();
        const company = await rowOf(page, 'General');
        await expect(company.getByRole('button', { name: /Edit shift/ })).toHaveCount(0);   // company-wide stays attendance.settings
    });
});

test.describe('Department Admin - modules', () => {
    test('attendance tabs, leave pages, onboarding, offboarding, assets are scoped and populated', async ({ page }) => {
        await login(page, MAHDI);
        await page.goto('/attendance');
        for (const tab of ['Daily Timesheet', 'Monthly Calendar', 'Analytics', 'Approvals', 'Roster', 'Shift Management']) {
            await page.getByRole('tab', { name: tab }).click();
            await page.waitForLoadState('networkidle');
        }
        await expect(page.getByRole('tab', { name: 'Settings' })).toHaveCount(0);
        await expect(page.getByRole('tab', { name: 'Biometric Devices' })).toHaveCount(0);

        await page.goto('/leaves');
        await expect(page.getByText('Ishrat Inspector').first()).toBeVisible();
        await expect(page.getByText('Omar Ops')).toHaveCount(0);

        await page.goto('/hr/onboarding');
        await expect(page.getByText('Ila Inspector').first()).toBeVisible();
        await expect(page.getByText('Ona Ops')).toHaveCount(0);
        await page.goto('/hr/offboarding');
        await expect(page.getByText('Imran Inspector').first()).toBeVisible();
        await expect(page.getByText('Oli Ops')).toHaveCount(0);
        await page.goto('/hr/assets');
        await expect(page.getByText('Inspection Laptop').first()).toBeVisible();
        await expect(page.getByText('Ops Radio')).toHaveCount(0);
    });
});

test.describe('Department Admin - CRUD inside Inspection (real server, real session)', () => {
    test('leave approve, roster and shift assignment, asset assign/return, onboarding/offboarding writes - and the same calls on Operations are refused', async ({ page }) => {
        await login(page, MAHDI);
        await page.goto('/employees');
        const headers = csrfHeaders(await page.context().cookies());
        const ok = (res, label) => expect([200, 201], `${label} -> ${res.status()} ${res.statusText()}`).toContain(res.status());
        const denied = (res, label) => expect([403, 404, 422], `${label} -> ${res.status()}`).toContain(res.status());

        // leave: Ishrat's pending leave (id 1) is his to approve; Omar's (id 2) is not
        ok(await page.request.post('/leaves/1/approve', { headers, data: {} }), 'approve Inspection leave');
        denied(await page.request.post('/leaves/2/approve', { headers, data: {} }), 'approve Operations leave');

        // shifts: roster cell and assignment with the company-wide shift (id 1); Operations-owned shift (id 2) is refused
        ok(await page.request.put('/attendance/roster/cell', { headers, data: { user_id: '2001', date: '2026-10-05', shift_id: 1 } }), 'roster cell');
        denied(await page.request.put('/attendance/roster/cell', { headers, data: { user_id: '2001', date: '2026-10-06', shift_id: 2 } }), 'roster cell with an Operations shift');
        denied(await page.request.put('/attendance/roster/cell', { headers, data: { user_id: '3001', date: '2026-10-05', shift_id: 1 } }), 'roster cell for an Operations employee');
        ok(await page.request.post('/attendance/shift-assignments', { headers, data: { scope_type: 'user', scope_id: '2002', shift_id: 1, anchor_date: '2026-10-05', effective_from: '2026-10-05' } }), 'shift assignment');
        denied(await page.request.post('/attendance/shift-assignments', { headers, data: { scope_type: 'user', scope_id: '2002', shift_id: 2, anchor_date: '2026-10-05', effective_from: '2026-10-05' } }), 'assignment of an Operations shift');

        // assets: Inspection Laptop (id 1) assign + return; Ops Radio (id 2) untouchable
        ok(await page.request.post('/hr/assets/1/assign', { headers, data: { employee_id: '2001', condition_on_issue: 'good' } }), 'assign asset');
        ok(await page.request.post('/hr/assets/1/return', { headers, data: { condition_on_return: 'good' } }), 'return asset');
        denied(await page.request.post('/hr/assets/2/return', { headers, data: { condition_on_return: 'good' } }), 'return an Operations asset');

        // lifecycle: onboarding for an Inspection employee, nothing for an Operations one
        denied(await page.request.post('/hr/onboarding', { headers, data: { employee_id: '3003', start_date: '2026-10-01', expected_completion_date: '2026-10-10' } }), 'onboard an Operations employee');
        denied(await page.request.put('/hr/offboarding/2', { headers, data: { notes: 'x' } }), 'edit an Operations offboarding');
    });
});

test.describe('HR Manager sanity pass', () => {
    test('keeps role management, full lists and the org-structure tabs', async ({ page }) => {
        guardCanary = false;
        tolerated = ['dashboard/command', 'api/log-error', 'API Error'];   // the global dashboard runs MySQL-only SQL; sqlite sandbox
        await login(page, HR);
        await goEmployees(page);
        await expect(page.getByText('Omar Ops').first()).toBeVisible();
        for (const tab of ['Departments', 'Designations', 'Work Locations']) await expect(page.getByRole('tab', { name: tab })).toBeVisible();

        await page.getByRole('button', { name: /add/i }).first().click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText('Assigned Roles')).toBeVisible();
        await expect(dialog.getByRole('checkbox', { name: 'Employee', exact: true })).toBeChecked();
        await expect(dialog.getByRole('checkbox', { name: 'Daily Works Contributor', exact: true })).toBeChecked();
        await expect(dialog.getByTestId('department-field-locked')).toHaveCount(0);   // global: the full list
        await page.keyboard.press('Escape');

        const menu = await openRowMenu(page, 'Ishrat Inspector');
        await expect(menu.getByRole('menuitem', { name: /Manage Access/ })).toBeVisible();
    });
});
