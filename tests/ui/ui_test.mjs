// Browser UI tests (headless Chromium via puppeteer-core). Run: node ui_test.mjs  (needs servers from README "Running tests")
import { mkdirSync } from 'fs'; mkdirSync('/tmp/loyalbox-shots', { recursive: true });
import chromium from '@sparticuz/chromium';
import puppeteer from 'puppeteer-core';
import fs from 'fs';

const BASE = process.env.BASE || 'http://127.0.0.1:8081';
const results = []; let failed = 0;
const check = (name, ok, detail = '') => { results.push([name, ok, detail]); if (!ok) failed++; console.log((ok ? '  PASS ' : '  FAIL ') + name + (ok || !detail ? '' : '  -> ' + String(detail).slice(0, 220))); };
const IGNORE = /fonts\.(googleapis|gstatic)\.com/;    // web font is optional; sandbox has no internet

const browser = await puppeteer.launch({ args: [...chromium.args.filter(a => a !== '--single-process'), '--no-sandbox'], executablePath: await chromium.executablePath(), headless: 'shell' });

async function newPage(w, h, scheme = 'light', reduced = false) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.setViewport({ width: w, height: h, deviceScaleFactor: 1, isMobile: w < 600 });
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }, { name: 'prefers-reduced-motion', value: reduced ? 'reduce' : 'no-preference' }]);
  page.problems = [];
  page.on('pageerror', e => page.problems.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !IGNORE.test(m.location().url || '') && !IGNORE.test(m.text())) page.problems.push('console: ' + m.text() + ' @ ' + (m.location().url || '')); });
  page.on('requestfailed', r => { if (!IGNORE.test(r.url())) page.problems.push('requestfailed: ' + r.url()); });
  page.on('response', r => { if (r.status() >= 400 && !IGNORE.test(r.url())) page.problems.push(r.status() + ' ' + r.url()); });
  return page;
}
async function login(page, area, email, pw) {
  await page.goto(`${BASE}/${area}/login.php`, { waitUntil: 'networkidle0' });
  await page.type('#f-email', email); await page.type('#f-password', pw);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);
}
const primaries = page => page.evaluate(() => [...document.querySelectorAll('.btn-primary')].filter(b => !b.closest('dialog') && b.offsetParent !== null).length);
const overflow = page => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

const customerPages = ['index', 'claim', 'rewards', 'history', 'redemptions', 'profile'];
const adminPages = ['index', 'products', 'codes', 'customers', 'customer?id=1', 'rewards', 'claims', 'redemptions', 'transactions', 'security', 'logs'];
const viewports = [['desktop-light', 1440, 900, 'light'], ['mobile-dark', 390, 844, 'dark'], ['tablet-light', 820, 1100, 'light']];

console.log('\n== Pages: render, no console errors, no horizontal overflow ==');
for (const [label, w, h, scheme] of viewports) {
  const c = await newPage(w, h, scheme);
  await c.goto(`${BASE}/customer/login.php`, { waitUntil: 'networkidle0' });
  check(`[${label}] customer login: no overflow`, (await overflow(c)) <= 0, 'overflow ' + await overflow(c));
  await c.screenshot({ path: `/tmp/loyalbox-shots/${label}-customer-login.png` });
  await c.goto(`${BASE}/customer/register.php`, { waitUntil: 'networkidle0' });
  check(`[${label}] customer register: no overflow`, (await overflow(c)) <= 0);
  await login(c, 'customer', 'zed@test.local', 'correct-horse-9');
  for (const p of customerPages) {
    c.problems.length = 0;
    await c.goto(`${BASE}/customer/${p}.php`, { waitUntil: 'networkidle0' });
    const o = await overflow(c);
    check(`[${label}] customer/${p}: no overflow, no console errors`, o <= 0 && c.problems.length === 0, `overflow ${o} ${c.problems.join(' | ')}`);
    if (label === 'desktop-light') { const n = await primaries(c), want = { index: 1, claim: 1, history: 1, redemptions: 1, profile: 1, rewards: 0 }[p]; check(`customer/${p}: exactly ${want} filled primary button`, n === want, 'found ' + n); }
    await c.screenshot({ path: `/tmp/loyalbox-shots/${label}-customer-${p}.png`, fullPage: true });
  }
  const a = await newPage(w, h, scheme);
  await a.goto(`${BASE}/admin/login.php`, { waitUntil: 'networkidle0' });
  check(`[${label}] admin login: no overflow`, (await overflow(a)) <= 0);
  await login(a, 'admin', 'admin@test.local', 'admin-password-1');
  for (const p of adminPages) {
    a.problems.length = 0;
    await a.goto(`${BASE}/admin/${p.includes('?') ? p.replace('?', '.php?') : p + '.php'}`, { waitUntil: 'networkidle0' });
    const o = await overflow(a);
    check(`[${label}] admin/${p}: no overflow, no console errors`, o <= 0 && a.problems.length === 0, `overflow ${o} ${a.problems.join(' | ')}`);
    if (label === 'desktop-light') { const n = await primaries(a), want = { index: 1, products: 1, codes: 1, rewards: 1 }[p] ?? 0; check(`admin/${p}: exactly ${want} filled primary button`, n === want, 'found ' + n); }
    await a.screenshot({ path: `/tmp/loyalbox-shots/${label}-admin-${p.replace(/\W/g, '_')}.png`, fullPage: true });
  }
  await c.browserContext().close(); await a.browserContext().close();
}

console.log('\n== Interactions (desktop) ==');
let page = await newPage(1440, 900, 'light');
await login(page, 'admin', 'admin@test.local', 'admin-password-1');

// theme toggle
await page.goto(`${BASE}/admin/index.php`, { waitUntil: 'networkidle0' });
const t0 = await page.$eval('html', e => e.dataset.theme);
await page.click('.theme-toggle'); await new Promise(r => setTimeout(r, 450));
const t1 = await page.$eval('html', e => e.dataset.theme);
const stored = await page.evaluate(() => localStorage.getItem('lb-theme'));
check('Theme toggle switches theme and saves it to localStorage', t0 === 'light' && t1 === 'dark' && stored === 'dark', `${t0}->${t1}, stored ${stored}`);
await page.reload({ waitUntil: 'networkidle0' });
check('Saved theme is applied again after reload', (await page.$eval('html', e => e.dataset.theme)) === 'dark');
const bg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
check('Dark theme uses the specified background #242932', bg === 'rgb(36, 41, 50)', bg);
await page.click('.theme-toggle'); await new Promise(r => setTimeout(r, 450));
check('Light theme background is #e3e8ef', (await page.evaluate(() => getComputedStyle(document.body).backgroundColor)) === 'rgb(227, 232, 239)');

// dashboard: chart + count-up
await page.goto(`${BASE}/admin/index.php`, { waitUntil: 'networkidle0' });
await new Promise(r => setTimeout(r, 900));
check('Dashboard chart loads from the API and draws 14 bars', (await page.$$eval('#claims-chart rect.bar', n => n.length)) === 14);
check('Skeleton is gone once the chart has loaded', (await page.$('#claims-chart .skeleton')) === null);
const counts = await page.$$eval('[data-count]', els => els.map(e => [e.dataset.count, e.textContent.replace(/,/g, '')]));
check('Count-up ends on the real database value', counts.length > 0 && counts.every(([a, b]) => a === b), JSON.stringify(counts));
check('Greeting is time-based', /^(Good (morning|afternoon|evening)), \w+\.$/.test(await page.$eval('[data-greeting]', e => e.textContent)));
check('Sidebar marks the current page', (await page.$eval('.nav-link.is-active', e => e.getAttribute('aria-current'))) === 'page');

// tables
await page.goto(`${BASE}/admin/customers.php`, { waitUntil: 'networkidle0' });
const total = await page.$$eval('tbody tr', r => r.length);
const counter0 = await page.$eval('[data-table-count]', e => e.textContent);
check('Record counter shows the total', counter0 === `${total} records`, counter0);
await page.keyboard.press('/');
check('Pressing "/" focuses the search box', await page.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-table-search')));
await page.keyboard.type('zed@');
await new Promise(r => setTimeout(r, 100));
const vis = await page.$$eval('tbody tr', r => r.filter(x => !x.hidden).length);
check('Live search filters rows and updates the counter', vis === 1 && (await page.$eval('[data-table-count]', e => e.textContent)) === `1 of ${total} records`, `visible ${vis}`);
await page.$eval('[data-table-search]', e => { e.value = 'no-such-person-xyz'; e.dispatchEvent(new Event('input')); });
check('Empty "No matches" state appears when nothing matches', await page.$eval('[data-table-empty]', e => !e.hidden));
await page.$eval('[data-table-search]', e => { e.value = ''; e.dispatchEvent(new Event('input')); });
await page.select('[data-table-filter]', 'blocked');
const blockedRows = await page.$$eval('tbody tr', r => r.filter(x => !x.hidden).length);
check('Status filter shows only blocked customers', blockedRows === 1, `rows ${blockedRows}`);
await page.select('[data-table-filter]', '');
await page.click('th:nth-child(4) button.sort');            // Points, ascending
let pts = await page.$$eval('tbody tr td:nth-child(4)', c => c.map(x => +x.textContent.replace(/,/g, '')));
const asc = pts.every((v, i) => i === 0 || pts[i - 1] <= v);
await page.click('th:nth-child(4) button.sort');
pts = await page.$$eval('tbody tr td:nth-child(4)', c => c.map(x => +x.textContent.replace(/,/g, '')));
const desc = pts.every((v, i) => i === 0 || pts[i - 1] >= v);
check('Clicking a header sorts ascending then descending (numeric)', asc && desc && (await page.$eval('th:nth-child(4)', e => e.getAttribute('aria-sort'))) === 'descending');

// confirm dialog
await page.goto(`${BASE}/admin/customers.php`, { waitUntil: 'networkidle0' });
const before = await page.$$eval('tbody tr td:nth-child(7)', c => c.map(x => x.textContent.trim()).join());
await page.evaluate(() => { const b = [...document.querySelectorAll('form[data-confirm] button')].find(x => /Block/.test(x.textContent)); b.click(); });
await new Promise(r => setTimeout(r, 200));
check('Custom confirmation dialog opens (not window.confirm)', await page.$eval('#confirm-dialog', d => d.open));
check('Dialog shows the message', (await page.$eval('#confirm-text', e => e.textContent)).startsWith('Block '));
await page.click('[data-confirm-cancel]');
await new Promise(r => setTimeout(r, 300));
const after = await page.$$eval('tbody tr td:nth-child(7)', c => c.map(x => x.textContent.trim()).join());
check('Cancel closes the dialog and changes nothing', !(await page.$eval('#confirm-dialog', d => d.open)) && before === after);

// product dialog + inline validation
await page.goto(`${BASE}/admin/products.php`, { waitUntil: 'networkidle0' });
await page.click('[data-dialog-open="#product-dialog"]');
await new Promise(r => setTimeout(r, 200));
check('Add-product dialog opens, title says "Add product"', (await page.$eval('#product-dialog', d => d.open)) && (await page.$eval('#pd-title', e => e.textContent)) === 'Add product');
await page.click('#product-dialog button[type=submit]');
const err = await page.$eval('#f-name-error', e => e.textContent);
check('Submitting empty required field shows inline error and does not post', err.length > 0 && (await page.url()).endsWith('products.php'), err);
check('Focus moves to the first invalid field', await page.evaluate(() => document.activeElement.id === 'f-name'));
await page.keyboard.press('Escape'); await new Promise(r => setTimeout(r, 200));
await page.evaluate(() => document.querySelector('[data-fill]').click());
await new Promise(r => setTimeout(r, 200));
check('Edit button pre-fills the form and title says "Edit product"', (await page.$eval('#f-name', e => e.value)) !== '' && (await page.$eval('#pd-title', e => e.textContent)) === 'Edit product');
await page.keyboard.press('Escape');

// code generation dialog keeps its selected product
await page.goto(`${BASE}/admin/codes.php`, { waitUntil: 'networkidle0' });
await page.click('[data-dialog-open="#gen-dialog"]'); await new Promise(r => setTimeout(r, 200));
check('Generate dialog keeps a product selected and title unchanged', (await page.$eval('#f-product_id', e => e.value)) !== '' && (await page.$eval('#gd-title', e => e.textContent)) === 'Generate codes');
await page.type('#f-quantity', '', {}); await page.$eval('#f-quantity', e => e.value = '3');
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#gen-dialog button[type=submit]')]);
check('Generate shows the one-time codes page with a download button', (await page.$('#download-codes')) !== null && (await page.$$eval('tbody tr', r => r.length)) === 3);
await page.evaluate(() => { window.__dl = null; const o = URL.createObjectURL; URL.createObjectURL = b => { window.__dl = b; return o.call(URL, b); }; });
await page.click('#download-codes'); await new Promise(r => setTimeout(r, 300));
const csv = await page.evaluate(async () => window.__dl ? await window.__dl.text() : '');
check('CSV download contains header + 3 rows with claim links', csv.split('\r\n').length === 4 && csv.startsWith('batch,sku,product,code,claim_url') && csv.includes('/customer/claim.php?code='), csv.slice(0, 80));
check('Download shows a toast', (await page.$$('.toast')).length >= 1);
await page.screenshot({ path: '/tmp/loyalbox-shots/interaction-toast-codes.png' });
await page.close();

// mobile nav
const m = await newPage(390, 844, 'light');
await login(m, 'admin', 'admin@test.local', 'admin-password-1');
await m.goto(`${BASE}/admin/index.php`, { waitUntil: 'networkidle0' });
check('Mobile: hamburger visible, sidebar hidden at first', (await m.$eval('.nav-toggle', e => getComputedStyle(e).display)) !== 'none' && (await m.$eval('#sidebar', e => getComputedStyle(e).visibility)) === 'hidden');
await m.click('.nav-toggle'); await new Promise(r => setTimeout(r, 400));
check('Mobile: menu slides in and aria-expanded=true', (await m.$eval('#sidebar', e => e.classList.contains('is-open') && getComputedStyle(e).visibility === 'visible')) && (await m.$eval('.nav-toggle', e => e.getAttribute('aria-expanded'))) === 'true');
await m.screenshot({ path: '/tmp/loyalbox-shots/interaction-mobile-nav-open.png' });
await m.click('.scrim', { offset: { x: 370, y: 400 } }); await new Promise(r => setTimeout(r, 400));
check('Mobile: tapping the scrim closes the menu', !(await m.$eval('#sidebar', e => e.classList.contains('is-open'))));
await m.click('.nav-toggle'); await new Promise(r => setTimeout(r, 300)); await m.keyboard.press('Escape'); await new Promise(r => setTimeout(r, 400));
check('Mobile: Escape closes the menu', !(await m.$eval('#sidebar', e => e.classList.contains('is-open'))));
await m.goto(`${BASE}/admin/codes.php`, { waitUntil: 'networkidle0' });
const scrollable = await m.$eval('.table-scroll', e => e.scrollWidth > e.clientWidth);
check('Mobile: wide tables scroll inside their container, not the page', scrollable && (await overflow(m)) <= 0);
await m.browserContext().close();

// customer: toast, validation, keyboard, reduced motion, focus ring
const cu = await newPage(1280, 800, 'light');
await login(cu, 'customer', 'zed@test.local', 'correct-horse-9');
await cu.goto(`${BASE}/customer/claim.php`, { waitUntil: 'networkidle0' });
check('Claim page autofocuses the code field', await cu.evaluate(() => document.activeElement.id === 'f-code'));
await cu.type('#f-code', 'ZZZZ-ZZZZ-ZZZZ-ZZZZ'); await cu.keyboard.press('Enter');
await cu.waitForNavigation({ waitUntil: 'networkidle0' });
check('Enter submits the form; friendly error appears inline', (await cu.$eval('#f-code-error', e => e.textContent)).includes('not found'));
const ring = await cu.evaluate(() => { document.querySelector('.btn-primary').focus(); return getComputedStyle(document.querySelector('.btn-primary')).outlineStyle; });
check('Focused controls show a visible outline ring', ring === 'solid', ring);
await cu.browserContext().close();
const rg = await newPage(1280, 800, 'light');
await rg.goto(`${BASE}/customer/register.php`, { waitUntil: 'networkidle0' });
await rg.type('#f-password', 'abc'); await rg.type('#f-password2', 'xyz'); await rg.click('button[type=submit]');
const e1 = await rg.$eval('#f-password-error', e => e.textContent), e2 = await rg.$eval('#f-email-error', e => e.textContent);
check('Register: inline errors for empty/short fields, no navigation', e1.length > 0 && e2.length > 0 && (await rg.url()).endsWith('register.php'), e1 + ' / ' + e2);
await rg.$eval('#f-password', e => e.value = ''); await rg.type('#f-password', 'long-enough-pass-1'); await rg.$eval('#f-password2', e => e.value = ''); await rg.type('#f-password2', 'different-pass-22');
await rg.click('#f-name');
check('Register: "passwords do not match" shows inline on blur', (await rg.$eval('#f-password2-error', e => e.textContent)).includes('do not match'));
await rg.browserContext().close();

const rm = await newPage(1280, 800, 'light', true);
await login(rm, 'admin', 'admin@test.local', 'admin-password-1');
await rm.goto(`${BASE}/admin/index.php`, { waitUntil: 'networkidle0' });
const dur = await rm.$eval('.stat', e => getComputedStyle(e).animationDuration);
check('prefers-reduced-motion disables the entrance animation', parseFloat(dur) < 0.01, dur);
check('Reduced motion: numbers show final value immediately', await rm.$$eval('[data-count]', els => els.every(e => e.textContent.replace(/,/g, '') === e.dataset.count)));
await rm.browserContext().close();

// no inline event handlers/styles (CSP) and no use of window.confirm
const chk = await newPage(1280, 800, 'light');
await login(chk, 'admin', 'admin@test.local', 'admin-password-1');
const resp = await chk.goto(`${BASE}/admin/products.php`, { waitUntil: 'networkidle0' });
const html = await resp.text();
check('Server-sent HTML has no inline style= or on*= handlers (CSP-safe)', !/\sstyle=|\sonclick=|\sonchange=|\sonsubmit=/i.test(html));
check('No CSP violations were logged on the products page', chk.problems.filter(p => /Content Security Policy/i.test(p)).length === 0, chk.problems.join(' | '));
await chk.browserContext().close();

await browser.close();
console.log('\n' + (failed ? `${failed} UI TEST(S) FAILED` : `ALL ${results.length} UI TESTS PASSED`));
let md = `# UI test results\n\nGenerated by \`node ui_test.mjs\` (headless Chromium via puppeteer-core) on ${new Date().toISOString().slice(0, 16)} UTC.\n\nGoogle Fonts could not be fetched in the test sandbox (no internet), so these runs use the system-font fallback.\n\n| Result | Test |\n|---|---|\n`;
for (const [n, ok, d] of results) md += `| ${ok ? 'PASS' : '**FAIL**'} | ${n}${ok || !d ? '' : ' — ' + d.replace(/\|/g, '/')} |\n`;
fs.writeFileSync(new URL('./RESULTS_UI.md', import.meta.url), md + `\n**${results.length - failed} of ${results.length} passed.**\n`);
process.exit(failed ? 1 : 0);
