const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:/Users/Emam Hosen/.gemini/antigravity-ide/brain/e6f802dc-3326-4df7-9200-81e74e26cb0e';
const BASE_URL = 'http://localhost:8089/mobile';

async function runAudit() {
    console.log('--- Starting DBEDC Mobile Web UI Playwright Audit ---');
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const context = await browser.newContext({
        viewport: { width: 390, height: 844 }, // iPhone 14
        deviceScaleFactor: 3,
        isMobile: true,
        hasTouch: true,
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1'
    });

    const auditResults = {
        timestamp: new Date().toISOString(),
        device: 'iPhone 14 (390x844)',
        pagesTested: [],
        issues: [],
        vmsTollAiCheck: { clean: true, findings: [] },
        themeConsistency: { compliant: true, findings: [] },
        accessibilityTapTargets: { passing: true, smallTargetsCount: 0, details: [] },
        layoutOverflows: { hasOverflow: false, elements: [] },
        overallScore: 100
    };

    const page = await context.newPage();

    // Default to dark/oled theme before hydration
    await page.addInitScript(() => {
        localStorage.setItem('aero.theme.preference', 'oled');
        localStorage.setItem('aero_theme_preference_v1', 'dark');
    });

    // Listen for uncaught errors and console errors
    const pageErrors = [];
    const consoleLogs = [];
    const failedRequests = [];

    page.on('pageerror', err => {
        pageErrors.push(err.toString());
        console.error('[PageError]:', err.message);
    });

    page.on('console', msg => {
        if (msg.type() === 'error') {
            consoleLogs.push({ type: 'error', text: msg.text() });
        }
    });

    page.on('requestfailed', req => {
        failedRequests.push({ url: req.url(), failure: req.failure() });
    });

    // Helper: Check for horizontal overflow
    async function checkHorizontalOverflow(screenName) {
        const overflows = await page.evaluate(() => {
            const docWidth = document.documentElement.clientWidth;
            const elementsWithOverflow = [];
            const allElements = document.querySelectorAll('*');
            for (const el of allElements) {
                const rect = el.getBoundingClientRect();
                if (rect.right > docWidth + 2) { // 2px tolerance
                    elementsWithOverflow.push({
                        tag: el.tagName.toLowerCase(),
                        id: el.id || '',
                        className: (el.className || '').toString().slice(0, 50),
                        rect: { right: Math.round(rect.right), width: Math.round(rect.width) },
                        docWidth
                    });
                }
            }
            return elementsWithOverflow.slice(0, 10);
        });

        if (overflows.length > 0) {
            auditResults.layoutOverflows.hasOverflow = true;
            auditResults.layoutOverflows.elements.push({ screen: screenName, overflows });
            auditResults.issues.push(`[Layout Overflow] on ${screenName}: ${overflows.length} elements exceed viewport width`);
            auditResults.overallScore -= 5;
        }
    }

    // Helper: Check Tap Target Sizes
    async function checkTapTargets(screenName) {
        const smallTargets = await page.evaluate(() => {
            const interactives = document.querySelectorAll('button, a, input, select, textarea, [role="button"]');
            const small = [];
            for (const el of interactives) {
                const rect = el.getBoundingClientRect();
                // Exclude hidden or zero-size elements
                if (rect.width > 0 && rect.height > 0 && (rect.width < 36 || rect.height < 36)) {
                    // Check if parent has enough padding/size
                    small.push({
                        tag: el.tagName.toLowerCase(),
                        text: (el.innerText || el.getAttribute('aria-label') || '').slice(0, 30),
                        width: Math.round(rect.width),
                        height: Math.round(rect.height)
                    });
                }
            }
            return small.slice(0, 10);
        });

        if (smallTargets.length > 0) {
            auditResults.accessibilityTapTargets.smallTargetsCount += smallTargets.length;
            auditResults.accessibilityTapTargets.details.push({ screen: screenName, smallTargets });
        }
    }

    // Helper: Check for any remnants of VMS, Toll operations, or AI distress
    async function checkVmsTollAiRemnants(screenName) {
        const prohibitedMatches = await page.evaluate(() => {
            const bodyText = document.body.innerText || '';
            const regex = /\b(vms|variable message sign|toll audit|toll operations|ai distress|ai vision|dashcam ai)\b/gi;
            const matches = [];
            let m;
            while ((m = regex.exec(bodyText)) !== null) {
                matches.push(m[0]);
            }
            return matches;
        });

        if (prohibitedMatches.length > 0) {
            auditResults.vmsTollAiCheck.clean = false;
            auditResults.vmsTollAiCheck.findings.push({ screen: screenName, matches: prohibitedMatches });
            auditResults.issues.push(`[Prohibited Feature Remnant] on ${screenName}: found mentions of ${[...new Set(prohibitedMatches)].join(', ')}`);
            auditResults.overallScore -= 10;
        }
    }

    // Helper: Check theme consistency (dark mode background & contrast)
    async function checkTheme(screenName) {
        const themeInfo = await page.evaluate(() => {
            const bodyBg = window.getComputedStyle(document.body).backgroundColor;
            const root = document.getElementById('root');
            const rootBg = root ? window.getComputedStyle(root).backgroundColor : '';
            return { bodyBg, rootBg };
        });

        // Theme should be dark (#050D18 / rgb(5, 13, 24))
        if (!themeInfo.bodyBg.includes('5, 13, 24') && !themeInfo.bodyBg.includes('15, 23, 42') && !themeInfo.bodyBg.includes('0, 0, 0')) {
            auditResults.themeConsistency.compliant = false;
            auditResults.themeConsistency.findings.push({ screen: screenName, issue: `Body bg is ${themeInfo.bodyBg}` });
        }
    }

    // ==========================================
    // TEST 1: Login Screen (Unauthenticated)
    // ==========================================
    console.log('Auditing Screen: Login...');
    await page.goto(`${BASE_URL}/`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);

    const loginScreenshot = path.join(ARTIFACTS_DIR, 'playwright_mobile_login.png');
    await page.screenshot({ path: loginScreenshot });
    auditResults.pagesTested.push({ screen: 'Login', url: page.url(), screenshot: loginScreenshot });

    await checkHorizontalOverflow('Login');
    await checkTapTargets('Login');
    await checkVmsTollAiRemnants('Login');
    await checkTheme('Login');

    // ==========================================
    // TEST 2: Operations Hub (Authenticated)
    // ==========================================
    console.log('Setting up Authenticated Session...');
    await page.evaluate(() => {
        const testUser = {
            id: 1,
            name: 'Habibur Rahman',
            email: 'habibur@dhakabypass.com',
            employee_id: 'DBEDC-001',
            role: 'super_admin',
            roles: ['Super Administrator'],
            permissions: [
                'om.dashboard.view',
                'om.maintenance.view',
                'om.maintenance.manage',
                'om.incidents.view',
                'om.incidents.manage',
                'om.equipment.view',
                'om.traffic.view',
                'om.shift.manage',
                'daily_works.view',
                'daily_works.manage',
                'attendance.punch'
            ]
        };

        const session = {
            baseUrl: 'http://127.0.0.1:8000',
            user: testUser,
            token: 'test-audit-token-dbedc'
        };

        localStorage.setItem('aero_mobile_auth_session_v1', JSON.stringify(session));
        localStorage.setItem('aero_mobile_auth_token_v1', 'test-audit-token-dbedc');
        localStorage.setItem('aero_theme_preference_v1', 'dark');
        localStorage.setItem('aero.theme.preference', 'oled');
    });

    console.log('Auditing Screen: Operations Hub...');
    await page.goto(`${BASE_URL}/operations`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);

    const opsScreenshot = path.join(ARTIFACTS_DIR, 'playwright_mobile_operations.png');
    await page.screenshot({ path: opsScreenshot });
    auditResults.pagesTested.push({ screen: 'Operations', url: page.url(), screenshot: opsScreenshot });

    await checkHorizontalOverflow('Operations');
    await checkTapTargets('Operations');
    await checkVmsTollAiRemnants('Operations');
    await checkTheme('Operations');

    // Test tab interactions in Operations Hub
    const tabButtons = await page.$$('[role="tab"], button, [data-testid*="tab"]');
    console.log(`Found ${tabButtons.length} interactive elements on Operations screen`);

    // Click Patrol Tab if present
    const patrolTab = await page.locator('text=Patrol').first();
    if (await patrolTab.isVisible().catch(() => false)) {
        console.log('Clicking Patrol Tab...');
        await patrolTab.click();
        await page.waitForTimeout(1000);
        const patrolScreenshot = path.join(ARTIFACTS_DIR, 'playwright_mobile_patrol.png');
        await page.screenshot({ path: patrolScreenshot });
        auditResults.pagesTested.push({ screen: 'Operations - Patrol', url: page.url(), screenshot: patrolScreenshot });
        await checkHorizontalOverflow('Operations - Patrol');
        await checkVmsTollAiRemnants('Operations - Patrol');
    }

    // ==========================================
    // TEST 3: Daily Works Screen
    // ==========================================
    console.log('Auditing Screen: Daily Works...');
    await page.goto(`${BASE_URL}/daily-works`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);

    const dailyWorksScreenshot = path.join(ARTIFACTS_DIR, 'playwright_mobile_daily_works.png');
    await page.screenshot({ path: dailyWorksScreenshot });
    auditResults.pagesTested.push({ screen: 'Daily Works', url: page.url(), screenshot: dailyWorksScreenshot });

    await checkHorizontalOverflow('Daily Works');
    await checkTapTargets('Daily Works');
    await checkVmsTollAiRemnants('Daily Works');
    await checkTheme('Daily Works');

    // ==========================================
    // TEST 4: Punch / Attendance Screen
    // ==========================================
    console.log('Auditing Screen: Attendance / Punch...');
    await page.goto(`${BASE_URL}/punch`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);

    const punchScreenshot = path.join(ARTIFACTS_DIR, 'playwright_mobile_punch.png');
    await page.screenshot({ path: punchScreenshot });
    auditResults.pagesTested.push({ screen: 'Punch', url: page.url(), screenshot: punchScreenshot });

    await checkHorizontalOverflow('Punch');
    await checkTapTargets('Punch');
    await checkVmsTollAiRemnants('Punch');
    await checkTheme('Punch');

    // Final checks
    if (pageErrors.length > 0) {
        auditResults.issues.push(...pageErrors.map(e => `[Runtime Error]: ${e}`));
        auditResults.overallScore -= (pageErrors.length * 10);
    }

    auditResults.consoleErrors = consoleLogs;
    auditResults.failedRequests = failedRequests;
    auditResults.overallScore = Math.max(0, Math.min(100, auditResults.overallScore));

    console.log('--- Playwright Audit Completed ---');
    console.log(`Overall UI Quality Score: ${auditResults.overallScore}/100`);
    console.log(`Issues Found: ${auditResults.issues.length}`);
    console.log(`VMS/Toll/AI Clean: ${auditResults.vmsTollAiCheck.clean}`);
    console.log(`Theme Consistent: ${auditResults.themeConsistency.compliant}`);
    console.log(`Layout Overflows: ${auditResults.layoutOverflows.hasOverflow}`);

    // Save report
    const reportPath = path.join(ARTIFACTS_DIR, 'playwright_mobile_audit_report.json');
    fs.writeFileSync(reportPath, JSON.stringify(auditResults, null, 2));
    console.log(`Report written to ${reportPath}`);

    await browser.close();
}

runAudit().catch(err => {
    console.error('Fatal audit error:', err);
    process.exit(1);
});
