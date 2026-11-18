// PHP_BIN=... PLAYWRIGHT_MODULE=... CHROME_PATH=... node tests/measurement-browser-test.js
//
// A mérőszkripteket valódi böngészőben futtatja, helyettesített WordPress-
// válaszokkal. A Google és a Meta könyvtár betöltését tiltja, így az inline
// sorba állító (stub) gtag/fbq hívásait vizsgálja – azt, ami az oldalról kimenne.
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const fixture = (...args) => execFileSync(process.env.PHP_BIN || 'php',
    [path.join(__dirname, 'measurement-test.php'), '--fixture', ...args], { encoding: 'utf8' });

const purchaseEntry = {
    order_id: 555,
    order_key: 'wc_order_fixture',
    google: { event: { send_to: 'AW-123456/AbCdEf', transaction_id: '555', value: 11470, currency: 'HUF' }, user_data: { email: 'vevo@example.test' } },
    meta: { event_id: '555', data: { value: 11470, currency: 'HUF', content_ids: ['SKU7_ferfi-polo'] } },
};

async function open(browser, { html, url, cookies = {}, ajax = () => ({ pending: [], waiting: false }) }) {
    const context = await browser.newContext();
    await context.addCookies(Object.entries(cookies).map(([name, value]) => ({ name, value, domain: 'shop.test', path: '/' })));
    const page = await context.newPage();
    const errors = [];
    const lookups = [];
    const confirmations = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request();
        const target = new URL(request.url());
        if (target.hostname !== 'shop.test') { await route.abort(); return; }
        if (target.pathname.endsWith('admin-ajax.php')) {
            const body = Object.fromEntries(new URLSearchParams(request.postData() || ''));
            if (body.action === 'mg_conversion_fired') {
                confirmations.push(body);
                await route.fulfill({ contentType: 'application/json', body: '{"success":true}' });
                return;
            }
            lookups.push(body);
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: ajax(lookups.length) }) });
            return;
        }
        await route.fulfill({ contentType: 'text/html', body: html });
    });
    // Rögzíti, mi volt már sorban, amikor a DOM elkészült (a késleltetett
    // tartalék-időzítők és a consent híd újraküldése előtt).
    await page.addInitScript(() => {
        document.addEventListener('DOMContentLoaded', () => {
            window.__atDomReady = {
                google: (window.dataLayer || []).filter(entry => entry && entry[0] === 'event').map(entry => entry[1]),
                meta: ((window.fbq && window.fbq.queue) || []).filter(entry => entry[0] === 'track').map(entry => entry[1]),
            };
        });
    });
    await page.goto(url);
    const read = () => page.evaluate(() => ({
        google: (window.dataLayer || []).filter(entry => entry && entry[0] === 'event').map(entry => ({ name: entry[1], data: entry[2] })),
        userData: (window.dataLayer || []).filter(entry => entry && entry[0] === 'set' && entry[1] === 'user_data').length,
        meta: ((window.fbq && window.fbq.queue) || []).filter(entry => entry[0] === 'track').map(entry => ({ name: entry[1], data: entry[2], options: entry[3] })),
        atDomReady: window.__atDomReady,
        rendered: window.mgConvRendered || {},
    }));
    return { context, page, errors, lookups, confirmations, read };
}

const named = (events, name) => events.filter(event => event.name === name);
const thankYouUrl = 'https://shop.test/penztar/order-received/555/?key=wc_order_fixture';
const granted = { mg_gads_consent: 'granted' };
const flag = { mg_pending_conv_f: '1' };

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
    try {
        // 1. Mérhető rendelés: a vásárlás a DOM elkészülte előtt sorba kerül,
        //    és a pótló szkript nem küldi el másodszor.
        let run = await open(browser, {
            html: fixture('thankyou', 'processing'), url: thankYouUrl, cookies: { ...granted, ...flag },
            ajax: () => ({ pending: [purchaseEntry], waiting: false }),
        });
        await run.page.waitForTimeout(2500);
        let state = await run.read();
        assert.deepEqual(run.errors, []);
        assert(state.atDomReady.google.includes('purchase'), 'Google purchase must be queued before DOMContentLoaded');
        assert(state.atDomReady.meta.includes('Purchase'), 'Meta Purchase must be queued before DOMContentLoaded');
        assert.equal(named(state.google, 'purchase').length, 1, 'One Google purchase, not a second one from the recovery script');
        assert.equal(named(state.meta, 'Purchase').length, 1, 'One Meta Purchase, not a second one from the recovery script');
        assert.equal(named(state.google, 'purchase')[0].data.transaction_id, '555');
        assert.equal(named(state.google, 'purchase')[0].data.send_to, 'AW-123456/AbCdEf');
        assert.equal(named(state.meta, 'Purchase')[0].options.eventID, '555');
        assert.equal(state.userData, 1, 'Enhanced Conversions data with consent');
        assert.deepEqual(state.rendered, { 'google:555': true, 'meta:555': true });
        assert.equal(named(state.google, 'begin_checkout').length + named(state.meta, 'InitiateCheckout').length, 0, 'The thank-you page is not a checkout start');
        assert.deepEqual(run.confirmations.map(body => body.channel).sort(), ['google', 'meta']);
        assert.equal(run.lookups.length, 1);
        await run.context.close();

        // 2. Elutasított hozzájárulás: a Google cookie nélküli jelet küld
        //    ügyféladat nélkül, a Meta semmit.
        run = await open(browser, { html: fixture('thankyou', 'processing'), url: thankYouUrl, cookies: { mg_gads_consent: 'denied' } });
        await run.page.waitForTimeout(2500);
        state = await run.read();
        assert.deepEqual(run.errors, []);
        assert.equal(named(state.google, 'purchase').length, 1);
        assert.equal(state.userData, 0, 'No customer data without consent');
        assert.equal(state.meta.length, 0, 'No Meta event without consent');
        assert.deepEqual(run.confirmations.map(body => body.channel), ['google']);
        assert.equal(run.lookups.length, 0, 'No lookup without a remembered order');
        await run.context.close();

        // 3. Fizetésre váró rendelés: a köszönőoldalon nincs tag, de az oldal
        //    újrakérdez, és a visszaigazolás után elküldi a vásárlást.
        const pendingHtml = fixture('thankyou', 'pending');
        assert(!pendingHtml.includes('_mgPurchaseData') && !pendingHtml.includes('_mgFbPurchaseData'), 'An unpaid order must not render a purchase tag');
        run = await open(browser, {
            html: pendingHtml, url: thankYouUrl, cookies: { ...granted, ...flag },
            ajax: call => (call === 1 ? { pending: [], waiting: true } : { pending: [purchaseEntry], waiting: false }),
        });
        await run.page.waitForTimeout(1500);
        state = await run.read();
        assert.equal(named(state.google, 'purchase').length + named(state.meta, 'Purchase').length, 0, 'Nothing is sent while the payment is unconfirmed');
        assert.equal(run.lookups.length, 1);
        await run.page.waitForTimeout(3500);
        state = await run.read();
        assert.deepEqual(run.errors, []);
        assert.equal(run.lookups.length, 2, 'The thank-you page asks again while the order waits');
        assert.equal(named(state.google, 'purchase').length, 1, 'Google purchase after the payment confirmation');
        assert.equal(named(state.meta, 'Purchase').length, 1, 'Meta Purchase after the payment confirmation');
        assert.equal(named(state.meta, 'Purchase')[0].options.eventID, '555');
        assert.deepEqual(run.confirmations.map(body => body.channel).sort(), ['google', 'meta']);
        await run.page.waitForTimeout(6000);
        assert.equal(run.lookups.length, 2, 'Polling stops once nothing is waiting');
        await run.context.close();

        // 4. Átlagos látogató: jelző nélkül egyetlen szerverkérés sincs.
        const home = fixture('home');
        run = await open(browser, { html: home, url: 'https://shop.test/', cookies: granted });
        await run.page.waitForTimeout(1500);
        assert.deepEqual(run.errors, []);
        assert.equal(run.lookups.length, 0, 'Visitors without a remembered order must not hit admin-ajax');
        await run.context.close();

        //    Visszatérő vevő: egy lekérdezés, de máshol nincs ismételgetés.
        run = await open(browser, {
            html: home, url: 'https://shop.test/', cookies: { ...granted, ...flag },
            ajax: () => ({ pending: [], waiting: true }),
        });
        await run.page.waitForTimeout(4500);
        assert.equal(run.lookups.length, 1, 'Only the thank-you page polls');
        await run.context.close();

        // 5. Pénztár és kosár a klasszikus űrlap hookjai nélkül (blokkos oldalak).
        run = await open(browser, { html: fixture('checkout'), url: 'https://shop.test/penztar/', cookies: granted });
        await run.page.waitForTimeout(1500);
        state = await run.read();
        assert.deepEqual(run.errors, []);
        assert(state.atDomReady.meta.includes('InitiateCheckout'), 'InitiateCheckout is queued at once when consent is known');
        assert.equal(named(state.meta, 'InitiateCheckout').length, 1);
        assert.deepEqual(named(state.meta, 'InitiateCheckout')[0].data.content_ids, ['SKU7_ferfi-polo']);
        assert.equal(named(state.meta, 'InitiateCheckout')[0].data.value, 9980);
        assert.equal(named(state.google, 'begin_checkout').length, 1);
        assert.equal(named(state.google, 'begin_checkout')[0].data.items[0].id, 'SKU7_ferfi-polo');
        assert.equal(named(state.google, 'view_cart').length, 0);
        await run.context.close();

        run = await open(browser, { html: fixture('cart'), url: 'https://shop.test/kosar/', cookies: granted });
        await run.page.waitForTimeout(1500);
        state = await run.read();
        assert.deepEqual(run.errors, []);
        assert.equal(named(state.google, 'view_cart').length, 1);
        assert.equal(named(state.google, 'begin_checkout').length + named(state.meta, 'InitiateCheckout').length, 0);
        await run.context.close();

        console.log('Measurement browser: immediate purchase, consent, payment polling, no idle lookups and block cart/checkout events passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
