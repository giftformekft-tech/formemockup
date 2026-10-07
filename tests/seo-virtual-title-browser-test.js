// PLAYWRIGHT_MODULE=/path/to/playwright JQUERY_PATH=/path/to/jquery.min.js node tests/seo-virtual-title-browser-test.js
// A virtuális típusrendszer a vevőnek is ugyanazt mutatja, mint a botnak:
// típusváltáskor a böngészőfül címe a típus SEO-címére vált, és a típusra
// végződő slugú termék alap URL-je nem számít típusos URL-nek.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const types = ['ferfi-polo', 'pulcsi', 'bogre'];
const labels = { 'ferfi-polo': 'Férfi póló', pulcsi: 'Pulcsi', bogre: 'Bögre' };

async function openProduct(browser, url, slug) {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('https://forme.test/**', async route => {
        if (route.request().resourceType() === 'image') return route.abort();
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><html><head><meta charset="utf-8"><title>Szerver cím</title></head><body>
            <div class="product"><div class="summary"><h1 class="product_title">Kapitány horgász</h1><p class="price">5990 Ft</p>
            <form class="cart"><div class="mg-virtual-variant"></div>
            <input name="mg_product_type"><input name="mg_color"><input name="mg_size"><input name="mg_preview_url">
            <button type="submit" class="single_add_to_cart_button">Kosárba</button></form></div></div></body></html>` });
    });
    await page.goto(url);
    await page.addScriptTag({ content: fs.readFileSync(process.env.JQUERY_PATH, 'utf8') });
    await page.evaluate(({ types, labels, slug }) => {
        const config = {};
        const seoTitles = {};
        for (const type of types) {
            config[type] = {
                label: labels[type], color_order: ['fekete'], colors: { fekete: { label: 'Fekete', swatch: '#000', sizes: ['M'] } },
                size_order: ['M'], price: 5990, has_size_chart: false, has_size_chart_models: false, has_description: false
            };
            seoTitles[type] = 'Kapitány horgász - ' + labels[type] + ' | Horgász | Forme.hu';
        }
        window.MG_VIRTUAL_VARIANTS = {
            types: config, product: { id: 7, sku: 'FORME7', name: 'Kapitány horgász', slug },
            mockup: { baseUrl: 'https://forme.test/mockups' }, useVirtualPermalinks: true, seoTitles,
            default: { type: 'ferfi-polo', color: 'fekete', size: '' }, order: { types }
        };
    }, { types, labels, slug });
    await page.addScriptTag({ path: path.join(__dirname, '../assets/js/virtual-variant-display.js') });
    await page.waitForFunction(() => window.MG_VIRTUAL_VARIANT_INSTANCES?.length === 1);
    return { page, errors };
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
    try {
        // Típusos URL: a fül címe és a H1 a típusé.
        let { page, errors } = await openProduct(browser, 'https://forme.test/termek/kapitany-horgasz-bogre/', 'kapitany-horgasz');
        assert.equal(await page.inputValue('input[name="mg_product_type"]'), 'bogre');
        assert.equal(await page.title(), 'Kapitány horgász - Bögre | Horgász | Forme.hu');
        assert.equal(await page.locator('.product_title').innerText(), 'Kapitány horgász - Bögre');
        await page.evaluate(() => window.MG_VIRTUAL_VARIANT_INSTANCES[0].setType('pulcsi'));
        assert.equal(await page.title(), 'Kapitány horgász - Pulcsi | Horgász | Forme.hu', 'tab title follows the virtual type');
        assert.equal(new URL(page.url()).pathname, '/termek/kapitany-horgasz-pulcsi/', 'URL follows the virtual type');
        assert.deepEqual(errors, []);
        await page.close();

        // „-pulcsi” végű slugú termék alap URL-je: az alapértelmezett típus, nem a pulcsi.
        ({ page, errors } = await openProduct(browser, 'https://forme.test/termek/kapitany-horgasz-polo-pulcsi/', 'kapitany-horgasz-polo-pulcsi'));
        assert.equal(await page.inputValue('input[name="mg_product_type"]'), 'ferfi-polo', 'base URL ending with a type slug is not a type URL');
        assert.equal(await page.title(), 'Kapitány horgász - Férfi póló | Horgász | Forme.hu');
        assert.equal(new URL(page.url()).pathname, '/termek/kapitany-horgasz-polo-pulcsi-ferfi-polo/', 'customer lands on the default virtual URL');
        assert.deepEqual(errors, []);
        await page.close();

        // SEO-címek nélkül (pl. SEO modul kikapcsolva) a fül címe érintetlen marad.
        ({ page, errors } = await openProduct(browser, 'https://forme.test/termek/kapitany-horgasz-bogre/', 'kapitany-horgasz'));
        await page.evaluate(() => { delete window.MG_VIRTUAL_VARIANTS.seoTitles; window.MG_VIRTUAL_VARIANT_INSTANCES[0].config.seoTitles = undefined; window.MG_VIRTUAL_VARIANT_INSTANCES[0].setType('pulcsi'); });
        assert.equal(await page.title(), 'Kapitány horgász - Bögre | Horgász | Forme.hu', 'no seoTitles keeps the last title');
        assert.deepEqual(errors, []);
        await page.close();

        console.log('SEO virtual title browser checks passed: type URL title, type switch title and URL, base slug ending with a type, missing titles.');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exit(1);
});
