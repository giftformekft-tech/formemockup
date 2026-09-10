// PHP_BIN=... PLAYWRIGHT_MODULE=... CHROME_PATH=... node tests/outlet-browser-test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
    const html = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'outlet-test.php'), '--fixture'], { encoding: 'utf8' });
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        const requests = [];
        let failed = true;
        await page.route('https://outlet.test/**', async route => {
            if (route.request().url().includes('admin-ajax')) {
                requests.push(new URLSearchParams(route.request().postData()));
                await route.fulfill({ contentType: 'application/json', body: JSON.stringify(failed
                    ? { success: false, data: { message: 'Próbahiba' } }
                    : { success: true, data: { message: 'Elkészült', edit_url: 'https://outlet.test/edit/101' } }) });
            } else await route.fulfill({ contentType: 'text/html', body: html });
        });
        await page.goto('https://outlet.test/outlet/');
        await page.evaluate(() => { window.ajaxurl = 'https://outlet.test/admin-ajax.php'; });
        await page.addScriptTag({ path: path.join(__dirname, '../assets/js/outlet-admin.js') });
        assert.equal(await page.locator('.mg-outlet-card').count(), 1);
        assert.match(await page.locator('.mg-outlet-stock').textContent(), /1 db/);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'mobile has no horizontal overflow');
        await page.locator('#mg-outlet-submit').click();
        assert.equal(requests.length, 0, 'empty selection not submitted');
        await page.locator('#mg-outlet-type').selectOption('shirt');
        await page.locator('#mg-outlet-color').selectOption('black');
        await page.locator('#mg-outlet-size').selectOption('XL');
        await page.locator('#mg-outlet-color').selectOption('white');
        assert.equal(await page.locator('#mg-outlet-size').inputValue(), 'S', 'size follows selected color');
        assert.equal(await page.locator('#mg-outlet-size option[value="XL"]').count(), 0, 'unavailable size removed');
        await page.locator('#mg-outlet-color').selectOption('black');
        assert.equal(await page.locator('#mg-outlet-size').inputValue(), '', 'multiple sizes require explicit selection');
        await page.locator('#mg-outlet-size').selectOption('XL');
        await page.locator('#mg-outlet-price').fill('3990');
        await page.locator('#mg-outlet-submit').click();
        await page.waitForFunction(() => document.getElementById('mg-outlet-result').textContent === 'Próbahiba');
        assert.equal(await page.locator('#mg-outlet-submit').isEnabled(), true, 'retry after server failure');
        failed = false;
        await page.locator('#mg-outlet-submit').click();
        await page.locator('#mg-outlet-result a').waitFor();
        assert.equal(requests[0].get('request'), requests[1].get('request'), 'retry preserves idempotency key');
        assert.equal(requests[1].get('size'), 'XL');
        assert.equal(await page.locator('#mg-outlet-submit').isDisabled(), true, 'successful creation cannot double-submit');
        await page.screenshot({ path: process.env.OUTLET_SCREENSHOT || path.join(require('node:os').tmpdir(), 'outlet-mobile.png'), fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'desktop has no overflow');
        assert.deepEqual(errors, []);
        console.log('Outlet browser: mobile/desktop layout, dependent selection, validation, retry and duplicate-submit checks passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
