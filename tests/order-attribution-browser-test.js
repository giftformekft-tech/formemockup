// PHP_BIN=... PLAYWRIGHT_MODULE=... CHROME_PATH=... node tests/order-attribution-browser-test.js
const assert = require('node:assert/strict');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
    const html = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'order-attribution-test.php'), '--fixture'], { encoding: 'utf8' });
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
    try {
        const page = await browser.newPage({ viewport: { width: 1360, height: 1000 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const first = [
            { day: '2026-09-01', platform: 'facebook', channel: 'paid', campaign: 'Őszi bögrék', currency: 'HUF', orders: 2, gross: 100000000, refunds: 0 },
            { day: '2026-09-02', platform: 'facebook', channel: 'referral', campaign: '', currency: 'HUF', orders: 3, gross: 150000000, refunds: 0 }
        ];
        const rest = [
            { day: '2026-09-07', platform: 'instagram', channel: 'paid', campaign: 'Téli "bögre" <img src=x onerror=alert(1)>', currency: 'HUF', orders: 1, gross: 80000000, refunds: 0 },
            { day: '2026-09-07', platform: 'google', channel: 'organic', campaign: '', currency: 'HUF', orders: 4, gross: 100000000, refunds: 0 },
            { day: '2026-09-08', platform: 'unknown', channel: 'unknown', campaign: '', currency: 'HUF', orders: 1, gross: 12000000, refunds: 0 },
            { day: '2026-09-09', platform: 'facebook', channel: 'paid', campaign: 'Őszi bögrék', currency: 'EUR', orders: 1, gross: 100000, refunds: 0 }
        ];
        let fail = true, delayed = false;
        const requests = [];
        await page.route('https://report.test/**', async route => {
            if (!route.request().url().includes('admin-ajax')) {
                await route.fulfill({ contentType: 'text/html', body: html }); return;
            }
            const request = Object.fromEntries(new URLSearchParams(route.request().postData()));
            requests.push(request);
            if (request.from === '2026-01-01') {
                delayed = true;
                await new Promise(resolve => setTimeout(resolve, 200));
                await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { rows: first, cursor: 2, ceiling: 2, processed: 2, total: 2, done: true } }) });
                return;
            }
            if (request.from === '2025-01-01') {
                await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { rows: [], cursor: 0, ceiling: 0, processed: 0, total: 0, done: true } }) });
                return;
            }
            if (request.cursor !== '0' && fail) {
                fail = false;
                await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'Próbahiba a második adagban.' } }) });
                return;
            }
            const start = request.cursor === '0';
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { rows: start ? first : rest, cursor: start ? 5 : 12, ceiling: 12, total: start ? 12 : null, processed: start ? 5 : 7, done: !start } }) });
        });
        await page.goto('https://report.test/admin.php?page=mockup-generator&mg_tab=order_attribution');
        await page.evaluate(() => { window.MG_ORDER_ATTRIBUTION = { ajaxUrl: 'https://report.test/admin-ajax.php', nonce: 'report-nonce', today: '2026-09-28', currency: 'HUF' }; });
        await page.addScriptTag({ path: path.join(__dirname, '../assets/js/order-attribution.js') });
        await page.locator('#mg-attribution-retry').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#mg-attribution-results').isVisible(), false, 'Partial report must not look complete');
        assert.match(await page.locator('#mg-attribution-status').textContent(), /5 rendelés már feldolgozva/);
        await page.locator('#mg-attribution-retry').click();
        await page.locator('#mg-attribution-results').waitFor({ state: 'visible' });
        assert.equal(requests[2].cursor, '5', 'Retry resumes without double counting the successful batch');
        assert.equal(await page.locator('#mg-attribution-orders').textContent(), '11', 'Foreign currency excluded from default total');
        assert.match(await page.locator('#mg-attribution-revenue').textContent(), /44\s*200/);
        assert.equal(await page.locator('#mg-attribution-results img').count(), 0, 'Campaign text cannot create HTML');
        assert.equal(await page.locator('#mg-attribution-timeline tbody tr').count(), 30, 'Includes days without sales');
        await page.screenshot({ path: path.join(process.env.REPORT_SCREENSHOT_DIR || os.tmpdir(), 'order-attribution-desktop.png'), fullPage: true });

        await page.locator('#mg-attribution-platforms').getByRole('button', { name: 'Facebook', exact: true }).click();
        assert.equal(await page.locator('#mg-attribution-orders').textContent(), '5');
        await page.locator('#mg-attribution-campaign').selectOption(JSON.stringify(''));
        assert.equal(await page.locator('#mg-attribution-orders').textContent(), '3', 'Missing campaign is a usable filter');
        await page.locator('#mg-attribution-channel').selectOption('paid');
        assert.equal(await page.locator('#mg-attribution-orders').textContent(), '2', 'Invalid campaign selection resets when channel changes');
        assert.equal(await page.locator('#mg-attribution-campaign').inputValue(), '');
        await page.locator('#mg-attribution-platform').selectOption('');
        assert.equal(await page.locator('#mg-attribution-orders').textContent(), '3');
        await page.locator('#mg-attribution-group').selectOption('week');
        assert.equal(await page.locator('#mg-attribution-timeline tbody tr').count(), 6, 'Monday-based weeks include partial boundary weeks');
        await page.locator('#mg-attribution-group').selectOption('month');
        assert.equal(await page.locator('#mg-attribution-timeline tbody tr').count(), 2);
        await page.locator('#mg-attribution-group').selectOption('year');
        assert.equal(await page.locator('#mg-attribution-timeline tbody tr').count(), 1);
        await page.locator('#mg-attribution-currency').selectOption('EUR');
        assert.equal(await page.locator('#mg-attribution-orders').textContent(), '1');
        assert.match(await page.locator('#mg-attribution-revenue').textContent(), /10,00/);
        assert.equal(requests.length, 3, 'View filters reuse loaded facts rather than re-querying all orders');

        await page.locator('#mg-attribution-currency').selectOption('HUF');
        await page.locator('#mg-attribution-channel').selectOption('');
        await page.locator('#mg-attribution-group').selectOption('day');
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Mobile page has no horizontal overflow');
        await page.screenshot({ path: path.join(process.env.REPORT_SCREENSHOT_DIR || os.tmpdir(), 'order-attribution-mobile.png'), fullPage: true });

        // Changing dates cancels an in-flight request; its late response must not restore stale data.
        await page.locator('#mg-attribution-preset').selectOption('this_year');
        await page.locator('#mg-attribution-form button').click();
        await page.waitForTimeout(40);
        await page.locator('#mg-attribution-preset').selectOption('last_year');
        await page.locator('#mg-attribution-form button').click();
        await page.locator('#mg-attribution-results').waitFor({ state: 'visible' });
        await page.waitForTimeout(250);
        assert.equal(delayed, true);
        assert.equal(await page.locator('#mg-attribution-orders').textContent(), '0');
        assert.match(await page.locator('#mg-attribution-period').textContent(), /2025-01-01 – 2025-12-31/);
        assert.match(await page.locator('#mg-attribution-platforms').textContent(), /Nincs rendelés/);
        assert.deepEqual(errors, []);
        console.log('PASS: browser filters, platform drill-down, currency, daily/weekly/monthly/yearly views, retry, stale-request cancellation, empty state, XSS escaping and mobile layout.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
