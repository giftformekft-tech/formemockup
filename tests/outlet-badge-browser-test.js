const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
    const script = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'outlet-test.php'), '--badge-script'], { encoding: 'utf8' });
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
    try {
        for (const prefix of ['order', 'post']) {
            const page = await browser.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('https://outlet.test/**', async route => {
                if (route.request().url().includes('admin-ajax')) {
                    const data = new URLSearchParams(route.request().postData());
                    assert.deepEqual(JSON.parse(data.get('order_ids')), [21, 22]);
                    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { has_outlet: [21], is_express: [21] } }) });
                } else {
                    await route.fulfill({ contentType: 'text/html', body: `<html><meta charset="utf-8"><table><tr id="${prefix}-21"><td class="column-order_total">3990 Ft</td></tr><tr id="${prefix}-22"><td class="column-order_total">6990 Ft</td></tr></table><script>${script}</script></html>` });
                }
            });
            await page.goto('https://outlet.test/orders');
            await page.locator('[data-mg-badge="has_outlet"]').waitFor();
            assert.equal((await page.locator(`#${prefix}-21 [data-mg-badge="has_outlet"]`).innerText()).trim(), 'OUTLET');
            assert.equal(await page.locator(`#${prefix}-22 [data-mg-badge]`).count(), 0);
            assert.equal(await page.locator('[data-mg-badge="is_express"]').count(), 1, 'existing badges remain');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('Outlet badges: HPOS and legacy order rows, normal-order exclusion and existing badges passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
