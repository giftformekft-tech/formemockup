// Requires Playwright + Chromium. Real browser/DOM, simulated WordPress responses.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const script = fs.readFileSync(path.join(__dirname, '../assets/js/order-export.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../assets/css/order-export.css'), 'utf8');
async function main() {
    const browser = await chromium.launch({ headless: true, channel: process.env.MG_BROWSER_CHANNEL || undefined });
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 950 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const png = await page.evaluate(() => {
            const c = document.createElement('canvas'); c.width = 900; c.height = 1000;
            const g = c.getContext('2d'); g.textAlign = 'center';
            g.strokeStyle = '#b27730'; g.lineWidth = 10; g.strokeRect(45, 45, 810, 910);
            g.fillStyle = '#9b6325'; g.font = 'bold 70px Arial';
            g.fillText('SZEPTEMBERBEN', 450, 260);
            g.fillText('SZÜLETNEK', 450, 370);
            g.font = 'bold 98px Arial'; g.fillText('A LEGENDÁK', 450, 540);
            g.font = 'bold 180px Arial'; g.fillText('1995', 450, 800);
            return c.toDataURL('image/png').split(',')[1];
        });
        const items = [
            { key: '292829_9531', order_id: 292829, item_id: 9531, quantity: 2, product_name: 'Szeptemberi legendák – egyedi póló', fields: [{ label: 'Hónap', value: 'szeptember' }, { label: 'Évszám', value: '1995' }] },
            { key: '292830_9532', order_id: 292830, item_id: 9532, quantity: 1, product_name: 'Születésnapi minta', fields: [{ label: 'Hónap', value: 'május' }, { label: 'Évszám', value: '2001' }] },
        ];
        const requests = [];
        let recovery = false;
        let recoveryPhase = 'queued';
        let workerReply = 'auth';
        await page.route('https://export.test/**', async route => {
            const request = route.request();
            if (request.url().includes('admin-ajax.php')) {
                if (request.method() === 'GET') return route.fulfill({ contentType: 'image/png', body: Buffer.from(png, 'base64') });
                const body = new URLSearchParams(request.postData());
                requests.push(body);
                const action = body.get('action');
                let data;
                if (action === 'mg_design_export_review') data = { review_id: 'mgr_browser', items: recovery ? [] : items, total: 3 };
                else if (action === 'mg_design_export_start') data = { job_id: 'browser_job', total: 3 };
                else if (action === 'mg_design_export_step') {
                    if (recovery && recoveryPhase === 'queued') data = { waiting: true, completed: 1, total: 3, percent: 33, ai_key: 'browser_worker', ai_status: 'queued', ai_stage: 'queued', ai_elapsed: 6, ai_worker_key: 'browser_worker', message: 'AI nyomat indításra vár' };
                    else if (recovery && recoveryPhase === 'running') data = { waiting: true, completed: 1, total: 3, percent: 33, ai_key: 'browser_worker', ai_status: 'running', ai_stage: 'api', ai_elapsed: 130, ai_api_timeout: 180, message: 'OpenAI válaszára vár – rendelés #292830, tétel #9532.' };
                    else if (recovery && recoveryPhase === 'error') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'Az OpenAI API-kulcs érvénytelen (HTTP 401). Mentsd az új kulcsot, majd folytasd az exportot.' } }) });
                    else data = { done: true, completed: 3, total: 3, percent: 100 };
                }
                else if (action === 'mg_design_export_run_ai') {
                    if (workerReply === 'unreadable') return route.fulfill({ status: 503, contentType: 'text/html', body: '<h1>Unavailable</h1><p>private server path</p>' });
                    if (workerReply === 'proxy') {
                        recoveryPhase = 'running';
                        return route.fulfill({ status: 504, contentType: 'text/html', body: '<h1>Gateway timeout</h1>' });
                    }
                    recoveryPhase = 'error'; data = {};
                }
                else if (action === 'mg_design_export_retry') { recoveryPhase = 'done'; data = { job_id: 'browser_job' }; }
                else throw new Error('Unexpected request ' + action);
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
            }
            return route.fulfill({ contentType: 'text/html', body: '<html lang="hu"><meta charset="utf-8"><style>body{font:14px Arial;background:#eee;color:#1d2327}.button{padding:8px 14px;border:1px solid #2271b1;border-radius:3px;cursor:pointer}.button-primary{background:#2271b1;color:white}.button:disabled{opacity:.5;cursor:default}.button-link,a{color:#2271b1}.button-link{border:0;background:none;cursor:pointer}h4{margin-top:0}' + css + '</style><body><h1>Rendelések</h1><script>window.MG_ORDER_EXPORT=' + JSON.stringify({ ajax_url: 'https://export.test/admin-ajax.php', nonce: 'browser', order_ids: [292829, 292830], i18n: { title: 'Minták exportálása', choice_question: 'Milyen módon exportáljunk?', choice_normal: 'Normál export', choice_strip: 'Fekete nélkül', close: 'Bezárás' } }) + ';</script><script>' + script + '</script></body></html>' });
        });
        await page.goto('https://export.test/');
        await page.getByRole('button', { name: 'Normál export', exact: true }).click();
        await page.getByRole('button', { name: 'Alapminta jó – nem kell AI' }).waitFor();
        await page.waitForFunction(() => !document.querySelector('.mg-order-export-use-original').disabled);
        assert.equal(requests.length, 1);
        assert.equal(await page.locator('.mg-order-export-next').isDisabled(), true);
        assert.deepEqual(await page.locator('.mg-order-export-fields dd').allTextContents(), ['szeptember', '1995']);
        const screenshotDir = process.env.MG_BROWSER_ARTIFACTS || path.join(os.tmpdir(), 'mg-export-review-qa');
        fs.mkdirSync(screenshotDir, { recursive: true });
        await page.screenshot({ path: path.join(screenshotDir, 'review-desktop.png') });
        await page.getByRole('button', { name: 'Sötét háttér', exact: true }).click();
        assert.match(await page.locator('.mg-order-export-image-wrap').getAttribute('class'), /is-dark/);
        await page.getByRole('button', { name: 'Alapminta jó – nem kell AI' }).click();
        await page.getByRole('button', { name: 'Következő tétel' }).click();
        await page.getByRole('button', { name: 'AI-módosítás kell', exact: true }).click();
        await page.getByRole('button', { name: 'Összesítés', exact: true }).click();
        assert.equal(requests.length, 1, 'no start/step before final confirmation');
        await page.screenshot({ path: path.join(screenshotDir, 'review-summary.png') });
        await page.locator('.mg-order-export-summary-list button').first().click();
        assert.equal(await page.locator('.mg-order-export-use-original').getAttribute('aria-pressed'), 'true');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.waitForFunction(() => !document.querySelector('.mg-order-export-use-original').disabled);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'mobile layout has no horizontal overflow');
        await page.screenshot({ path: path.join(screenshotDir, 'review-mobile.png') });
        await page.getByRole('button', { name: 'Következő tétel' }).click();
        await page.getByRole('button', { name: 'Összesítés', exact: true }).click();
        await page.getByRole('button', { name: 'Export indítása', exact: true }).dblclick();
        await page.locator('.mg-order-export-download').waitFor({ state: 'visible' });
        assert.equal(requests.filter(x => x.get('action') === 'mg_design_export_start').length, 1);
        assert.deepEqual(JSON.parse(requests[1].get('decisions')), { '292829_9531': 'original', '292830_9532': 'generate' });
        recovery = true;
        await page.reload();
        await page.getByRole('button', { name: 'Normál export', exact: true }).click();
        await page.getByRole('button', { name: 'Export folytatása', exact: true }).waitFor({ state: 'visible' });
        assert.match(await page.locator('.mg-order-export-error').textContent(), /HTTP 401/);
        assert.match(await page.locator('.mg-order-export-status').textContent(), /export megállt/);
        assert.equal(await page.locator('.mg-order-export-download').isVisible(), false);
        assert.equal(requests.filter(x => x.get('action') === 'mg_design_export_run_ai').length, 1, 'stalled queue starts through the separate fallback request');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'mobile error and recovery controls fit the viewport');
        await page.screenshot({ path: path.join(screenshotDir, 'export-key-error-mobile.png') });
        await page.getByRole('button', { name: 'Export folytatása', exact: true }).click();
        await page.locator('.mg-order-export-download').waitFor({ state: 'visible' });
        assert.equal(requests.filter(x => x.get('action') === 'mg_design_export_retry').length, 1);
        assert.equal(requests.filter(x => x.get('action') === 'mg_design_export_start').length, 2, 'recovery does not create a third job');
        assert.equal(await page.locator('.mg-order-export-retry').isVisible(), false);
        await page.screenshot({ path: path.join(screenshotDir, 'export-recovered-mobile.png') });
        recoveryPhase = 'queued';
        workerReply = 'unreadable';
        await page.reload();
        await page.getByRole('button', { name: 'Normál export', exact: true }).click();
        await page.getByRole('button', { name: 'Export folytatása', exact: true }).waitFor({ state: 'visible' });
        assert.match(await page.locator('.mg-order-export-error').textContent(), /generálás nem indult el.*HTTP 503/);
        assert.doesNotMatch(await page.locator('.mg-order-export-error').textContent(), /private server path/);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(screenshotDir, 'export-worker-failure-mobile.png') });

        recoveryPhase = 'queued';
        workerReply = 'proxy';
        await page.reload();
        await page.getByRole('button', { name: 'Normál export', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('.mg-order-export-status').textContent.includes('OpenAI válaszára vár'));
        await page.waitForFunction(() => /2:1[1-9]/.test(document.querySelector('.mg-order-export-detail').textContent));
        assert.match(await page.locator('.mg-order-export-detail').textContent(), /időkorlátja: 3:00/);
        assert.match(await page.locator('.mg-order-export-notice').textContent(), /HTTP 504/);
        assert.equal(await page.locator('.mg-order-export-retry').isVisible(), false);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(screenshotDir, 'export-running-diagnostics-mobile.png') });
        recoveryPhase = 'done';
        await page.locator('.mg-order-export-download').waitFor({ state: 'visible' });
        assert.equal(await page.locator('.mg-order-export-notice').isVisible(), false);
        assert.deepEqual(errors, []);
        console.log('Browser export passed: review, recovery, visible worker errors, live elapsed time, proxy timeout with successful completion and mobile layout. Screenshots: ' + screenshotDir);
    } finally { await browser.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
