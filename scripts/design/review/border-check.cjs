// Cyber border-model check (docs/design/CYBER_FIDELITY_CHECKLIST.md, "Border model"): every card section closes with ONE
// solid line, no line floats inside a card, no right-edge outline, one border colour inside cards, no subtitle row.
// Local review only: logs in through the review proxy (127.0.0.1:8002) with the throwaway local account in
// <data-dir>/local-review-login.txt and writes screenshots to <data-dir>/diag/.
// Usage: [VW=1440] [VH=900] node scripts/design/review/border-check.cjs [data-dir] [path]
const fs = require('fs');
const { chromium } = require('playwright');
const D = process.argv[2] || process.env.CYBER_REVIEW_DATA || require('os').homedir() + '/.local/share/dbedc-cyber-review';
const PATH = process.argv[3] || '/dashboard';
fs.mkdirSync(D + '/diag', { recursive: true });
const raw = fs.readFileSync(D + '/local-review-login.txt', 'utf8');
const email = raw.match(/email=(\S+)/)[1], pw = raw.match(/password=(\S+)/)[1];
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROMIUM || undefined });
  const page = await browser.newPage({ viewport: { width: Number(process.env.VW || 1440), height: Number(process.env.VH || 900) } });
  await page.route(/react-scan/, (r) => r.abort());
  await page.goto('http://127.0.0.1:8002/login', { waitUntil: 'networkidle' });
  await page.fill('input[type=email], input[name=email]', email); await page.fill('input[type=password]', pw);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle', timeout: 60000 }).catch(() => {}), page.click('button[type=submit]')]);
  await page.goto('http://127.0.0.1:8002' + PATH, { waitUntil: 'networkidle', timeout: 90000 }); await page.waitForTimeout(4000);
  const out = await page.evaluate(() => {
    const bb = (el) => { const c = getComputedStyle(el); return c.borderBottomWidth === '0px' ? '' : c.borderBottomWidth + ' ' + c.borderBottomColor; };
    const issues = [];
    const cards = [...document.querySelectorAll('.dl-col > .dl-card')];
    const rows = cards.map((card) => {
      const title = card.querySelector('.dl-card__title')?.textContent;
      const body = card.querySelector(':scope > .dl-card__body');
      const bodyR = body.getBoundingClientRect();
      const kids = [...body.children].map((k, i, a) => {
        const r = k.getBoundingClientRect();
        const line = bb(k);
        if (i === a.length - 1 && line && r.bottom < bodyR.bottom - 2) issues.push(`${title}: last section ${k.className} has a floating bottom line`);
        if (i < a.length - 1 && !line) issues.push(`${title}: section ${k.className} does not close with a line`);
        return `${k.className.split(' ')[0]}[${Math.round(r.height)}]${line ? ' bb=' + line : ''}`;
      });
      // any border inside the card that is not the solid token or the header translucent token
      [...card.querySelectorAll('*')].forEach((el) => {
        const c = getComputedStyle(el);
        ['Top', 'Right', 'Bottom', 'Left'].forEach((s) => {
          if (c['border' + s + 'Width'] !== '0px' && c['border' + s + 'Style'] !== 'none') {
            const col = c['border' + s + 'Color'];
            // Cyber components whose toned borders are part of their spec (outline badges and buttons, alerts, inputs) are not panel borders.
            if (!/rgb\(77, 77, 77\)|rgba\(255, 255, 255, 0\.15\)|rgba\(0, 0, 0, 0\)/.test(col) && !el.closest('.apexcharts-canvas, .cy-badge, .cy-alert, .cy-btn, .cy-input, .cy-tabs')) issues.push(`${title}: ${el.className?.toString().slice(0, 40)} border-${s} ${col}`);
          }
        });
      });
      return `${title} | ${kids.join(' > ')}`;
    });
    const rowsEl = [...document.querySelectorAll('.dl-page > .dl-row')];
    const edge = rowsEl.map((r) => getComputedStyle(r).borderRightWidth).filter((w) => w !== '0px');
    if (edge.length) issues.push(`${edge.length} rows still draw a right-edge outline`);
    const strip = document.querySelector('.cy-kpi-strip');
    if (strip && getComputedStyle(strip).borderRightWidth !== '0px') issues.push('KPI strip draws a right-edge outline');
    if (document.querySelector('.dl-col > .dl-card .dl-card__sub--padded')) issues.push('a widget card still renders the subtitle row');
    return { rows, issues: [...new Set(issues)] };
  });
  out.rows.forEach((r) => console.log(r));
  console.log('ISSUES', out.issues.length); out.issues.forEach((i) => console.log('  - ' + i));
  process.exitCode = out.issues.length ? 1 : 0;
  await page.screenshot({ path: D + '/diag/ours-1440.png' });
  await page.screenshot({ path: D + '/diag/ours-full.png' });
  await page.screenshot({ path: D + '/diag/ours-zoom.png', clip: { x: 0, y: 0, width: Math.min(820, Number(process.env.VW || 1440)), height: 640 } });
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
