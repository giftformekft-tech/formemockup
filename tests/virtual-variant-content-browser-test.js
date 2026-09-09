// PLAYWRIGHT_MODULE=/path/to/playwright JQUERY_PATH=/path/to/jquery.min.js node tests/virtual-variant-content-browser-test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const requests = [];
        await page.route('https://variant.test/**', async route => {
            if (route.request().url().includes('admin-ajax')) {
                requests.push({ route, data: new URLSearchParams(route.request().postData()) });
                return;
            }
            if (route.request().resourceType() === 'image') return route.abort();
            return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><body>
                <div class="product"><div class="summary"><h1 class="product_title">Teszt</h1><p class="price">3990 Ft</p>
                <div class="woocommerce-product-details__short-description">Original description</div>
                <form class="cart"><div class="mg-virtual-variant"></div>
                <input name="mg_product_type"><input name="mg_color"><input name="mg_size"><input name="mg_preview_url">
                <button type="submit" class="single_add_to_cart_button">Kosárba</button></form></div></div>
                </body></html>` });
        });
        await page.goto('https://variant.test/product');
        await page.addScriptTag({ content: fs.readFileSync(process.env.JQUERY_PATH, 'utf8') });
        await page.evaluate(() => {
            const types = {};
            for (const slug of ['shirt', 'hoodie', 'mug']) {
                types[slug] = {
                    label: slug, color_order: ['white'], colors: { white: { label: 'White', swatch: '#fff', sizes: ['S', 'M'] } },
                    size_order: ['S', 'M'], price: 3990, has_size_chart: true, has_size_chart_models: true, has_description: true
                };
            }
            types.shirt.description = '<p>Shirt description</p>';
            window.MG_VIRTUAL_VARIANTS = {
                types, product: { id: 42, sku: 'SKU42', name: 'Teszt' },
                mockup: { baseUrl: 'https://variant.test/mockups' },
                contentAjax: { url: 'https://variant.test/admin-ajax.php', nonce: 'test' },
                default: { type: 'shirt', color: 'white', size: '' }, order: { types: ['shirt', 'hoodie', 'mug'] }
            };
        });
        await page.addScriptTag({ path: path.join(__dirname, '../assets/js/virtual-variant-display.js') });
        await page.waitForFunction(() => window.MG_VIRTUAL_VARIANT_INSTANCES?.length === 1);
        const run = fn => page.evaluate(fn);
        const requestCount = async count => {
            await assertEventually(() => requests.length >= count);
            assert.equal(requests.length, count);
        };
        const reply = (index, html) => requests[index].route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { html } }) });
        const chart = page.locator('.mg-size-chart-modal__panel--chart .mg-size-chart-modal__content');
        const models = page.locator('.mg-size-chart-modal__panel--models .mg-size-chart-modal__content');
        const description = page.locator('.woocommerce-product-details__short-description');

        assert.equal(requests.length, 0, 'initial load needs no content requests');
        assert.equal(await page.locator('.mg-size-chart-modal, canvas, .mg-pattern-preview').count(), 0, 'no unused preview or chart modal at startup');
        assert.equal(await description.innerText(), 'Shirt description');
        assert.equal(await page.locator('input[name="mg_size"]').inputValue(), '', 'size remains an explicit customer choice');
        await page.locator('.mg-size-chart-link').click();
        await requestCount(1);
        assert.equal(requests[0].data.get('section'), 'size_chart');
        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].showSizeChart());
        assert.equal(requests.length, 1, 'in-flight request shared');
        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].hideSizeChart());
        await reply(0, '<p>Shirt chart</p>');
        await page.waitForFunction(() => MG_VIRTUAL_VARIANTS.types.shirt.size_chart === '<p>Shirt chart</p>');
        assert.equal(await chart.innerText(), '', 'closing prevents late DOM insertion');
        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].showSizeChart());
        assert.equal(await chart.innerText(), 'Shirt chart');
        assert.equal(requests.length, 1, 'reopening uses cache');
        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].showSizeChartModels());
        await requestCount(2);
        assert.equal(requests[1].data.get('section'), 'size_chart_models');
        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].showSizeChart());
        await reply(1, '<p>Shirt models</p><img src="https://variant.test/model.webp">');
        await page.waitForFunction(() => Object.hasOwn(MG_VIRTUAL_VARIANTS.types.shirt, 'size_chart_models'));
        assert.equal(await models.innerHTML(), '', 'inactive model images never inserted');
        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].showSizeChartModels());
        assert.equal(await models.innerText(), 'Shirt models');
        assert.equal(requests.length, 2);

        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].setType('hoodie'));
        await requestCount(3);
        assert.equal(requests[2].data.get('section'), 'description');
        assert.equal(await page.locator('.mg-size-chart-modal.is-open').count(), 0);
        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].setType('mug'));
        await requestCount(4);
        await reply(3, '<p>Mug description</p>');
        await page.waitForFunction(() => document.querySelector('.woocommerce-product-details__short-description').textContent === 'Mug description');
        await reply(2, '<p>Hoodie description</p>');
        await page.waitForFunction(() => Object.hasOwn(MG_VIRTUAL_VARIANTS.types.hoodie, 'description'));
        assert.equal(await description.innerText(), 'Mug description', 'stale description cannot overwrite current type');
        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].showSizeChart());
        await requestCount(5);
        await requests[4].route.fulfill({ contentType: 'application/json', body: '{"success":false}' });
        await chart.locator('button').waitFor();
        await chart.locator('button').click();
        await requestCount(6);
        await requests[5].route.abort('failed');
        await chart.locator('button').waitFor();
        await chart.locator('button').click();
        await requestCount(7);
        await reply(6, '');
        await page.waitForFunction(() => Object.hasOwn(MG_VIRTUAL_VARIANTS.types.mug, 'size_chart'));
        assert.match(await chart.innerText(), /Nincs megjeleníthető/);
        await run(() => { const ui = window.MG_VIRTUAL_VARIANT_INSTANCES[0]; ui.hideSizeChart(); ui.showSizeChart(); });
        assert.equal(requests.length, 7, 'empty success is cached');
        await run(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].setType('shirt'));
        assert.equal(await description.innerText(), 'Shirt description');
        assert.equal(requests.length, 7);
        assert.deepEqual(errors, [], 'no browser runtime errors');
        console.log('Virtual variant browser checks passed: lazy loading, cache, request sharing, stale responses, close/reopen, hidden images, retries, empty content, explicit size choice.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

async function assertEventually(predicate) {
    const deadline = Date.now() + 5000;
    while (!predicate()) {
        if (Date.now() >= deadline) throw new Error('Expected request did not arrive');
        await new Promise(resolve => setTimeout(resolve, 20));
    }
}
