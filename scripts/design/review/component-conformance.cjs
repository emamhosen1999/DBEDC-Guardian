// Cyber component conformance: every shared component in our gallery (/dev/cyber-components, local only) measured against
// the matching element on Cyber's own pages (https://seantheme.com/cyber/), property by property. Prints each mismatch
// and writes <data-dir>/diag/conformance.json. Exit code 1 when anything differs.
// Usage: [PW_CHROMIUM=<headless shell>] node scripts/design/review/component-conformance.cjs [data-dir]
const fs = require('fs');
const os = require('os');
const { chromium } = require('playwright');

const D = process.argv[2] || process.env.CYBER_REVIEW_DATA || `${os.homedir()}/.local/share/dbedc-cyber-review`;
const BASE = 'http://127.0.0.1:8002';
const CYBER = 'https://seantheme.com/cyber/';

// [id, cyber page, cyber selector, our selector (inside the gallery)]
const PAIRS = [
    ['button: theme', 'ui_buttons.html', '.btn.btn-theme', '[data-conf="btn-theme"]'],
    ['button: outline theme', 'form_elements.html', '.btn.btn-outline-theme', '[data-conf="btn-outline-theme"]'],
    ['button: secondary', 'page_gallery.html', '.btn.btn-secondary', '[data-conf="btn-secondary"]'],
    ['button: outline secondary', 'analytics.html', '.btn.btn-outline-secondary', '[data-conf="btn-outline-secondary"]'],
    ['button: small', 'email_detail.html', '.btn.btn-sm', '[data-conf="btn-sm"]'],
    ['badge: theme', 'page_data_management.html', '.badge.bg-theme', '[data-conf="badge-theme"]'],
    ['tab', 'ui_tabs_accordions.html', '.nav-tabs-v2 .nav-link:not(.active)', '[data-conf="tabs"] [role="tab"][aria-selected="false"]'],
    ['tab: active', 'ui_tabs_accordions.html', '.nav-tabs-v2 .nav-link.active', '[data-conf="tabs"] [role="tab"][aria-selected="true"]'],
    ['pagination: link', 'page_orders.html', '.pagination .page-item:not(.active):not(.disabled) .page-link', '[data-conf="pagination"] .cy-pagination__link:not([aria-current]):not(:disabled)'],
    ['pagination: active', 'page_orders.html', '.pagination .page-item.active .page-link', '[data-conf="pagination"] .cy-pagination__link[aria-current]'],
    ['accordion: button', 'ui_tabs_accordions.html', '.accordion-button', '[data-conf="accordion"] .cy-accordion__button'],
    ['input', 'form_elements.html', 'input.form-control:not(.form-control-lg):not(.form-control-sm)', '[data-conf="input"]'],
    ['input: large', 'form_elements.html', 'input.form-control-lg', '[data-conf="input-lg"]'],
    ['select', 'page_data_management.html', '.form-select', '[data-conf="select"]'],
    ['table: header cell', 'table_elements.html', '.table thead th', '[data-conf="table"] thead th'],
    ['table: body cell', 'table_elements.html', '.table tbody td', '[data-conf="table"] tbody td'],
    ['alert: danger', 'ui_bootstrap.html', '.alert.alert-danger', '[data-conf="alert-danger"]'],
    ['alert: warning', 'ui_bootstrap.html', '.alert.alert-warning', '[data-conf="alert-warning"]'],
    ['progress', 'index.html', '.progress', '[data-conf="progress"] .cy-progress'],
    ['modal: content', 'ui_modal_notification.html', '.modal-content', '[data-conf="modal"]'],
    ['modal: header', 'ui_modal_notification.html', '.modal-header', '[data-conf="modal"] .cy-modal__head'],
    ['card: header', 'index.html', '.card-header', '#dev\\:buttons .dl-card__header, [id="dev:buttons"] .dl-card__header, .dl-card__header'],
];

const PROPS = ['font-family', 'font-size', 'font-weight', 'letter-spacing', 'line-height', 'text-transform', 'color', 'background-color',
    'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border-top-width', 'border-top-style', 'border-top-color',
    'border-bottom-width', 'border-bottom-color', 'border-left-width', 'border-left-color', 'border-radius', 'height', 'box-shadow'];

const read = (page, selector) => page.evaluate(({ selector, props }) => {
    const el = [...document.querySelectorAll(selector)].find((e) => e.getClientRects().length) || document.querySelector(selector);
    if (!el) return null;
    const c = getComputedStyle(el);
    const out = {};
    for (const p of props) out[p] = p === 'height' ? `${Math.round(el.getBoundingClientRect().height)}px` : c.getPropertyValue(p);
    out['font-family'] = out['font-family'].split(',')[0].replace(/["']/g, '').trim();
    return out;
}, { selector, props: PROPS });

const near = (a, b) => {
    const na = parseFloat(a); const nb = parseFloat(b);
    if (!Number.isNaN(na) && !Number.isNaN(nb) && /px$/.test(a) && /px$/.test(b)) return Math.abs(na - nb) <= 0.6;
    return a === b;
};

(async () => {
    const raw = fs.readFileSync(`${D}/local-review-login.txt`, 'utf8');
    const email = raw.match(/email=(\S+)/)[1]; const pw = raw.match(/password=(\S+)/)[1];
    const browser = await chromium.launch({ executablePath: process.env.PW_CHROMIUM || undefined });
    const ours = await browser.newPage({ viewport: { width: 1440, height: 1200 } });
    await ours.route(/react-scan/, (r) => r.abort());
    await ours.goto(`${BASE}/login`); await ours.fill('input[type=email]', email); await ours.fill('input[type=password]', pw);
    await Promise.all([ours.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 60000 }), ours.click('button[type=submit]')]);
    await ours.goto(`${BASE}/dev/cyber-components`); await ours.waitForSelector('[data-conf="btn-theme"]', { timeout: 60000 }); await ours.waitForTimeout(800);

    const live = await browser.newPage({ viewport: { width: 1440, height: 1200 } });
    const report = [];
    let current = null;
    for (const [id, cyberPage, cyberSel, ourSel] of PAIRS) {
        if (current !== cyberPage) { await live.goto(CYBER + cyberPage, { waitUntil: 'networkidle', timeout: 90000 }).catch(() => {}); current = cyberPage; }
        const c = await read(live, cyberSel);
        const o = await read(ours, ourSel);
        const diffs = !c || !o ? [{ prop: '(element)', cyber: c ? 'found' : 'MISSING', ours: o ? 'found' : 'MISSING' }]
            : PROPS.filter((p) => !near(c[p], o[p])).map((p) => ({ prop: p, cyber: c[p], ours: o[p] }));
        report.push({ id, cyberPage, diffs });
        console.log(`${diffs.length ? '✗' : '✓'} ${id} (${cyberPage}) ${diffs.length ? `- ${diffs.length} differences` : ''}`);
        diffs.forEach((d) => console.log(`     ${d.prop.padEnd(20)} cyber ${String(d.cyber).padEnd(34)} ours ${d.ours}`));
    }
    fs.mkdirSync(`${D}/diag`, { recursive: true });
    fs.writeFileSync(`${D}/diag/conformance.json`, JSON.stringify(report, null, 1));
    const bad = report.filter((r) => r.diffs.length).length;
    console.log(`\n${report.length - bad}/${report.length} components conform`);
    process.exitCode = bad ? 1 : 0;
    await browser.close();
})().catch((e) => { console.error(e); process.exit(2); });
